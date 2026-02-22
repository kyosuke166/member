<?php
/**
 * Data Manager API
 * 物理削除抑制・ON DUPLICATE KEY対応版
 */

require_once __DIR__ . '/../db-config.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';
$mail_id = $data['mail_id'] ?? null;
$json_output_path = dirname(__DIR__, 2) . '/member/projects.json';

try {
    $pdo = get_db_connection();

    if ($action === 'list') {
        $page = isset($data['page']) ? (int)$data['page'] : 1;
        $limit = 50;
        $offset = ($page - 1) * $limit;
        
        $stmt = $pdo->query("SELECT COUNT(*) FROM project_summaries");
        $total = $stmt->fetchColumn();

        // categoryを取得するためにreceived_mailsとJOIN
        $sql = "SELECT ps.*, rm.category, rm.subject, rm.date AS mail_date 
                FROM project_summaries ps
                JOIN received_mails rm ON ps.mail_id = rm.id
                ORDER BY rm.date DESC LIMIT ? OFFSET ?";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'data' => $list, 'total' => $total]);
        exit;
    }

    elseif ($action === 'get_original') {
        $stmt = $pdo->prepare("SELECT raw_body_path FROM received_mails WHERE id = ?");
        $stmt->execute([$mail_id]);
        $path = $stmt->fetchColumn();
        $full_path = dirname(__DIR__, 2) . '/member/storage/emails/' . $path;

        if (file_exists($full_path) && filesize($full_path) > 0) {
            $raw_content = file_get_contents($full_path);
            $encoding = mb_detect_encoding($raw_content, "ASCII, JIS, UTF-8, CP932, EUC-JP, iso-2022-jp", true);
            $body = ($encoding) ? mb_convert_encoding($raw_content, 'UTF-8', $encoding) : $raw_content;
        } else {
            $body = "【警告】ファイル不在: " . $path;
        }
        echo json_encode(['success' => true, 'body' => $body]);
        exit;
    }

    elseif ($action === 'reset') {
        // ★修正ポイント: 物理削除せず、解析フラグのみ戻す。
        // これにより、再解析時に ON DUPLICATE KEY UPDATE が走り、同じ ID で更新される。
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE received_mails SET analyze_flg = 0 WHERE id = ?")->execute([$mail_id]);
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    } 

    elseif ($action === 'delete') {
        // 削除（非表示）は完全に消す運用を維持
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM project_summaries WHERE mail_id = ?")->execute([$mail_id]);
            $pdo->prepare("UPDATE received_mails SET analyze_flg = 9 WHERE id = ?")->execute([$mail_id]);
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    elseif ($action === 'update') {
        $pdo->beginTransaction();
        try {
            // 1. project_summariesの更新
            $sql = "UPDATE project_summaries SET title=?, reward=?, location=?, remote=?, skills=?, summary_text=? WHERE mail_id=?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $data['title'], $data['reward'], $data['location'], 
                $data['remote'], $data['skills'], $data['summary_text'], $mail_id
            ]);

            // 2. received_mailsのcategoryを更新 (もし送られてきていれば)
            if (isset($data['category'])) {
                $stmt_cat = $pdo->prepare("UPDATE received_mails SET category = ? WHERE id = ?");
                $stmt_cat->execute([(int)$data['category'], $mail_id]);
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // --- データの変更があった場合、JSONファイルを同期 (案件のみ抽出) ---
    if (in_array($action, ['reset', 'delete', 'update'])) {
        $stmt = $pdo->query("
            SELECT ps.* FROM project_summaries ps
            JOIN received_mails rm ON ps.mail_id = rm.id
            WHERE rm.category = 1
            ORDER BY ps.created DESC
        ");
        $all_projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        file_put_contents($json_output_path, json_encode($all_projects, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        
        echo json_encode(['success' => true]);
        exit;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}