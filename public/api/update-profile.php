<?php
session_start();
require_once __DIR__ . '/../../db-config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

$user_id = $_SESSION['user_id'];

try {
    $pdo = get_db_connection();
    
    // ファイル名生成に必要な情報をDBから取得
    $stmtUser = $pdo->prepare("SELECT last_name_kana, first_name_kana, gender, birthday FROM members WHERE id = :id");
    $stmtUser->execute([':id' => $user_id]);
    $u = $stmtUser->fetch();

    $skillsheet_name = null;
    if (isset($_FILES['skill_sheet']) && $_FILES['skill_sheet']['error'] === UPLOAD_ERR_OK) {
        // イニシャル生成
        $map = ['ア'=>'A','イ'=>'I','ウ'=>'U','エ'=>'E','オ'=>'O','カ'=>'K','キ'=>'K','ク'=>'K','ケ'=>'K','コ'=>'K','サ'=>'S','シ'=>'S','ス'=>'S','セ'=>'S','ソ'=>'S','タ'=>'T','チ'=>'T','ツ'=>'T','テ'=>'T','ト'=>'T','ナ'=>'N','ニ'=>'N','ヌ'=>'N','ネ'=>'N','ノ'=>'N','ハ'=>'H','ヒ'=>'H','フ'=>'F','ヘ'=>'H','ホ'=>'H','マ'=>'M','ミ'=>'M','ム'=>'M','メ'=>'M','モ'=>'M','ヤ'=>'Y','ユ'=>'Y','ヨ'=>'Y','ラ'=>'R','リ'=>'R','ル'=>'R','レ'=>'R','ロ'=>'R','ワ'=>'W','ガ'=>'G','ギ'=>'G','グ'=>'G','ゲ'=>'G','ゴ'=>'G','ザ'=>'Z','ジ'=>'Z','ズ'=>'Z','ゼ'=>'Z','ゾ'=>'Z','ダ'=>'D','ヂ'=>'D','ヅ'=>'D','デ'=>'D','ド'=>'D','バ'=>'B','ビ'=>'B','ブ'=>'B','ベ'=>'B','ボ'=>'B','パ'=>'P','ピ'=>'P','プ'=>'P','ペ'=>'P','ポ'=>'P'];
        $eng_f = $map[mb_substr($u['first_name_kana'], 0, 1)] ?? 'X';
        $eng_l = $map[mb_substr($u['last_name_kana'], 0, 1)] ?? 'X';
        $initials = $eng_f . "." . $eng_l;
        $age = date_diff(date_create($u['birthday']), date_create('today'))->y;
        $gender_text = ($u['gender'] == 1) ? '男性' : '女性';
        
        $loc = $_POST['location'] ?? '';
        preg_match('/線\s*(.*)駅/', $loc, $m);
        $station = $m[1] ?? $loc;
        $serial = (int)((strtotime($u['birthday']) - strtotime('1899-12-30')) / 86400);

        $upload_dir = '../uploads/skillsheet/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        
        $file_ext = pathinfo($_FILES['skill_sheet']['name'], PATHINFO_EXTENSION);
        $skillsheet_name = sprintf("%s(%d歳、%s、%s)_%d.%s", $initials, $age, $gender_text, $station, $serial, $file_ext);
        
        move_uploaded_file($_FILES['skill_sheet']['tmp_name'], $upload_dir . $skillsheet_name);
    }

    $sql = "UPDATE members SET 
                tel = :tel, location = :location, role = :role, job_category = :job_category,
                reward = :reward, availability = :availability, work_status = :work_status,
                portfolio = :portfolio, skills = :skills, bio = :bio, updated = NOW()";
    
    if ($skillsheet_name) $sql .= ", skillsheet = :skillsheet";
    $sql .= " WHERE id = :id";

    $params = [
        ':tel' => $_POST['tel'] ?? '',
        ':location' => $_POST['location'] ?? '',
        ':role' => $_POST['role'] ?? '',
        ':job_category' => $_POST['job_category'] ?? '',
        ':reward' => (int)($_POST['desired_rate'] ?? 0),
        ':availability' => $_POST['availability'] ?? '',
        ':work_status' => $_POST['work_status'] ?? '',
        ':portfolio' => $_POST['github_url'] ?? '',
        ':skills' => $_POST['skills'] ?? '',
        ':bio' => $_POST['bio'] ?? '',
        ':id' => $user_id
    ];
    if ($skillsheet_name) $params[':skillsheet'] = $skillsheet_name;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}