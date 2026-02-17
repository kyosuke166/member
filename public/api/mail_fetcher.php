<?php
/**
 * メール同期エンジン (Fetcher版 - 文字コード強化モデル)
 * 役割: IMAPからメールを取得し、JIS/SJISをUTF-8に変換して保存
 */

$start_time = microtime(true);

set_time_limit(0);
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');

while (ob_get_level()) ob_end_flush();
ob_implicit_flush(true);

require_once __DIR__ . '/../db-config.php';

// 保存先ディレクトリの設定
$base_dir = dirname(__DIR__, 2); 
$storage_base = $base_dir . '/member/storage'; 
$emails_dir = $storage_base . '/emails';
$attachments_dir = $storage_base . '/attachments';

foreach ([$storage_base, $emails_dir, $attachments_dir] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        chmod($dir, 0755);
    }
}

/**
 * IMAPコマンド実行用
 */
function exec_imap_cmd($socket, $cmd) {
    fputs($socket, "A01 $cmd\r\n");
    $res = "";
    while ($line = fgets($socket)) {
        $res .= $line;
        if (strpos($line, "A01 OK") !== false || strpos($line, "A01 NO") !== false || strpos($line, "A01 BAD") !== false) break;
    }
    return $res;
}

/**
 * メールアドレス抽出用
 */
function extract_all_emails($string) {
    if (empty($string)) return "";
    $decoded = mb_decode_mimeheader($string);
    $cleaned = str_replace(["\r", "\n", "\t"], ' ', $decoded);
    $pattern = '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/';
    if (preg_match_all($pattern, $cleaned, $matches)) {
        return implode(', ', array_unique($matches[0]));
    }
    return ""; 
}

echo "<!DOCTYPE html><html><head><title>Mail Fetcher</title>";
echo "<style>
    body { background:#1a1a1a; color:#ccc; font-family:'Courier New', monospace; padding:20px; line-height:1.2; font-size:13px; }
    .row { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-bottom: 2px; }
    .col-id { color:#555; display:inline-block; width:50px; }
    .col-date { color:#666; display:inline-block; width:150px; }
    .col-from { display:inline-block; width:180px; font-weight:bold; color:#aaa; }
    .col-clip { color:#fff; display:inline-block; width:10px; text-align:center; font-size:16px; }
    .col-status { color:#00d4ff; font-weight:bold; margin-left:10px; }
    .separator { color:#444; margin: 0 10px; }
    .debug-path { color:yellow; font-size:10px; margin-bottom:10px; border-bottom:1px solid #333; padding-bottom:5px; }
    .result-box { color:#00ff00; font-family:sans-serif; margin-top:20px; padding:10px; border:1px solid #00ff00; background:rgba(0,255,0,0.1); }
</style></head><body>";

echo "<h2 style='color:#fff;'>>>> Salesメール取込エンジン (Fetcher Mode)</h2>";
echo "<div class='debug-path'>[Storage] " . htmlspecialchars($storage_base) . "</div>";

try {
    $pdo = get_db_connection();
    $socket = fsockopen('ssl://' . IMAP_HOST, 993, $errno, $errstr, 30);
    if (!$socket) throw new Exception("IMAP接続失敗");
    fgets($socket); 
    exec_imap_cmd($socket, "LOGIN " . IMAP_USER . " " . IMAP_PASS);
    exec_imap_cmd($socket, "SELECT INBOX");

    $search_res = exec_imap_cmd($socket, "SEARCH ALL");
    preg_match('/\* SEARCH (.+)/', $search_res, $m_search);
    $msg_numbers = isset($m_search[1]) ? explode(' ', trim($m_search[1])) : [];
    rsort($msg_numbers);

    $fetch_limit = 50; 
    $processed_count = 0;
    $new_mail_count = 0;

    foreach ($msg_numbers as $msg_id) {
        if ($processed_count >= $fetch_limit) break;

        fputs($socket, "A01 FETCH $msg_id (INTERNALDATE BODY.PEEK[])\r\n");
        $headers = []; $body_raw = ""; $in_header = true; $internal_date = ""; $first_line = true;

        while ($line = fgets($socket)) {
            if (strpos($line, "A01 OK") === 0) break;
            if ($first_line) {
                if (preg_match('/INTERNALDATE \"([^"]+)\"/i', $line, $m)) $internal_date = $m[1];
                if (preg_match('/\{\d+\}\r\n$/', $line)) { $first_line = false; continue; }
            }
            if ($in_header) {
                if (trim($line) === "" || $line === "\r\n") { $in_header = false; continue; }
                if (preg_match('/^[ \t]/', $line) && !empty($headers)) $headers[count($headers)-1] .= trim($line);
                else $headers[] = trim($line);
            } else { $body_raw .= $line; }
        }
        $body_raw = preg_replace('/\)\r\n$/', '', $body_raw);
        $h = []; foreach ($headers as $l) { $kv = explode(':', $l, 2); if (isset($kv[1])) $h[strtolower(trim($kv[0]))] = trim($kv[1]); }
        $message_id = isset($h['message-id']) ? trim($h['message-id'], '<>') : "id_{$msg_id}_" . time();

        // 既読チェック
        $stmt = $pdo->prepare("SELECT id FROM received_mails WHERE message_id = ?");
        $stmt->execute([$message_id]);
        if ($stmt->fetch()) { $processed_count++; continue; }

        $subject = isset($h['subject']) ? mb_decode_mimeheader($h['subject']) : "No Subject";
        $from = extract_all_emails($h['from'] ?? "");
        $to = extract_all_emails($h['to'] ?? "");
        $cc = extract_all_emails($h['cc'] ?? "");
        $date_ts = $internal_date ? strtotime($internal_date) : time();
        $date_str = date('Y-m-d H:i:s', $date_ts);
        $date_path = date('Y/m/d', $date_ts);

        // カテゴリ分け
        $category = 0; $row_color = "#888";
        if (preg_match('/案件|急募|募集|元請|エンド|増員|交代|限定|面談|大量|動き早い/u', $subject)) { $category = 1; $row_color = "#00d4ff"; }
        elseif (preg_match('/技術者|要員|人材|プロパー|個人|正社員|フリーランス|契約社員|稼働可能|スキルシート|BP/u', $subject)) { $category = 2; $row_color = "#ff85c0"; }

        // 添付ファイル解析
        $boundary = "";
        if (isset($h['content-type']) && preg_match('/boundary=\"?([^";\s]+)\"?/i', $h['content-type'], $m_b)) $boundary = $m_b[1];

        // --- 本文・添付ファイルの抽出 ---
        $body_text = ""; $attachment_info = [];
        $encoding = $h['content-transfer-encoding'] ?? '';

        if ($boundary) {
            $parts = explode("--" . $boundary, $body_raw);
            foreach ($parts as $part) {
                if (stripos($part, 'Content-Type: text/plain') !== false && empty($body_text)) {
                    $p_split = explode("\r\n\r\n", $part, 2);
                    $body_text = $p_split[1] ?? "";
                    $part_encoding = "";
                    if (preg_match('/Content-Transfer-Encoding:\s*([^\s\r\n]+)/i', $part, $m_enc)) $part_encoding = strtolower($m_enc[1]);
                    
                    if ($part_encoding === 'base64') $body_text = base64_decode($body_text);
                    elseif ($part_encoding === 'quoted-printable') $body_text = quoted_printable_decode($body_text);
                }
                if (preg_match('/filename=\"?([^";\s\r\n]+)\"?/i', $part, $m_fn)) {
                    $filename = mb_decode_mimeheader($m_fn[1]);
                    $p_split = explode("\r\n\r\n", $part, 2);
                    $data = isset($p_split[1]) ? trim($p_split[1]) : "";
                    $file_enc = "";
                    if (preg_match('/Content-Transfer-Encoding:\s*([^\s\r\n]+)/i', $part, $m_fenc)) $file_enc = strtolower($m_fenc[1]);
                    
                    if ($file_enc === 'base64') $data = base64_decode($data);
                    elseif ($file_enc === 'quoted-printable') $data = quoted_printable_decode($data);

                    if ($data) {
                        $hash = md5($data);
                        $save_sub = "$attachments_dir/$date_path";
                        if (!is_dir($save_sub)) mkdir($save_sub, 0755, true);
                        $final_name = $hash . "_" . $filename;
                        file_put_contents("$save_sub/$final_name", $data);
                        $attachment_info[] = ['name'=>$filename, 'path'=>"$date_path/$final_name", 'hash'=>$hash, 'size'=>strlen($data)];
                    }
                }
            }
        } else { 
            $body_text = $body_raw;
            if (strtolower($encoding) === 'base64') $body_text = base64_decode($body_text);
            elseif (strtolower($encoding) === 'quoted-printable') $body_text = quoted_printable_decode($body_text);
        }

        // --- 文字コード変換強化 ---
        if ($body_text !== "") {
            if (strpos($body_text, "\x1b") !== false) {
                $body_text = mb_convert_encoding($body_text, "UTF-8", "ISO-2022-JP-MS, ISO-2022-JP, JIS, EUC-JP, SJIS-win");
            } else {
                $detect_enc = mb_detect_encoding($body_text, ["UTF-8", "SJIS-win", "EUC-JP", "CP932", "JIS"], true);
                if ($detect_enc && $detect_enc !== "UTF-8") {
                    $body_text = mb_convert_encoding($body_text, "UTF-8", $detect_enc);
                }
            }
        }
        $body_text = trim($body_text);

        $email_sub = "$emails_dir/$date_path";
        if (!is_dir($email_sub)) mkdir($email_sub, 0755, true);
        $body_file = preg_replace('/[^a-zA-Z0-9]/', '', $message_id) . ".txt";
        file_put_contents("$email_sub/$body_file", $body_text);

        // DB登録 (titleカラムに$subjectを入れるように修正)
        $stmt = $pdo->prepare("INSERT INTO received_mails (message_id, date, title, from_address, to_address, cc_address, subject, category, raw_body_path, analyze_flg) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)");
        $stmt->execute([$message_id, $date_str, $subject, $from, $to, $cc, $subject, $category, "$date_path/$body_file"]);
        $mail_db_id = $pdo->lastInsertId();

        foreach ($attachment_info as $at) {
            $stmt = $pdo->prepare("INSERT INTO mail_attachments (mail_id, file_hash, file_name, file_path, file_size) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$mail_db_id, $at['hash'], $at['name'], $at['path'], $at['size']]);
        }

        echo "<div class='row' style='color:{$row_color};'>";
        echo "<span class='col-id'>[$msg_id]</span><span class='col-date'>$date_str</span><span class='separator'>|</span>";
        echo "<span class='col-from'>" . htmlspecialchars(mb_strimwidth($from, 0, 30, "...")) . "</span><span class='separator'>|</span>";
        echo "<span class='col-clip'>" . (!empty($attachment_info) ? "📎" : " ") . "</span><span class='separator'>|</span>";
        echo htmlspecialchars($subject);
        echo "<span class='col-status'>[保存完了]</span></div>";

        flush();
        $processed_count++; $new_mail_count++;
    }
    fclose($socket);

    $duration = round(microtime(true) - $start_time, 2);
    echo "<div class='result-box'><h3>>>> メール取込完了</h3>";
    echo "スキャン: {$processed_count}件 / 新規保存: {$new_mail_count}件 / 時間: {$duration}秒</div>";
} catch (Exception $e) { 
    echo "<br><span style='color:red;'>ERROR: " . htmlspecialchars($e->getMessage()) . "</span>"; 
}
echo "</body></html>";