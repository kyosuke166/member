<?php
require_once 'db-config.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

// --- データ受け取り ---
$email           = $_POST['email'] ?? '';
$key             = $_POST['key'] ?? '';
$password        = $_POST['password'] ?? '';
$last_name       = $_POST['last_name'] ?? '';
$first_name      = $_POST['first_name'] ?? '';
$last_name_kana  = $_POST['last_name_kana'] ?? '';
$first_name_kana = $_POST['first_name_kana'] ?? '';
$birthday        = $_POST['birthday'] ?? '';
$tel             = $_POST['tel'] ?? '';
$location        = $_POST['location'] ?? '';
$role            = $_POST['role'] ?? '';
$job_category    = $_POST['job_category'] ?? '';
$exp_y           = (int)($_POST['exp_y'] ?? 0);
$exp_m           = (int)($_POST['exp_m'] ?? 0);
$availability    = $_POST['availability'] ?? '';
$skills          = $_POST['skills'] ?? '';
$bio             = $_POST['bio'] ?? '';

// バリデーション
if (empty($email) || empty($password) || strlen($password) < 8 || empty($key)) {
    die(json_encode(['success' => false, 'message' => '入力が不正または不足しています。']));
}

try {
    $pdo = get_db_connection();

    // --- 1. セキュリティチェック (URLのkeyが正しいか再検証) ---
    $stmt = $pdo->prepare("SELECT created, status FROM members WHERE email = :email");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if (!$user || $user['status'] != 0) {
        die(json_encode(['success' => false, 'message' => '無効なリクエスト、または既に登録済みです。']));
    }

    // トークン作成時と同じロジックでキーを再現
    $db_created_str = date('Y-m-d H:i:s', strtotime($user['created']));
    $expected_key = hash('sha256', $email . $db_created_str);

    if ($key !== $expected_key) {
        die(json_encode(['success' => false, 'message' => '認証キーが一致しません。正しくないURLです。']));
    }

    // --- 2. データの加工 ---
    // 経験年数の逆算
    $total_months = ($exp_y * 12) + $exp_m;
    $experience_start = date('Y-m-01', strtotime("-{$total_months} month"));

    // パスワードハッシュ化
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // --- 3. DB更新 ---
    $sql = "UPDATE members SET 
                password = :password,
                last_name = :last_name,
                first_name = :first_name,
                last_name_kana = :last_name_kana,
                first_name_kana = :first_name_kana,
                birthday = :birthday,
                tel = :tel,
                location = :location,
                role = :role,
                job_category = :job_category,
                experience = :experience,
                availability = :availability,
                skills = :skills,
                bio = :bio,
                status = 1,
                updated = NOW()
            WHERE email = :email AND status = 0";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':password'        => $hashed_password,
        ':last_name'       => $last_name,
        ':first_name'      => $first_name,
        ':last_name_kana'  => $last_name_kana,
        ':first_name_kana' => $first_name_kana,
        ':birthday'        => $birthday,
        ':tel'             => $tel,
        ':location'        => $location,
        ':role'            => $role,
        ':job_category'    => $job_category,
        ':experience'      => $experience_start,
        ':availability'    => $availability,
        ':skills'          => $skills,
        ':bio'             => $bio,
        ':email'           => $email
    ]);

    if ($stmt->rowCount() > 0) {
        // 成功：リダイレクト
        header('Location: /register-complete');
        exit;
    } else {
        echo json_encode(['success' => false, 'message' => '登録処理に失敗しました。']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'システムエラー: ' . $e->getMessage()]);
}