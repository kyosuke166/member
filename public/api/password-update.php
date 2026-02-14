<?php
require_once 'db-config.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

// --- データ受け取り ---
$v        = $_POST['v'] ?? '';        // Base64エンコードされたメールアドレス
$t        = $_POST['t'] ?? '';        // トークン（今回は簡易的なハッシュ）
$password = $_POST['password'] ?? '';

// バリデーション
if (empty($v) || empty($password) || strlen($password) < 8) {
    die(json_encode(['success' => false, 'message' => '入力が不正です。8文字以上のパスワードを設定してください。']));
}

// メールアドレスをデコード
$email = base64_decode($v);

try {
    $pdo = get_db_connection();

    // 1. ユーザーが存在するか、かつ本登録済みか確認
    $stmt = $pdo->prepare("SELECT id FROM members WHERE email = :email AND status = 1");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if (!$user) {
        die(json_encode(['success' => false, 'message' => 'このユーザーは存在しないか、本登録が完了していません。']));
    }

    // 2. パスワードをハッシュ化
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // 3. パスワードの更新
    $sql = "UPDATE members SET 
                password = :password,
                updated = NOW()
            WHERE email = :email AND status = 1";

    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute([
        ':password' => $hashed_password,
        ':email'    => $email
    ]);

    if ($result) {
        // 成功：ログイン画面（ルート）へリダイレクト
        // 本来は「変更完了画面」へ飛ばすのが丁寧ですが、直接ログインへ戻します
        header('Location: /?reset=success');
        exit;
    } else {
        echo json_encode(['success' => false, 'message' => 'パスワードの更新に失敗しました。']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'システムエラー: ' . $e->getMessage()]);
}