<?php
/**
 * Search API Endpoint - فَقَدتُ ووَجَدتُ
 * Ultra-lightweight JSON endpoint for searching lost & found items.
 *
 * GET api/search.php?q=<query>
 */

if (!ob_start('ob_gzhandler')) {
    ob_start();
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/security.php';
require_once __DIR__ . '/../../core/matcher.php';
require_once __DIR__ . '/../../core/attributes.php';

// Rate limiting: 30 requests per minute
$clientIP = getClientIP();
if (!checkRateLimit($clientIP, 'search', 30, 1)) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'count' => 0,
        'results' => [],
        'message' => 'تم تجاوز حد محاولات البحث المسموح بها، يرجى الانتظار قليلاً.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$q = trim($_GET['q'] ?? '');
if ($q === '' || mb_strlen($q, 'UTF-8') < 2) {
    echo json_encode([
        'success' => true,
        'count' => 0,
        'results' => [],
        'has_radar' => false,
        'radar_message' => 'الرجاء إدخال حرفين على الأقل للبحث.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Category labels map
$categoryLabels = [
    'id_card' => 'هوية شخصية',
    'passport' => 'جواز سفر',
    'unrwa_card' => 'كرت وكالة',
    'phone' => 'هاتف محمول',
    'laptop' => 'لابتوب/تابلت',
    'bag' => 'حقيبة',
    'document' => 'وثائق',
    'money' => 'نقود/ذهب',
    'keys' => 'مفاتيح',
    'other' => 'أخرى'
];

function formatTimeAgo($datetime): string {
    if (!$datetime) return 'الآن';
    try {
        $now = new DateTime('now');
        $ago = new DateTime($datetime);
        $diff = $now->diff($ago);
        if ($diff->y > 0) return "قبل {$diff->y} سنة";
        if ($diff->m > 0) return "قبل {$diff->m} شهر";
        if ($diff->d > 0) return "قبل {$diff->d} يوم";
        if ($diff->h > 0) return "قبل {$diff->h} ساعة";
        if ($diff->i > 0) return "قبل {$diff->i} دقيقة";
        return "الآن";
    } catch (Exception $e) {
        return 'حديثاً';
    }
}

$results = [];
$db = getDB();

// 1. Tag Code Search (e.g. GZ-XXXX or contains GZ-)
if (preg_match('/^GZ-[A-Z0-9]{3,10}$/i', $q) || preg_match('/^[0-9]{4,6}$/', $q)) {
    $tag = searchByTagCode($q);
    if (!$tag && !str_starts_with(strtoupper($q), 'GZ-')) {
        $tag = searchByTagCode('GZ-' . strtoupper($q));
    }
    if ($tag) {
        $results[] = [
            'id' => (int)$tag['id'],
            'type' => 'tag',
            'type_label' => 'كود أمانة مسجل',
            'category' => 'tag',
            'category_label' => 'ملصق حماية وقائي',
            'title' => $tag['owner_name'] . ' (' . ($tag['item_description'] ?: 'غرض محمي') . ')',
            'location' => 'كود أمانة: ' . $tag['tag_code'],
            'drop_point' => null,
            'has_secret_question' => false,
            'secret_question' => null,
            'contact_visible' => true,
            'contact_info' => $tag['contact_phone'],
            'time_ago' => formatTimeAgo($tag['created_at']),
            'whatsapp_text' => "عثرت على غرض يحمل كود الأمانة [{$tag['tag_code']}] التابع للمواطن: {$tag['owner_name']}."
        ];
    }
}

// 2. Identifier Hash Search (National ID, Passport, IMEI, Serial)
// If query consists of digits or alphanumeric without spaces (ID, Passport, IMEI)
$cleanedIdent = preg_replace('/[\s\-\.]/', '', $q);
$isIdentifierQuery = (bool)preg_match('/^[A-Za-z0-9]{6,25}$/', $cleanedIdent);

if ($isIdentifierQuery) {
    $identHash = hashIdentifier($cleanedIdent);
    $identItems = searchByIdentifier($identHash);
    foreach ($identItems as $item) {
        // Fetch full secret question and contact visibility
        $itemStmt = $db->prepare("SELECT secret_question, secret_answer_hash, contact_info, contact_alt FROM items WHERE id = :id");
        $itemStmt->bindValue(':id', $item['id']);
        $itemStmt->execute();
        $fullItem = $itemStmt->fetch() ?: [];

        $hasSecret = !empty($fullItem['secret_question']) && !empty($fullItem['secret_answer_hash']);
        // Numbers are revealed on request only (api/contact.php or api/verify.php)
        $hasContact = !empty($fullItem['contact_info']);

        $catLabel = $categoryLabels[$item['category']] ?? $item['category'];
        $typeLabel = $item['type'] === 'lost' ? 'مفقود' : 'معثور عليه';

        $waText = "بلاغ [{$typeLabel}] على منصة فقدت ووجدت: {$item['title_or_name']} - المكان: " . ($item['location'] ?: 'قطاع غزة');

        $results[] = [
            'id' => (int)$item['id'],
            'type' => $item['type'],
            'type_label' => $typeLabel,
            'category' => $item['category'],
            'category_label' => $catLabel,
            'title' => $item['title_or_name'],
            'location' => $item['location'] ?: ($item['location_area'] ?: 'غير محدد'),
            'drop_point' => $item['drop_point'] ?: null,
            'has_secret_question' => $hasSecret,
            'secret_question' => $hasSecret ? $fullItem['secret_question'] : null,
            'contact_visible' => false,
            'has_contact' => $hasContact,
            'contact_info' => '',
            'description' => $item['description'] ?? '',
            'specs' => publicAttributes($item['category'], decodeAttributes($item['attributes'] ?? null)),
            'event_date' => $item['event_date'] ?? '',
            'time_ago' => formatTimeAgo($item['created_at']),
            'whatsapp_text' => $waText
        ];
    }
}

// 3. Name / Keyword Fuzzy Arabic Search
$nameItems = searchByName($q);
$existingIds = array_column($results, 'id');

foreach ($nameItems as $item) {
    if (in_array((int)$item['id'], $existingIds, true)) {
        continue;
    }

    $itemStmt = $db->prepare("SELECT secret_question, secret_answer_hash, contact_info, contact_alt FROM items WHERE id = :id");
    $itemStmt->bindValue(':id', $item['id']);
    $itemStmt->execute();
    $fullItem = $itemStmt->fetch() ?: [];

    $hasSecret = !empty($fullItem['secret_question']) && !empty($fullItem['secret_answer_hash']);
    // Numbers are revealed on request only (api/contact.php or api/verify.php)
    $hasContact = !empty($fullItem['contact_info']);

    $catLabel = $categoryLabels[$item['category']] ?? $item['category'];
    $typeLabel = $item['type'] === 'lost' ? 'مفقود' : 'معثور عليه';
    $waText = "بلاغ [{$typeLabel}] على منصة فقدت ووجدت: {$item['title_or_name']} - المكان: " . ($item['location'] ?: 'قطاع غزة');

    $results[] = [
        'id' => (int)$item['id'],
        'type' => $item['type'],
        'type_label' => $typeLabel,
        'category' => $item['category'],
        'category_label' => $catLabel,
        'title' => $item['title_or_name'],
        'location' => $item['location'] ?: ($item['location_area'] ?: 'غير محدد'),
        'drop_point' => $item['drop_point'] ?: null,
        'has_secret_question' => $hasSecret,
        'secret_question' => $hasSecret ? $fullItem['secret_question'] : null,
        'contact_visible' => false,
        'has_contact' => $hasContact,
        'contact_info' => '',
        'description' => $item['description'] ?? '',
            'specs' => publicAttributes($item['category'], decodeAttributes($item['attributes'] ?? null)),
        'event_date' => $item['event_date'] ?? '',
        'time_ago' => formatTimeAgo($item['created_at']),
        'whatsapp_text' => $waText
    ];
}

// Check radar status
$hasRadar = false;
$radarMessage = null;

if (empty($results)) {
    if ($isIdentifierQuery) {
        $identHash = hashIdentifier($cleanedIdent);
        $subs = checkRadarMatch($identHash);
        if (!empty($subs)) {
            $hasRadar = true;
            $radarMessage = 'رقمك مسجل مسبقاً لهذا البحث. عندما يسجّل أحد هذا الغرض سيظهر له رقمك ليتصل بك.';
        } else {
            $radarMessage = 'لا يوجد بلاغ بهذا الرقم حتى الآن. اترك رقم جوالك: عندما يسجّل أحد هذا الغرض سيظهر له رقمك ليتصل بك.';
        }
    } else {
        // Name searches cannot be matched automatically later (only document numbers can)
        $radarMessage = 'لم نجد بلاغاً يطابق بحثك حتى الآن. سجّل بلاغ مفقود ليراه من يجد غرضك، أو ابحث برقم الوثيقة إن كان معك.';
    }
}

logAction('search', "Query: {$q} - Found: " . count($results));

echo json_encode([
    'success' => true,
    'count' => count($results),
    'results' => $results,
    'has_radar' => $hasRadar,
    'radar_available' => $isIdentifierQuery && !$hasRadar,
    'radar_message' => $radarMessage
], JSON_UNESCAPED_UNICODE);
