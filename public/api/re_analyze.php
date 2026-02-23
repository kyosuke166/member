<?php
/**
 * api/re_analyze.php
 * 指定されたメールIDを強制的にAI解析(Gemini)し、結果をDBに反映する
 */

// 出力バッファリング開始（BOMや不要な出力を飲み込む）
ob_start();

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db-config.php';
require_once __DIR__ . '/gemini_ai.php'; // 今回作成するGemini用関数ファイル

// db-config.phpで定義されているGEMINI_API_KEYを使用
$mail_id = $_GET['mail_id'] ?? null;
$api_key = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';

if (!$mail_id) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'メールIDが指定されていません。']);
    exit;
}

try {
    $pdo = get_db_connection();
    
    // 1. 対象データの取得（analyze_flgの状態に関わらず取得）
    $stmt = $pdo->prepare("SELECT * FROM received_mails WHERE id = ?");
    $stmt->execute([$mail_id]);
    $mail = $stmt->fetch();

    if (!$mail) {
        throw new Exception('対象のメールが見つかりませんでした。');
    }

    // 2. 本文ファイルの読み込み
    $file_path = dirname(__DIR__, 2) . '/member/storage/emails/' . $mail['raw_body_path'];
    if (!file_exists($file_path)) {
        throw new Exception('解析元の本文ファイルが存在しません。');
    }
    $body = mb_strimwidth(file_get_contents($file_path), 0, 4000);

    // 3. AI解析実行（Gemini）
    // ※ analyze_mail_with_ai 関数は gemini_ai.php 内で定義
    $result = analyze_mail_with_ai($body, $api_key);

    if (!$result['success']) {
        $code = $result['code'] ?? 'Unknown';
        $error_detail = $result['error'] ?? '詳細不明';
        
        // 429(Rate Limit)やその他のエラーを判定
        $msg = ($code == 429) ? 'AI利用制限中です（Gemini）。時間を置いて試してください。' : 'AI解析失敗(Code:'.$code.')';
        
        ob_end_clean();
        echo json_encode([
            'success' => false, 
            'message' => $msg,
            'debug_log' => $error_detail
        ]);
        exit;
    }

    // 4. 解析成功時：DB更新処理（トランザクション）
    $pdo->beginTransaction();
    $data = $result['data'];

    // 単価(reward)の正規化ロジック
    $reward = null;
    if (!empty($data['reward'])) {
        $val = mb_convert_kana($data['reward'], "n");
        if (preg_match('/(\d+)/', $val, $m)) {
            $num = (int)$m[1];
            $reward = ($num >= 10000) ? (int)($num / 10000) : $num;
        }
    }

    // project_summaries の更新または挿入
    $checkStmt = $pdo->prepare("SELECT id FROM project_summaries WHERE mail_id = ?");
    $checkStmt->execute([$mail_id]);
    $existingId = $checkStmt->fetchColumn();

    if ($existingId) {
        $sql = "UPDATE project_summaries SET 
                    title = :title, 
                    term = :term, 
                    location = :loc, 
                    remote = :rem, 
                    reward = :rew, 
                    skills = :skl, 
                    summary_text = :txt, 
                    created = :created 
                WHERE mail_id = :mid";
    } else {
        $sql = "INSERT INTO project_summaries (mail_id, title, term, location, remote, reward, skills, summary_text, created) 
                VALUES (:mid, :title, :term, :loc, :rem, :rew, :skl, :txt, :created)";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':mid'     => $mail_id,
        ':title'   => mb_strimwidth($data['title'], 0, 255),
        ':term'    => $data['term'] ?? '要確認',
        ':loc'     => $data['location'] ?? '調査中',
        ':rem'     => $data['remote'] ?? '要確認',
        ':rew'     => $reward,
        ':skl'     => mb_strimwidth($data['skills'] ?? '', 0, 255),
        ':txt'     => $data['summary_text'] ?? '',
        ':created' => $mail['date']
    ]);

    // 5. 元データのフラグとタイトルを更新
    $pdo->prepare("UPDATE received_mails SET title = ?, analyze_flg = '1' WHERE id = ?")
        ->execute([$data['title'], $mail_id]);

    $pdo->commit();

    // projects.json を最新500件＆受信日ベースで同期 ---
    $json_output_path = dirname(__DIR__, 2) . '/member/projects.json';
    $stmt = $pdo->query("
        SELECT 
            ps.mail_id,
            ps.title,
            ps.reward,
            ps.location,
            ps.remote,
            ps.skills,
            ps.summary_text,
            rm.date AS created 
        FROM project_summaries ps
        JOIN received_mails rm ON ps.mail_id = rm.id
        WHERE rm.category = 1
        ORDER BY rm.date DESC
        LIMIT 500
    ");
    $all_projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    file_put_contents($json_output_path, json_encode($all_projects, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));    

    // 出力バッファをクリアして純粋なJSONのみを送信
    ob_end_clean();
    echo json_encode(['success' => true, 'message' => '解析が完了しました。']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (ob_get_level() > 0) ob_end_clean();
    
    echo json_encode([
        'success' => false, 
        'message' => 'システムエラー: ' . $e->getMessage()
    ]);
}