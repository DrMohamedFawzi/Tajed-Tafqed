<?php
/**
 * Secret Question Verification API Endpoint - فَقَدتُ ووَجَدتُ
 * Verifies the secret answer before revealing finder's contact information.
 *
 * POST api/verify.php
 * Accepts JSON or application/x-www-form-urlencoded
 */

if (!ob_start('ob_gzhandler')) {
    ob_start();
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/security.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'match' => false, 'message' => 'طريقة الطلب غير مسموحة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$clientIP = getClientIP();
// Strict rate limit: 5 attempts per minute
if (!checkRateLimit($clientIP, 'verify', 5, 1)) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'match' => false,
        'message' => 'تم استنفاد محاولات التحقق المسموحة (5 محاولات في الدقيقة). يرجى الانتظار.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Support both JSON body and Form POST
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    $data = $_POST;
}

$itemId = isset($data['item_id']) ? (int)$data['item_id'] : 0;
$answer = trim($data['answer'] ?? '');

if ($itemId <= 0 || $answer === '') {
    echo json_encode([
        'success' => false,
        'match' => false,
        'message' => 'يرجى إدخال إجابة التحقق المطلوبة.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = getDB();
$stmt = $db->prepare("SELECT id, title_or_name, secret_question, secret_answer_hash, contact_info, contact_alt, drop_point, status FROM items WHERE id = :id");
$stmt->bindValue(':id', $itemId, PDO::PARAM_INT);
$stmt->execute();
$item = $stmt->fetch();

if (!$item || $item['status'] !== 'active') {
    echo json_encode([
        'success' => false,
        'match' => false,
        'message' => 'البلاغ غير موجود أو تم إغلاقه سابقاً.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($item['secret_answer_hash'])) {
    // No secret question: numbers are revealed through api/contact.php (rate-limited)
    echo json_encode([
        'success' => false,
        'match' => false,
        'message' => 'هذا البلاغ لا يحتاج سؤال تحقق. استخدم زر «إظهار رقم الجوال».'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Normalize answer for fair comparison (handle Arabic variations)
$normalizedInput = normalizeArabic($answer);

// Verify with bcrypt hash
$isCorrect = verifyAnswer($answer, $item['secret_answer_hash']) || verifyAnswer($normalizedInput, $item['secret_answer_hash']);

if ($isCorrect) {
    logAction('verify_success', "Item ID: {$itemId} verified by IP {$clientIP}");
    echo json_encode([
        'success' => true,
        'match' => true,
        'message' => 'إجابة صحيحة! تم تأكيد الملكية بنجاح.',
        'contact_info' => $item['contact_info'] ?: 'غير متوفر',
        'contact_alt' => $item['contact_alt'] ?: '',
        'drop_point' => $item['drop_point'] ?: ''
    ], JSON_UNESCAPED_UNICODE);
} else {
    logAction('verify_fail', "Item ID: {$itemId} failed attempt from IP {$clientIP}");
    echo json_encode([
        'success' => false,
        'match' => false,
        'message' => 'الإجابة غير صحيحة، يرجى التأكد من الإجابة والمحاولة مجدداً.'
    ], JSON_UNESCAPED_UNICODE);
}
