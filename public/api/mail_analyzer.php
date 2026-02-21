<?php
/**
 * SESメール解析エンジン (Mistral AI Mode - プロジェクト要約保存版)
 */

$start_time = microtime(true);
set_time_limit(0);
header('Content-Type: text/html; charset=utf-8'); 

require_once __DIR__ . '/../db-config.php';

// 設定
$current_month = date('n月');
$api_key = defined('MISTRAL_API_KEY') ? MISTRAL_API_KEY : '';
$api_url = 'https://api.mistral.ai/v1/chat/completions';

// GETパラメータ: limit（件数）または mail_id（単一指定）
$limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 50) : 10;
$target_mail_id = $_GET['mail_id'] ?? null;

echo ">>> SESメール解析エンジン (Mistral AI Mode) 開始\n";
if ($target_mail_id) {
    echo "ターゲットID: {$target_mail_id}\n";
} else {
    echo "処理件数: {$limit}件\n";
}
echo "--------------------------------------------------\n";

if (empty($api_key)) {
    die("<span style='color:#f87171;'>[エラー] APIキーが設定されていません。</span>\n");
}

try {
    $pdo = get_db_connection();
    
    if ($target_mail_id) {
        $stmt = $pdo->prepare("SELECT id, title, raw_body_path, date FROM received_mails WHERE id = ?");
        $stmt->execute([$target_mail_id]);
    } else {
        $stmt = $pdo->prepare("SELECT id, title, raw_body_path, date FROM received_mails WHERE analyze_flg = 0 ORDER BY date ASC LIMIT ?");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
    }
    $mails = $stmt->fetchAll();

    if (empty($mails)) {
        echo "解析対象のメールはありませんでした。\n";
    }

    $success_count = 0;

    foreach ($mails as $mail) {
        $mail_id = $mail['id'];
        $display_title = mb_strimwidth($mail['title'], 0, 44, "...");
        echo "<span style='display:inline-block; width:400px;'>[ID: $mail_id] 解析中: {$display_title}</span>";

        $storage_base = dirname(__DIR__, 2) . '/member/storage/emails';
        $file_path = $storage_base . '/' . $mail['raw_body_path'];

        if (!file_exists($file_path)) {
            echo "<span style='color:#f87171;'> [失敗] ファイル不在</span>\n";
            continue;
        }

        $body_content = file_get_contents($file_path);

        if (mb_strlen($body_content) > 5000) {
            $body_content = mb_substr($body_content, 0, 5000);
        }

        $prompt = "あなたはプロのIT案件キュレーターです。提供されたメール本文から情報を抽出し、必ず以下の構造の【json】形式で出力してください。
【json項目ルール】
1. title: 内容を元に「[主要技術]を用いた[業務内容]」の形式で20〜30文字程度で魅力的に作成。
2. summary_text: 案件の概要・背景・ミッションを2〜3文（100文字程度）で要約。
3. term: 「〇月～」の形式。開始が現在（{$current_month}）以前なら「即日～」とする。
4. location: 最寄駅名のみ（例：「大崎」「海老名」）。「駅」は含めない。
5. remote: 「フルリモート」「一部リモート」「出社」のいずれかに分類。
6. reward: 月額単価の最大値を【数値のみ】で抽出（例：65）。「スキル見合い」や単価不明の場合は、文字列を入れず、必ず null を出力してください。
7. skills: 技術スタックを最大12個の配列として出力（例: [\"Java\", \"Spring\"]）。";

        $post_data = [
            'model' => 'mistral-small-latest',
            'messages' => [
                ['role' => 'system', 'content' => $prompt],
                ['role' => 'user', 'content' => "解析対象メール本文:\n\n" . $body_content]
            ],
            'response_format' => ['type' => 'json_object']
        ];

        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . trim($api_key)
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200) {
            echo "<span style='color:#f87171;'> [エラー] HTTP $http_code</span>\n";
            continue;
        }

        $result = json_decode($response, true);
        $ai_json_raw = $result['choices'][0]['message']['content'] ?? '';
        $data = json_decode($ai_json_raw, true);

        if ($data && isset($data['title'])) {
            $skills_str = is_array($data['skills']) ? implode(',', $data['skills']) : $data['skills'];

            $pdo->beginTransaction();
            try {
                $upd = $pdo->prepare("UPDATE received_mails SET title = ?, analyze_flg = 1 WHERE id = ?");
                $upd->execute([$data['title'], $mail_id]);

                $pdo->prepare("DELETE FROM project_summaries WHERE mail_id = ?")->execute([$mail_id]);
                
                $ins = $pdo->prepare("INSERT INTO project_summaries 
                    (mail_id, title, term, location, remote, reward, skills, summary_text, created) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                
                $reward_val = $data['reward'];
                if (!is_numeric($reward_val)) {
                    $reward_val = null;
                } else {
                    $reward_val = (int)$reward_val;
                }

                $ins->execute([
                    $mail_id, $data['title'], $data['term'], $data['location'], 
                    $data['remote'], $reward_val, $skills_str, $data['summary_text'],
                    $mail['date'] // 受信日時を注入
                ]);

                $pdo->commit();
                echo "<span style='color:#4ade80;'> [成功] {$data['title']}</span>\n";
                $success_count++;
            } catch (Exception $e) {
                $pdo->rollBack();
                echo "<span style='color:#f87171;'> [DBエラー] " . $e->getMessage() . "</span>\n";
            }
        } else {
            echo "<span style='color:#f87171;'> [解析失敗] JSONエラー</span>\n";
        }
        flush();
    }

    echo "--------------------------------------------------\n";
    echo "JSONファイルを更新中...";
    $json_output_path = dirname(__DIR__, 2) . '/member/projects.json'; 
    $stmt = $pdo->query("SELECT * FROM project_summaries ORDER BY created DESC");
    $all_projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    file_put_contents($json_output_path, json_encode($all_projects, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo " <span style='color:#4ade80;'>[完了]</span>\n";

} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
}