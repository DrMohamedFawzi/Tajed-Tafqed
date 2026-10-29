<?php
/**
 * منصة فَقَدتُ ووَجَدتُ - قطاع غزة
 * Ultra-lightweight Community Lost & Found PWA
 * Main Homepage, Search Engine & Paginated Items Browser
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/matcher.php';

setSecurityHeaders();

$db = getDB();
$successCount = getSuccessCount();
$recentSuccesses = getRecentSuccesses(6);

// Areas list in Gaza Strip
$areas = [
    'خانيونس', 'دير البلح', 'النصيرات', 'رفح', 'المواصي',
    'غزة المدينة', 'جباليا', 'بيت لاهيا', 'بيت حانون',
    'الزوايدة', 'البريج', 'المغازي', 'عبسان', 'بني سهيلا', 'القرارة'
];

// Categories map
$categories = [
    'id_card' => ['name' => 'هوية شخصية', 'icon' => '🪪'],
    'passport' => ['name' => 'جواز سفر', 'icon' => '📕'],
    'unrwa_card' => ['name' => 'كرت وكالة', 'icon' => '🎫'],
    'phone' => ['name' => 'هاتف محمول', 'icon' => '📱'],
    'laptop' => ['name' => 'لابتوب / تابلت', 'icon' => '💻'],
    'bag' => ['name' => 'حقيبة / محفظة', 'icon' => '👜'],
    'document' => ['name' => 'شهادات ووثائق', 'icon' => '📄'],
    'money' => ['name' => 'نقود ومصاغ ذهبي', 'icon' => '💰'],
    'keys' => ['name' => 'مفاتيح', 'icon' => '🔑'],
    'other' => ['name' => 'أغراض أخرى', 'icon' => '📦']
];

// Pagination & Filtering for Browse Section
$currentPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 6;
$filterType = isset($_GET['filter_type']) && in_array($_GET['filter_type'], ['lost', 'found'], true) ? $_GET['filter_type'] : null;
$filterCategory = isset($_GET['filter_category']) && !empty($_GET['filter_category']) ? sanitize($_GET['filter_category']) : null;
$filterArea = isset($_GET['filter_area']) && !empty($_GET['filter_area']) ? sanitize($_GET['filter_area']) : null;

$totalItems = getPaginatedCount($filterType, $filterCategory, $filterArea, 'active');
$totalPages = max(1, (int)ceil($totalItems / $perPage));
$currentPage = min($currentPage, $totalPages);
$paginatedItems = getPaginatedItems($currentPage, $perPage, $filterType, $filterCategory, $filterArea, 'active');

if (!function_exists('formatCardTime')) {
    function formatCardTime($datetime): string {
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
            return 'مؤخراً';
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
    <meta name="description" content="منصة طوارئ مجتمعية لإدارة المفقودات والأمانات في قطاع غزة - سرعة فائقة وحماية تامة">
    <title>فَقَدتُ ووَجَدتُ - منصة المفقودات والأمانات | غزة</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="manifest" href="manifest.json">
    <link rel="icon" type="image/svg+xml" href="assets/logo.svg">
    <link rel="apple-touch-icon" href="assets/logo.svg">
</head>
<body>

<!-- Splash Screen ("غزة لسا فيها خير") -->
<div id="splashScreen" class="splash-overlay">
    <div class="splash-logo-wrap">
        <img src="assets/logo.svg" alt="شعار فَقَدتُ ووَجَدتُ" style="width: 100%; height: 100%;">
    </div>
    <div class="splash-title">فَقَدتُ ووَجَدتُ</div>
    <div class="splash-motto">
        <span>✨</span>
        <span>غزة لسا فيها خير 🇵🇸</span>
        <span>✨</span>
    </div>
    <div class="splash-progress-track">
        <div id="splashProgressFill" class="splash-progress-fill"></div>
    </div>
    <div id="splashLoadingText" class="splash-loading-text">جاري تهيئة المنصة المجتمعية...</div>
    <button type="button" class="splash-skip-btn" onclick="dismissSplash()">تخطي والدخول الفوري ◀</button>
</div>

<!-- Modern Top Navbar -->
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
            <a href="index.php" class="nav-link active" onclick="goToHome(event)">🏠 الرئيسية</a>
            <a href="#browseSection" class="nav-link">📋 تصفح البلاغات</a>
            <a href="report.php?type=tag" class="nav-link">🏷️ كود الأمانة</a>
            <a href="report.php?type=lost" class="nav-link btn-nav-add">➕ أضف بلاغاً</a>
        </div>
    </div>
</nav>

<!-- Header & Counter -->
<header class="hero-header">
    <div class="container">
        <div class="hero-badge">
            <span>🤲</span>
            <span>تمت إعادة <strong style="color: #fde047; font-size: 1.15rem;" id="successCounter"><?= (int)$successCount ?></strong> أمانة لأصحابها بحمد الله</span>
        </div>
        <h1 style="font-size: 2.2rem; font-weight: 900; margin-bottom: 0.5rem; letter-spacing: -0.5px;">
            منصة "فَقَدتُ ووَجَدتُ" في قطاع غزة
        </h1>
        <p style="font-size: 1rem; opacity: 0.9; max-width: 620px; margin: 0 auto;">
            محرك طوارئ مجتمعي فائق الخفة لإعادة الوثائق والمقتنيات المفقودة في ظروف الاتصال الضعيف
        </p>
    </div>
</header>

<!-- Live Success Ticker -->
<?php if (!empty($recentSuccesses)): ?>
<div class="ticker-bar">
    <div class="ticker-track">
        <?php foreach ($recentSuccesses as $s): ?>
            <span>🎉 تمت إعادة <strong><?= htmlspecialchars($s['category']) ?></strong> في <?= htmlspecialchars($s['location']) ?> (<?= htmlspecialchars($s['time_ago']) ?>)</span>
        <?php endforeach; ?>
        <?php foreach ($recentSuccesses as $s): ?>
            <span>🎉 تمت إعادة <strong><?= htmlspecialchars($s['category']) ?></strong> في <?= htmlspecialchars($s['location']) ?> (<?= htmlspecialchars($s['time_ago']) ?>)</span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Main Search Hero Card -->
<div class="container">
    <div class="search-card">
        <h2 style="font-size: 1.3rem; font-weight: 800; color: var(--slate-900); margin-bottom: 1rem; text-align: center;">
            🔍 ابحث عن مفقوداتك فوراً بـ "الرقم الحاسم" أو الاسم
        </h2>
        <form id="searchForm" onsubmit="return handleSearchSubmit(event)">
            <div class="search-input-group">
                <input 
                    type="text" 
                    id="mainSearchInput" 
                    class="search-input" 
                    placeholder="اكتب رقم الهوية، رقم الجواز، IMEI، كود الأمانة، أو الاسم الرباعي..." 
                    autocomplete="off"
                    required
                    dir="rtl"
                >
                <button type="submit" class="btn-search" id="mainSearchBtn">
                    <span>ابحث الآن</span>
                </button>
            </div>
        </form>
        <p style="text-align: center; font-size: 0.8rem; color: var(--slate-600); margin-top: 0.75rem;">
            ⚡ البحث خفيف جداً ويستهلك أقل من 5 كيلوبايت - يعمل حتى مع أضعف إشارة شبكة
        </p>

        <!-- Dynamic Search Results -->
        <div id="liveSearchResults" class="hidden mt-3"></div>
    </div>
</div>

<!-- Quick Action Tiles -->
<div class="container">
    <div class="actions-grid">
        <a href="report.php?type=lost" class="action-tile">
            <div class="action-icon">📋</div>
            <div class="action-title">تسجيل بلاغ مفقود</div>
            <div class="action-desc">أضف مواصفات ما فقدته ليعثر عليه غيرك</div>
        </a>
        <a href="report.php?type=found" class="action-tile">
            <div class="action-icon">🤲</div>
            <div class="action-title">تسجيل أمانة معثور عليها</div>
            <div class="action-desc">سجّل ما وجدته بأمان وسؤال تحقق سري</div>
        </a>
        <a href="report.php?type=tag" class="action-tile">
            <div class="action-icon">🏷️</div>
            <div class="action-title">كود الأمانة الوقائي</div>
            <div class="action-desc">أنشئ كوداً لحماية أجهزتك وحقيبتك مسبقاً</div>
        </a>
        <a href="report.php?type=imei" class="action-tile">
            <div class="action-icon">📱</div>
            <div class="action-title">فاحص أجهزة IMEI</div>
            <div class="action-desc">افحص أي هاتف مستعمل قبل شرائه</div>
        </a>
    </div>
</div>

<!-- Paginated Items Browser (تقسيم الصفحات وتصفح البلاغات) -->
<section id="browseSection" class="container" style="margin-bottom: 3.5rem;">
    <div class="section-header">
        <div>
            <h3 class="section-title">
                <span>📑</span>
                <span>سجل البلاغات والأمانات النشطة</span>
            </h3>
            <p style="font-size: 0.85rem; color: var(--slate-600); margin-top: 0.2rem;">
                تصفح البلاغات المسجلة في مختلف مناطق القطاع (إجمالي: <?= $totalItems ?> بلاغاً)
            </p>
        </div>

        <!-- Filter Type Tabs -->
        <div class="filter-tabs">
            <a href="index.php?filter_type=&filter_category=<?= urlencode((string)$filterCategory) ?>&filter_area=<?= urlencode((string)$filterArea) ?>#browseSection" class="filter-tab <?= empty($filterType) ? 'active' : '' ?>">الكل (<?= getPaginatedCount(null, $filterCategory, $filterArea) ?>)</a>
            <a href="index.php?filter_type=lost&filter_category=<?= urlencode((string)$filterCategory) ?>&filter_area=<?= urlencode((string)$filterArea) ?>#browseSection" class="filter-tab <?= $filterType === 'lost' ? 'active' : '' ?>">🔴 مفقودات</a>
            <a href="index.php?filter_type=found&filter_category=<?= urlencode((string)$filterCategory) ?>&filter_area=<?= urlencode((string)$filterArea) ?>#browseSection" class="filter-tab <?= $filterType === 'found' ? 'active' : '' ?>">🟢 معثورات</a>
        </div>
    </div>

    <!-- Category & Area Filters Form -->
    <form method="GET" action="index.php#browseSection" class="filters-bar">
        <?php if (!empty($filterType)): ?>
            <input type="hidden" name="filter_type" value="<?= htmlspecialchars($filterType) ?>">
        <?php endif; ?>

        <select name="filter_category" class="filter-select" onchange="this.form.submit()">
            <option value="">-- جميع التصنيفات --</option>
            <?php foreach ($categories as $k => $c): ?>
                <option value="<?= htmlspecialchars($k) ?>" <?= $filterCategory === $k ? 'selected' : '' ?>>
                    <?= $c['icon'] ?> <?= htmlspecialchars($c['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="filter_area" class="filter-select" onchange="this.form.submit()">
            <option value="">-- جميع المناطق (قطاع غزة) --</option>
            <?php foreach ($areas as $a): ?>
                <option value="<?= htmlspecialchars($a) ?>" <?= $filterArea === $a ? 'selected' : '' ?>>
                    📍 <?= htmlspecialchars($a) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <?php if (!empty($filterCategory) || !empty($filterArea) || !empty($filterType)): ?>
            <a href="index.php#browseSection" class="btn btn-outline-primary btn-sm" style="height: 38px;">
                🔄 إعادة تعيين الفلاتر
            </a>
        <?php endif; ?>
    </form>

    <!-- Items Grid -->
    <?php if (empty($paginatedItems)): ?>
        <div style="background: #ffffff; border: 1.5px dashed var(--slate-300); border-radius: 16px; padding: 3rem 1rem; text-align: center;">
            <div style="font-size: 3rem; margin-bottom: 0.5rem;">🔍</div>
            <h4 style="font-size: 1.15rem; font-weight: 800; color: var(--slate-800); margin-bottom: 0.5rem;">
                لا توجد بلاغات تطابق الفلاتر المحددة حالياً
            </h4>
            <p style="font-size: 0.9rem; color: var(--slate-600); margin-bottom: 1.25rem;">
                يمكنك تسجيل بلاغ جديد أو إزالة الفلاتر لرؤية باقي الأمانات.
            </p>
            <a href="report.php?type=lost" class="btn btn-primary">➕ إضافة بلاغ جديد</a>
        </div>
    <?php else: ?>
        <div class="items-grid">
            <?php foreach ($paginatedItems as $item): ?>
                <?php
                $catInfo = $categories[$item['category']] ?? ['name' => $item['category'], 'icon' => '📦'];
                $isLost = $item['type'] === 'lost';
                $cardClass = $isLost ? 'type-lost' : 'type-found';
                $badgeClass = $isLost ? 'badge-lost' : 'badge-found';
                $badgeText = $isLost ? 'مفقود' : 'معثور عليه';
                $hasSecret = !empty($item['secret_question']);
                $waShareText = "🔴 بلاغ [" . ($isLost ? 'مفقود' : 'معثور عليه') . "] على منصة فقدت ووجدت: " . $item['title_or_name'] . " - المكان: " . ($item['location'] ?: 'قطاع غزة');
                ?>
                <div class="item-card <?= $cardClass ?>">
                    <div>
                        <div class="item-card-header">
                            <span class="badge <?= $badgeClass ?>">
                                <span><?= $isLost ? '🔴' : '🟢' ?></span>
                                <span><?= $badgeText ?></span>
                                <span>•</span>
                                <span><?= $catInfo['name'] ?></span>
                            </span>
                            <span class="item-time">⏱️ <?= formatCardTime($item['created_at']) ?></span>
                        </div>

                        <h4 class="item-title">
                            <?= $catInfo['icon'] ?> <?= htmlspecialchars($item['title_or_name']) ?>
                        </h4>

                        <div class="item-meta">
                            <div class="item-meta-row">
                                <span>📍</span>
                                <span><?= htmlspecialchars($item['location'] ?: ($item['location_area'] ?: 'قطاع غزة')) ?></span>
                            </div>
                            <?php if (!empty($item['transport_route'])): ?>
                                <div class="item-meta-row" style="color: #0369a1;">
                                    <span>🚗</span>
                                    <span><?= htmlspecialchars($item['transport_route']) ?> (<?= htmlspecialchars($item['transport_vehicle'] ?? '') ?>)</span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($item['drop_point'])): ?>
                            <div class="drop-point-pill">
                                <span>📦 نقطة التسليم:</span>
                                <strong><?= htmlspecialchars($item['drop_point']) ?></strong>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="item-card-footer">
                        <?php if ($hasSecret): ?>
                            <button class="btn btn-outline-primary btn-sm" onclick="openVerifyModal(<?= (int)$item['id'] ?>, '<?= htmlspecialchars(addslashes($item['secret_question'])) ?>')">
                                🔐 التحقق من الملكية
                            </button>
                        <?php elseif (!empty($item['contact_info'])): ?>
                            <a href="tel:<?= htmlspecialchars($item['contact_info']) ?>" class="btn btn-primary btn-sm">
                                📞 <?= htmlspecialchars(maskPhone($item['contact_info'])) ?>
                            </a>
                        <?php endif; ?>

                        <button class="btn btn-whatsapp btn-sm" onclick="copyShareText(this, '<?= htmlspecialchars(addslashes($waShareText)) ?>')">
                            📋 واتساب
                        </button>

                        <button class="btn btn-outline-primary btn-sm" style="color: #059669; border-color: #a7f3d0;" onclick="confirmResolve(<?= (int)$item['id'] ?>)">
                            🤲 تم الاستلام
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Pagination Controls (تقسيم الصفحات) -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination-container">
                <!-- Previous Button -->
                <a href="index.php?page=<?= max(1, $currentPage - 1) ?>&filter_type=<?= urlencode((string)$filterType) ?>&filter_category=<?= urlencode((string)$filterCategory) ?>&filter_area=<?= urlencode((string)$filterArea) ?>#browseSection" class="page-btn <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                    السابق ◀
                </a>

                <!-- Numbered Pages -->
                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <?php if ($p === 1 || $p === $totalPages || abs($p - $currentPage) <= 2): ?>
                        <a href="index.php?page=<?= $p ?>&filter_type=<?= urlencode((string)$filterType) ?>&filter_category=<?= urlencode((string)$filterCategory) ?>&filter_area=<?= urlencode((string)$filterArea) ?>#browseSection" class="page-btn <?= $p === $currentPage ? 'active' : '' ?>">
                            <?= $p ?>
                        </a>
                    <?php elseif (abs($p - $currentPage) === 3): ?>
                        <span class="page-btn disabled">...</span>
                    <?php endif; ?>
                <?php endfor; ?>

                <!-- Next Button -->
                <a href="index.php?page=<?= min($totalPages, $currentPage + 1) ?>&filter_type=<?= urlencode((string)$filterType) ?>&filter_category=<?= urlencode((string)$filterCategory) ?>&filter_area=<?= urlencode((string)$filterArea) ?>#browseSection" class="page-btn <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                    ▶ التالي
                </a>
            </div>
            <p style="text-align: center; font-size: 0.8rem; color: var(--slate-600);">
                عرض الصفحة <?= $currentPage ?> من أصل <?= $totalPages ?> (<?= $totalItems ?> بلاغاً نشطاً)
            </p>
        <?php endif; ?>
    <?php endif; ?>
</section>

<!-- Safety Tag Quick Lookup Section -->
<section style="background: #ffffff; border-top: 1px solid var(--slate-200); border-bottom: 1px solid var(--slate-200); padding: 3rem 1rem;">
    <div class="container" style="max-width: 680px; text-align: center;">
        <span style="font-size: 2.5rem;">🏷️</span>
        <h3 style="font-size: 1.4rem; font-weight: 800; color: var(--slate-900); margin: 0.5rem 0 0.5rem;">
            هل عثرت على غرض يحمل كود أمانة؟
        </h3>
        <p style="font-size: 0.9rem; color: var(--slate-600); margin-bottom: 1.5rem;">
            أدخل رمز الـ 4 أرقام المكتوب على ظهر الغرض أو كود الـ QR للوصول الفوري لهاتف صاحبه:
        </p>
        <form onsubmit="return handleTagLookup(event)" style="display: flex; gap: 0.5rem; justify-content: center; flex-wrap: wrap;">
            <input 
                type="text" 
                id="tagLookupInput" 
                class="form-control" 
                style="max-width: 280px; text-align: center; font-family: monospace; font-size: 1.25rem; font-weight: 800; letter-spacing: 2px;"
                placeholder="GZ-4920" 
                dir="ltr"
                required
            >
            <button type="submit" class="btn btn-primary" id="tagLookupBtn">🏷️ فحص الكود</button>
        </form>
        <div id="tagLookupResult" class="hidden mt-2"></div>
    </div>
</section>

<!-- Area Poster Print Section -->
<section class="container" style="padding: 3rem 1rem; text-align: center; max-width: 680px;">
    <span style="font-size: 2.5rem;">🖨️</span>
    <h3 style="font-size: 1.4rem; font-weight: 800; color: var(--slate-900); margin: 0.5rem 0 0.5rem;">
        طباعة نشرة مفقودات الحي (للمساجد ونقاط الشحن والأفران)
    </h3>
    <p style="font-size: 0.9rem; color: var(--slate-600); margin-bottom: 1.5rem;">
        توليد صفحة A4 بيضاء وسوداء بالكامل (لتوفير الحبر) تحتوي على كافة مفقودات الحي لتعليقها على الجدران:
    </p>
    <div style="display: flex; gap: 0.5rem; justify-content: center; flex-wrap: wrap;">
        <select id="posterAreaSelect" class="form-control" style="max-width: 280px;">
            <option value="">-- اختر المنطقة لطباعة نشرتها --</option>
            <?php foreach ($areas as $a): ?>
                <option value="<?= htmlspecialchars($a) ?>"><?= htmlspecialchars($a) ?></option>
            <?php endforeach; ?>
        </select>
        <button onclick="printNeighborhoodPoster()" class="btn btn-primary">🖨️ طباعة النشرة الآن</button>
    </div>
</section>

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
            <a href="index.php" onclick="goToHome(event)">الرئيسية</a>
            <a href="#browseSection">سجل البلاغات</a>
            <a href="report.php?type=lost">تسجيل مفقود</a>
            <a href="report.php?type=found">تسجيل معثور عليه</a>
            <a href="report.php?type=tag">كود الأمانة</a>
            <a href="report.php?type=imei">فحص IMEI</a>
            <a href="report.php?type=bulk">كشف جماعي للمستشفيات</a>
        </div>
        <p class="footer-copy">
            منصة "فَقَدتُ ووَجَدتُ" - منصة طوارئ مجتمعية لوجه الله تعالى | صُممت بحب لأهلنا الصامدين في قطاع غزة 🇵🇸
        </p>
    </div>
</footer>

<!-- Interactive Verification Modal -->
<div id="verifyModal" class="modal-backdrop">
    <div class="modal-box">
        <h3 style="font-size: 1.25rem; font-weight: 800; color: var(--slate-900); margin-bottom: 0.75rem;">
            🔐 سؤال التحقق السري من الملكية
        </h3>
        <p id="modalSecretQuestion" style="background: var(--slate-100); border-radius: 8px; padding: 0.75rem; font-size: 0.95rem; font-weight: 600; color: var(--slate-800); margin-bottom: 1rem;">
        </p>
        <input type="hidden" id="modalItemId">
        <div class="form-group">
            <label class="form-label">أدخل إجابتك السرية لإثبات ملكيتك للأمانة *</label>
            <input type="text" id="modalSecretAnswer" class="form-control" placeholder="اكتب إجابتك بدقة...">
        </div>
        <div id="modalVerifyResult" class="hidden mb-1"></div>
        <div style="display: flex; gap: 0.5rem; justify-content: flex-end; margin-top: 1rem;">
            <button class="btn btn-outline-primary" onclick="closeVerifyModal()">إلغاء</button>
            <button class="btn btn-primary" id="modalVerifyBtn" onclick="submitModalVerify()">تأكيد وإظهار الهاتف</button>
        </div>
    </div>
</div>

<script>
// Live Search Implementation
function handleSearchSubmit(e) {
    e.preventDefault();
    var q = document.getElementById('mainSearchInput').value.trim();
    if (q.length < 2) return false;

    var btn = document.getElementById('mainSearchBtn');
    var resBox = document.getElementById('liveSearchResults');
    btn.disabled = true;
    btn.innerHTML = '<span>جارٍ البحث...</span>';
    resBox.classList.remove('hidden');
    resBox.innerHTML = '<div style="text-align:center; padding: 1.5rem; color: var(--primary);">⏳ جاري استرجاع النتائج...</div>';

    fetch('api/search.php?q=' + encodeURIComponent(q))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            btn.disabled = false;
            btn.innerHTML = '<span>ابحث الآن</span>';

            if (data.results && data.results.length > 0) {
                var html = '<h4 style="font-weight:800; margin-bottom:0.75rem; color:var(--slate-800);">📋 نتائج البحث المباشرة (' + data.results.length + '):</h4>';
                data.results.forEach(function(item) {
                    var isLost = item.type === 'lost';
                    var badgeClass = isLost ? 'badge-lost' : 'badge-found';
                    var badgeText = item.type_label || (isLost ? 'مفقود' : 'معثور عليه');

                    html += '<div style="background:#ffffff; border:1px solid var(--slate-200); border-radius:12px; padding:1rem; margin-bottom:0.75rem; box-shadow:var(--shadow-sm); text-align:right;">';
                    html += '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.5rem;">';
                    html += '<span class="badge ' + badgeClass + '">' + badgeText + ' • ' + (item.category_label || item.category) + '</span>';
                    html += '<span style="font-size:0.75rem; color:var(--slate-600);">' + (item.time_ago || '') + '</span>';
                    html += '</div>';
                    html += '<div style="font-size:1.05rem; font-weight:800; color:var(--slate-900); margin-bottom:0.35rem;">' + escHtml(item.title) + '</div>';
                    html += '<div style="font-size:0.85rem; color:var(--slate-600); margin-bottom:0.5rem;">📍 ' + escHtml(item.location) + '</div>';

                    if (item.drop_point) {
                        html += '<div class="drop-point-pill" style="margin-bottom:0.5rem;">📦 مكان الاستلام: <strong>' + escHtml(item.drop_point) + '</strong></div>';
                    }

                    html += '<div style="display:flex; gap:0.5rem; flex-wrap:wrap; margin-top:0.5rem;">';
                    if (item.has_secret_question) {
                        html += '<button class="btn btn-outline-primary btn-sm" onclick="openVerifyModal(' + item.id + ', \'' + escAttr(item.secret_question) + '\')">🔐 التحقق من الملكية</button>';
                    } else if (item.contact_visible && item.contact_info) {
                        html += '<a href="tel:' + escAttr(item.contact_info) + '" class="btn btn-primary btn-sm">📞 ' + escHtml(item.contact_info) + '</a>';
                    }
                    if (item.whatsapp_text) {
                        html += '<button class="btn btn-whatsapp btn-sm" onclick="copyShareText(this, \'' + escAttr(item.whatsapp_text) + '\')">📋 واتساب</button>';
                    }
                    html += '</div></div>';
                });
                resBox.innerHTML = html;
            } else {
                // No results - Offer reverse radar registration
                var html = '<div class="alert alert-warning" style="flex-direction:column; text-align:right;">';
                html += '<div style="font-weight:800; font-size:1.05rem; margin-bottom:0.4rem;">⚠️ لم يتم العثور على نتائج مطابقة لهذا الرقم حالياً</div>';
                html += '<p style="font-size:0.85rem; margin-bottom:0.75rem;">' + (data.radar_message || 'سجّل رقم جوالك لتفعيل الرادار العكسي، وسنبلغك فوراً عند تسجيل أي شخص لنفس هذا الغرض!') + '</p>';
                html += '<form onsubmit="return handleRadarSubmit(event, \'' + escAttr(q) + '\')" style="display:flex; gap:0.5rem; flex-wrap:wrap; width:100%;">';
                html += '<input type="text" value="' + escAttr(q) + '" class="form-control" style="max-width:180px;" readonly>';
                html += '<input type="tel" name="phone" class="form-control" style="max-width:180px;" placeholder="رقم جوالك الشخصي" dir="ltr" required>';
                html += '<button type="submit" class="btn btn-primary btn-sm">📡 فعّل الرادار لنفس هذا الرقم</button>';
                html += '</form></div>';
                resBox.innerHTML = html;
            }
        })
        .catch(function() {
            btn.disabled = false;
            btn.innerHTML = '<span>ابحث الآن</span>';
            resBox.innerHTML = '<div class="alert alert-danger">حدث خطأ في الاتصال، يرجى المحاولة مجدداً.</div>';
        });

    return false;
}

// Radar Auto-Matching Registration
function handleRadarSubmit(e, query) {
    e.preventDefault();
    var form = e.target;
    var phone = form.phone.value.trim();
    if (!phone) return false;

    fetch('api/radar.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({identifier: query, phone: phone})
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            form.parentElement.innerHTML = '<div class="alert alert-success" style="font-weight:bold;">' + escHtml(data.message) + '</div>';
        } else {
            alert(data.error || 'حدث خطأ');
        }
    })
    .catch(function() { alert('خطأ في الاتصال'); });

    return false;
}

// Safety Tag Lookup
function handleTagLookup(e) {
    e.preventDefault();
    var code = document.getElementById('tagLookupInput').value.trim();
    var box = document.getElementById('tagLookupResult');
    var btn = document.getElementById('tagLookupBtn');

    box.classList.remove('hidden');
    box.innerHTML = '<div style="color:var(--primary);">⏳ جاري التحقق...</div>';

    fetch('api/tag_lookup.php?code=' + encodeURIComponent(code))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.found) {
                var html = '<div class="alert alert-success" style="text-align:right; flex-direction:column;">';
                html += '<div style="font-size:1.1rem; font-weight:800; margin-bottom:0.35rem;">🎉 تم العثور على صاحب الغرض!</div>';
                html += '<div>صاحب الغرض: <strong>' + escHtml(data.owner_name) + '</strong></div>';
                html += '<div>وصف الغرض: ' + escHtml(data.item_description || 'أمانة محمية') + '</div>';
                html += '<div style="margin-top:0.75rem;"><a href="tel:' + escAttr(data.phone) + '" class="btn btn-primary btn-sm">📞 اتصل به: ' + escHtml(data.phone) + '</a></div>';
                html += '</div>';
                box.innerHTML = html;
            } else {
                box.innerHTML = '<div class="alert alert-danger">' + escHtml(data.error || 'لم يتم العثور على هذا الكود') + '</div>';
            }
        })
        .catch(function() { box.innerHTML = '<div class="alert alert-danger">حدث خطأ في الاتصال.</div>'; });

    return false;
}

// Secret Question Verification Modal
function openVerifyModal(itemId, question) {
    document.getElementById('modalItemId').value = itemId;
    document.getElementById('modalSecretQuestion').textContent = question;
    document.getElementById('modalSecretAnswer').value = '';
    document.getElementById('modalVerifyResult').classList.add('hidden');
    document.getElementById('verifyModal').classList.add('show');
}

function closeVerifyModal() {
    document.getElementById('verifyModal').classList.remove('show');
}

function submitModalVerify() {
    var itemId = document.getElementById('modalItemId').value;
    var answer = document.getElementById('modalSecretAnswer').value.trim();
    var resBox = document.getElementById('modalVerifyResult');
    var btn = document.getElementById('modalVerifyBtn');

    if (!answer) {
        resBox.classList.remove('hidden');
        resBox.className = 'alert alert-danger';
        resBox.textContent = 'يرجى كتابة إجابة سؤال التحقق.';
        return;
    }

    btn.disabled = true;
    fetch('api/verify.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({item_id: itemId, answer: answer})
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        btn.disabled = false;
        resBox.classList.remove('hidden');
        if (data.match) {
            resBox.className = 'alert alert-success';
            var html = '<div>' + escHtml(data.message) + '</div>';
            html += '<div style="margin-top:0.5rem; font-size:1.15rem;">📞 هاتف التواصل: <a href="tel:' + escAttr(data.contact_info) + '" style="font-weight:bold; color:#065f46;" dir="ltr">' + escHtml(data.contact_info) + '</a></div>';
            if (data.drop_point) {
                html += '<div>📦 مكان الاستلام: <strong>' + escHtml(data.drop_point) + '</strong></div>';
            }
            resBox.innerHTML = html;
        } else {
            resBox.className = 'alert alert-danger';
            resBox.textContent = data.message || 'الإجابة غير صحيحة.';
        }
    })
    .catch(function() {
        btn.disabled = false;
        resBox.classList.remove('hidden');
        resBox.className = 'alert alert-danger';
        resBox.textContent = 'حدث خطأ أثناء فحص الإجابة.';
    });
}

// Mark Resolved & Update Live Counter
function confirmResolve(itemId) {
    if (!confirm('هل تم استلام الأمانة بنجاح بحمد الله؟ سيتم حذف رقم الهاتف فوراً لحماية الخصوصية وزيادة عداد النجاح.')) {
        return;
    }

    fetch('api/resolve.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({item_id: itemId})
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            alert(data.message);
            var counter = document.getElementById('successCounter');
            if (counter && data.new_count) {
                counter.textContent = data.new_count;
            }
            window.location.reload();
        } else {
            alert(data.message || 'حدث خطأ');
        }
    })
    .catch(function() { alert('خطأ في الاتصال'); });
}

// Neighborhood Poster
function printNeighborhoodPoster() {
    var area = document.getElementById('posterAreaSelect').value;
    if (!area) {
        alert('يرجى اختيار المنطقة أولاً من القائمة');
        return;
    }
    window.open('api/poster.php?area=' + encodeURIComponent(area), '_blank');
}

// WhatsApp Text Copy
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
        setTimeout(function() { btn.textContent = '📋 واتساب'; }, 2000);
    }
}

function escHtml(s) {
    if (!s) return '';
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

function escAttr(s) {
    if (!s) return '';
    return s.replace(/&/g,'&amp;').replace(/'/g,'&#39;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// Splash Preloader Logic ("غزة لسا فيها خير")
(function initSplash() {
    var splash = document.getElementById('splashScreen');
    var fill = document.getElementById('splashProgressFill');
    var txt = document.getElementById('splashLoadingText');
    if (!splash) return;

    var duration = 2400; // ~2.4 seconds loading
    var start = Date.now();
    var timer = setInterval(function() {
        var elapsed = Date.now() - start;
        var pct = Math.min(100, Math.round((elapsed / duration) * 100));
        if (fill) fill.style.width = pct + '%';
        if (pct >= 50 && txt) txt.textContent = 'غزة لسا فيها خير... أهلاً بكم 🇵🇸';
        if (pct >= 100) {
            clearInterval(timer);
            dismissSplash();
        }
    }, 25);

    window.dismissSplash = function() {
        clearInterval(timer);
        splash.classList.add('fade-out');
        setTimeout(function() {
            if (splash.parentNode) splash.parentNode.removeChild(splash);
        }, 600);
    };
})();

// Go to Home smoothly
function goToHome(e) {
    if (window.location.pathname.endsWith('index.php') && window.location.search === '') {
        e.preventDefault();
        var searchInput = document.getElementById('mainSearchInput');
        if (searchInput) searchInput.value = '';
        var searchBox = document.getElementById('liveSearchResults');
        if (searchBox) searchBox.classList.add('hidden');
        window.scrollTo({top: 0, behavior: 'smooth'});
    } else {
        window.location.href = 'index.php';
    }
}

if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(function(){});
}
</script>
</body>
</html>
