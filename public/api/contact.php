<?php
/**
 * فَقَدتُ ووَجَدتُ - Reveal Contact Number API
 *
 * Phone numbers are never printed in public lists. A visitor asks for one
 * report's number explicitly; requests are rate-limited and logged.
 * Reports protected by a secret question must go through verify.php instead.
 *
 * POST api/contact.php  {"item_id": 12}
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/security.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'طريقة الطلب غير مسموحة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$clientIP = getClientIP();
// 15 numbers per hour per visitor
if (!checkRateLimit($clientIP, 'contact_reveal', 15, 60)) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'message' => 'وصلت إلى الحد المسموح لعرض الأرقام خلال ساعة. حاول لاحقاً.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = $_POST;
}

$itemId = isset($data['item_id']) ? (int)$data['item_id'] : 0;
if ($itemId <= 0) {
    echo json_encode(['success' => false, 'message' => 'رقم البلاغ غير صالح.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = getDB();
$stmt = $db->prepare("SELECT id, secret_answer_hash, contact_info, contact_alt, drop_point, status FROM items WHERE id = :id");
$stmt->bindValue(':id', $itemId, PDO::PARAM_INT);
$stmt->execute();
$item = $stmt->fetch();

if (!$item || $item['status'] !== 'active') {
    echo json_encode(['success' => false, 'message' => 'هذا البلاغ غير موجود أو تم إغلاقه.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!empty($item['secret_answer_hash'])) {
    echo json_encode([
        'success' => false,
        'needs_verification' => true,
        'message' => 'هذا البلاغ محمي بسؤال تحقق. أجب عن السؤال ليظهر الرقم.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($item['contact_info'])) {
    echo json_encode([
        'success' => false,
        'message' => 'لا يوجد رقم جوال لهذا البلاغ' . (!empty($item['drop_point']) ? '، يمكن استلامه من نقطة التسليم المذكورة.' : '.')
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

logAction('contact_reveal', "Item ID: {$itemId}");

echo json_encode([
    'success' => true,
    'contact_info' => $item['contact_info'],
    'contact_alt' => $item['contact_alt'] ?: '',
    'drop_point' => $item['drop_point'] ?: ''
], JSON_UNESCAPED_UNICODE);
