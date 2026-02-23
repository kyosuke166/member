<?php
/**
 * SESメール解析エンジン (Ultimate Integrated Mode)
 */

$start_time = microtime(true);
set_time_limit(0);

// 出力バッファリングが開始されていない場合のみ、直接実行用のヘッダーを出す
if (php_sapi_name() !== 'cli' && !headers_sent() && ob_get_level() === 0) {
    if (!isset($_GET['target_id'])) {
        header('Content-Type: text/plain; charset=utf-8');
    }
}
// 直接実行時のみヘッダーを出力する ---
if (php_sapi_name() !== 'cli' && !headers_sent()) {
    // 呼び出し元が re_analyze.php などの場合は JSON を返すため、
    // テキストヘッダーを出さないように制御
    if (!isset($_GET['target_id'])) {
        header('Content-Type: text/plain; charset=utf-8'); 
        header('X-Content-Type-Options: nosniff');
    }
}

require_once __DIR__ . '/../db-config.php';

$api_key = defined('MISTRAL_API_KEY') ? MISTRAL_API_KEY : '';
$api_url = 'https://api.mistral.ai/v1/chat/completions';
$json_output_path = dirname(__DIR__, 2) . '/member/projects.json';

// --- カラー定義（プレフィックス） ---
$tag_project  = "[CAT:1] "; // 案件用
$tag_engineer = "[CAT:2] "; // 技術者用
$tag_done     = "[DONE] ";
$tag_error    = "[ERROR] ";

// カウンター
$counts = ['total' => 0, 'engineer' => 0, 'project' => 0, 'failed' => 0];

// パラメータ取得
$limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 50) : 10;
$target_mail_id = $_GET['target_id'] ?? ($_GET['mail_id'] ?? null);

echo ">>> SESメール解析エンジン起動\n";
echo str_repeat("-", 50) . "\n";

try {
    $pdo = get_db_connection();
    
    // 1. 対象メールの抽出
    if ($target_mail_id) {
        $stmt = $pdo->prepare("SELECT id, title, subject, raw_body_path, date, category FROM received_mails WHERE id = ?");
        $stmt->execute([$target_mail_id]);
    } else {
        $stmt = $pdo->prepare("SELECT id, title, subject, raw_body_path, date, category FROM received_mails WHERE analyze_flg = 0 ORDER BY date ASC LIMIT ?");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
    }
    $mails = $stmt->fetchAll();

    foreach ($mails as $mail) {
        $mail_id = $mail['id'];
        $counts['total']++;
        $display_subject = mb_strimwidth($mail['subject'], 0, 40, "...");
        
        // --- カテゴリ2: 技術者 (AI解析をスキップしてDB更新のみ) ---
        if ((int)$mail['category'] === 2) {
            echo "{$tag_engineer}[ID: $mail_id] 【技術者】 $display_subject ...";
            $pdo->beginTransaction();
            try {
                // 1. 存在チェック
                $checkStmt = $pdo->prepare("SELECT id FROM project_summaries WHERE mail_id = ?");
                $checkStmt->execute([$mail_id]);
                $existingId = $checkStmt->fetchColumn();

                if ($existingId) {
                    // 2. 存在すればUPDATE
                    $stmt = $pdo->prepare("UPDATE project_summaries SET title = ?, summary_text = '技術者情報メール', created = ? WHERE mail_id = ?");
                    $stmt->execute([$mail['title'] ?: $mail['subject'], $mail['date'], $mail_id]);
                } else {
                    // 3. 存在しなければINSERT (ここでのみIDが発行される)
                    $stmt = $pdo->prepare("INSERT INTO project_summaries (mail_id, title, summary_text, created) VALUES (?, ?, '技術者情報メール', ?)");
                    $stmt->execute([$mail_id, $mail['title'] ?: $mail['subject'], $mail['date']]);
                }

                // received_mails側のフラグ更新
                $pdo->prepare("UPDATE received_mails SET analyze_flg = 1 WHERE id = ?")->execute([$mail_id]);
                
                $pdo->commit();
                $counts['engineer']++;
                echo " [完了]\n";
            } catch (Exception $e) { 
                $pdo->rollBack(); 
                echo " {$tag_error}[DB失敗]\n"; 
                $counts['failed']++; 
            }
            flush(); continue;
        }

        // --- カテゴリ1: 案件解析 (AI実行) ---
        echo "{$tag_project}[ID: $mail_id] 【案件解析】 $display_subject ...";

        $file_path = dirname(__DIR__, 2) . '/member/storage/emails/' . $mail['raw_body_path'];
        if (!file_exists($file_path)) { 
            echo " {$tag_error}[本文不在]\n";
            $counts['failed']++;
            continue; 
        }

        $body = mb_strimwidth(file_get_contents($file_path), 0, 4000);

        // プロンプト（update_projects.phpの品質を維持）
        $post_data = [
            'model' => 'mistral-small-latest',
            'messages' => [
                ['role' => 'system', 'content' => "あなたはプロのIT案件キュレーターです。提供されたメールから案件情報を抽出し、日本語のJSON形式で出力してください。

【各項目ルール】
1. 会社名は、タイトルや要約に含めず「大手企業」「DX推進企業」などの一般名詞に変換すること。
2. 案件タイトル(title)は内容が伝わるよう魅力的にリライトすること。
3. 要約(summary_text)は、作業内容や環境を4行程度でまとめること。
4. 作業期間(term)は、「4月～」のような形式で抽出。現在月や過去月の場合は「即日～」にする。
5. 場所(location)は「駅名」から「駅」を省いて抽出。無い場合は地名を探す。
6. リモート(remote)は「フルリモート」「一部リモート」「出社」から選択。
7. 単価(reward)は数値のみ（例：「80万円」なら「80」）。
8. スキル(skills)は、技術スタックを短い単語で最大9つのカンマ区切り文字列。
9. 出力は必ず以下のJSON構造のみとすること。
{\"title\":\"\",\"summary_text\":\"\",\"term\":\"\",\"location\":\"\",\"remote\":\"\",\"reward\":\"\",\"skills\":\"\"}"],
                ['role' => 'user', 'content' => "以下から抽出:\n" . $body]
            ],
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.1
        ];

        // APIリクエスト
        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . trim($api_key)]);
        
        $response = curl_exec($ch); 
        $info = curl_getinfo($ch); // 閉じる前に情報を取得
        $curl_error = curl_error($ch); // エラーがあれば取得
        curl_close($ch); // ここで一度だけ閉じる

        if ($info['http_code'] !== 200) {
            echo " [ERROR] API失敗(HTTP: {$info['http_code']}) Error: {$curl_error}\n";
        }

        $res_arr = json_decode($response, true);
        $json_raw = $res_arr['choices'][0]['message']['content'] ?? '';
        $data = json_decode($json_raw, true);

        if ($data && !empty($data['title'])) {
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
                // 1. 既に対象の mail_id が存在するかチェック
                $checkStmt = $pdo->prepare("SELECT id FROM project_summaries WHERE mail_id = ?");
                $checkStmt->execute([$mail_id]);
                $existingId = $checkStmt->fetchColumn();

                if ($existingId) {
                    // 2. 存在する場合は UPDATE (IDは変わらない)
                    $updateSql = "UPDATE project_summaries SET 
                        title = :title, term = :term, location = :loc, 
                        remote = :rem, reward = :rew, skills = :skl, 
                        summary_text = :txt, created = :created 
                        WHERE mail_id = :mid";
                    $stmt = $pdo->prepare($updateSql);
                } else {
                    // 3. 存在しない場合のみ INSERT (ここで初めて新しいIDが発行される)
                    $insertSql = "INSERT INTO project_summaries 
                        (mail_id, title, term, location, remote, reward, skills, summary_text, created) 
                        VALUES (:mid, :title, :term, :loc, :rem, :rew, :skl, :txt, :created)";
                    $stmt = $pdo->prepare($insertSql);
                }

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

                $pdo->prepare("UPDATE received_mails SET title = ?, analyze_flg = 1 WHERE id = ?")->execute([$data['title'], $mail_id]);
                $pdo->commit();
                $counts['project']++;
                echo " [成功]\n";
            } catch (Exception $e) {
                $pdo->rollBack();
                echo " {$tag_error}[DBエラー]\n";
                $counts['failed']++;
            }
        } else {
            echo " {$tag_error}[解析不備]\n";
            $counts['failed']++;
        }
        flush();
    }

    // JSON書き出し
    $stmt = $pdo->query("SELECT ps.* FROM project_summaries ps JOIN received_mails rm ON ps.mail_id = rm.id WHERE rm.category = 1 ORDER BY ps.created DESC");
    file_put_contents($json_output_path, json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    $execution_time = round(microtime(true) - $start_time, 2);

    echo str_repeat("-", 50) . "\n";
    echo "{$tag_done}解析プロセス完了\n";
    echo "【処理結果内訳】\n";
    echo " ・総処理数: {$counts['total']} 件（";
    echo " ・案件解析: {$counts['project']} 件 ／ ";
    echo " ・技術者等: {$counts['engineer']} 件 ／ ";
    echo " ・失敗/不備: {$counts['failed']} 件）\n";
    echo " ・実行時間: {$execution_time} 秒\n";

} catch (Exception $e) { echo "{$tag_error}FATAL ERROR: " . $e->getMessage(); }