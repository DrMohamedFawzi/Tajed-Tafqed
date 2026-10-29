<?php
/**
 * منصة فَقَدتُ ووَجَدتُ - نظام تسجيل وإدارة البلاغات
 * Report & Registration Handler (Lost, Found, Safety Tag, IMEI, Bulk, Transport)
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/matcher.php';

setSecurityHeaders();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$type = $_GET['type'] ?? 'lost';
$allowedTypes = ['lost', 'found', 'tag', 'imei', 'bulk', 'transport'];
if (!in_array($type, $allowedTypes, true)) {
    $type = 'lost';
}

$db = getDB();
$csrfToken = generateCSRFToken();

$result = null;
$error = null;
$radarMatch = null;
$generatedTag = null;

// Gaza Areas List
$areas = [
    'خانيونس', 'دير البلح', 'النصيرات', 'رفح', 'المواصي',
    'غزة المدينة', 'جباليا', 'بيت لاهيا', 'بيت حانون',
    'الزوايدة', 'البريج', 'المغازي', 'عبسان', 'بني سهيلا', 'القرارة'
];

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
    'lost' => '📋 تسجيل بلاغ عن مفقود',
    'found' => '🤲 تسجيل أمانة تم العثور عليها',
    'tag' => '🏷️ إنشاء ملصق كود الأمانة الوقائي',
    'imei' => '📱 فحص وتسجيل جهاز بالـ IMEI',
    'bulk' => '📦 كشف جماعي (للمستشفيات ومراكز الإيواء)',
    'transport' => '🚗 بلاغ فقدان في المواصلات (تكتك / سيارة)'
];

// Handle POST submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($submittedToken)) {
        $error = 'انتهت صلاحية جلسة الأمان، يرجى تحديث الصفحة والمحاولة مجدداً.';
    } elseif (!checkRateLimit(getClientIP(), 'report_submit', 15, 60)) {
        $error = 'تم تجاوز الحد المسموح به من البلاغات (15 بلاغاً في الساعة). يرجى الانتظار.';
    } else {
        $clientIP = getClientIP();

        switch ($type) {
            case 'lost':
            case 'found':
                $category = sanitize($_POST['category'] ?? 'other');
                $ident = trim($_POST['identifier'] ?? '');
                $title = sanitize($_POST['title'] ?? '');
                $location = sanitize($_POST['location'] ?? '');
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

                if (empty($title) || empty($location) || empty($phone)) {
                    $error = 'يرجى إدخال اسم/وصف الغرض، والمنطقة، ورقم الهاتف للتواصل.';
                } else {
                    $cleanedIdent = preg_replace('/[\s\-\.]/', '', $ident);
                    $identHash = !empty($cleanedIdent) ? hashIdentifier($cleanedIdent) : null;
                    $normName = normalizeArabic($title);

                    $stmt = $db->prepare("
                        INSERT INTO items (
                            type, category, identifier_hash, title_or_name, normalized_name,
                            location, location_area, drop_point, secret_question, secret_answer_hash,
                            contact_info, contact_alt, status, ip_address
                        ) VALUES (
                            :type, :cat, :hash, :title, :norm,
                            :loc, :area, :drop, :sq, :sa,
                            :phone, :alt, 'active', :ip
                        )
                    ");
                    $stmt->bindValue(':type', $type);
                    $stmt->bindValue(':cat', $category);
                    $stmt->bindValue(':hash', $identHash);
                    $stmt->bindValue(':title', $title);
                    $stmt->bindValue(':norm', $normName);
                    $stmt->bindValue(':loc', $location);
                    $stmt->bindValue(':area', $location);
                    $stmt->bindValue(':drop', $dropPoint);
                    $stmt->bindValue(':sq', $secretQuestion ?: null);
                    $stmt->bindValue(':sa', $secretAnswerHash);
                    $stmt->bindValue(':phone', $phone);
                    $stmt->bindValue(':alt', $altPhone ?: null);
                    $stmt->bindValue(':ip', $clientIP);
                    $stmt->execute();
                    $newItemId = (int)$db->lastInsertId();

                    logAction("report_{$type}", "Item ID: {$newItemId} - {$title}");

                    // Check radar auto-matching for found items
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
                        'phone' => $phone,
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
                    $error = 'يرجى إدخال اسم صاحب الغرض ورقم الهاتف ووصف الغرض.';
                } else {
                    // Generate unique 4-digit code
                    $tagCode = 'GZ-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
                    // Ensure unique
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
                        'item_description' => $itemDesc,
                        'phone' => $phone
                    ];
                }
                break;

            case 'imei':
                $deviceType = sanitize($_POST['device_type'] ?? 'هاتف');
                $brand = sanitize($_POST['brand'] ?? '');
                $color = sanitize($_POST['color'] ?? '');
                $imei = trim($_POST['imei'] ?? '');
                $title = sanitize($_POST['title'] ?? '');
                $location = sanitize($_POST['location'] ?? '');
                $phone = sanitize($_POST['phone'] ?? '');
                $altPhone = sanitize($_POST['alt_phone'] ?? '');

                if (empty($imei) || empty($location) || empty($phone)) {
                    $error = 'يرجى ملء رقم IMEI/الرقم التسلسلي، والمنطقة، ورقم التواصل.';
                } else {
                    $fullTitle = $brand . ' ' . $deviceType . ' (' . $color . ') - IMEI: ' . $imei;
                    if (!empty($title)) {
                        $fullTitle = $title . ' - IMEI: ' . $imei;
                    }
                    $cleanedImei = preg_replace('/[\s\-\.]/', '', $imei);
                    $imeiHash = hashIdentifier($cleanedImei);

                    $stmt = $db->prepare("
                        INSERT INTO items (
                            type, category, identifier_hash, title_or_name, normalized_name,
                            location, location_area, contact_info, contact_alt, status, ip_address
                        ) VALUES (
                            'lost', 'phone', :hash, :title, :norm,
                            :loc, :area, :phone, :alt, 'active', :ip
                        )
                    ");
                    $stmt->bindValue(':hash', $imeiHash);
                    $stmt->bindValue(':title', $fullTitle);
                    $stmt->bindValue(':norm', normalizeArabic($fullTitle));
                    $stmt->bindValue(':loc', $location);
                    $stmt->bindValue(':area', $location);
                    $stmt->bindValue(':phone', $phone);
                    $stmt->bindValue(':alt', $altPhone ?: null);
                    $stmt->bindValue(':ip', $clientIP);
                    $stmt->execute();
                    $newItemId = (int)$db->lastInsertId();

                    logAction('imei_reported', "IMEI: {$imei}");
                    $result = [
                        'type' => 'imei',
                        'id' => $newItemId,
                        'title' => $fullTitle,
                        'imei' => $imei,
                        'location' => $location
                    ];
                }
                break;

            case 'bulk':
                $bulkData = trim($_POST['bulk_data'] ?? '');
                $location = sanitize($_POST['location'] ?? '');
                $dropPoint = sanitize($_POST['drop_point'] ?? '');
                $orgName = sanitize($_POST['org_name'] ?? 'مؤسسة طبية/إيوائية');

                if (empty($bulkData) || empty($location)) {
                    $error = 'يرجى لصق قائمة الهويات وتحديد مكان الاستلام.';
                } else {
                    $lines = explode("\n", $bulkData);
                    $parsedItems = [];
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if (empty($line)) continue;
                        $parts = explode('|', $line, 2);
                        $idNum = trim($parts[0] ?? '');
                        $pName = trim($parts[1] ?? '');
                        if (!empty($idNum) || !empty($pName)) {
                            $parsedItems[] = [
                                'identifier' => $idNum,
                                'name' => $pName,
                                'category' => 'id_card'
                            ];
                        }
                    }

                    if (!empty($parsedItems)) {
                        $inserted = bulkInsertItems($parsedItems, $location, $dropPoint ?: $orgName, $clientIP);
                        $result = [
                            'type' => 'bulk',
                            'count' => $inserted,
                            'org' => $orgName,
                            'location' => $location,
                            'drop_point' => $dropPoint ?: $orgName
                        ];
                    } else {
                        $error = 'لم يتم العثور على أسطر صالحة في القائمة. يرجى التأكد من التنسيق (رقم الهوية | الاسم).';
                    }
                }
                break;

            case 'transport':
                $fromArea = sanitize($_POST['from_area'] ?? '');
                $toArea = sanitize($_POST['to_area'] ?? '');
                $travelDate = sanitize($_POST['travel_date'] ?? date('Y-m-d'));
                $timeRange = sanitize($_POST['time_range'] ?? '');
                $vehicleType = sanitize($_POST['vehicle_type'] ?? 'تكتك');
                $vehicleColor = sanitize($_POST['vehicle_color'] ?? '');
                $itemDesc = sanitize($_POST['item_description'] ?? '');
                $phone = sanitize($_POST['phone'] ?? '');
                $altPhone = sanitize($_POST['alt_phone'] ?? '');

                if (empty($fromArea) || empty($toArea) || empty($itemDesc) || empty($phone)) {
                    $error = 'يرجى تحديد خط السير ووصف الغرض ورقم التواصل.';
                } else {
                    $routeTitle = "مفقود في المواصلات ({$fromArea} ← {$toArea}) - {$itemDesc}";
                    $route = "من {$fromArea} إلى {$toArea}";
                    $vehicle = "{$vehicleType} " . ($vehicleColor ? "لون {$vehicleColor}" : '');

                    $stmt = $db->prepare("
                        INSERT INTO items (
                            type, category, title_or_name, normalized_name, location, location_area,
                            transport_route, transport_time, transport_vehicle, contact_info, contact_alt,
                            status, ip_address
                        ) VALUES (
                            'lost', 'other', :title, :norm, :loc, :area,
                            :route, :time, :vehicle, :phone, :alt,
                            'active', :ip
                        )
                    ");
                    $stmt->bindValue(':title', $routeTitle);
                    $stmt->bindValue(':norm', normalizeArabic($routeTitle));
                    $stmt->bindValue(':loc', "خط سير: {$route}");
                    $stmt->bindValue(':area', $fromArea);
                    $stmt->bindValue(':route', $route);
                    $stmt->bindValue(':time', "{$travelDate} {$timeRange}");
                    $stmt->bindValue(':vehicle', $vehicle);
                    $stmt->bindValue(':phone', $phone);
                    $stmt->bindValue(':alt', $altPhone ?: null);
                    $stmt->bindValue(':ip', $clientIP);
                    $stmt->execute();
                    $newItemId = (int)$db->lastInsertId();

                    logAction('transport_reported', "ID: {$newItemId}");
                    $result = [
                        'type' => 'transport',
                        'id' => $newItemId,
                        'title' => $routeTitle,
                        'route' => $route,
                        'vehicle' => $vehicle
                    ];
                }
                break;
        }
    }
}

if (!function_exists('generateWhatsAppShareText')) {
    function generateWhatsAppShareText(?array $res): string {
        if (!$res) return '';
        switch ($res['type'] ?? '') {
            case 'lost':
                return "🔴 مفقود في قطاع غزة: {$res['title']} - المنطقة: {$res['location']}\nلمن يجده يرجى التواصل عبر منصة (فَقَدتُ ووَجَدتُ).";
            case 'found':
                $dp = !empty($res['drop_point']) ? " - مكان التسليم: {$res['drop_point']}" : "";
                return "🟢 بشرى سارة: عُثر على {$res['title']} في {$res['location']}{$dp}\nلصاحب الغرض، راجع منصة (فَقَدتُ ووَجَدتُ).";
            case 'tag':
                return "🏷️ كود الأمانة الوقائي الخاص بي [{$res['tag_code']}]. إذا عثرت على هذا الغرض، ابحث برقم الكود في منصة (فَقَدتُ ووَجَدتُ).";
            case 'imei':
                return "📱 جهاز مفقود: {$res['title']}\nتنبيه لأصحاب المحلات والبسطات قبل الشراء عبر منصة (فَقَدتُ ووَجَدتُ).";
            case 'bulk':
                return "📦 تم رفع كشف يضم {$res['count']} وثيقة مفقودة في {$res['location']} ومودعة لدى {$res['drop_point']}.";
            case 'transport':
                return "🚗 مفقود في المواصلات: {$res['title']} ({$res['vehicle']}) - راجع منصة (فَقَدتُ ووَجَدتُ).";
            default:
                return '';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <meta name="theme-color" content="#0d9488">
    <title><?= htmlspecialchars($pageTitles[$type] ?? 'تسجيل بلاغ') ?> - فَقَدتُ ووَجَدتُ</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="manifest" href="manifest.json">
    <link rel="icon" type="image/svg+xml" href="assets/logo.svg">
    <link rel="apple-touch-icon" href="assets/logo.svg">
</head>
<body>

<!-- Navbar -->
<nav class="navbar-top">
    <div class="container navbar-inner">
        <a href="index.php" class="brand-wrap">
            <img src="assets/logo.svg" alt="شعار فَقَدتُ ووَجَدتُ" style="width: 40px; height: 40px;">
            <div>
                <div class="brand-title">فَقَدتُ ووَجَدتُ</div>
                <div class="brand-sub">منصة طوارئ مجتمعية - غزة</div>
            </div>
        </a>
        <div class="nav-links">
            <a href="index.php" class="nav-link">🏠 الرئيسية والبحث</a>
            <a href="index.php#browseSection" class="nav-link">📋 تصفح البلاغات</a>
            <a href="report.php?type=lost" class="nav-link btn-nav-add">➕ أضف بلاغاً</a>
        </div>
    </div>
</nav>

<div class="container" style="padding-top: 2rem; padding-bottom: 3rem;">

    <!-- Types Navigation Bar -->
    <div class="filter-tabs" style="justify-content: center; margin-bottom: 2rem;">
        <a href="report.php?type=lost" class="filter-tab <?= $type === 'lost' ? 'active' : '' ?>">📋 بلاغ مفقود</a>
        <a href="report.php?type=found" class="filter-tab <?= $type === 'found' ? 'active' : '' ?>">🤲 أمانة معثور عليها</a>
        <a href="report.php?type=tag" class="filter-tab <?= $type === 'tag' ? 'active' : '' ?>">🏷️ كود الأمانة</a>
        <a href="report.php?type=imei" class="filter-tab <?= $type === 'imei' ? 'active' : '' ?>">📱 فحص IMEI</a>
        <a href="report.php?type=transport" class="filter-tab <?= $type === 'transport' ? 'active' : '' ?>">🚗 مفقودات المواصلات</a>
        <a href="report.php?type=bulk" class="filter-tab <?= $type === 'bulk' ? 'active' : '' ?>">📦 كشف جماعي</a>
    </div>

    <!-- Error Alert -->
    <?php if ($error): ?>
        <div class="alert alert-danger">
            <span>⚠️ <?= htmlspecialchars($error) ?></span>
        </div>
    <?php endif; ?>

    <!-- Success Result & Radar Match Alert -->
    <?php if ($result): ?>
        <div class="alert alert-success" style="flex-direction: column;">
            <div style="font-size: 1.1rem; font-weight: 800; margin-bottom: 0.5rem;">
                ✅ تم حفظ البلاغ بنجاح في قاعدة البيانات!
            </div>
            <p>رقم المرجع في النظام: <strong>#<?= htmlspecialchars($result['id'] ?? $result['tag_code'] ?? '0') ?></strong></p>
            
            <?php if (!empty($radarMatch)): ?>
                <!-- RADAR MATCH ALERT -->
                <div style="background: #ffffff; border: 2px dashed #059669; border-radius: 12px; padding: 1.25rem; margin-top: 1rem; width: 100%;">
                    <h3 style="color: #065f46; font-size: 1.2rem; margin-bottom: 0.5rem;">🎉 بشرى سارة! صاحب هذه الهوية مسجل في الرادار ويبحث عنها!</h3>
                    <p style="color: #374151; margin-bottom: 0.75rem;">الاسم المسجل: <strong><?= htmlspecialchars($radarMatch['target_name'] ?? 'الفاقد') ?></strong></p>
                    <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
                        <a href="tel:<?= htmlspecialchars($radarMatch['subscriber_phone']) ?>" class="btn btn-primary">
                            📞 اتصل بصاحب الغرض: <?= htmlspecialchars($radarMatch['subscriber_phone']) ?>
                        </a>
                        <?php if (!empty($radarMatch['alt_phone'])): ?>
                            <a href="tel:<?= htmlspecialchars($radarMatch['alt_phone']) ?>" class="btn btn-outline-primary">
                                📞 هاتف بديل: <?= htmlspecialchars($radarMatch['alt_phone']) ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($generatedTag)): ?>
                <!-- TAG GENERATION DISPLAY -->
                <div style="background: #ffffff; border: 3px dashed var(--primary); border-radius: 16px; padding: 1.5rem; text-align: center; margin-top: 1rem; width: 100%;">
                    <p style="font-size: 0.9rem; color: var(--slate-600); margin-bottom: 0.5rem;">رمز كود الأمانة الوقائي الخاص بك:</p>
                    <div style="font-size: 2.8rem; font-weight: 900; color: var(--primary-dark); font-family: monospace; letter-spacing: 4px;">
                        #<?= htmlspecialchars($generatedTag) ?>
                    </div>
                    <p style="font-size: 0.85rem; color: var(--slate-700); margin-top: 0.75rem;">
                        ✍️ اكتب هذا الرمز بقلم حبر أو اطبع ملصقاً وضعه على ظهر هويتك، حقيبتك، أو شاحنك.
                    </p>
                    <button class="btn btn-outline-primary btn-sm mt-2" onclick="window.print()">🖨️ طباعة بطاقة الكود</button>
                </div>
            <?php endif; ?>

            <!-- WhatsApp Share Button -->
            <div style="margin-top: 1.25rem;">
                <button class="btn btn-whatsapp" onclick="copyShareText(this, '<?= htmlspecialchars(addslashes(generateWhatsAppShareText($result))) ?>')">
                    📋 نسخ نص منسق للواتساب والتليجرام
                </button>
                <a href="index.php" class="btn btn-outline-primary" style="margin-right: 0.5rem;">العودة للرئيسية</a>
            </div>
        </div>
    <?php endif; ?>

    <!-- The Form Card -->
    <div class="form-card">
        <h2 style="font-size: 1.35rem; font-weight: 800; color: var(--slate-900); margin-bottom: 1.5rem; border-bottom: 2px solid var(--slate-100); padding-bottom: 0.75rem;">
            <?= htmlspecialchars($pageTitles[$type] ?? 'تسجيل بلاغ') ?>
        </h2>

        <form method="POST" action="report.php?type=<?= htmlspecialchars($type) ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <?php if ($type === 'lost' || $type === 'found'): ?>
                <div class="form-group">
                    <label class="form-label">تصنيف الغرض *</label>
                    <select name="category" class="form-control" required>
                        <?php foreach ($categories as $k => $v): ?>
                            <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">رقم الهوية أو الرقم التسلسلي (اختياري للبحث الفوري)</label>
                    <input type="text" name="identifier" class="form-control" placeholder="مثال: 405123456 أو رقم جواز السفر" dir="ltr">
                    <div class="form-text">🔒 لن يُعرض الرقم علناً؛ يتم تحويله إلى هاش رقمي مشفر لحماية الخصوصية ومطابقته فوراً.</div>
                </div>

                <div class="form-group">
                    <label class="form-label">الاسم الرباعي أو عنوان البلاغ *</label>
                    <input type="text" name="title" class="form-control" placeholder="مثال: أحمد محمد خليل النجار" required>
                </div>

                <div class="form-group">
                    <label class="form-label">المنطقة الجغرافية *</label>
                    <select name="location" class="form-control" required>
                        <option value="">-- اختر المنطقة في قطاع غزة --</option>
                        <?php foreach ($areas as $a): ?>
                            <option value="<?= htmlspecialchars($a) ?>"><?= htmlspecialchars($a) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($type === 'found'): ?>
                    <!-- Security Question For Valuables -->
                    <div style="background: var(--slate-50); border: 1.5px solid var(--slate-200); border-radius: 12px; padding: 1.25rem; margin-bottom: 1.25rem;">
                        <h4 style="font-weight: 800; color: var(--slate-800); margin-bottom: 0.5rem;">🔐 سؤال التحقق السري (للأموال والذهب والمقتنيات الثمينة)</h4>
                        <p class="form-text" style="margin-bottom: 0.75rem;">
                            إذا عثرت على مال أو حقيبة، لا تنشر مواصفاتها علناً! ضع سؤالاً لا يعرف إجابته إلا صاحبها الحقيقي (مثل: ما هو لون المحفظة وكم المبلغ تقريباً؟).
                        </p>
                        <div class="form-group">
                            <label class="form-label">سؤال التحقق (يظهر للباحثين)</label>
                            <input type="text" name="secret_question" class="form-control" placeholder="مثال: ما هي العلامة المميزة داخل الحقيبة؟">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">إجابة سؤال التحقق (مشفرة بـ Bcrypt ولا تظهر لأحد)</label>
                            <input type="text" name="secret_answer" class="form-control" placeholder="مثال: محفظة جلد بنية بداخلها 400 شيكل">
                        </div>
                    </div>

                    <!-- Drop Point Section -->
                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-weight: 700;">
                            <input type="checkbox" name="has_drop_point" id="dropCheck" onchange="toggleDropSection(this)">
                            <span>📦 سلّمتُ الغرض في نقطة أمانات معتمدة (صيدلية / مسجد / مستشفى)</span>
                        </label>
                    </div>

                    <div id="dropSection" class="form-group hidden">
                        <label class="form-label">اختر نقطة الاستلام المعتمدة</label>
                        <select name="drop_point" class="form-control">
                            <?php foreach ($dropPoints as $dp): ?>
                                <option value="<?= htmlspecialchars($dp) ?>"><?= htmlspecialchars($dp) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label class="form-label">رقم جوال للتواصل *</label>
                    <input type="tel" name="phone" class="form-control" placeholder="0599000000" dir="ltr" required>
                </div>

                <div class="form-group">
                    <label class="form-label">رقم بديل (قريب أو صديق للطوارئ)</label>
                    <input type="tel" name="alt_phone" class="form-control" placeholder="0569000000" dir="ltr">
                </div>

            <?php elseif ($type === 'tag'): ?>
                <div class="form-group">
                    <label class="form-label">اسم صاحب الغرض *</label>
                    <input type="text" name="owner_name" class="form-control" placeholder="الاسم الثلاثي أو الرباعي" required>
                </div>
                <div class="form-group">
                    <label class="form-label">وصف الغرض الذي تريد حمايته *</label>
                    <input type="text" name="item_description" class="form-control" placeholder="مثال: لابتوب ديل أسود + شاحن أصلي أو كرت هوية" required>
                </div>
                <div class="form-group">
                    <label class="form-label">رقم الجوال الشخصي *</label>
                    <input type="tel" name="phone" class="form-control" placeholder="0599000000" dir="ltr" required>
                </div>
                <div class="form-group">
                    <label class="form-label">رقم هاتف بديل لأحد الأقارب</label>
                    <input type="tel" name="alt_phone" class="form-control" placeholder="0569000000" dir="ltr">
                </div>

            <?php elseif ($type === 'imei'): ?>
                <div class="form-group">
                    <label class="form-label">نوع الجهاز *</label>
                    <select name="device_type" class="form-control" required>
                        <option value="هاتف ذكي">هاتف ذكي</option>
                        <option value="لابتوب">لابتوب</option>
                        <option value="تابلت / آيباد">تابلت / آيباد</option>
                        <option value="ساعة ذكية">ساعة ذكية</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">الماركة والموديل</label>
                    <input type="text" name="brand" class="form-control" placeholder="مثال: سامسونج Galaxy A52 أو آيفون 13">
                </div>
                <div class="form-group">
                    <label class="form-label">اللون</label>
                    <input type="text" name="color" class="form-control" placeholder="مثال: أزرق / أسود">
                </div>
                <div class="form-group">
                    <label class="form-label">رقم الـ IMEI أو الرقم التسلسلي (Serial Number) *</label>
                    <input type="text" name="imei" class="form-control" placeholder="15 رقماً (تظهر بالاتصال على #06#*)" dir="ltr" required>
                    <div class="form-text">💡 يُعتمد هذا السجل من قبل أصحاب محلات الصيانة وبسطات المستعمل لفحص أي جهاز قبل شرائه.</div>
                </div>
                <div class="form-group">
                    <label class="form-label">المنطقة التي فُقد فيها *</label>
                    <select name="location" class="form-control" required>
                        <?php foreach ($areas as $a): ?>
                            <option value="<?= htmlspecialchars($a) ?>"><?= htmlspecialchars($a) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">رقم التواصل *</label>
                    <input type="tel" name="phone" class="form-control" placeholder="0599000000" dir="ltr" required>
                </div>

            <?php elseif ($type === 'transport'): ?>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form-group">
                        <label class="form-label">منطقة الانطلاق *</label>
                        <select name="from_area" class="form-control" required>
                            <?php foreach ($areas as $a): ?>
                                <option value="<?= htmlspecialchars($a) ?>"><?= htmlspecialchars($a) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">منطقة الوصول *</label>
                        <select name="to_area" class="form-control" required>
                            <?php foreach ($areas as $a): ?>
                                <option value="<?= htmlspecialchars($a) ?>"><?= htmlspecialchars($a) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form-group">
                        <label class="form-label">تاريخ الركوب</label>
                        <input type="date" name="travel_date" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">الوقت التقريبي</label>
                        <input type="text" name="time_range" class="form-control" placeholder="مثال: بين 10:00 و 11:30 صباحاً">
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form-group">
                        <label class="form-label">نوع المركبة *</label>
                        <select name="vehicle_type" class="form-control" required>
                            <option value="تكتك">تكتك</option>
                            <option value="سيارة عمومي / ملاكي">سيارة عمومي / ملاكي</option>
                            <option value="عربة كارو">عربة كارو</option>
                            <option value="حافلة / باص">حافلة / باص</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">لون المركبة أو علامة مميزة</label>
                        <input type="text" name="vehicle_color" class="form-control" placeholder="مثال: تكتك أزرق بسقف قماش">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">وصف الغرض المنسي في المركبة *</label>
                    <input type="text" name="item_description" class="form-control" placeholder="مثال: كيس أدوية وكروكا طبية في المقعد الخلفي" required>
                </div>
                <div class="form-group">
                    <label class="form-label">رقم التواصل مع الراكب *</label>
                    <input type="tel" name="phone" class="form-control" placeholder="0599000000" dir="ltr" required>
                </div>

            <?php elseif ($type === 'bulk'): ?>
                <div class="form-group">
                    <label class="form-label">اسم المؤسسة / المستشفى / مركز الإيواء *</label>
                    <input type="text" name="org_name" class="form-control" placeholder="مثال: قسم الاستعلامات - مجمع ناصر الطبي" required>
                </div>
                <div class="form-group">
                    <label class="form-label">المنطقة الجغرافية *</label>
                    <select name="location" class="form-control" required>
                        <?php foreach ($areas as $a): ?>
                            <option value="<?= htmlspecialchars($a) ?>"><?= htmlspecialchars($a) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">مكان تسليم الأمانات بالتحديد للمواطنين *</label>
                    <input type="text" name="drop_point" class="form-control" placeholder="مثال: كشك الأمن عند بوابة الطوارئ الرئيسية" required>
                </div>
                <div class="form-group">
                    <label class="form-label">قائمة الوثائق والهويات (كل بطاقة في سطر منفصل) *</label>
                    <textarea name="bulk_data" rows="8" class="form-control" placeholder="التنسيق: رقم الهوية | الاسم الرباعي&#10;مثال:&#10;405123456 | أحمد محمد خليل النجار&#10;901234567 | محمود سعيد أبو عودة" required dir="ltr"></textarea>
                    <div class="form-text">✨ يمكنك نسخ عمودين من Excel ولصقهما مباشرة، أو كتابة: <code>رقم الهوية | الاسم</code>.</div>
                </div>
                <div class="form-group">
                    <label class="form-label">رقم هاتف مسؤول النقطة</label>
                    <input type="tel" name="phone" class="form-control" placeholder="0599000000" dir="ltr" value="0599000000">
                </div>
            <?php endif; ?>

            <div class="mt-3">
                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 0.9rem; font-size: 1.05rem;">
                    حفظ وإرسال البلاغ الآن
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Footer -->
<footer class="footer-wrap">
    <div class="container">
        <!-- MSN-IT UK Sponsor Section -->
        <div class="sponsor-box">
            <div class="sponsor-title">مبادرة مجتمعية تطوعية لإسناد أهلنا الصامدين في قطاع غزة</div>
            <div class="sponsor-company">
                <span>تم دعمه وتطويره برعاية شركة</span>
                <strong>MSN-IT UK</strong>
                <span class="sponsor-flag">🇬🇧</span>
            </div>
            <div class="sponsor-desc">
                حلول تكنولوجيا المعلومات المتقدمة والأنظمة السحابية - المملكة المتحدة
            </div>
        </div>

        <div class="footer-links">
            <a href="index.php">الرئيسية</a>
            <a href="index.php#browseSection">تصفح المفقودات</a>
            <a href="report.php?type=lost">تسجيل مفقود</a>
            <a href="report.php?type=found">تسجيل معثور عليه</a>
            <a href="report.php?type=tag">كود الأمانة</a>
        </div>
        <p class="footer-copy">منصة فَقَدتُ ووَجَدتُ - منصة طوارئ مجتمعية لوجه الله تعالى | صُمم بحب لأهل غزة 🇵🇸</p>
    </div>
</footer>

<script>
function toggleDropSection(chk) {
    var sec = document.getElementById('dropSection');
    if (sec) {
        sec.classList.toggle('hidden', !chk.checked);
    }
}

function copyShareText(btn, text) {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(function() {
            var old = btn.textContent;
            btn.textContent = '✅ تم النسخ!';
            setTimeout(function() { btn.textContent = old; }, 2000);
        });
    } else {
        var ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        btn.textContent = '✅ تم النسخ!';
        setTimeout(function() { btn.textContent = '📋 نسخ نص منسق للواتساب والتليجرام'; }, 2000);
    }
}

if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(function(){});
}
</script>
</body>
</html>
