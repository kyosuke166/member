<?php
// api/skip_analyzer.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../db-config.php'; 

try {
    // 関数を呼び出してPDOオブジェクトを取得する
    $pdo = get_db_connection();

    set_time_limit(180); 
    $pdo->beginTransaction();

    // 1. 未解析データを project_summaries にコピー（IGNOREで重複回避）
    $sqlInsert = "
        INSERT IGNORE INTO project_summaries (mail_id, title, created)
        SELECT id, subject, NOW()
        FROM received_mails
        WHERE analyze_flg = '0'
    ";
    $stmtInsert = $pdo->prepare($sqlInsert);
    $stmtInsert->execute();
    $insertedCount = $stmtInsert->rowCount();

    // 2. 解析フラグを更新
    $sqlUpdate = "UPDATE received_mails SET analyze_flg = '1' WHERE analyze_flg = '0'";
    $pdo->exec($sqlUpdate);

    $pdo->commit();
    echo json_encode([
        'success' => true, 
        'message' => "{$insertedCount} 件をスキップ登録しました。"
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}