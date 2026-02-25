<?php
require_once __DIR__ . '/../../db-config.php';

// db-config.phpで定義した PROJECTS_JSON_PATH を使用
if (defined('PROJECTS_JSON_PATH') && file_exists(PROJECTS_JSON_PATH)) {
    header('Content-Type: application/json');
    echo file_get_contents(PROJECTS_JSON_PATH);
} else {
    http_response_code(404);
    echo json_encode(['error' => 'File not found']);
}