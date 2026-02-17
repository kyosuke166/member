<?php
/**
 * SESメール解析エンジン (Mistral AI Mode - 最終安定版)
 */

$start_time = microtime(true);

set_time_limit(0);
header('Content-Type: text/html; charset=utf-8');

// db-config.php を読み込み（ここに MISTRAL_API_KEY 定数がある前提）
require_once __DIR__ . '/../db-config.php';

// 設定
$current_month = date('n月');
$api_key = defined('MISTRAL_API_KEY') ? MISTRAL_API_KEY : '';
$api_url = 'https://api.mistral.ai/v1/chat/completions';

echo "<!DOCTYPE html><html><head><title>Mail Analyzer</title>";
echo "<style>
    body { background:#1a1a1a; color:#ccc; font-family:sans-serif; padding:20px; line-height:1.4; }
    .row { border-bottom:1px solid #333; padding:10px 0; }
    .col-id { color:#888; margin-right:10px; }
    .status-ok { color:#00ff00; font-weight:bold; }
    .status-err { color:#ff4444; font-weight:bold; }
    pre { background:#000; padding:10px; color:#00ff00; font-size:12px; overflow:auto; max-height:200px; }
</style></head><body>";

echo "<h2>>>> SESメール解析エンジン (Mistral AI Mode)</h2>";

if (empty($api_key)) {
    die("<span class='status-err'>[エラー] APIキーが db-config.php に設定されていません。</span>");
}

try {
    $pdo = get_db_connection();
    
    // 未解析のメールを取得 (analyze_flg = 0)
    $stmt = $pdo->query("SELECT id, subject, raw_body_path FROM received_mails WHERE analyze_flg = 0 LIMIT 10");
    $mails = $stmt->fetchAll();

    if (empty($mails)) {
        echo "未解析のメールはありませんでした。";
    }

    foreach ($mails as $mail) {
        $mail_id = $mail['id'];
        echo "<div class='row'>";
        echo "<span class='col-id'>[Mail ID: $mail_id]</span> " . htmlspecialchars($mail['subject']) . "<br>";

        // メール本文の読み込み
        $storage_base = dirname(__DIR__, 2) . '/member/storage/emails';
        $file_path = $storage_base . '/' . $mail['raw_body_path'];

        if (!file_exists($file_path)) {
            echo "<span class='status-err'>[エラー] 本文ファイルが見つかりません</span></div>";
            continue;
        }

        $body_content = file_get_contents($file_path);

        // --- AIプロンプト作成 ---
        $prompt = "あなたはプロのIT案件キュレーターです。提供されたメール本文から情報を抽出し、必ず以下の構造の【json】形式で出力してください。

【json項目ルール】
1. title: 内容を元に「[主要技術]を用いた[業務内容]」の形式で20〜30文字程度で魅力的に作成。
2. summary_text: 案件の概要・背景・ミッションを2〜3文（100文字程度）で要約。
3. term: 「〇月～」の形式。開始が現在（{$current_month}）以前なら「即日～」とする。
4. location: 最寄駅名のみ（例：「大崎」「海老名」）。「駅」は含めない。
5. remote: 「フルリモート」「一部リモート」「出社」のいずれかに分類。
6. reward: 月額単価の最大値を数値のみで抽出（例：65）。
7. skills: 技術スタックを最大12個の配列として出力（例: [\"Java\", \"Spring\"]）。

【重要】
出力は、Markdownの装飾（```json ... ```）を省き、純粋なjsonオブジェクトのみを返してください。";

        // Mistral API 呼び出し
        $post_data = [
            'model' => 'mistral-small-latest',
            'messages' => [
                ['role' => 'system', 'content' => $prompt],
                ['role' => 'user', 'content' => "解析対象メール本文:\n\n" . $body_content]
            ]
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
            echo "<span class='status-err'>[APIエラー] HTTPコード: $http_code</span></div>";
            continue;
        }

        $result = json_decode($response, true);
        $ai_json_raw = $result['choices'][0]['message']['content'] ?? '';
        $ai_json_raw = preg_replace('/^```json\s*|```$/', '', trim($ai_json_raw));
        $data = json_decode($ai_json_raw, true);

        if ($data && isset($data['title'])) {
            $skills_str = is_array($data['skills']) ? implode(',', $data['skills']) : $data['skills'];

            $pdo->beginTransaction();
            try {
                // 1. received_mails の更新
                $upd = $pdo->prepare("UPDATE received_mails SET title = ?, analyze_flg = 1 WHERE id = ?");
                $upd->execute([$data['title'], $mail_id]);

                // 2. project_summaries への保存 (カラム名: created)
                $pdo->prepare("DELETE FROM project_summaries WHERE mail_id = ?")->execute([$mail_id]);
                $ins = $pdo->prepare("INSERT INTO project_summaries 
                    (mail_id, title, term, location, remote, reward, skills, summary_text, created) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                
                $ins->execute([
                    $mail_id,
                    $data['title'],
                    $data['term'],
                    $data['location'],
                    $data['remote'],
                    $data['reward'],
                    $skills_str,
                    $data['summary_text']
                ]);

                $pdo->commit();
                echo "<span class='status-ok'>[解析成功]</span> " . htmlspecialchars($data['title']);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo "<span class='status-err'>[DBエラー] " . htmlspecialchars($e->getMessage()) . "</span>";
            }
        } else {
            echo "<span class='status-err'>[解析失敗] JSONパースエラー</span>";
        }

        echo "</div>";
        flush();
    }

    $duration = round(microtime(true) - $start_time, 2);
    echo "<h3>解析プロセス終了</h3>";
    echo "実行時間: {$duration}秒";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}

echo "</body></html>";