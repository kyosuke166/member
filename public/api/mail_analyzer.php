<?php
/**
 * SESメール解析エンジン (Gemini Batch Mode)
 */

set_time_limit(0);
if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

require_once __DIR__ . '/../db-config.php';
require_once __DIR__ . '/gemini_ai.php';

$api_key = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
$json_output_path = dirname(__DIR__, 2) . '/member/projects.json';

// --- カラー定義（プレフィックス）復活 ---
$tag_project  = "[CAT:1] "; // 案件用
$tag_engineer = "[CAT:2] "; // 技術者用
$tag_done     = "[DONE] ";
$tag_error    = "[ERROR] ";

$counts = ['total' => 0, 'engineer' => 0, 'project' => 0, 'failed' => 0];
$limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 50) : 10;

echo ">>> SESメール解析エンジン(Gemini) 起動\n";
echo str_repeat("-", 50) . "\n";

try {
    $pdo = get_db_connection();
    
    $stmt = $pdo->prepare("SELECT id, subject, raw_body_path, date, category FROM received_mails WHERE analyze_flg = 0 ORDER BY date ASC LIMIT ?");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $mails = $stmt->fetchAll();

    foreach ($mails as $mail) {
        $mail_id = $mail['id'];
        $counts['total']++;
        $display_subject = mb_strimwidth($mail['subject'], 0, 40, "...");
        
        // --- カテゴリ2: 技術者の場合 ---
        if ((int)$mail['category'] === 2) {
            echo "{$tag_engineer}[ID: $mail_id] 【技術者】 $display_subject ...";
            $pdo->prepare("UPDATE received_mails SET analyze_flg = 1 WHERE id = ?")->execute([$mail_id]);
            $counts['engineer']++;
            echo " [スキップ完了]\n";
            continue;
        }

        // --- カテゴリ1: 案件解析 (Gemini実行) ---
        echo "{$tag_project}[ID: $mail_id] 【案件解析】 $display_subject ...";

        $file_path = dirname(__DIR__, 2) . '/member/storage/emails/' . $mail['raw_body_path'];
        if (!file_exists($file_path)) { 
            echo " {$tag_error}[本文不在]\n";
            $counts['failed']++;
            continue; 
        }

        $body = mb_strimwidth(file_get_contents($file_path), 0, 4000);
        $result = analyze_mail_with_ai($body, $api_key);

        if ($result['success']) {
            $data = $result['data'];
            
            // 単価の正規化
            $reward = null;
            if (!empty($data['reward'])) {
                $val = mb_convert_kana($data['reward'], "n");
                if (preg_match('/(\d+)/', $val, $m)) {
                    $num = (int)$m[1];
                    $reward = ($num >= 10000) ? (int)($num / 10000) : $num;
                }
            }

            $pdo->beginTransaction();
            try {
                $sql = "INSERT INTO project_summaries (mail_id, title, term, location, remote, reward, skills, summary_text, created) 
                        VALUES (:mid, :title, :term, :loc, :rem, :rew, :skl, :txt, :created)
                        ON DUPLICATE KEY UPDATE 
                        title=:title, term=:term, location=:loc, remote=:rem, reward=:rew, skills=:skl, summary_text=:txt, created=:created";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':mid' => $mail_id,
                    ':title' => mb_strimwidth($data['title'], 0, 255),
                    ':term' => $data['term'] ?? '要確認',
                    ':loc' => $data['location'] ?? '調査中',
                    ':rem' => $data['remote'] ?? '要確認',
                    ':rew' => $reward,
                    ':skl' => mb_strimwidth($data['skills'] ?? '', 0, 255),
                    ':txt' => $data['summary_text'] ?? '',
                    ':created' => $mail['date']
                ]);

                $pdo->prepare("UPDATE received_mails SET analyze_flg = 1 WHERE id = ?")->execute([$mail_id]);
                $pdo->commit();
                $counts['project']++;
                echo " [成功]\n";
            } catch (Exception $e) {
                $pdo->rollBack();
                echo " {$tag_error}[DBエラー: " . $e->getMessage() . "]\n";
                $counts['failed']++;
            }
        } else {
            echo " {$tag_error}[解析失敗]\n";
            $counts['failed']++;
        }
        flush();
    }

    // JSON更新 (最新500件)
    if ($counts['project'] > 0 || $counts['engineer'] > 0) {
        $stmt = $pdo->query("
            SELECT ps.mail_id, ps.title, ps.reward, ps.location, ps.remote, ps.skills, ps.summary_text, rm.date AS created 
            FROM project_summaries ps 
            JOIN received_mails rm ON ps.mail_id = rm.id 
            WHERE rm.category = 1 ORDER BY rm.date DESC LIMIT 500
        ");
        file_put_contents($json_output_path, json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    echo str_repeat("-", 50) . "\n";
    echo "{$tag_done}解析プロセス完了\n";
    echo "【処理結果内訳】\n";
    echo " ・案件解析: {$counts['project']} 件 / 技術者: {$counts['engineer']} 件 / 失敗: {$counts['failed']} 件\n";

} catch (Exception $e) { 
    echo "{$tag_error}FATAL ERROR: " . $e->getMessage(); 
}