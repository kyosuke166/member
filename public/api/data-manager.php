<?php
/**
 * Data Manager API
 * 解析データの取得・更新・削除・元メール読み込みを担当
 */

require_once __DIR__ . '/../db-config.php';
header('Content-Type: application/json');

// JSON入力の取得
$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';
$mail_id = $data['mail_id'] ?? null;

// JSONの出力先パス
// ※環境に合わせて絶対パスでの指定を推奨します
$json_output_path = dirname(__DIR__, 2) . '/member/projects.json';

try {
    $pdo = get_db_connection();

    // --- 1. アクション別の処理 ---

    if ($action === 'list') {
        // ページネーション付き一覧取得
        $page = isset($data['page']) ? (int)$data['page'] : 1;
        $limit = 50;
        $offset = ($page - 1) * $limit;
        
        $stmt = $pdo->query("SELECT COUNT(*) FROM project_summaries");
        $total = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT * FROM project_summaries ORDER BY created DESC LIMIT ? OFFSET ?");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'data' => $list, 'total' => $total]);
        exit;
    }

    elseif ($action === 'get_original') {
        // 元メール本文の読み込み（文字化け対策込み）
        $stmt = $pdo->prepare("SELECT raw_body_path FROM received_mails WHERE id = ?");
        $stmt->execute([$mail_id]);
        $path = $stmt->fetchColumn();
        
        $full_path = dirname(__DIR__, 2) . '/member/storage/emails/' . $path;

        if (file_exists($full_path) && filesize($full_path) > 0) {
            $raw_content = file_get_contents($full_path);
            
            // 文字コードの自動判定とUTF-8変換
            $encoding = mb_detect_encoding($raw_content, "ASCII, JIS, UTF-8, CP932, EUC-JP, iso-2022-jp", true);
            $body = ($encoding) ? mb_convert_encoding($raw_content, 'UTF-8', $encoding) : $raw_content;
        } else {
            $body = "【警告】メール本文が空、またはファイルが見つかりません。\nPath: " . $path;
        }
        
        echo json_encode(['success' => true, 'body' => $body]);
        exit;
    }

    elseif ($action === 'reset') {
        // 再解析待ちに戻す
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM project_summaries WHERE mail_id = ?")->execute([$mail_id]);
        $pdo->prepare("UPDATE received_mails SET analyze_flg = 0 WHERE id = ?")->execute([$mail_id]);
        $pdo->commit();
    } 

    elseif ($action === 'delete') {
        // 除外処理（生データは残し、フラグを 9:除外済 に変更）
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM project_summaries WHERE mail_id = ?")->execute([$mail_id]);
        $pdo->prepare("UPDATE received_mails SET analyze_flg = 9 WHERE id = ?")->execute([$mail_id]);
        $pdo->commit();
    }

    elseif ($action === 'update') {
        // 編集内容の保存
        $sql = "UPDATE project_summaries SET title=?, reward=?, location=?, remote=?, skills=?, summary_text=? WHERE mail_id=?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['title'], $data['reward'], $data['location'], 
            $data['remote'], $data['skills'], $data['summary_text'], $mail_id
        ]);
    }

    // --- 2. データの変更があった場合、JSONファイルを同期 ---

    if (in_array($action, ['reset', 'delete', 'update'])) {
        $stmt = $pdo->query("SELECT * FROM project_summaries ORDER BY created DESC");
        $all_projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $json_data = json_encode($all_projects, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
        if (file_put_contents($json_output_path, $json_data) === false) {
            throw new Exception("JSONファイルの書き出しに失敗しました。パスを確認してください: " . $json_output_path);
        }
        
        echo json_encode(['success' => true]);
        exit;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}