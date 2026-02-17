<?php
/**
 * mail_view.php
 * 保存されたメール本文をそのまま表示するデバッグツール
 */
require_once __DIR__ . '/../db-config.php';

$id = $_GET['id'] ?? null;
if (!$id) die("ID指定がありません");

try {
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("SELECT raw_body_path FROM received_mails WHERE id = ?");
    $stmt->execute([$id]);
    $mail = $stmt->fetch();

    if (!$mail) die("メールが見つかりません");

    $storage_base = dirname(__DIR__, 2) . '/member/storage/emails';
    $file_path = $storage_base . '/' . $mail['raw_body_path'];

    if (!file_exists($file_path)) die("ファイルが存在しません: " . $file_path);

    $content = file_get_contents($file_path);

    echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Mail View</title>";
    echo "<style>body{background:#eee; padding:20px; font-family:monospace; white-space:pre-wrap; word-wrap:break-word; line-height:1.5;}</style></head><body>";
    echo "<h3>Raw Mail Content (ID: $id)</h3><hr>";
    echo htmlspecialchars($content);
    echo "</body></html>";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}