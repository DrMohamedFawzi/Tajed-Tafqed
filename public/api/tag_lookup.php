<?php
/**
 * Safety Tag Lookup API Endpoint - فَقَدتُ ووَجَدتُ
 * Looks up a preventive safety tag (GZ-XXXX) to display owner contact info.
 *
 * GET api/tag_lookup.php?code=GZ-XXXX
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

$clientIP = getClientIP();
if (!checkRateLimit($clientIP, 'tag_lookup', 30, 1)) {
    http_response_code(429);
    echo json_encode(['found' => false, 'error' => 'تم تجاوز حد المحاولات المسموح بها.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$code = trim($_GET['code'] ?? '');
if ($code === '') {
    echo json_encode(['found' => false, 'error' => 'يرجى إدخال كود الأمانة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Normalize code
$code = strtoupper($code);
$tag = searchByTagCode($code);
if (!$tag && !str_starts_with($code, 'GZ-')) {
    $tag = searchByTagCode('GZ-' . $code);
}

if ($tag) {
    logAction('tag_lookup_success', "Code: {$tag['tag_code']}");
    echo json_encode([
        'found' => true,
        'tag_code' => $tag['tag_code'],
        'owner_name' => $tag['owner_name'],
        'phone' => $tag['contact_phone'],
        'alt_phone' => $tag['contact_alt'] ?: '',
        'item_description' => $tag['item_description'] ?: ''
    ], JSON_UNESCAPED_UNICODE);
} else {
    logAction('tag_lookup_miss', "Code: {$code}");
    echo json_encode([
        'found' => false,
        'error' => 'لم يتم العثور على هذا الكود. تأكد من صحة الرمز.'
    ], JSON_UNESCAPED_UNICODE);
}
