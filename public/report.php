<?php
/**
 * منصة فَقَدتُ ووَجَدتُ - نظام تسجيل وإدارة البلاغات
 * Report & Registration Handler (Lost, Found, Safety Tag, IMEI, Bulk, Transport)
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/matcher.php';
require_once __DIR__ . '/../core/layout.php';

setSecurityHeaders();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$allowedTypes = ['lost', 'found', 'tag', 'imei', 'bulk', 'transport'];
$type = $_GET['type'] ?? 'choose';
if (!in_array($type, $allowedTypes, true)) {
    $type = 'choose';
}

$db = getDB();
$csrfToken = generateCSRFToken();

$result = null;
$error = null;
$radarMatch = null;
$generatedTag = null;
$ownerCode = null;

$areas = gazaAreas();

// Drop points in Gaza
$dropPoints = [
    'صيدلية الشفاء - خانيونس',
    'مجمع ناصر الطبي - قسم الاستعلامات',
    'مستشفى شهداء الأقصى - دير البلح',
    'صيدلية النصيرات المركزية',
    'مسجد الفاروق - رفح',
    'نقطة شحن الأمل - مواصي خانيونس',
    'مقر الهلال الأحمر - دير البلح',
    'مقر الأونروا الرئيسي'
];

// Categories
$categories = [
    'id_card' => 'هوية شخصية',
    'passport' => 'جواز سفر',
    'unrwa_card' => 'كرت وكالة',
    'phone' => 'هاتف محمول',
    'laptop' => 'لابتوب / تابلت',
    'bag' => 'حقيبة / محفظة',
    'document' => 'شهادات ووثائق رسمية',
    'money' => 'نقود ومصاغ ذهبي',
    'keys' => 'مفاتيح',
    'other' => 'أغراض أخرى'
];

$pageTitles = [
    'choose' => 'سجّل بلاغاً',
    'lost' => 'فقدتُ شيئاً',
    'found' => 'وجدتُ شيئاً',
    'tag' => 'إنشاء رمز أمانة لأغراضك',
    'imei' => 'تسجيل هاتف أو جهاز مفقود',
    'bulk' => 'قائمة وثائق معثور عليها (للمستشفيات والمؤسسات)',
    'transport' => 'نسيت شيئاً في المواصلات'
];

$pageIntros = [
    'lost' => 'اكتب ما فقدته بوضوح. إن وجده أحد وبحث عنه، سيصل إليك.',
    'found' => 'جزاك الله خيراً. سجّل ما وجدته ليتمكن صاحبه من الوصول إليك.',
    'tag' => 'رمز قصير تكتبه على أغراضك. من يجد الغرض يُدخل الرمز في المنصة فيظهر له اسمك ورقمك ليتصل بك.',
    'imei' => 'سجّل رقم IMEI لهاتفك المفقود. أصحاب محلات الصيانة والمستعمل يبحثون به قبل الشراء.',
    'bulk' => 'للمستشفيات ومراكز الإيواء: أضف قائمة الهويات والوثائق المعثور عليها دفعة واحدة.',
    'transport' => 'نسيت شيئاً في تكتك أو سيارة أو حافلة؟ سجّل خط السير ووقت الركوب ليتعرف عليه السائق أو الركاب.'
];

// Icon per report type (core/icons.php)
$pageIcons = [
    'lost' => 'search',
    'found' => 'heart',
    'tag' => 'tag',
    'imei' => 'smartphone',
    'bulk' => 'layers',
    'transport' => 'car'
];

/** Value previously submitted (kept when the form shows an error). */
function old(string $key, string $default = ''): string
{
    return htmlspecialchars((string)($_POST[$key] ?? $default));
}

/** Basic phone check: 8-15 digits, optional leading +. */
function isValidPhone(string $phone): bool
{
    return (bool)preg_match('/^\+?[0-9]{8,15}$/', preg_replace('/[\s\-]/', '', $phone));
}

/** Accept a Y-m-d date that is not in the future; otherwise null. */
function cleanEventDate(?string $date): ?string
{
    $date = trim((string)$date);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return null;
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date || $d > new DateTime('tomorrow')) return null;
    return $date;
}

/** Area + optional landmark, e.g. "النصيرات - قرب مسجد الهدى". */
function buildLocation(string $area, string $landmark): string
{
    return $landmark !== '' ? "{$area} - {$landmark}" : $area;
}

// Handle POST submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $type !== 'choose') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($submittedToken)) {
        $error = 'انتهت صلاحية الصفحة. حدّث الصفحة ثم أرسل البلاغ مرة أخرى.';
    } elseif (!checkRateLimit(getClientIP(), 'report_submit', 15, 60)) {
        $error = 'وصلت إلى الحد المسموح (15 بلاغاً في الساعة). حاول لاحقاً.';
    } else {
        $clientIP = getClientIP();

        switch ($type) {
            case 'lost':
            case 'found':
                $category = sanitize($_POST['category'] ?? 'other');
                if (!isset($categories[$category])) $category = 'other';
                $ident = trim($_POST['identifier'] ?? '');
                $title = sanitize($_POST['title'] ?? '');
                $description = mb_substr(sanitize($_POST['description'] ?? ''), 0, 300);
                $area = sanitize($_POST['location'] ?? '');
                $landmark = mb_substr(sanitize($_POST['landmark'] ?? ''), 0, 80);
                $eventDate = cleanEventDate($_POST['event_date'] ?? null);
                $phone = sanitize($_POST['phone'] ?? '');
                $altPhone = sanitize($_POST['alt_phone'] ?? '');
                $secretQuestion = '';
                $secretAnswerHash = null;
                $dropPoint = null;

                if ($type === 'found') {
                    $sq = trim($_POST['secret_question'] ?? '');
                    $sa = trim($_POST['secret_answer'] ?? '');
                    if ($sq !== '' && $sa !== '') {
                        $secretQuestion = sanitize($sq);
                        $secretAnswerHash = hashAnswer($sa);
                    }
                    if (!empty($_POST['has_drop_point'])) {
                        $dropPoint = sanitize($_POST['drop_point'] ?? '');
                    }
                }

                if (empty($title) || empty($area) || empty($phone)) {
                    $error = 'يرجى كتابة ما فقدته أو وجدته، واختيار المنطقة، وكتابة رقم جوالك.';
                } elseif (!isValidPhone($phone) || ($altPhone !== '' && !isValidPhone($altPhone))) {
                    $error = 'رقم الجوال غير صحيح. اكتبه بالأرقام فقط، مثل 0599123456.';
                } elseif ($type === 'found' && (trim($_POST['secret_question'] ?? '') !== '') !== (trim($_POST['secret_answer'] ?? '') !== '')) {
                    $error = 'اكتب سؤال التحقق وجوابه معاً، أو اتركهما فارغين.';
                } else {
                    $cleanedIdent = preg_replace('/[\s\-\.]/', '', $ident);
                    $identHash = !empty($cleanedIdent) ? hashIdentifier($cleanedIdent) : null;
                    $normName = normalizeArabic($title . ' ' . $description);
                    $location = buildLocation($area, $landmark);
                    $ownerCode = generateOwnerCode();

                    $stmt = $db->prepare("
                        INSERT INTO items (
                            type, category, identifier_hash, title_or_name, normalized_name,
                            location, location_area, drop_point, secret_question, secret_answer_hash,
                            contact_info, contact_alt, status, ip_address,
                            description, event_date, owner_code_hash
                        ) VALUES (
                            :type, :cat, :hash, :title, :norm,
                            :loc, :area, :drop, :sq, :sa,
                            :phone, :alt, 'active', :ip,
                            :desc, :edate, :owner
                        )
                    ");
                    $stmt->bindValue(':type', $type);
                    $stmt->bindValue(':cat', $category);
                    $stmt->bindValue(':hash', $identHash);
                    $stmt->bindValue(':title', $title);
                    $stmt->bindValue(':norm', $normName);
                    $stmt->bindValue(':loc', $location);
                    $stmt->bindValue(':area', $area);
                    $stmt->bindValue(':drop', $dropPoint);
                    $stmt->bindValue(':sq', $secretQuestion ?: null);
                    $stmt->bindValue(':sa', $secretAnswerHash);
                    $stmt->bindValue(':phone', $phone);
                    $stmt->bindValue(':alt', $altPhone ?: null);
                    $stmt->bindValue(':ip', $clientIP);
                    $stmt->bindValue(':desc', $description ?: null);
                    $stmt->bindValue(':edate', $eventDate);
                    $stmt->bindValue(':owner', hashOwnerCode($ownerCode));
                    $stmt->execute();
                    $newItemId = (int)$db->lastInsertId();

                    logAction("report_{$type}", "Item ID: {$newItemId} - {$title}");

                    // Someone is already waiting for this document number
                    if ($type === 'found' && $identHash) {
                        $radarSubscribers = checkRadarMatch($identHash);
                        if (!empty($radarSubscribers)) {
                            $radarMatch = $radarSubscribers[0];
                        }
                    }

                    $result = [
                        'type' => $type,
                        'id' => $newItemId,
                        'title' => $title,
                        'category' => $categories[$category] ?? $category,
                        'location' => $location,
                        'drop_point' => $dropPoint
                    ];
                }
                break;

            case 'tag':
                $ownerName = sanitize($_POST['owner_name'] ?? '');
                $phone = sanitize($_POST['phone'] ?? '');
                $altPhone = sanitize($_POST['alt_phone'] ?? '');
                $itemDesc = sanitize($_POST['item_description'] ?? '');

                if (empty($ownerName) || empty($phone) || empty($itemDesc)) {
                    $error = 'يرجى كتابة اسمك، ووصف الغرض، ورقم جوالك.';
                } elseif (!isValidPhone($phone) || ($altPhone !== '' && !isValidPhone($altPhone))) {
                    $error = 'رقم الجوال غير صحيح. اكتبه بالأرقام فقط، مثل 0599123456.';
                } else {
                    // Unique code: GZ- + 4 characters (digits and letters A-F)
                    $tagCode = 'GZ-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
                    while (searchByTagCode($tagCode)) {
                        $tagCode = 'GZ-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
                    }

                    $stmt = $db->prepare("
                        INSERT INTO safety_tags (tag_code, owner_name, contact_phone, contact_alt, item_description)
                        VALUES (:code, :name, :phone, :alt, :desc)
                    ");
                    $stmt->bindValue(':code', $tagCode);
                    $stmt->bindValue(':name', $ownerName);
                    $stmt->bindValue(':phone', $phone);
                    $stmt->bindValue(':alt', $altPhone ?: null);
                    $stmt->bindValue(':desc', $itemDesc);
                    $stmt->execute();

                    logAction('tag_created', "Code: {$tagCode} for {$ownerName}");
                    $generatedTag = $tagCode;
                    $result = [
                        'type' => 'tag',
                        'tag_code' => $tagCode,
                        'owner_name' => $ownerName,
                        'item_description' => $itemDesc
                    ];
                }
                break;

            case 'imei':
                $deviceType = sanitize($_POST['device_type'] ?? 'هاتف');
                $brand = sanitize($_POST['brand'] ?? '');
                $color = sanitize($_POST['color'] ?? '');
                $imei = trim($_POST['imei'] ?? '');
                $area = sanitize($_POST['location'] ?? '');
                $landmark = mb_substr(sanitize($_POST['landmark'] ?? ''), 0, 80);
                $eventDate = cleanEventDate($_POST['event_date'] ?? null);
                $phone = sanitize($_POST['phone'] ?? '');

                if (empty($imei) || empty($area) || empty($phone)) {
                    $error = 'يرجى كتابة رقم IMEI أو الرقم التسلسلي، واختيار المنطقة، وكتابة رقم جوالك.';
                } elseif (!isValidPhone($phone)) {
                    $error = 'رقم الجوال غير صحيح. اكتبه بالأرقام فقط، مثل 0599123456.';
                } else {
                    $fullTitle = trim($deviceType . ' ' . $brand) . ($color !== '' ? " ({$color})" : '');
                    $cleanedImei = preg_replace('/[\s\-\.]/', '', $imei);
                    $imeiHash = hashIdentifier($cleanedImei);
                    $location = buildLocation($area, $landmark);
                    $ownerCode = generateOwnerCode();

                    $stmt = $db->prepare("
                        INSERT INTO items (
                            type, category, identifier_hash, title_or_name, normalized_name,
                            location, location_area, contact_info, status, ip_address,
                            event_date, owner_code_hash
                        ) VALUES (
                            'lost', 'phone', :hash, :title, :norm,
                            :loc, :area, :phone, 'active', :ip,
                            :edate, :owner
                        )
                    ");
                    $stmt->bindValue(':hash', $imeiHash);
                    $stmt->bindValue(':title', $fullTitle);
                    $stmt->bindValue(':norm', normalizeArabic($fullTitle));
                    $stmt->bindValue(':loc', $location);
                    $stmt->bindValue(':area', $area);
                    $stmt->bindValue(':phone', $phone);
                    $stmt->bindValue(':ip', $clientIP);
                    $stmt->bindValue(':edate', $eventDate);
                    $stmt->bindValue(':owner', hashOwnerCode($ownerCode));
                    $stmt->execute();
                    $newItemId = (int)$db->lastInsertId();

                    logAction('imei_reported', "Item ID: {$newItemId}");
                    $result = [
                        'type' => 'imei',
                        'id' => $newItemId,
                        'title' => $fullTitle,
                        'location' => $location
                    ];
                }
                break;

            case 'bulk':
                $bulkData = trim($_POST['bulk_data'] ?? '');
                $area = sanitize($_POST['location'] ?? '');
                $dropPoint = sanitize($_POST['drop_point'] ?? '');
                $orgName = sanitize($_POST['org_name'] ?? '');
                $phone = sanitize($_POST['phone'] ?? '');

                if (empty($bulkData) || empty($area) || empty($orgName) || empty($dropPoint)) {
                    $error = 'يرجى كتابة اسم المؤسسة، والمنطقة، ومكان الاستلام، ولصق القائمة.';
                } elseif ($phone !== '' && !isValidPhone($phone)) {
                    $error = 'رقم الهاتف غير صحيح. اكتبه بالأرقام فقط، أو اتركه فارغاً.';
                } else {
                    $parsedItems = [];
                    foreach (explode("\n", $bulkData) as $line) {
                        $line = trim($line);
                        if ($line === '') continue;
                        // "id | name", or two columns pasted from Excel (tab separated)
                        $parts = preg_split('/\s*[|\t]\s*/', $line, 2);
                        $idNum = trim($parts[0] ?? '');
                        $pName = trim($parts[1] ?? '');
                        if ($idNum !== '' || $pName !== '') {
                            $parsedItems[] = ['identifier' => $idNum, 'name' => $pName, 'category' => 'id_card'];
                        }
                    }

                    if (!empty($parsedItems)) {
                        $ownerCode = generateOwnerCode();
                        $fullDrop = $orgName . ' - ' . $dropPoint;
                        $inserted = bulkInsertItems($parsedItems, $area, $fullDrop, $clientIP, $phone ?: null, hashOwnerCode($ownerCode));
                        $result = [
                            'type' => 'bulk',
                            'count' => $inserted,
                            'location' => $area,
                            'drop_point' => $fullDrop
                        ];
                    } else {
                        $error = 'لم نجد أسطراً صالحة في القائمة. اكتب كل وثيقة في سطر: رقم الهوية | الاسم.';
                    }
                }
                break;

            case 'transport':
                $fromArea = sanitize($_POST['from_area'] ?? '');
                $toArea = sanitize($_POST['to_area'] ?? '');
                $travelDate = cleanEventDate($_POST['travel_date'] ?? null) ?? date('Y-m-d');
                $timeRange = sanitize($_POST['time_range'] ?? '');
                $vehicleType = sanitize($_POST['vehicle_type'] ?? 'تكتك');
                $vehicleColor = sanitize($_POST['vehicle_color'] ?? '');
                $itemDesc = sanitize($_POST['item_description'] ?? '');
                $phone = sanitize($_POST['phone'] ?? '');

                if (empty($fromArea) || empty($toArea) || empty($itemDesc) || empty($phone)) {
                    $error = 'يرجى اختيار منطقة الركوب والنزول، ووصف الغرض، وكتابة رقم جوالك.';
                } elseif (!isValidPhone($phone)) {
                    $error = 'رقم الجوال غير صحيح. اكتبه بالأرقام فقط، مثل 0599123456.';
                } else {
                    $routeTitle = "{$itemDesc} (في المواصلات: {$fromArea} ← {$toArea})";
                    $route = "من {$fromArea} إلى {$toArea}";
                    $vehicle = trim("{$vehicleType} " . ($vehicleColor ? "- {$vehicleColor}" : ''));
                    $ownerCode = generateOwnerCode();

                    $stmt = $db->prepare("
                        INSERT INTO items (
                            type, category, title_or_name, normalized_name, location, location_area,
                            transport_route, transport_time, transport_vehicle, contact_info,
                            status, ip_address, event_date, owner_code_hash
                        ) VALUES (
                            'lost', 'other', :title, :norm, :loc, :area,
                            :route, :time, :vehicle, :phone,
                            'active', :ip, :edate, :owner
                        )
                    ");
                    $stmt->bindValue(':title', $routeTitle);
                    $stmt->bindValue(':norm', normalizeArabic($routeTitle));
                    $stmt->bindValue(':loc', "خط السير: {$route}");
                    $stmt->bindValue(':area', $fromArea);
                    $stmt->bindValue(':route', $route);
                    $stmt->bindValue(':time', trim("{$travelDate} {$timeRange}"));
                    $stmt->bindValue(':vehicle', $vehicle);
                    $stmt->bindValue(':phone', $phone);
                    $stmt->bindValue(':ip', $clientIP);
                    $stmt->bindValue(':edate', $travelDate);
                    $stmt->bindValue(':owner', hashOwnerCode($ownerCode));
                    $stmt->execute();
                    $newItemId = (int)$db->lastInsertId();

                    logAction('transport_reported', "ID: {$newItemId}");
                    $result = [
                        'type' => 'transport',
                        'id' => $newItemId,
                        'title' => $routeTitle,
                        'vehicle' => $vehicle
                    ];
                }
                break;
        }
    }
    // verifyCSRFToken() rotates the token; refresh it for the form shown again
    $csrfToken = generateCSRFToken();
}

if (!function_exists('generateWhatsAppShareText')) {
    function generateWhatsAppShareText(?array $res): string {
        if (!$res) return '';
        switch ($res['type'] ?? '') {
            case 'lost':
                return "مفقود في قطاع غزة: {$res['title']} - المنطقة: {$res['location']}\nمن يجده يرجى التواصل عبر منصة فَقَدتُ ووَجَدتُ.";
            case 'found':
                $dp = !empty($res['drop_point']) ? " - مكان الاستلام: {$res['drop_point']}" : "";
                return "بشرى: عُثر على {$res['title']} في {$res['location']}{$dp}\nصاحب الغرض يجده على منصة فَقَدتُ ووَجَدتُ.";
            case 'tag':
                return "رمز الأمانة لأغراضي: {$res['tag_code']}. من يجد غرضاً عليه هذا الرمز يبحث عنه في منصة فَقَدتُ ووَجَدتُ.";
            case 'imei':
                return "جهاز مفقود: {$res['title']}\nتنبيه لأصحاب المحلات والبسطات: افحصوا رقم IMEI قبل الشراء على منصة فَقَدتُ ووَجَدتُ.";
            case 'bulk':
                return "تم نشر قائمة فيها {$res['count']} وثيقة معثور عليها في {$res['location']}، مكان الاستلام: {$res['drop_point']}.";
            case 'transport':
                return "مفقود في المواصلات: {$res['title']} ({$res['vehicle']})\nعلى منصة فَقَدتُ ووَجَدتُ.";
            default:
                return '';
        }
    }
}

/** Print an area <select> with a placeholder option. */
function areaSelect(string $name, string $id, array $areas, string $placeholder = 'اختر المنطقة'): void
{
    $current = $_POST[$name] ?? '';
    echo '<select name="' . $name . '" id="' . $id . '" class="form-control" required>';
    echo '<option value="">' . htmlspecialchars($placeholder) . '</option>';
    foreach ($areas as $a) {
        $sel = $current === $a ? ' selected' : '';
        echo '<option value="' . htmlspecialchars($a) . '"' . $sel . '>' . htmlspecialchars($a) . '</option>';
    }
    echo '</select>';
}

pageStart($pageTitles[$type] ?? 'سجّل بلاغاً', 'report');
?>

<div class="container" style="padding-top: 2rem; padding-bottom: 3rem;">

<?php if ($type === 'choose'): ?>
    <!-- Step 1: choose the report type -->
    <div class="page-heading">
        <h1>ماذا تريد أن تسجّل؟</h1>
        <p>اختر ما يناسب حالتك.</p>
    </div>
    <div class="chooser-grid">
        <a href="report.php?type=lost" class="chooser-card primary">
            <span class="action-icon"><?= icon('search') ?></span>
            <span class="chooser-title">فقدتُ شيئاً</span>
            <span class="chooser-desc">هوية، جواز، هاتف، حقيبة، أو أي غرض آخر.</span>
        </a>
        <a href="report.php?type=found" class="chooser-card primary">
            <span class="action-icon"><?= icon('heart') ?></span>
            <span class="chooser-title">وجدتُ شيئاً</span>
            <span class="chooser-desc">سجّل الأمانة ليصل إليك صاحبها.</span>
        </a>
        <a href="report.php?type=transport" class="chooser-card">
            <span class="action-icon"><?= icon('car') ?></span>
            <span class="chooser-title">نسيت شيئاً في المواصلات</span>
            <span class="chooser-desc">تكتك أو سيارة أو حافلة.</span>
        </a>
        <a href="report.php?type=imei" class="chooser-card">
            <span class="action-icon"><?= icon('smartphone') ?></span>
            <span class="chooser-title">هاتف أو جهاز مفقود</span>
            <span class="chooser-desc">سجّل رقم IMEI حتى لا يُباع جهازك.</span>
        </a>
        <a href="report.php?type=tag" class="chooser-card">
            <span class="action-icon"><?= icon('tag') ?></span>
            <span class="chooser-title">رمز أمانة لأغراضي</span>
            <span class="chooser-desc">احمِ أغراضك قبل أن تضيع.</span>
        </a>
        <a href="report.php?type=bulk" class="chooser-card">
            <span class="action-icon"><?= icon('layers') ?></span>
            <span class="chooser-title">قائمة وثائق (للمؤسسات)</span>
            <span class="chooser-desc">مستشفى أو مركز إيواء لديه وثائق كثيرة.</span>
        </a>
    </div>

<?php else: ?>

    <p class="back-link"><a href="report.php"><?= icon('chevron-right') ?> كل أنواع البلاغات</a></p>

    <?php if ($error): ?>
        <div class="alert alert-danger narrow" role="alert">
            <?= icon('alert') ?>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
    <?php endif; ?>

    <?php if ($result): ?>
        <!-- Success -->
        <div class="alert alert-success narrow" style="flex-direction: column;" role="status">
            <div class="alert-title" style="display: flex; align-items: center; gap: 0.45rem;">
                <?= icon('check-circle') ?>
                <?= $type === 'tag' ? 'تم إنشاء رمز الأمانة' : ($type === 'bulk' ? 'تم نشر القائمة (' . (int)$result['count'] . ' وثيقة)' : 'تم نشر بلاغك') ?>
            </div>
            <?php if (!empty($result['id'])): ?>
                <p>رقم البلاغ: <strong>#<?= (int)$result['id'] ?></strong></p>
            <?php endif; ?>

            <?php if (!empty($radarMatch)): ?>
                <div class="result-panel" style="border: 1.5px solid #9fd3b0;">
                    <h3 class="panel-title" style="color: var(--success-dark);"><?= icon('radar') ?> بشرى: صاحب هذه الوثيقة يبحث عنها</h3>
                    <p style="margin-bottom: 0.75rem;">ترك صاحبها رقمه ليُتصل به عند العثور عليها. الاسم المسجل: <strong><?= htmlspecialchars($radarMatch['target_name'] ?? '') ?></strong></p>
                    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                        <a href="tel:<?= htmlspecialchars($radarMatch['subscriber_phone']) ?>" class="btn btn-primary">
                            <?= icon('phone') ?> اتصل بصاحبها: <span dir="ltr"><?= htmlspecialchars($radarMatch['subscriber_phone']) ?></span>
                        </a>
                        <?php if (!empty($radarMatch['alt_phone'])): ?>
                            <a href="tel:<?= htmlspecialchars($radarMatch['alt_phone']) ?>" class="btn btn-outline-primary">
                                <?= icon('phone') ?> رقم بديل: <span dir="ltr"><?= htmlspecialchars($radarMatch['alt_phone']) ?></span>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($ownerCode): ?>
                <div class="result-panel owner-code-panel">
                    <h3 class="panel-title"><?= icon('key') ?> رمز إدارة <?= $type === 'bulk' ? 'القائمة' : 'البلاغ' ?>: احفظه</h3>
                    <div class="owner-code" dir="ltr"><?= htmlspecialchars($ownerCode) ?></div>
                    <p>
                        ستحتاج هذا الرمز لإغلاق <?= $type === 'bulk' ? 'أي وثيقة في القائمة عند تسليمها' : 'البلاغ عندما يعود الغرض' ?>، أو لحذفه.
                        لن نعرضه مرة أخرى، فصوّر الشاشة أو انسخه الآن. لا تعطه لأحد.
                    </p>
                    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-top: 0.75rem;">
                        <button type="button" class="btn btn-outline-primary btn-sm" data-action="copy" data-text="<?= htmlspecialchars($ownerCode) ?>"><?= icon('file-text') ?><span class="lbl">نسخ الرمز</span></button>
                        <?php if (!empty($result['id'])): ?>
                            <a href="manage.php?id=<?= (int)$result['id'] ?>" class="btn btn-outline-primary btn-sm"><?= icon('key') ?> صفحة إدارة البلاغ</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($generatedTag)): ?>
                <div class="result-panel tag-display">
                    <p style="font-size: 0.9rem; color: var(--slate-600); margin-bottom: 0.5rem;">رمز الأمانة الخاص بك:</p>
                    <div class="tag-code"><?= htmlspecialchars($generatedTag) ?></div>
                    <p style="font-size: 0.9rem; color: var(--slate-700); margin-top: 0.75rem;">
                        اكتب هذا الرمز بقلم ثابت أو على ملصق، وضعه على هويتك أو حقيبتك أو جهازك.
                        من يجد الغرض يُدخل الرمز في المنصة فيظهر له اسمك ورقم جوالك.
                    </p>
                    <button type="button" class="btn btn-outline-primary btn-sm mt-2" onclick="window.print()"><?= icon('printer') ?> اطبع بطاقة الرمز</button>
                </div>
            <?php endif; ?>

            <div class="no-print" style="margin-top: 1.25rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
                <button type="button" class="btn btn-whatsapp" data-action="share" data-text="<?= htmlspecialchars(generateWhatsAppShareText($result)) ?>">
                    <?= icon('share') ?> مشاركة على واتساب
                </button>
                <a href="report.php?type=<?= htmlspecialchars($type) ?>" class="btn btn-outline-primary">سجّل بلاغاً آخر</a>
                <a href="index.php" class="btn btn-outline-primary">العودة للرئيسية</a>
            </div>
        </div>

    <?php else: ?>

    <div class="form-card">
        <h1 class="form-title">
            <?= icon($pageIcons[$type] ?? 'file-text', 'ic-lg') ?>
            <span><?= htmlspecialchars($pageTitles[$type]) ?></span>
        </h1>
        <p class="form-intro"><?= htmlspecialchars($pageIntros[$type] ?? '') ?></p>
        <p class="form-required-note">الحقول المعلَّمة بـ <span class="req">*</span> مطلوبة.</p>

        <form method="POST" action="report.php?type=<?= htmlspecialchars($type) ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <?php if ($type === 'lost' || $type === 'found'): ?>
                <?php $isFound = $type === 'found'; ?>
                <div class="form-group">
                    <label class="form-label" for="f-title"><?= $isFound ? 'ما الذي وجدته؟' : 'ما الذي فقدته؟' ?> <span class="req">*</span></label>
                    <input type="text" name="title" id="f-title" class="form-control" value="<?= old('title') ?>" required
                        placeholder="<?= $isFound ? 'مثال: هوية باسم أحمد محمد النجار، أو محفظة جلد' : 'مثال: هوية باسم أحمد محمد النجار، أو هاتف سامسونج أزرق' ?>">
                    <div class="form-text"><?= icon('info') ?><span>للوثائق اكتب الاسم الكامل المكتوب عليها، فهكذا يبحث الناس.</span></div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="f-category">النوع <span class="req">*</span></label>
                    <select name="category" id="f-category" class="form-control" required>
                        <?php foreach ($categories as $k => $v): ?>
                            <option value="<?= htmlspecialchars($k) ?>" <?= ($_POST['category'] ?? '') === $k ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="f-identifier">رقم الوثيقة أو الرقم التسلسلي (اختياري)</label>
                    <input type="text" name="identifier" id="f-identifier" class="form-control" value="<?= old('identifier') ?>" placeholder="رقم الهوية أو الجواز أو IMEI" dir="ltr">
                    <div class="form-text"><?= icon('lock') ?><span>لن يظهر هذا الرقم لأحد. نستخدمه فقط للمطابقة: من يبحث بنفس الرقم يجد بلاغك فوراً.</span></div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="f-description">مواصفات إضافية (اختياري)</label>
                    <textarea name="description" id="f-description" class="form-control" rows="2" maxlength="300" placeholder="اللون، علامة مميزة، ما بداخله..."><?= old('description') ?></textarea>
                    <?php if ($isFound): ?>
                        <div class="form-text"><?= icon('alert') ?><span>لا تكتب هنا تفاصيل يعرفها صاحب الغرض وحده (مثل المبلغ). ضعها في سؤال التحقق بالأسفل.</span></div>
                    <?php endif; ?>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="f-location">المنطقة <span class="req">*</span></label>
                        <?php areaSelect('location', 'f-location', $areas); ?>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="f-landmark">أقرب معلم أو اسم الشارع (اختياري)</label>
                        <input type="text" name="landmark" id="f-landmark" class="form-control" value="<?= old('landmark') ?>" maxlength="80" placeholder="مثال: قرب مسجد الهدى">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="f-date"><?= $isFound ? 'متى وجدته؟' : 'متى فقدته؟' ?></label>
                    <input type="date" name="event_date" id="f-date" class="form-control" value="<?= old('event_date', date('Y-m-d')) ?>" max="<?= date('Y-m-d') ?>">
                </div>

                <?php if ($isFound): ?>
                    <div class="form-panel">
                        <h2 class="panel-title"><?= icon('shield') ?> سؤال التحقق (للمال والذهب والأغراض الثمينة)</h2>
                        <p class="form-text" style="display: block; margin-bottom: 0.75rem;">
                            اختياري. إذا وجدت مالاً أو شيئاً ثميناً، لا تنشر كل مواصفاته. اكتب سؤالاً لا يعرف جوابه إلا صاحبه، مثل: كم المبلغ تقريباً؟ ما لون المحفظة؟
                            لن يظهر رقمك إلا لمن يجيب إجابة صحيحة.
                        </p>
                        <div class="form-group">
                            <label class="form-label" for="f-sq">السؤال (يظهر للجميع)</label>
                            <input type="text" name="secret_question" id="f-sq" class="form-control" value="<?= old('secret_question') ?>" placeholder="مثال: ما العلامة المميزة داخل الحقيبة؟">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" for="f-sa">الجواب (لا يظهر لأحد)</label>
                            <input type="text" name="secret_answer" id="f-sa" class="form-control" autocomplete="off" placeholder="مثال: محفظة بنية بداخلها 400 شيكل">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="check-row">
                            <input type="checkbox" name="has_drop_point" id="dropCheck" onchange="toggleDropSection(this)" <?= !empty($_POST['has_drop_point']) ? 'checked' : '' ?>>
                            <span>سلّمتُ الغرض لنقطة أمانات معتمدة (صيدلية أو مسجد أو مستشفى)</span>
                        </label>
                    </div>

                    <div id="dropSection" class="form-group <?= empty($_POST['has_drop_point']) ? 'hidden' : '' ?>">
                        <label class="form-label" for="f-drop">نقطة الاستلام</label>
                        <select name="drop_point" id="f-drop" class="form-control">
                            <?php foreach ($dropPoints as $dp): ?>
                                <option value="<?= htmlspecialchars($dp) ?>" <?= ($_POST['drop_point'] ?? '') === $dp ? 'selected' : '' ?>><?= htmlspecialchars($dp) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="f-phone">رقم جوالك <span class="req">*</span></label>
                        <input type="tel" name="phone" id="f-phone" class="form-control" value="<?= old('phone') ?>" placeholder="0599123456" dir="ltr" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="f-alt">رقم بديل (اختياري)</label>
                        <input type="tel" name="alt_phone" id="f-alt" class="form-control" value="<?= old('alt_phone') ?>" placeholder="رقم قريب أو صديق" dir="ltr">
                    </div>
                </div>
                <div class="privacy-note">
                    <?= icon('lock') ?>
                    <span>لا يظهر رقمك في قائمة البلاغات. يظهر فقط لمن يضغط «إظهار رقم الجوال» على بلاغك<?= $isFound ? '، أو لمن يجيب سؤال التحقق إن وضعته' : '' ?>.</span>
                </div>

            <?php elseif ($type === 'tag'): ?>
                <div class="form-group">
                    <label class="form-label" for="f-owner">اسمك <span class="req">*</span></label>
                    <input type="text" name="owner_name" id="f-owner" class="form-control" value="<?= old('owner_name') ?>" placeholder="الاسم الثلاثي" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="f-itemdesc">الغرض الذي تريد حمايته <span class="req">*</span></label>
                    <input type="text" name="item_description" id="f-itemdesc" class="form-control" value="<?= old('item_description') ?>" placeholder="مثال: لابتوب ديل أسود، أو هوية" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="f-phone">رقم جوالك <span class="req">*</span></label>
                        <input type="tel" name="phone" id="f-phone" class="form-control" value="<?= old('phone') ?>" placeholder="0599123456" dir="ltr" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="f-alt">رقم بديل (اختياري)</label>
                        <input type="tel" name="alt_phone" id="f-alt" class="form-control" value="<?= old('alt_phone') ?>" placeholder="رقم قريب" dir="ltr">
                    </div>
                </div>
                <div class="privacy-note">
                    <?= icon('info') ?>
                    <span>من يُدخل رمزك في المنصة يرى اسمك ورقمك ليتصل بك. لذلك ضع الرمز على الغرض نفسه فقط.</span>
                </div>

            <?php elseif ($type === 'imei'): ?>
                <div class="form-group">
                    <label class="form-label" for="f-imei">رقم IMEI أو الرقم التسلسلي <span class="req">*</span></label>
                    <input type="text" name="imei" id="f-imei" class="form-control" value="<?= old('imei') ?>" placeholder="15 رقماً" dir="ltr" inputmode="numeric" required>
                    <div class="form-text"><?= icon('info') ?><span>تجده على علبة الهاتف أو فاتورته، أو اطلب <bdi dir="ltr">*#06#</bdi> من الهاتف قبل فقدانه.</span></div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="f-device">نوع الجهاز <span class="req">*</span></label>
                        <select name="device_type" id="f-device" class="form-control" required>
                            <?php foreach (['هاتف ذكي', 'لابتوب', 'تابلت / آيباد', 'ساعة ذكية'] as $d): ?>
                                <option value="<?= $d ?>" <?= ($_POST['device_type'] ?? '') === $d ? 'selected' : '' ?>><?= $d ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="f-brand">الماركة والموديل</label>
                        <input type="text" name="brand" id="f-brand" class="form-control" value="<?= old('brand') ?>" placeholder="مثال: سامسونج A52">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="f-color">اللون</label>
                    <input type="text" name="color" id="f-color" class="form-control" value="<?= old('color') ?>" placeholder="مثال: أزرق">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="f-location">المنطقة التي فُقد فيها <span class="req">*</span></label>
                        <?php areaSelect('location', 'f-location', $areas); ?>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="f-landmark">أقرب معلم (اختياري)</label>
                        <input type="text" name="landmark" id="f-landmark" class="form-control" value="<?= old('landmark') ?>" maxlength="80">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="f-date">متى فقدته؟</label>
                        <input type="date" name="event_date" id="f-date" class="form-control" value="<?= old('event_date', date('Y-m-d')) ?>" max="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="f-phone">رقم جوال للتواصل <span class="req">*</span></label>
                        <input type="tel" name="phone" id="f-phone" class="form-control" value="<?= old('phone') ?>" placeholder="0599123456" dir="ltr" required>
                    </div>
                </div>
                <div class="privacy-note">
                    <?= icon('lock') ?>
                    <span>رقم IMEI لا يظهر لأحد. من يبحث بنفس الرقم يجد بلاغك، ويظهر رقم جوالك لمن يضغط «إظهار رقم الجوال».</span>
                </div>

            <?php elseif ($type === 'transport'): ?>
                <div class="form-group">
                    <label class="form-label" for="f-itemdesc">ماذا نسيت؟ <span class="req">*</span></label>
                    <input type="text" name="item_description" id="f-itemdesc" class="form-control" value="<?= old('item_description') ?>" placeholder="مثال: كيس أدوية في المقعد الخلفي" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="f-from">ركبتُ من <span class="req">*</span></label>
                        <?php areaSelect('from_area', 'f-from', $areas, 'اختر منطقة الركوب'); ?>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="f-to">نزلتُ في <span class="req">*</span></label>
                        <?php areaSelect('to_area', 'f-to', $areas, 'اختر منطقة النزول'); ?>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="f-tdate">تاريخ الركوب</label>
                        <input type="date" name="travel_date" id="f-tdate" class="form-control" value="<?= old('travel_date', date('Y-m-d')) ?>" max="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="f-time">الوقت التقريبي</label>
                        <input type="text" name="time_range" id="f-time" class="form-control" value="<?= old('time_range') ?>" placeholder="مثال: بين 10 و 11 صباحاً">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="f-vehicle">نوع المركبة <span class="req">*</span></label>
                        <select name="vehicle_type" id="f-vehicle" class="form-control" required>
                            <?php foreach (['تكتك', 'سيارة عمومي / ملاكي', 'عربة كارو', 'حافلة / باص'] as $v): ?>
                                <option value="<?= $v ?>" <?= ($_POST['vehicle_type'] ?? '') === $v ? 'selected' : '' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="f-vcolor">لون المركبة أو علامة مميزة</label>
                        <input type="text" name="vehicle_color" id="f-vcolor" class="form-control" value="<?= old('vehicle_color') ?>" placeholder="مثال: تكتك أزرق بسقف قماش">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="f-phone">رقم جوالك <span class="req">*</span></label>
                    <input type="tel" name="phone" id="f-phone" class="form-control" value="<?= old('phone') ?>" placeholder="0599123456" dir="ltr" required>
                </div>
                <div class="privacy-note">
                    <?= icon('lock') ?>
                    <span>لا يظهر رقمك في القائمة. يظهر فقط لمن يضغط «إظهار رقم الجوال» على بلاغك.</span>
                </div>

            <?php elseif ($type === 'bulk'): ?>
                <div class="form-group">
                    <label class="form-label" for="f-org">اسم المؤسسة أو المستشفى أو مركز الإيواء <span class="req">*</span></label>
                    <input type="text" name="org_name" id="f-org" class="form-control" value="<?= old('org_name') ?>" placeholder="مثال: مجمع ناصر الطبي" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="f-location">المنطقة <span class="req">*</span></label>
                        <?php areaSelect('location', 'f-location', $areas); ?>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="f-drop">أين يستلم الناس وثائقهم؟ <span class="req">*</span></label>
                        <input type="text" name="drop_point" id="f-drop" class="form-control" value="<?= old('drop_point') ?>" placeholder="مثال: قسم الاستعلامات عند البوابة" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="f-bulk">قائمة الوثائق (كل وثيقة في سطر) <span class="req">*</span></label>
                    <textarea name="bulk_data" id="f-bulk" rows="8" class="form-control" required dir="rtl" placeholder="رقم الهوية | الاسم&#10;405123456 | أحمد محمد خليل النجار&#10;901234567 | محمود سعيد أبو عودة"><?= old('bulk_data') ?></textarea>
                    <div class="form-text"><?= icon('info') ?><span>اكتب في كل سطر: رقم الهوية | الاسم. أو انسخ عمودين من Excel والصقهما مباشرة.</span></div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="f-phone">هاتف نقطة الاستلام (اختياري)</label>
                    <input type="tel" name="phone" id="f-phone" class="form-control" value="<?= old('phone') ?>" placeholder="0599123456" dir="ltr">
                    <div class="form-text"><?= icon('lock') ?><span>يظهر فقط لمن يضغط «إظهار رقم الجوال» على إحدى الوثائق.</span></div>
                </div>
            <?php endif; ?>

            <div class="mt-3">
                <button type="submit" class="btn btn-primary btn-block">
                    <?= $type === 'tag' ? 'أنشئ رمز الأمانة' : ($type === 'bulk' ? 'انشر القائمة' : 'انشر البلاغ') ?>
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>
<?php endif; ?>
</div>

<?php
$script = <<<'JS'
function toggleDropSection(chk) {
    var sec = document.getElementById('dropSection');
    if (sec) sec.classList.toggle('hidden', !chk.checked);
}

// Move focus to the message after submitting, so it is seen and read first
(function() {
    var msg = document.querySelector('.alert[role="alert"], .alert[role="status"]');
    if (msg) {
        msg.setAttribute('tabindex', '-1');
        msg.focus({preventScroll: false});
    }
})();
JS;
pageEnd($script);
