<?php
// データベース接続情報
define('DB_HOST', 'mysql3113.db.sakura.ne.jp');
define('DB_USER', 'sbt-inc_member');
define('DB_PASS', 'kyosuke166');
define('DB_NAME', 'sbt-inc_member');

// SMTP設定情報
define('SMTP_HOST', 'sbt-inc.sakura.ne.jp');
define('SMTP_USER', 'info@sbt-inc.co.jp');
define('SMTP_PASS', 'Flowersf0rAlgernon');
define('SMTP_FROM', 'info@sbt-inc.co.jp');
define('SMTP_FROM_NAME', 'no-reply(SBTフリーランス)');

/**
 * PDO接続を返す共通関数
 */
function get_db_connection() {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        header('Content-Type: application/json', true, 500);
        echo json_encode(['success' => false, 'message' => 'DB接続エラーが発生しました。']);
        exit;
    }
}