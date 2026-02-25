<?php
session_start();
require_once __DIR__ . '/../../db-config.php';
header('Content-Type: application/json');

if (isset($_SESSION['user_id'])) {
    $pdo = get_db_connection();
    
    // last_loginを追加で取得
    $stmt = $pdo->prepare("SELECT last_name, first_name, work_status, skills, last_login FROM members WHERE id = :id");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    // スキル文字列を配列に変換（元のロジックを維持）
    $userSkills = [];
    if (!empty($user['skills'])) {
        $rawSkills = str_replace('、', ',', $user['skills']);
        $userSkills = array_map('trim', explode(',', $rawSkills));
    }

    echo json_encode([
        'isLoggedIn' => true, 
        'userName' => ($user['last_name'] . ' ' . $user['first_name']),
        'workStatus' => $user['work_status'] ?? 'searching',
        'userSkills' => $userSkills,
        'lastLogin' => $user['last_login'] // これを新規追加
    ]);
} else {
    echo json_encode(['isLoggedIn' => false]);
}