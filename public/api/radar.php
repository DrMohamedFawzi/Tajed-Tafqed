<?php
/**
 * Radar Subscription API Endpoint - فَقَدتُ ووَجَدتُ
 * Registers a user's phone for reverse-matching when a matching lost item is found later.
 *
 * POST api/radar.php
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
    echo json_encode(['success' => false, 'error' => 'طريقة الطلب غير مسموحة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$clientIP = getClientIP();
// Rate limit: 10 per hour
if (!checkRateLimit($clientIP, 'radar_sub', 10, 60)) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'error' => 'وصلت إلى الحد المسموح لحفظ الأرقام. حاول لاحقاً.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    $data = $_POST;
}

$identifier = trim($data['identifier'] ?? '');
$phone = trim($data['phone'] ?? '');
$altPhone = trim($data['alt_phone'] ?? '');
$name = trim($data['name'] ?? 'مواطن فاقد لوثيقة/غرض');

if ($identifier === '' || $phone === '') {
    echo json_encode([
        'success' => false,
        'error' => 'يرجى كتابة رقم الوثيقة ورقم جوالك.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Basic phone validation
if (!preg_match('/^[0-9+]{8,15}$/', preg_replace('/[\s\-]/', '', $phone))) {
    echo json_encode([
        'success' => false,
        'error' => 'رقم الجوال غير صالح. يرجى كتابة رقم هاتف صحيح.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$cleanedIdent = preg_replace('/[\s\-\.]/', '', $identifier);
$hash = hashIdentifier($cleanedIdent);

$db = getDB();

// Check if already subscribed
$checkStmt = $db->prepare("SELECT id FROM radar_subscribers WHERE target_identifier_hash = :hash AND subscriber_phone = :phone");
$checkStmt->bindValue(':hash', $hash);
$checkStmt->bindValue(':phone', $phone);
$checkStmt->execute();

if ($checkStmt->fetch()) {
    echo json_encode([
        'success' => true,
        'message' => 'رقمك مسجل مسبقاً لهذا البحث. عندما يسجّل أحد هذا الغرض سيظهر له رقمك ليتصل بك.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $newId = registerRadar($hash, sanitize($name), sanitize($phone), $altPhone ? sanitize($altPhone) : null);
    
    // Also check if an active item was registered in the database just now
    $existingMatches = searchByIdentifier($hash);
    $foundNow = false;
    foreach ($existingMatches as $em) {
        if ($em['type'] === 'found') {
            $foundNow = true;
            break;
        }
    }

    $msg = 'تم حفظ رقمك. عندما يسجّل أحد هذا الغرض سيظهر له رقمك ليتصل بك.';
    if ($foundNow) {
        $msg = 'بشرى سارة: يوجد غرض معثور عليه بهذا الرقم. ابحث عنه مرة أخرى في الصفحة الرئيسية.';
    }

    echo json_encode([
        'success' => true,
        'message' => $msg,
        'immediate_match' => $foundNow
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'تعذر حفظ رقمك، حاول مرة أخرى.'
    ], JSON_UNESCAPED_UNICODE);
}
