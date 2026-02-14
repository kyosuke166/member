<?php
require_once 'db-config.php'; 

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require 'PHPMailer/Exception.php';
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

/**
 * ユーザー向けにデザインされたエラー画面を表示する関数
 */
function show_user_error($message) {
    echo '<!DOCTYPE html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>登録情報の確認 | SBTフリーランス</title>
        <style>
            body { background: #f8fafc; font-family: sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
            .card { background: white; padding: 40px; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); max-width: 400px; width: 90%; text-align: center; }
            h1 { font-size: 1.2rem; color: #1e293b; margin-bottom: 20px; }
            p { color: #64748b; line-height: 1.6; font-size: 0.95rem; margin-bottom: 25px; }
            .btn { display: block; background: #2563eb; color: white; padding: 12px; border-radius: 8px; text-decoration: none; font-weight: bold; transition: opacity 0.2s; }
            .btn:hover { opacity: 0.8; }
        </style>
    </head>
    <body>
        <div class="card">
            <h1>お知らせ</h1>
            <p>' . htmlspecialchars($message) . '</p>
            <a href="https://member.sbt-inc.co.jp/" class="btn">ログイン画面へ</a>
        </div>
    </body>
    </html>';
    exit;
}

$email = $_POST['email'] ?? '';

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    show_user_error('有効なメールアドレスを入力してください。');
}

try {
    $pdo = get_db_connection();

    // 1. 二重登録のチェック
    $stmt = $pdo->prepare("SELECT status FROM members WHERE email = :email");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if ($user && $user['status'] == 1) {
        show_user_error('このメールアドレスは既に本登録が完了しています。ログイン画面からログインしてください。');
    }

    // 2. 仮登録（または再送のための更新）
    $sql = "INSERT INTO members (email, status, created) VALUES (:email, 0, NOW()) 
            ON DUPLICATE KEY UPDATE created = NOW(), status = 0";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':email' => $email]);

    $stmt = $pdo->prepare("SELECT created FROM members WHERE email = :email");
    $stmt->execute([':email' => $email]);
    $created = $stmt->fetchColumn();

    $encoded_email = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($email));
    $key = hash('sha256', $email . $created);
    $register_url = "https://member.sbt-inc.co.jp/confirm?v=" . $encoded_email . "&key=" . $key;

    // 4. メール送信
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port       = 465;
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
    $mail->addAddress($email);
    $mail->isHTML(false);
    $mail->Subject = '【SBTフリーランス】メンバー本登録のお願い';
    $mail->Body    = "SBTフリーランスのメンバー仮登録ありがとうございます。\n\n以下のURLより、24時間以内に本登録を完了させてください。\n" . $register_url;

    $mail->send();

    header('Location: /register-sent');
    exit;

} catch (Exception $e) {
    show_user_error("エラーが発生しました。時間を置いて再度お試しください。");
}