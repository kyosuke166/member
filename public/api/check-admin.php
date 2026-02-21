<?php
session_start();
require_once __DIR__ . '/../db-config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit(json_encode(['isAdmin' => false]));
}

$pdo = get_db_connection();

// 名前と前回ログイン日時を取得
$stmt = $pdo->prepare("SELECT admin, last_name, first_name, last_login FROM members WHERE id = :id");
$stmt->execute([':id' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || (int)$user['admin'] !== 1) {
    http_response_code(403);
    exit(json_encode(['isAdmin' => false]));
}

// 未解析（analyze_flg = 0）の件数を取得
$stmt_count = $pdo->query("SELECT COUNT(*) FROM received_mails WHERE analyze_flg = 0");
$pending_count = $stmt_count->fetchColumn();

echo json_encode([
    'isAdmin' => true,
    'userName' => $user['last_name'] . ' ' . $user['first_name'],
    'lastLogin' => $user['last_login'],
    'pendingCount' => (int)$pending_count // ★ これを返す
]);