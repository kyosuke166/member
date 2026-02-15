<?php
session_start();
require_once 'db-config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit(json_encode(['error' => 'Unauthorized']));
}

try {
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("
        SELECT 
            email, last_name, first_name, last_name_kana, first_name_kana, gender,
            birthday, tel, nationality, location, role, job_category, experience, 
            reward, availability, work_status, portfolio, skills, bio 
        FROM members WHERE id = :id LIMIT 1
    ");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        exit(json_encode(['error' => 'User not found']));
    }

    $user['desired_rate'] = (int)($user['reward'] ?? 0);
    $user['github_url']   = $user['portfolio'] ?? '';

    echo json_encode($user, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}