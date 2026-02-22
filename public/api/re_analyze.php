<?php
// api/re_analyze.php
require_once __DIR__ . '/../db-config.php';

$mail_id = $_GET['mail_id'] ?? null;

if (!$mail_id) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'メールIDが指定されていません。']);
    exit;
}

try {
    $pdo = get_db_connection();

    // 1. フラグを 0 に戻す
    $stmt = $pdo->prepare("UPDATE received_mails SET analyze_flg = '0' WHERE id = ?");
    $stmt->execute([$mail_id]);

    // 2. mail_analyzer.php を内部的に実行する
    $_GET['target_id'] = $mail_id;
    $_GET['limit'] = 1;

    // バッファリング開始
    ob_start();
    require __DIR__ . '/mail_analyzer.php';
    $output = ob_get_clean();

    // ★重要：mail_analyzer.php が出力した text/plain ヘッダーを JSON 用に上書きする
    header('Content-Type: application/json; charset=utf-8');

    // ログテキストが含まれているので、成功か失敗かを判定してクリーンなJSONを返す
    if (strpos($output, '[成功]') !== false || strpos($output, '[完了]') !== false) {
        echo json_encode([
            'success' => true, 
            'message' => '再解析が完了しました。',
            'debug_log' => $output // 念のためログも入れておく
        ]);
    } else {
        echo json_encode([
            'success' => false, 
            'message' => '解析プロセスは走りましたが、成功を確認できませんでした。',
            'debug_log' => $output
        ]);
    }

} catch (Exception $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '再解析中にエラーが発生しました: ' . $e->getMessage()]);
}