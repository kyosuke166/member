<?php
session_start();
require_once 'db-config.php';
header('Content-Type: application/json');

if (isset($_SESSION['user_id'])) {
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("SELECT last_name, first_name FROM members WHERE id = :id");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    echo json_encode([
        'isLoggedIn' => true, 
        'userName' => ($user['last_name'] . ' ' . $user['first_name'])
    ]);
} else {
    echo json_encode(['isLoggedIn' => false]);
}