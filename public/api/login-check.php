<?php
require_once 'db-config.php';
session_start(); // ログイン状態を保持するために必須

header('Content-Type: application/json');

$email = $_POST['email'] ?? '';
$password = $_POST['password'] ?? '';

if (empty($email) || empty($password)) {
    die(json_encode(['success' => false, 'message' => 'メールアドレスとパスワードを入力してください。']));
}

try {
    $pdo = get_db_connection();

    // 1. ユーザーを検索（本登録済み status=1 のユーザーのみ）
    $stmt = $pdo->prepare("SELECT id, email, password FROM members WHERE email = :email AND status = 1");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    // 2. パスワード照合
    if ($user && password_verify($password, $user['password'])) {
        // 認証成功：セッションにユーザー情報を保存
        session_regenerate_id(true); // セキュリティ対策：セッションIDを更新
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];

        // ダッシュボードへリダイレクト
        header('Location: /dashboard');
        exit;
    } else {
        // 認証失敗
        // セキュリティのため、メアド・パスワードどちらが間違っているかは明示しないのが定石
        header('Location: /?error=login_failed');
        exit;
    }

} catch (Exception $e) {
    die(json_encode(['success' => false, 'message' => 'システムエラーが発生しました。']));
}