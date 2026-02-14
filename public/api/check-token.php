<?php
require_once 'db-config.php'; // 共通DB設定の読み込み

// エラー表示設定
ini_set('display_errors', 1);
error_reporting(E_ALL);

// JSONとして返す宣言
header('Content-Type: application/json');

// --- 1. パラメータ取得 ---
$v = $_GET['v'] ?? '';   // Base64エンコードされたメアド
$key = $_GET['key'] ?? ''; // URLに含まれるハッシュ値

if (!$v || !$key) {
    echo json_encode([
        'valid' => false, 
        'message' => 'パラメータが不足しています。',
        'debug' => ['v' => $v, 'key' => $key]
    ]);
    exit;
}

try {
    // --- 2. メアドの復元 (URLセーフBase64デコード) ---
    $base64 = str_replace(['-', '_'], ['+', '/'], $v);
    $padding = strlen($base64) % 4;
    if ($padding > 0) {
        $base64 .= str_repeat('=', 4 - $padding);
    }
    $email = base64_decode($base64);

    if (!$email) {
        echo json_encode(['valid' => false, 'message' => 'メアドの復号に失敗しました。']);
        exit;
    }

    // --- 3. 共通関数でDB接続 ---
    $pdo = get_db_connection();

    // --- 4. ユーザー検索 ---
    $stmt = $pdo->prepare("SELECT created, status FROM members WHERE email = :email");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        // --- 5. ハッシュの再計算 ---
        // 作成時と合わせるため、createdの形式を PHP側で 'Y-m-d H:i:s' に統一
        $db_created_str = date('Y-m-d H:i:s', strtotime($user['created']));
        $expected_key = hash('sha256', $email . $db_created_str);
        
        // 期限チェック（24時間）
        $created_time = strtotime($user['created']);
        $is_expired = (time() - $created_time) > (24 * 60 * 60);

        if ($key === $expected_key && !$is_expired && $user['status'] == 0) {
            // 全てOK
            echo json_encode([
                'valid' => true, 
                'email' => $email
            ]);
        } else {
            // 照合失敗時の詳細デバッグ情報
            echo json_encode([
                'valid' => false,
                'message' => '照合に失敗しました（ハッシュ不一致または期限切れ）',
                'debug' => [
                    'email_decoded' => $email,
                    'db_created_raw' => $user['created'],
                    'db_created_formatted' => $db_created_str,
                    'received_key' => $key,
                    'expected_key' => $expected_key,
                    'is_expired' => $is_expired,
                    'status' => $user['status']
                ]
            ]);
        }
    } else {
        echo json_encode([
            'valid' => false, 
            'message' => '該当するメールアドレスがDBに見つかりません。',
            'debug' => ['email_searched' => $email]
        ]);
    }

} catch (Exception $e) {
    echo json_encode([
        'valid' => false, 
        'message' => 'サーバーエラーが発生しました。',
        'error_detail' => $e->getMessage()
    ]);
}