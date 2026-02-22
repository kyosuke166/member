<?php
/**
 * SESメール解析エンジン
 */

$start_time = microtime(true);
set_time_limit(0);
// ブラウザでリアルタイムに出力するための設定
header('Content-Type: text/plain; charset=utf-8'); 
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../db-config.php';

$api_key = defined('MISTRAL_API_KEY') ? MISTRAL_API_KEY : '';
$api_url = 'https://api.mistral.ai/v1/chat/completions';
$json_output_path = dirname(__DIR__, 2) . '/member/projects.json';

// --- カラー定義をプレフィックスに変更 ---
$tag_project  = "[CAT:1] "; // 案件用
$tag_engineer = "[CAT:2] "; // 技術者用
$tag_done     = "[DONE] ";
$tag_error    = "[ERROR] ";
$tag_info     = ""; 

// カウンターの初期化
$counts = [
    'total'   => 0,
    'engineer'=> 0, 
    'project' => 0, 
    'failed'  => 0, 
];

// GETパラメータ
$limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 50) : 10;
// re_analyze.php から渡される target_id を優先的にチェック
$target_mail_id = isset($_GET['target_id']) ? $_GET['target_id'] : ($_GET['mail_id'] ?? null);

echo ">>> SESメール解析エンジン (Ultimate Integrated Mode)\n";
echo str_repeat("-", 50) . "\n";

try {
    $pdo = get_db_connection();
    
    $upsert_sql = "INSERT INTO project_summaries 
                    (mail_id, title, term, location, remote, reward, skills, summary_text, created) 
                   VALUES 
                    (:mid, :title, :term, :loc, :rem, :rew, :skl, :txt, :created)
                    ON DUPLICATE KEY UPDATE 
                     title = VALUES(title), term = VALUES(term), location = VALUES(location),
                     remote = VALUES(remote), reward = VALUES(reward), skills = VALUES(skills),
                     summary_text = VALUES(summary_text), created = VALUES(created)";

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
        
        // --- Category 2 (技術者) の処理 ---
        if ((int)$mail['category'] === 2) {
            $display_title = mb_strimwidth($mail['title'] ?: $mail['subject'], 0, 40, "...");
            echo "{$tag_engineer}[ID: $mail_id] 【技術者】 $display_title ...";
            
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare($upsert_sql);
                $stmt->execute([
                    ':mid' => $mail_id, ':title' => $mail['title'],
                    ':term' => '要確認', ':loc' => '調査中', ':rem' => '要確認',
                    ':rew' => null, ':skl' => '', ':txt' => '技術者情報メール', ':created' => $mail['date']
                ]);
                $pdo->prepare("UPDATE received_mails SET analyze_flg = 1 WHERE id = ?")->execute([$mail_id]);
                $pdo->commit();
                $counts['engineer']++;
                echo " [完了]\n";
            } catch (Exception $e) { 
                $pdo->rollBack(); 
                $counts['failed']++;
                echo " {$tag_error}[DBエラー]\n"; 
            }
            flush(); continue;
        }

        // --- Category 1 (案件) AI解析処理 ---
        $display_subject = mb_strimwidth($mail['subject'], 0, 40, "...");
        echo "{$tag_project}[ID: $mail_id] 【案件解析】 $display_subject ...";

        $file_path = dirname(__DIR__, 2) . '/member/storage/emails/' . $mail['raw_body_path'];
        if (!file_exists($file_path)) { 
            echo " {$tag_error}[失敗] 本文不在\n"; 
            $counts['failed']++;
            continue; 
        }

        $body = mb_strimwidth(file_get_contents($file_path), 0, 5000);
        $post_data = [
            'model' => 'mistral-small-latest',
            'messages' => [
                ['role' => 'system', 'content' => "あなたはプロのIT案件キュレーターです。提供されたメールから案件情報を抽出し、日本語のJSON形式で出力してください。

            【各項目ルール】
            1. 会社名は、タイトルや要約に含めず「大手企業」「DX推進企業」などの一般名詞に変換すること。
            2. 案件タイトル(title)は内容が伝わるよう魅力的にリライトすること。
            3. 要約(summary_text)は、作業内容や環境を4行程度でまとめること。
            4. 作業期間(term)は、「2026年4月～」のような形式で抽出してください。
            5. 場所(location)は「駅名」を抽出。
            6. リモート(remote)は「フルリモート」「一部リモート」「出社」から選択。
            7. 単価(reward)の抽出：数値のみを抽出（例：「〜120万円」なら「120」）。
            8. スキル(skills)は、技術スタックを最大6つのカンマ区切り文字列。
            9. 出力は必ず以下のJSON構造のみとすること。
            {\"title\":\"\",\"summary_text\":\"\",\"term\":\"\",\"location\":\"\",\"remote\":\"\",\"reward\":\"\",\"skills\":\"\"}"],
                ['role' => 'user', 'content' => "以下から抽出:\n" . $body]
            ],
            'response_format' => ['type' => 'json_object']
        ];

        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . trim($api_key)]);
        $response = curl_exec($ch); curl_close($ch);

        $res_arr = json_decode($response, true);
        $raw_content = $res_arr['choices'][0]['message']['content'] ?? '';
        $raw_content = htmlspecialchars_decode($raw_content, ENT_QUOTES);
        $clean_json = preg_match('/\{.*\}/s', $raw_content, $matches) ? $matches[0] : $raw_content;
        // 前後の空白やマークダウンのゴミを掃除
        $clean_json = trim($raw_content);
        $clean_json = preg_replace('/^```json\s+/', '', $clean_json);
        $clean_json = preg_replace('/\s+```$/', '', $clean_json);
        // 強力に { } の外側を削る
        if (preg_match('/\{.*\}/us', $clean_json, $matches)) {
            $clean_json = $matches[0];
        }
        $data = json_decode($clean_json, true);

        if ($data) {
            if (isset($data['案件'])) $data = array_merge($data, $data['案件']);
            if (isset($data['project'])) $data = array_merge($data, $data['project']);

            $mapping = [
                'title' => ['案件名', '業務名', '案件', '案件概要'],
                'summary_text' => ['概要', '業務内容', '詳細', '内容', '案件内容'],
                'term' => ['期間', '開始日', '稼働期間'],
                'location' => ['場所', '最寄駅', '勤務地', 'エリア'],
                'remote' => ['リモート', '働き方', 'リモート区分'],
                'reward' => ['単価', '報酬', '金額', '単価(万)'],
                'skills' => ['スキル', '必須スキル', '応募要件', '必要経験', '要求スキル']
            ];
            foreach ($mapping as $eng => $jps) {
                if (empty($data[$eng])) {
                    foreach ($jps as $jp) { if (!empty($data[$jp])) { $data[$eng] = $data[$jp]; break; } }
                }
            }

            $reward = null;
            if (!empty($data['reward'])) {
                $reward_val = is_array($data['reward']) ? implode(' ', $data['reward']) : $data['reward'];
                $val = mb_convert_kana($reward_val, "n");
                if (preg_match('/(\d+\.?\d*)/', $val, $m)) {
                    $num = (float)$m[1];
                    $reward = ($num >= 10000) ? (int)($num / 10000) : (int)$num;
                }
            }

            $remote_raw = is_array($data['remote'] ?? '') ? implode(' ', $data['remote']) : ($data['remote'] ?? '');
            $remote = '出社';
            if (preg_match('/(リモート|在宅|テレワーク|リモ)/u', $body . $remote_raw)) {
                if (preg_match('/(フル|完全|全)/u', $remote_raw . $body)) $remote = 'フルリモート';
                elseif (preg_match('/(一部|ハイブリッド|併用|相談|週[1-4]|可$)/u', $remote_raw . $body)) $remote = '一部リモート';
            }

            $it = new RecursiveIteratorIterator(new RecursiveArrayIterator(is_array($data['skills'] ?? '') ? $data['skills'] : [$data['skills'] ?? '']));
            $skill_list = [];
            foreach($it as $v) { if(!empty($v)) $skill_list[] = mb_strimwidth($v, 0, 40, "..."); }
            $skills_final = mb_strimwidth(implode(', ', $skill_list), 0, 255, "...");

            $pdo->beginTransaction();
            try {
                $title = mb_strimwidth($data['title'] ?? '無題', 0, 255, "");
                $summary = mb_strimwidth($data['summary_text'] ?? '', 0, 60000, "");
                $pdo->prepare("UPDATE received_mails SET title = ?, analyze_flg = 1 WHERE id = ?")->execute([$title, $mail_id]);
                $stmt = $pdo->prepare($upsert_sql);
                $stmt->execute([
                    ':mid' => $mail_id, 
                    ':title' => $title, 
                    ':term' => mb_strimwidth($data['term'] ?? '要確認', 0, 100, ""),
                    ':loc' => mb_strimwidth($data['location'] ?? '調査中', 0, 100, ""), 
                    ':rem' => $remote,
                    ':rew' => $reward, 
                    ':skl' => $skills_final,
                    ':txt' => $summary, // カット後のテキスト
                    ':created' => $mail['date']
                ]);
                $pdo->commit(); 
                $counts['project']++;
                echo " [成功]\n";
            } catch (Exception $e) { 
                $pdo->rollBack(); 
                $counts['failed']++;
                echo " {$tag_error}[DBエラー]\n"; 
            }
        } else {
            $counts['failed']++;
            echo " {$tag_error}[解析不備] Debug: " . htmlspecialchars(mb_strimwidth($raw_content, 0, 40)) . "...\n";
        }
        flush();
    }

    $stmt = $pdo->query("SELECT ps.* FROM project_summaries ps JOIN received_mails rm ON ps.mail_id = rm.id WHERE rm.category = 1 ORDER BY ps.created DESC");
    file_put_contents($json_output_path, json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    $execution_time = round(microtime(true) - $start_time, 2);

    echo str_repeat("-", 50) . "\n";
    echo "{$tag_done}JSON一括更新完了\n";
    echo "【処理結果内訳】\n";
    echo " ・総処理数: {$counts['total']} 件（案件解析: {$counts['project']} 件／技術者等: {$counts['engineer']} 件／失敗/不備: {$counts['failed']} 件）\n";
    echo " ・実行時間: {$execution_time} 秒\n";
    echo "[DONE] [" . date('H:i:s') . "] 解析プロセスが完了しました。\n";

} catch (Exception $e) { echo "{$tag_error}FATAL ERROR: " . $e->getMessage(); }