<?php
/**
 * メール同期エンジン (Fetcher版 - シンプルテキスト出力版)
 */

$start_time = microtime(true);

set_time_limit(0);
date_default_timezone_set('Asia/Tokyo');

// ブラウザへリアルタイムに文字列として渡すため text/plain に変更
header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');

while (ob_get_level()) ob_end_flush();
ob_implicit_flush(true);

require_once __DIR__ . '/../../db-config.php';

// ブラウザ(GET)かコマンドライン引数から mode を取得
$mode = $_GET['mode'] ?? null;
// GETになければ、コマンドライン引数($argv)を探す
if (!$mode && isset($argv)) {
    foreach ($argv as $arg) {
        if (strpos($arg, 'mode=') === 0) {
            $mode = str_replace('mode=', '', $arg);
        }
    }
}
// 最終的に何もなければ manual にする
$mode = $mode ?: 'manual';

if ($mode === 'cron') {
    // cronの場合は直近3時間分だけに限定（漏れを防ぐため少し長めに取る）
    $start_period = date("Y-m-d H:i:s", strtotime("-3 hours"));
    $end_period   = date("Y-m-d H:i:s");
    $fetch_limit  = 1000; // cronなら500件もあれば十分
} else {
    // 手動実行（ブラウザからのアクセス）
    $target_msg_id = $_GET['msg_id'] ?? null; 
    $start_period  = $_GET['from'] ?? date("Y-m-d 00:00:00");
    $end_period    = $_GET['to']   ?? date("Y-m-d 23:59:59");
    $fetch_limit   = 3000; // サーバ負荷的には3000が推奨
    $fetch_limit   = 10000;
}


$base_dir = dirname(__DIR__, 2); 
$storage_base = $base_dir . '/member/storage'; 
$emails_dir = $storage_base . '/emails';
$attachments_dir = $storage_base . '/attachments';

try {
    $pdo = get_db_connection();
    $socket = fsockopen('ssl://' . IMAP_HOST, 993, $errno, $errstr, 30);
    if (!$socket) throw new Exception("IMAP接続失敗");

    fgets($socket); 
    exec_imap_cmd($socket, "LOGIN " . IMAP_USER . " " . IMAP_PASS);
    exec_imap_cmd($socket, "SELECT INBOX");

    if ($target_msg_id) {
        $msg_numbers = [$target_msg_id];
    } else {
        $ts_start = strtotime($start_period);
        $imap_since_date = date("d-M-Y", $ts_start);
        $search_res = exec_imap_cmd($socket, "SEARCH SINCE $imap_since_date");
        preg_match('/\* SEARCH (.+)/', $search_res, $m_search);
        $msg_numbers = isset($m_search[1]) ? explode(' ', trim($m_search[1])) : [];
        sort($msg_numbers); 
    }

    $processed_count = 0;
    $new_count = 0;
    $update_count = 0;

    foreach ($msg_numbers as $msg_id) {
        if (!$target_msg_id && $processed_count >= $fetch_limit) break;

        fputs($socket, "A01 FETCH $msg_id (INTERNALDATE)\r\n");
        $date_line = "";
        while($line = fgets($socket)){
            if(strpos($line, "A01 OK") === 0) break;
            if(stripos($line, "INTERNALDATE") !== false) $date_line = $line;
        }
        preg_match('/INTERNALDATE \"([^"]+)\"/i', $date_line, $m);
        $mail_ts = strtotime($m[1] ?? "now");
        $date_str = date('Y-m-d H:i:s', $mail_ts);
        $date_path = date('Y/m/d', $mail_ts);

        if (!$target_msg_id && ($mail_ts < strtotime($start_period) || $mail_ts > strtotime($end_period))) continue;

        $processed_count++;

        fputs($socket, "A01 FETCH $msg_id (BODY.PEEK[])\r\n");
        $headers_raw = ""; $body_raw = ""; $in_header = true; $first_line = true;
        stream_set_timeout($socket, 15);

        while (!feof($socket)) {
            $line = fgets($socket);
            if ($line === false || strpos($line, "A01 OK") === 0) break;
            if ($first_line && preg_match('/\{(\d+)\}\r\n$/', $line)) { $first_line = false; continue; }
            if ($in_header) {
                if (trim($line) === "") { $in_header = false; continue; }
                $headers_raw .= $line;
            } else { $body_raw .= $line; }
        }
        $body_raw = preg_replace('/\)\r\n$/', '', $body_raw);

        $h = []; $current_key = "";
        foreach (explode("\n", str_replace("\r", "", $headers_raw)) as $l) {
            if (preg_match('/^([a-zA-Z0-9-]+):(.+)/', $l, $m_h)) {
                $current_key = strtolower(trim($m_h[1]));
                $h[$current_key] = trim($m_h[2]);
            } elseif ($current_key !== "" && (strpos($l, " ") === 0 || strpos($l, "\t") === 0)) {
                $h[$current_key] .= " " . trim($l);
            }
        }

        $message_id = isset($h['message-id']) ? trim($h['message-id'], '<>') : "id_{$msg_id}_".time();
        $subject = isset($h['subject']) ? mb_decode_mimeheader($h['subject']) : "No Subject";
        $from = extract_all_emails($h['from'] ?? "");
        $to = extract_all_emails($h['to'] ?? "");
        $cc = extract_all_emails($h['cc'] ?? "");

        $boundary = "";
        if (isset($h['content-type']) && preg_match('/boundary=\"?([^";\s]+)\"?/i', $h['content-type'], $m_b)) $boundary = $m_b[1];
        
        $final_text = ""; $attachments = [];
        if ($boundary) {
            $parts = explode("--" . $boundary, $body_raw);
            foreach ($parts as $part) {
                $split = explode("\r\n\r\n", ltrim($part), 2);
                if (count($split) < 2) continue;
                $p_head = $split[0]; 
                $p_body = rtrim($split[1], "\r\n--");
                $p_enc = (preg_match('/Content-Transfer-Encoding:\s*([^\s\r\n]+)/i', $p_head, $m_e)) ? strtolower($m_e[1]) : "";
                $filename = (preg_match('/name=\"?([^";\s\r\n]+)\"?/i', $p_head, $m_n)) ? mb_decode_mimeheader($m_n[1]) : "";
                $decoded = ($p_enc === 'base64') ? base64_decode($p_body) : (($p_enc === 'quoted-printable') ? quoted_printable_decode($p_body) : $p_body);

                if (!empty($filename) || stripos($p_head, 'Content-Disposition: attachment') !== false) {
                    $attachments[] = ['name' => $filename ?: 'attached_file', 'data' => $decoded, 'mime' => (preg_match('/Content-Type:\s*([^;\s\r\n]+)/i', $p_head, $m_t)) ? $m_t[1] : 'application/octet-stream'];
                } else {
                    if (stripos($p_head, 'Content-Type: text/plain') !== false) $final_text = $decoded;
                    elseif (empty($final_text) && stripos($p_head, 'Content-Type: text/html') !== false) $final_text = strip_tags(preg_replace('/<(br|p|div|tr)[^>]*>/i', "\n", $decoded));
                }
            }
        } else {
            $enc = $h['content-transfer-encoding'] ?? '';
            $final_text = (strtolower($enc) === 'base64') ? base64_decode($body_raw) : $body_raw;
            if (isset($h['content-type']) && stripos($h['content-type'], 'html') !== false) $final_text = strip_tags(preg_replace('/<(br|p|div|tr)[^>]*>/i', "\n", $final_text));
        }

        $detect = mb_detect_encoding($final_text, ["UTF-8", "SJIS-win", "EUC-JP", "JIS", "ISO-2022-JP"], true);
        if ($detect && $detect !== "UTF-8") $final_text = mb_convert_encoding($final_text, "UTF-8", $detect);
        $final_text = trim($final_text);

        $category = 0; 
        if (preg_match('/技術者の|人材|要員情報|プロパー|稼働可能|正社員|氏名|【(技術者|要員|人材)情報】/u', $subject)) $category = 2;
        elseif (preg_match('/案件|急募|募集|展示|エンド直|元請け直|不可|面談|貴社|外国籍可/u', $subject)) $category = 1;
        if (!empty($attachments) || stripos($final_text, 'drive.google.com') !== false) $category = 2;

        $email_sub = "$emails_dir/$date_path";
        if (!is_dir($email_sub)) mkdir($email_sub, 0755, true);
        $body_file = preg_replace('/[^a-zA-Z0-9]/', '', $message_id) . ".txt";
        file_put_contents("$email_sub/$body_file", $final_text);

        $stmt = $pdo->prepare("SELECT id FROM received_mails WHERE message_id = ?");
        $stmt->execute([$message_id]);
        $mail_row = $stmt->fetch();

        if ($mail_row) {
            $mail_db_id = $mail_row['id'];
            $pdo->prepare("UPDATE received_mails SET category = ?, raw_body_path = ?, to_address = ?, cc_address = ?, analyze_flg = 0 WHERE id = ?")->execute([$category, "$date_path/$body_file", $to, $cc, $mail_db_id]);
            $update_count++;
        } else {
            $short_title = mb_strimwidth($subject, 0, 255, ""); 
            $stmt = $pdo->prepare("INSERT INTO received_mails (message_id, date, title, from_address, to_address, cc_address, subject, category, raw_body_path, analyze_flg) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)");
            $stmt->execute([$message_id, $date_str, $short_title, $from, $to, $cc, $subject, $category, "$date_path/$body_file"]);
            $mail_db_id = $pdo->lastInsertId();
            $new_count++;
        }

        foreach ($attachments as $at) {
            $f_hash = md5($at['data']);
            $f_name = $f_hash . "." . (pathinfo($at['name'], PATHINFO_EXTENSION) ?: 'dat');
            $f_sub  = "$attachments_dir/$date_path";
            if (!is_dir($f_sub)) mkdir($f_sub, 0755, true);
            file_put_contents("$f_sub/$f_name", $at['data']);
            $pdo->prepare("INSERT IGNORE INTO mail_attachments (mail_id, file_hash, file_name, file_path, file_size, mime_type) VALUES (?, ?, ?, ?, ?, ?)")->execute([$mail_db_id, $f_hash, $at['name'], "$date_path/$f_name", strlen($at['data']), $at['mime']]);
        }

        // 処理ログ出力
        $display_date = date('m/d H:i:s', $mail_ts);
        $clip_icon = !empty($attachments) ? "[📎]" : " 　 ";
        // カテゴリーを日本語ラベルに変換
        if ($category == 1) {
            $label = "[案件]";
        } elseif ($category == 2) {
            $label = "[技術者]";
        } else {
            $label = "[未分類]";
        }
        echo "{$label}[{$display_date}]{$clip_icon} " . $subject . "\n";
        flush();
    }

    $execution_time = round(microtime(true) - $start_time, 2);
    echo "\n" . str_repeat("=", 50) . "\n[DONE] 完了: $processed_count 件 (新規:$new_count 更新:$update_count) / {$execution_time}秒\n";

} catch (Exception $e) { 
    echo "[ERROR] " . $e->getMessage() . "\n"; 
}

function exec_imap_cmd($socket, $cmd) {
    fputs($socket, "A01 $cmd\r\n");
    $res = "";
    while ($line = fgets($socket)) { $res .= $line; if (strpos($line, "A01 OK") !== false) break; }
    return $res;
}

function extract_all_emails($string) {
    if (empty($string)) return "";
    $decoded_string = mb_decode_mimeheader($string);
    $pattern = '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/';
    if (preg_match_all($pattern, $decoded_string, $matches)) return implode(', ', array_unique($matches[0]));
    return ""; 
}