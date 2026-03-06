<?php
/**
 * form-handler.php
 * 応募フォームの送信を受け取り、Chatworkに通知するバックエンドスクリプト
 *
 * 設定方法:
 * 1. CHATWORK_API_TOKEN に Chatwork の APIトークンを設定
 * 2. CHATWORK_ROOM_ID  に 通知先のルームIDを設定
 * 3. このファイルをWebサーバー（PHP対応ホスティング）にアップロード
 *
 * ⚠️ セキュリティ注意:
 * - APIトークンは環境変数または別ファイルで管理し、このファイルには直接書かないことを推奨します
 * - このファイルはGitにコミットする場合、トークンを必ず環境変数に移してください
 */

// ──────────────────────────────────────────
// 設定（環境変数が最優先、なければここの値を使用）
// ──────────────────────────────────────────
define('CHATWORK_API_TOKEN', getenv('CHATWORK_API_TOKEN') ?: 'YOUR_CHATWORK_API_TOKEN_HERE');
define('CHATWORK_ROOM_ID',   getenv('CHATWORK_ROOM_ID')   ?: 'YOUR_CHATWORK_ROOM_ID_HERE');

// ──────────────────────────────────────────
// CORS & セキュリティヘッダー
// ──────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// 開発中は同一オリジン・本番では適切なドメインに変更
$allowed_origins = [
    'https://soulsyncs.github.io',
    'http://localhost',
    'http://127.0.0.1',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}

// プリフライトリクエスト対応
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}

// POSTのみ受け付ける
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ──────────────────────────────────────────
// リクエスト読み込み & バリデーション
// ──────────────────────────────────────────
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// 必須フィールドの確認
$required = ['name', 'email', 'motivation'];
foreach ($required as $field) {
    if (empty($data[$field])) {
        http_response_code(400);
        echo json_encode(['error' => "Missing required field: {$field}"]);
        exit;
    }
}

// メールアドレスの形式確認
if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid email format']);
    exit;
}

// ──────────────────────────────────────────
// Chatworkメッセージ構築
// ──────────────────────────────────────────
function sanitize(string $value): string {
    return htmlspecialchars(strip_tags(trim($value)), ENT_QUOTES, 'UTF-8');
}

$name          = sanitize($data['name'] ?? '');
$name_kana     = sanitize($data['name_kana'] ?? '');
$age           = sanitize((string)($data['age'] ?? ''));
$gender        = sanitize($data['gender'] ?? '未回答');
$pref          = sanitize($data['pref'] ?? '');
$email         = sanitize($data['email'] ?? '');
$tel           = sanitize($data['tel'] ?? '');
$work_status   = sanitize($data['work_status'] ?? '');
$job_type      = sanitize($data['job_type'] ?? '');
$has_pc        = sanitize($data['has_pc'] ?? '');
$has_online    = sanitize($data['has_online'] ?? '');
$sns_exp       = sanitize($data['sns_exp'] ?? '');
$tools         = is_array($data['tools'] ?? null) ? implode('、', array_map('sanitize', $data['tools'])) : 'なし';
$event_exp     = sanitize($data['event_exp'] ?? '未回答');
$migration_exp = sanitize($data['migration_exp'] ?? '未回答');
$start_date    = sanitize($data['start_date'] ?? '');
$side_job      = sanitize($data['side_job'] ?? '未回答');
$motivation    = sanitize($data['motivation'] ?? '');
$ashikita      = sanitize($data['ashikita_passion'] ?? 'なし');
$free_note     = sanitize($data['free_note'] ?? 'なし');

$now = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y年m月d日 H:i');

$message = <<<MSG
[info][title]【御立岬PJ】新規応募が届きました[/title]
受付日時: {$now}

■ 基本情報
氏名: {$name}（{$name_kana}）
年齢: {$age}歳
性別: {$gender}
居住地: {$pref}
メール: {$email}
電話: {$tel}

■ 現在の状況
就業状況: {$work_status}
職種・業種: {$job_type}
PC所持: {$has_pc}
オンラインMTG環境: {$has_online}

■ 経験・スキル
SNS経験: {$sns_exp}
使えるツール: {$tools}
イベント経験: {$event_exp}
移住・地域おこし経験: {$migration_exp}

■ 志望動機
希望勤務開始: {$start_date}
副業・兼業: {$side_job}

志望動機:
{$motivation}

芦北への想い:
{$ashikita}

その他:
{$free_note}
[/info]
MSG;

// ──────────────────────────────────────────
// Chatwork APIに送信
// ──────────────────────────────────────────
function sendToChatwork(string $roomId, string $apiToken, string $message): array {
    $url = "https://api.chatwork.com/v2/rooms/{$roomId}/messages";
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['body' => $message]),
        CURLOPT_HTTPHEADER     => ["X-ChatWorkToken: {$apiToken}"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    return [
        'http_code' => $httpCode,
        'response'  => $response,
        'curl_error'=> $error,
    ];
}

$result = sendToChatwork(CHATWORK_ROOM_ID, CHATWORK_API_TOKEN, $message);

if ($result['curl_error']) {
    error_log('[form-handler] cURL error: ' . $result['curl_error']);
    http_response_code(500);
    echo json_encode(['error' => 'Failed to send notification']);
    exit;
}

if ($result['http_code'] < 200 || $result['http_code'] >= 300) {
    error_log('[form-handler] Chatwork API error: ' . $result['http_code'] . ' ' . $result['response']);
    http_response_code(500);
    echo json_encode(['error' => 'Chatwork API error', 'code' => $result['http_code']]);
    exit;
}

// ──────────────────────────────────────────
// 成功レスポンス
// ──────────────────────────────────────────
http_response_code(200);
echo json_encode(['success' => true, 'message' => '応募を受け付けました']);
