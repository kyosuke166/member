<?php
session_start();
require_once __DIR__ . '/../db-config.php';
header('Content-Type: application/json');

if (isset($_SESSION['user_id'])) {
    $pdo = get_db_connection();
    
    // skillsカラムを追加で取得
    $stmt = $pdo->prepare("SELECT last_name, first_name, work_status, skills FROM members WHERE id = :id");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    // スキル文字列を配列に変換（空の場合は空配列を返す）
    $userSkills = [];
    if (!empty($user['skills'])) {
        // 全角カンマが含まれる可能性を考慮して置換してから分割
        $rawSkills = str_replace('、', ',', $user['skills']);
        $userSkills = array_map('trim', explode(',', $rawSkills));
    }

    echo json_encode([
        'isLoggedIn' => true, 
        'userName' => ($user['last_name'] . ' ' . $user['first_name']),
        'workStatus' => $user['work_status'] ?? 'searching',
        'userSkills' => $userSkills // これでフロントエンドでマッチング計算が可能になります！
    ]);
} else {
    echo json_encode(['isLoggedIn' => false]);
}