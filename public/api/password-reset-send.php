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

$email = $_POST['email'] ?? '';

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    // メアドが不正ならリセット画面に戻すかエラー表示
    header('Location: /password-reset?error=invalid');
    exit;
}

try {
    $pdo = get_db_connection();

    // 1. 本登録済みのユーザーかチェック
    $stmt = $pdo->prepare("SELECT id, created FROM members WHERE email = :email AND status = 1");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    // セキュリティ上の理由で、ユーザーがいない場合も「送信しました」画面へ飛ばす
    // (攻撃者にメアドの登録有無を知られないため)
    if ($user) {
        // 2. 再設定用URLの生成
        // メールアドレスと作成日時を混ぜたハッシュをトークンにする（仮登録のkeyロジックを流用）
        $v = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($email));
        $token = hash('sha256', $email . $user['created'] . "reset"); // "reset"を足して仮登録用と区別
        
        $reset_url = "https://member.sbt-inc.co.jp/password-reentry?v=" . $v . "&t=" . $token;

        // 3. メール送信
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
        $mail->Subject = '【SBTフリーランス】パスワード再設定URLのお知らせ';
        $mail->Body    = "SBTフリーランスをご利用いただきありがとうございます。\n\n以下のURLより、新しいパスワードの設定を行ってください。\n\n" . $reset_url . "\n\n※このURLの有効期限は24時間です。\n※心当たりがない場合は、このメールを破棄してください。";

        $mail->send();
    }

    // ユーザーがいてもいなくても「送信完了ページ」へ
    header('Location: /password-reset-sent');
    exit;

} catch (Exception $e) {
    // 本番環境では詳細なエラーは出さずログに吐くのが良いですが、デバッグ用にメッセージ表示
    die("メール送信中にエラーが発生しました。: {$mail->ErrorInfo}");
}