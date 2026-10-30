<?php
/**
 * Resolve Item API Endpoint - فَقَدتُ ووَجَدتُ
 * Marks an item as resolved (الحمد لله تم الاستلام).
 * Purges private contact details and updates the live success counter.
 *
 * POST api/resolve.php
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
require_once __DIR__ . '/../../core/matcher.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'طريقة الطلب غير مسموحة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$clientIP = getClientIP();
// Owner codes are short, so attempts are limited tightly
if (!checkRateLimit($clientIP, 'resolve_item', 5, 1)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'طلبات متكررة، يرجى الانتظار قليلاً.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    $data = $_POST;
}

$itemId = isset($data['item_id']) ? (int)$data['item_id'] : 0;
if ($itemId <= 0) {
    echo json_encode(['success' => false, 'message' => 'معرّف البلاغ غير صالح.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = getDB();
$stmt = $db->prepare("SELECT id, category, location, location_area, status, owner_code_hash FROM items WHERE id = :id");
$stmt->bindValue(':id', $itemId, PDO::PARAM_INT);
$stmt->execute();
$item = $stmt->fetch();

if (!$item) {
    echo json_encode(['success' => false, 'message' => 'لم يتم العثور على هذا البلاغ.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($item['status'] === 'resolved') {
    echo json_encode([
        'success' => true,
        'message' => 'تم إنهاء هذا البلاغ مسبقاً والحمد لله.',
        'new_count' => getSuccessCount()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!verifyOwnerCode((string)($data['owner_code'] ?? ''), $item['owner_code_hash'] ?? null)) {
    logAction('resolve_denied', "Item ID {$itemId}: wrong or missing owner code");
    echo json_encode([
        'success' => false,
        'message' => 'رمز الإدارة غير صحيح. يستطيع صاحب البلاغ فقط إغلاقه باستخدام الرمز الذي ظهر له عند التسجيل.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$category = $item['category'] ?? 'أمانة عامة';
$location = $item['location_area'] ?: ($item['location'] ?: 'قطاع غزة');

$resolved = resolveItem($itemId, $category, $location);

if ($resolved) {
    $newCount = getSuccessCount();
    echo json_encode([
        'success' => true,
        'message' => 'الحمد لله! تم تأكيد الاستلام بنجاح، ومسح بيانات التواصل لحماية الخصوصية.',
        'new_count' => $newCount
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'تعذر تحديث حالة البلاغ، يرجى المحاولة لاحقاً.'
    ], JSON_UNESCAPED_UNICODE);
}
