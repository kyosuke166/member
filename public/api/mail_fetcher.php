<?php
/**
 * メール同期エンジン (Fetcher版 - カテゴリ判定強化モデル)
 */

set_time_limit(0);
date_default_timezone_set('Asia/Tokyo');

header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
while (ob_get_level()) ob_end_flush();
ob_implicit_flush(true);

require_once __DIR__ . '/../db-config.php';

$target_msg_id = $_GET['msg_id'] ?? null; 
$start_period  = $_GET['from'] ?? date("Y-m-d 00:00:00");
$end_period    = $_GET['to']   ?? date("Y-m-d 23:59:59");
$fetch_limit   = 3000;

$base_dir = dirname(__DIR__, 2); 
$storage_base = $base_dir . '/member/storage'; 
$emails_dir = $storage_base . '/emails';

try {
    $pdo = get_db_connection();
    $socket = fsockopen('ssl://' . IMAP_HOST, 993, $errno, $errstr, 30);
    if (!$socket) throw new Exception("IMAP接続失敗");

    fgets($socket); 
    exec_imap_cmd($socket, "LOGIN " . IMAP_USER . " " . IMAP_PASS);
    exec_imap_cmd($socket, "SELECT INBOX");

    if ($target_msg_id) {
        echo ">>> [MODE: RESCUE] IMAP_ID [$target_msg_id]\n";
        $msg_numbers = [$target_msg_id];
    } else {
        $ts_start = strtotime($start_period);
        $imap_since_date = date("d-M-Y", $ts_start);
        $search_res = exec_imap_cmd($socket, "SEARCH SINCE $imap_since_date");
        preg_match('/\* SEARCH (.+)/', $search_res, $m_search);
        $msg_numbers = isset($m_search[1]) ? explode(' ', trim($m_search[1])) : [];
        rsort($msg_numbers);
    }

    $processed_count = 0;
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

        if (!$target_msg_id) {
            if ($mail_ts < strtotime($start_period) || $mail_ts > strtotime($end_period)) continue;
        }

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
        if (strlen($body_raw) === 0) continue;

        $h = [];
        foreach (explode("\n", str_replace("\r", "", $headers_raw)) as $l) {
            if (preg_match('/^([a-zA-Z0-9-]+):(.+)/', $l, $m_h)) $h[strtolower(trim($m_h[1]))] = trim($m_h[2]);
        }
        $message_id = isset($h['message-id']) ? trim($h['message-id'], '<>') : "id_{$msg_id}_".time();
        $subject = isset($h['subject']) ? mb_decode_mimeheader($h['subject']) : "No Subject";

        // --- 本文抽出 ---
        $boundary = "";
        if (isset($h['content-type']) && preg_match('/boundary=\"?([^";\s]+)\"?/i', $h['content-type'], $m_b)) $boundary = $m_b[1];
        
        $final_text = "";
        if ($boundary) {
            $parts = explode("--" . $boundary, $body_raw);
            $extracted = [];
            foreach ($parts as $part) {
                $split = explode("\r\n\r\n", ltrim($part), 2);
                if (count($split) < 2) continue;
                $p_head = $split[0]; $p_body = $split[1];
                $p_enc = (preg_match('/Content-Transfer-Encoding:\s*([^\s\r\n]+)/i', $p_head, $m_e)) ? strtolower($m_e[1]) : "";
                $decoded = $p_body;
                if ($p_enc === 'base64') $decoded = base64_decode($decoded);
                elseif ($p_enc === 'quoted-printable') $decoded = quoted_printable_decode($decoded);

                if (stripos($p_head, 'Content-Type: text/plain') !== false) {
                    $extracted['plain'] = $decoded;
                } elseif (stripos($p_head, 'Content-Type: text/html') !== false) {
                    $extracted['html'] = strip_tags(preg_replace('/<(br|p|div|tr)[^>]*>/i', "\n", $decoded));
                }
            }
            $final_text = $extracted['plain'] ?? $extracted['html'] ?? "";
        }

        if (empty(trim($final_text))) {
            $enc = $h['content-transfer-encoding'] ?? '';
            $final_text = $body_raw;
            if (strtolower($enc) === 'base64') $final_text = base64_decode($final_text);
            if (isset($h['content-type']) && stripos($h['content-type'], 'html') !== false) {
                $final_text = strip_tags(preg_replace('/<(br|p|div|tr)[^>]*>/i', "\n", $final_text));
            }
        }

        $detect = mb_detect_encoding($final_text, ["UTF-8", "SJIS-win", "EUC-JP", "JIS", "ISO-2022-JP"], true);
        if ($detect && $detect !== "UTF-8") $final_text = mb_convert_encoding($final_text, "UTF-8", $detect);
        $final_text = trim($final_text);

        // --- カテゴリ判定強化ロジック ---
        // 1. デフォルト設定
        $category = 0; 

        // 2. キーワード判定 (技術者)
        if (preg_match('/技術者|要員|人材|プロパー|稼働可能/u', $subject . $final_text)) {
            $category = 2;
        } 
        // 3. キーワード判定 (案件) ※技術者フラグが立っていない場合
        elseif (preg_match('/案件|急募|募集|展示/u', $subject . $final_text)) {
            $category = 1;
        }

        // 4. 追加フィルター：添付ファイル or ドライブリンクがあれば「技術者」に倒す
        // Content-Disposition: attachment が rawデータに含まれているか
        // または Google Drive の共有リンクが含まれているか
        if (stripos($body_raw, 'Content-Disposition: attachment') !== false || 
            stripos($final_text, 'drive.google.com') !== false || 
            stripos($final_text, 'docs.google.com') !== false) {
            $category = 2; 
        }

        // 保存とDB
        $email_sub = "$emails_dir/$date_path";
        if (!is_dir($email_sub)) mkdir($email_sub, 0755, true);
        $body_file = preg_replace('/[^a-zA-Z0-9]/', '', $message_id) . ".txt";
        file_put_contents("$email_sub/$body_file", $final_text);

        $from = extract_all_emails($h['from'] ?? "");
        $stmt = $pdo->prepare("SELECT id FROM received_mails WHERE message_id = ?");
        $stmt->execute([$message_id]);
        $existing = $stmt->fetch();

        if ($existing) {
            $stmt = $pdo->prepare("UPDATE received_mails SET category = ?, raw_body_path = ?, analyze_flg = 0 WHERE id = ?");
            $stmt->execute([$category, "$date_path/$body_file", $existing['id']]);
            echo "[OK:更新] ID:{$existing['id']} (Cat:$category) | $subject\n";
        } else {
            $stmt = $pdo->prepare("INSERT INTO received_mails (message_id, date, title, from_address, subject, category, raw_body_path, analyze_flg) VALUES (?, ?, ?, ?, ?, ?, ?, 0)");
            $stmt->execute([$message_id, $date_str, $subject, $from, $subject, $category, "$date_path/$body_file"]);
            echo "[OK:新規] (Cat:$category) $subject\n";
        }
        flush();
    }
} catch (Exception $e) { echo "ERROR: " . $e->getMessage(); }

function exec_imap_cmd($socket, $cmd) {
    fputs($socket, "A01 $cmd\r\n");
    $res = "";
    while ($line = fgets($socket)) {
        $res .= $line;
        if (strpos($line, "A01 OK") !== false) break;
    }
    return $res;
}

function extract_all_emails($string) {
    if (empty($string)) return "";
    $pattern = '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/';
    if (preg_match_all($pattern, mb_decode_mimeheader($string), $matches)) return implode(', ', array_unique($matches[0]));
    return ""; 
}