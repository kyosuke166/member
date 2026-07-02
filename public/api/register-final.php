<?php
require_once __DIR__ . '/../../db-config.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

function show_error_page($message) {
    echo '<!DOCTYPE html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>登録エラー | SBTフリーランス</title>
        <style>
            body { background: #f8fafc; font-family: sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
            .card { background: white; padding: 40px; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); max-width: 420px; width: 90%; text-align: center; }
            h1 { font-size: 1.2rem; color: #1e293b; margin-bottom: 20px; }
            p { color: #64748b; line-height: 1.6; font-size: 0.95rem; margin-bottom: 25px; }
        </style>
    </head>
    <body>
        <div class="card">
            <h1>登録できませんでした</h1>
            <p>' . htmlspecialchars($message) . '</p>
        </div>
    </body>
    </html>';
    exit;
}

function verify_recaptcha($response, $action = '') {
    $secret = defined('RECAPTCHA_SECRET_KEY') ? RECAPTCHA_SECRET_KEY : '';
    if ($secret === '') {
        return true;
    }

    if (empty($response)) {
        return false;
    }

    $payload = http_build_query([
        'secret' => $secret,
        'response' => $response,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-type: application/x-www-form-urlencoded\r\n",
            'content' => $payload,
            'timeout' => 10,
        ],
    ]);

    $result = @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $context);
    if ($result === false) {
        return false;
    }

    $data = json_decode($result, true);
    if (empty($data['success'])) {
        return false;
    }

    if (!empty($data['score']) && (float)$data['score'] < 0.5) {
        return false;
    }

    if ($action !== '' && !empty($data['action']) && $data['action'] !== $action) {
        return false;
    }

    return true;
}

$email           = $_POST['email'] ?? '';
$key             = $_POST['key'] ?? '';
$password        = $_POST['password'] ?? '';
$last_name       = $_POST['last_name'] ?? '';
$first_name      = $_POST['first_name'] ?? '';
$last_name_kana  = $_POST['last_name_kana'] ?? '';
$first_name_kana = $_POST['first_name_kana'] ?? '';
$gender_code     = (int)($_POST['gender'] ?? 0);
$birthday        = $_POST['birthday'] ?? '';
$tel             = $_POST['tel'] ?? '';
$nationality     = $_POST['nationality'] ?? '';
$location        = $_POST['location'] ?? '';
$role            = $_POST['role'] ?? '';
$job_category    = $_POST['job_category'] ?? '';
$exp_y           = (int)($_POST['exp_y'] ?? 0);
$exp_m           = (int)($_POST['exp_m'] ?? 0);
$availability    = $_POST['availability'] ?? '';
$reward          = (int)($_POST['desired_rate'] ?? 0);
$work_status     = $_POST['work_status'] ?? '';
$portfolio       = $_POST['github_url'] ?? '';
$skills          = $_POST['skills'] ?? '';
$bio             = $_POST['bio'] ?? '';
$skillsheet_name = null;
$honeypot = trim($_POST['website'] ?? '');
$recaptcha_response = $_POST['g-recaptcha-response'] ?? '';
$recaptcha_action = $_POST['g-recaptcha-action'] ?? 'register_final';

if ($honeypot !== '') {
    show_error_page('送信内容に不備がありました。');
}

if (!verify_recaptcha($recaptcha_response, $recaptcha_action)) {
    show_error_page('reCAPTCHA認証に失敗しました。もう一度お試しください。');
}

if (empty($email) || empty($password) || empty($key)) {
    die(json_encode(['success' => false, 'message' => '入力不足です。']));
}

try {
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("SELECT id, created, status FROM members WHERE email = :email");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if (!$user || $user['status'] != 0) {
        die(json_encode(['success' => false, 'message' => '無効なリクエストです。']));
    }

    // --- ファイル名生成ロジック ---
    if (isset($_FILES['skill_sheet']) && $_FILES['skill_sheet']['error'] === UPLOAD_ERR_OK) {
        // 1. イニシャル (ササキ ユウヤ -> Y.S) ※カナの逆順
        $initial_f = mb_substr($first_name_kana, 0, 1);
        $initial_l = mb_substr($last_name_kana, 0, 1);
        $map = ['ア'=>'A','イ'=>'I','ウ'=>'U','エ'=>'E','オ'=>'O','カ'=>'K','キ'=>'K','ク'=>'K','ケ'=>'K','コ'=>'K','サ'=>'S','シ'=>'S','ス'=>'S','セ'=>'S','ソ'=>'S','タ'=>'T','チ'=>'T','ツ'=>'T','テ'=>'T','ト'=>'T','ナ'=>'N','ニ'=>'N','ヌ'=>'N','ネ'=>'N','ノ'=>'N','ハ'=>'H','ヒ'=>'H','フ'=>'F','ヘ'=>'H','ホ'=>'H','マ'=>'M','ミ'=>'M','ム'=>'M','メ'=>'M','モ'=>'M','ヤ'=>'Y','ユ'=>'Y','ヨ'=>'Y','ラ'=>'R','リ'=>'R','ル'=>'R','レ'=>'R','ロ'=>'R','ワ'=>'W','ガ'=>'G','ギ'=>'G','グ'=>'G','ゲ'=>'G','ゴ'=>'G','ザ'=>'Z','ジ'=>'Z','ズ'=>'Z','ゼ'=>'Z','ゾ'=>'Z','ダ'=>'D','ヂ'=>'D','ヅ'=>'D','デ'=>'D','ド'=>'D','バ'=>'B','ビ'=>'B','ブ'=>'B','ベ'=>'B','ボ'=>'B','パ'=>'P','ピ'=>'P','プ'=>'P','ペ'=>'P','ポ'=>'P'];
        $eng_f = $map[$initial_f] ?? 'X';
        $eng_l = $map[$initial_l] ?? 'X';
        $initials = $eng_f . "." . $eng_l;

        // 2. 年齢
        $age = date_diff(date_create($birthday), date_create('today'))->y;

        // 3. 性別
        $gender_text = ($gender_code === 1) ? '男性' : '女性';

        // 4. 駅名 (山手線 池袋駅 -> 池袋)
        preg_match('/線\s*(.*)駅/', $location, $m);
        $station = $m[1] ?? $location;

        // 5. 生年月日シリアル値 (Excel互換: 1899/12/30からの経過日数)
        $serial = (int)((strtotime($birthday) - strtotime('1899-12-30')) / 86400);

        $upload_dir = '../uploads/skillsheet/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        $file_ext = pathinfo($_FILES['skill_sheet']['name'], PATHINFO_EXTENSION);
        // フォーマット：Y.S(25歳、男性、池袋)_28644.xlsx
        $skillsheet_name = sprintf("%s(%d歳、%s、%s)_%d.%s", $initials, $age, $gender_text, $station, $serial, $file_ext);
        
        move_uploaded_file($_FILES['skill_sheet']['tmp_name'], $upload_dir . $skillsheet_name);
    }

    $total_months = ($exp_y * 12) + $exp_m;
    $experience_start = date('Y-m-01', strtotime("-{$total_months} month"));

    $sql = "UPDATE members SET 
                password = :password, last_name = :last_name, first_name = :first_name,
                last_name_kana = :last_name_kana, first_name_kana = :first_name_kana,
                gender = :gender, birthday = :birthday, tel = :tel, nationality = :nationality,
                location = :location, role = :role, job_category = :job_category,
                experience = :experience, availability = :availability, 
                reward = :reward, work_status = :work_status, portfolio = :portfolio, 
                skillsheet = :skillsheet, skills = :skills, bio = :bio,
                status = 1, updated = NOW()
            WHERE email = :email AND status = 0";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':password' => password_hash($password, PASSWORD_DEFAULT),
        ':last_name' => $last_name, ':first_name' => $first_name,
        ':last_name_kana' => $last_name_kana, ':first_name_kana' => $first_name_kana,
        ':gender' => $gender_code, ':birthday' => $birthday, ':tel' => $tel, ':nationality' => $nationality,
        ':location' => $location, ':role' => $role, ':job_category' => $job_category,
        ':experience' => $experience_start, ':availability' => $availability,
        ':reward' => $reward, ':work_status' => $work_status, ':portfolio' => $portfolio,
        ':skillsheet' => $skillsheet_name, ':skills' => $skills, ':bio' => $bio, ':email' => $email
    ]);

    header('Location: /register-complete');
    exit;

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}