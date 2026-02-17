<?php
session_start();
require_once __DIR__ . '/../db-config.php';
header('Content-Type: application/json');

if (isset($_SESSION['user_id'])) {
    $pdo = get_db_connection();
    // work_status（就業状況）や available_date（稼働可能日）などを取得
    $stmt = $pdo->prepare("SELECT last_name, first_name, work_status FROM members WHERE id = :id");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    echo json_encode([
        'isLoggedIn' => true, 
        'userName' => ($user['last_name'] . ' ' . $user['first_name']),
        'workStatus' => $user['work_status'] ?? 'searching' // デフォルトは「案件探し中」
    ]);
} else {
    echo json_encode(['isLoggedIn' => false]);
}