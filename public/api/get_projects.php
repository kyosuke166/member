<?php
/**
 * 案件JSON生成スクリプト (get_projects.php)
 * cron等で定期実行し、静的なJSONファイルを生成する
 */

require_once __DIR__ . '/../db-config.php';

// 保存先のパス
$save_path = __DIR__ . '/../projects.json';

try {
    $pdo = get_db_connection();

    $sql = "SELECT * FROM project_summaries 
            WHERE id IN (
                SELECT MAX(id) 
                FROM project_summaries 
                WHERE created >= DATE_SUB(NOW(), INTERVAL 1 MONTH)
                GROUP BY title, location, reward
            )
            ORDER BY created DESC";

    $stmt = $pdo->query($sql);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // データ整形
    foreach ($projects as &$p) {
        // 1. スキル文字列の配列化
        $p['skills'] = !empty($p['skills']) ? explode(',', $p['skills']) : [];
        
        // 2. 数値型へのキャスト
        $p['reward'] = (int)$p['reward'];

        // 3. 作業期間の整形（西暦削除 ＆ 頭の0を削除）
        if (!empty($p['term'])) {
            // 「2026年」や「2026/」を削除
            $term = preg_replace('/^20\d{2}[年\/]/u', '', $p['term']);
            // 「04月」などの先頭の0を削除（12月などはそのまま）
            $p['term'] = preg_replace('/^0+(\d)/', '$1', $term);
        }
    }
    unset($p);

    $json_data = json_encode($projects, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
    if (!is_dir(dirname($save_path))) {
        mkdir(dirname($save_path), 0755, true);
    }

    if (file_put_contents($save_path, $json_data)) {
        echo "Successfully generated: " . count($projects) . " projects found.\n";
    } else {
        throw new Exception("Failed to write file.");
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}