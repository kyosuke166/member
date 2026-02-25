<?php
/**
 * 案件JSON生成スクリプト (get_projects.php)
 */

require_once __DIR__ . '/../../db-config.php';

// 保存先のパスを共通化
$save_path = PROJECTS_JSON_PATH;

try {
    $pdo = get_db_connection();

    // 修正ポイント：受信日(rm.date)をcreatedとして取得し、最新500件に絞る
    // 重複排除(GROUP BY)は一旦外し、シンプルに最新順にします
    $sql = "SELECT 
                ps.mail_id,
                ps.title,
                ps.reward,
                ps.location,
                ps.remote,
                ps.skills,
                ps.summary_text,
                ps.term,
                rm.date AS created 
            FROM project_summaries ps
            JOIN received_mails rm ON ps.mail_id = rm.id
            WHERE rm.category = 1
            ORDER BY rm.date DESC
            LIMIT 500";

    $stmt = $pdo->query($sql);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // データ整形
    foreach ($projects as &$p) {
        // 1. スキル文字列の配列化（Astro側が配列を期待している場合）
        $p['skills_array'] = !empty($p['skills']) ? explode(',', $p['skills']) : [];
        
        // 2. 数値型へのキャスト
        $p['reward'] = (int)$p['reward'];

        // 3. 作業期間の整形
        if (!empty($p['term'])) {
            $term = preg_replace('/^20\d{2}[年\/]/u', '', $p['term']);
            $p['term'] = preg_replace('/^0+(\d)/', '$1', $term);
        }
    }
    unset($p);

    $json_data = json_encode($projects, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
    if (file_put_contents($save_path, $json_data)) {
        echo "Successfully generated: " . count($projects) . " projects found.\n";
    } else {
        throw new Exception("Failed to write file.");
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}