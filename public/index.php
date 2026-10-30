<?php
/**
 * منصة فَقَدتُ ووَجَدتُ - قطاع غزة
 * Ultra-lightweight Community Lost & Found PWA
 * Main Homepage, Search Engine & Paginated Items Browser
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/matcher.php';
require_once __DIR__ . '/../core/layout.php';

setSecurityHeaders();

$db = getDB();
$successCount = getSuccessCount();
$recentSuccesses = getRecentSuccesses(3);
$areas = gazaAreas();

// Categories map (icon = name in core/icons.php sprite)
$categories = [
    'id_card' => ['name' => 'هوية شخصية', 'icon' => 'id-card'],
    'passport' => ['name' => 'جواز سفر', 'icon' => 'book'],
    'unrwa_card' => ['name' => 'كرت وكالة', 'icon' => 'ticket'],
    'phone' => ['name' => 'هاتف محمول', 'icon' => 'smartphone'],
    'laptop' => ['name' => 'لابتوب / تابلت', 'icon' => 'laptop'],
    'bag' => ['name' => 'حقيبة / محفظة', 'icon' => 'bag'],
    'document' => ['name' => 'شهادات ووثائق', 'icon' => 'file-text'],
    'money' => ['name' => 'نقود ومصاغ ذهبي', 'icon' => 'wallet'],
    'keys' => ['name' => 'مفاتيح', 'icon' => 'key'],
    'other' => ['name' => 'أغراض أخرى', 'icon' => 'package']
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

// "2026-10-29" -> "29/10/2026"
function formatEventDate(?string $date): string {
    if (empty($date) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) return '';
    return "{$m[3]}/{$m[2]}/{$m[1]}";
}

$filterQs = '&filter_category=' . urlencode((string)$filterCategory) . '&filter_area=' . urlencode((string)$filterArea);
$pageQs = '&filter_type=' . urlencode((string)$filterType) . $filterQs;

pageStart('منصة المفقودات والأمانات في غزة', 'home');
?>

<!-- Splash Screen ("غزة لسا فيها خير") - shown once per session -->
<div id="splashScreen" class="splash-overlay">
    <div class="splash-logo-wrap">
        <img src="assets/logo.svg" alt="" style="width: 100%; height: 100%;">
    </div>
    <div class="splash-title">فَقَدتُ ووَجَدتُ</div>
    <div class="splash-motto">
        <span>غزة لسا فيها خير</span>
        <span class="flag-mini" aria-hidden="true"></span>
    </div>
    <div class="splash-progress-track">
        <div id="splashProgressFill" class="splash-progress-fill"></div>
    </div>
    <div id="splashLoadingText" class="splash-loading-text">جاري تهيئة المنصة المجتمعية...</div>
    <button type="button" class="splash-skip-btn" onclick="dismissSplash()">تخطي والدخول <?= icon('chevron-left') ?></button>
</div>
<script>
try { if (sessionStorage.getItem('lfSplashSeen')) { var s = document.getElementById('splashScreen'); s.parentNode.removeChild(s); } } catch (e) {}
</script>

<!-- Hero: the two main choices -->
<header class="hero-header">
    <div class="container">
        <div class="hero-badge">
            <?= icon('heart') ?>
            <span>تمت إعادة <strong><?= (int)$successCount ?></strong> أمانة لأصحابها بحمد الله</span>
        </div>
        <h1 class="hero-title">منصة "فَقَدتُ ووَجَدتُ" في قطاع غزة</h1>
        <p class="hero-lead">
            نساعد أهل غزة على إعادة الوثائق والأغراض المفقودة لأصحابها. المنصة مجانية وخفيفة، وتعمل حتى مع ضعف الإنترنت.
        </p>

        <div class="choice-grid">
            <a href="report.php?type=lost" class="choice-card">
                <span class="choice-icon"><?= icon('search', 'ic-lg') ?></span>
                <span>
                    <span class="choice-title">فقدتُ شيئاً</span>
                    <span class="choice-desc">سجّل ما فقدته ليراه من يجده</span>
                </span>
            </a>
            <a href="report.php?type=found" class="choice-card">
                <span class="choice-icon"><?= icon('heart', 'ic-lg') ?></span>
                <span>
                    <span class="choice-title">وجدتُ شيئاً</span>
                    <span class="choice-desc">سجّل الأمانة ليصل إليك صاحبها</span>
                </span>
            </a>
        </div>
    </div>
</header>

<!-- Search -->
<div class="container">
    <section class="search-card" id="searchSection" aria-labelledby="searchTitle">
        <h2 class="search-title" id="searchTitle">ابحث في البلاغات المسجلة</h2>
        <form id="searchForm" onsubmit="return handleSearchSubmit(event)" role="search">
            <div class="search-input-group">
                <label for="mainSearchInput" class="sr-only">ابحث</label>
                <input
                    type="search"
                    id="mainSearchInput"
                    class="search-input"
                    placeholder="رقم الهوية، الاسم، أو رمز الأمانة"
                    autocomplete="off"
                    required
                    minlength="2"
                    dir="rtl"
                    aria-describedby="searchHint"
                >
                <button type="submit" class="btn-search" id="mainSearchBtn">
                    <?= icon('search') ?><span class="lbl">ابحث</span>
                </button>
            </div>
        </form>
        <p class="search-hint" id="searchHint">
            <?= icon('info') ?>
            <span>يمكنك البحث برقم الهوية أو الجواز، أو رقم IMEI للهاتف (اطلب <bdi dir="ltr">*#06#</bdi> من الهاتف لعرضه)، أو رمز الأمانة، أو الاسم.</span>
        </p>

        <div id="liveSearchResults" class="hidden mt-3" aria-live="polite"></div>
    </section>
</div>

<!-- Recent returns (static, no moving ticker) -->
<?php if (!empty($recentSuccesses)): ?>
<div class="container">
    <div class="recent-returns">
        <span class="recent-label"><?= icon('check-circle') ?> آخر الأمانات التي عادت لأصحابها:</span>
        <?php foreach ($recentSuccesses as $s): ?>
            <span class="recent-item"><?= htmlspecialchars($categories[$s['category']]['name'] ?? $s['category']) ?> في <?= htmlspecialchars($s['location']) ?> <span class="recent-time">(<?= htmlspecialchars($s['time_ago']) ?>)</span></span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Other services -->
<div class="container">
    <h2 class="sub-heading">خدمات أخرى</h2>
    <div class="actions-grid">
        <a href="#searchSection" class="action-tile" onclick="focusSearch(event, 'imei')">
            <div class="action-icon"><?= icon('smartphone') ?></div>
            <div class="action-title">افحص هاتفاً قبل شرائه</div>
            <div class="action-desc">ابحث برقم IMEI لتتأكد أن الهاتف المستعمل ليس مسجلاً كمفقود</div>
        </a>
        <a href="report.php?type=tag" class="action-tile">
            <div class="action-icon"><?= icon('tag') ?></div>
            <div class="action-title">رمز الأمانة</div>
            <div class="action-desc">رمز قصير تكتبه على أغراضك، فمن يجدها يصل إليك</div>
        </a>
        <a href="report.php?type=transport" class="action-tile">
            <div class="action-icon"><?= icon('car') ?></div>
            <div class="action-title">نسيت شيئاً في مواصلات</div>
            <div class="action-desc">تكتك أو سيارة أو حافلة: سجّل خط السير ووقت الركوب</div>
        </a>
        <a href="report.php?type=bulk" class="action-tile">
            <div class="action-icon"><?= icon('layers') ?></div>
            <div class="action-title">للمستشفيات والمؤسسات</div>
            <div class="action-desc">أضف قائمة وثائق معثور عليها دفعة واحدة</div>
        </a>
    </div>
</div>

<!-- Paginated Items Browser -->
<section id="browseSection" class="container" style="margin-bottom: 3.5rem;">
    <div class="section-header">
        <div>
            <h2 class="section-title"><?= icon('list') ?><span>البلاغات النشطة</span></h2>
            <p class="section-sub">بلاغات مسجلة في مختلف مناطق القطاع (العدد: <?= $totalItems ?>)</p>
        </div>

        <div class="filter-tabs" aria-label="نوع البلاغ">
            <a href="index.php?filter_type=<?= $filterQs ?>#browseSection" class="filter-tab <?= empty($filterType) ? 'active' : '' ?>">الكل (<?= getPaginatedCount(null, $filterCategory, $filterArea) ?>)</a>
            <a href="index.php?filter_type=lost<?= $filterQs ?>#browseSection" class="filter-tab <?= $filterType === 'lost' ? 'active' : '' ?>"><span class="dot dot-lost"></span> مفقودات</a>
            <a href="index.php?filter_type=found<?= $filterQs ?>#browseSection" class="filter-tab <?= $filterType === 'found' ? 'active' : '' ?>"><span class="dot dot-found"></span> معثورات</a>
        </div>
    </div>

    <form method="GET" action="index.php#browseSection" class="filters-bar">
        <?php if (!empty($filterType)): ?>
            <input type="hidden" name="filter_type" value="<?= htmlspecialchars($filterType) ?>">
        <?php endif; ?>

        <select name="filter_category" class="filter-select" onchange="this.form.submit()" aria-label="التصنيف">
            <option value="">كل التصنيفات</option>
            <?php foreach ($categories as $k => $c): ?>
                <option value="<?= htmlspecialchars($k) ?>" <?= $filterCategory === $k ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <select name="filter_area" class="filter-select" onchange="this.form.submit()" aria-label="المنطقة">
            <option value="">كل المناطق</option>
            <?php foreach ($areas as $a): ?>
                <option value="<?= htmlspecialchars($a) ?>" <?= $filterArea === $a ? 'selected' : '' ?>><?= htmlspecialchars($a) ?></option>
            <?php endforeach; ?>
        </select>

        <noscript><button type="submit" class="btn btn-primary btn-sm">تطبيق</button></noscript>

        <?php if (!empty($filterCategory) || !empty($filterArea) || !empty($filterType)): ?>
            <a href="index.php#browseSection" class="btn btn-outline-primary btn-sm">
                <?= icon('refresh') ?> إزالة الفلاتر
            </a>
        <?php endif; ?>
    </form>

    <?php if (empty($paginatedItems)): ?>
        <div class="empty-state">
            <?= icon('search', 'ic-xl') ?>
            <h3>لا توجد بلاغات تطابق اختيارك حالياً</h3>
            <p>جرّب إزالة الفلاتر، أو سجّل بلاغاً جديداً.</p>
            <a href="report.php" class="btn btn-primary"><?= icon('plus') ?> سجّل بلاغاً</a>
        </div>
    <?php else: ?>
        <div class="items-grid">
            <?php foreach ($paginatedItems as $item): ?>
                <?php
                $catInfo = $categories[$item['category']] ?? ['name' => $item['category'], 'icon' => 'package'];
                $isLost = $item['type'] === 'lost';
                $badgeText = $isLost ? 'مفقود' : 'معثور عليه';
                $hasSecret = !empty($item['secret_question']);
                $eventDate = formatEventDate($item['event_date'] ?? null);
                $shareText = ($isLost ? 'مفقود' : 'معثور عليه') . ': ' . $item['title_or_name'] . ' - ' . ($item['location'] ?: 'قطاع غزة') . "\nعلى منصة فَقَدتُ ووَجَدتُ";
                ?>
                <article class="item-card <?= $isLost ? 'type-lost' : 'type-found' ?>">
                    <div>
                        <div class="item-card-header">
                            <span class="badge <?= $isLost ? 'badge-lost' : 'badge-found' ?>">
                                <span class="dot <?= $isLost ? 'dot-lost' : 'dot-found' ?>"></span>
                                <span><?= $badgeText ?></span>
                                <span>·</span>
                                <span><?= htmlspecialchars($catInfo['name']) ?></span>
                            </span>
                            <span class="item-time" title="وقت النشر"><?= icon('clock') ?> <?= formatCardTime($item['created_at']) ?></span>
                        </div>

                        <h3 class="item-title">
                            <?= icon($catInfo['icon']) ?>
                            <span><?= htmlspecialchars($item['title_or_name']) ?></span>
                        </h3>

                        <?php if (!empty($item['description'])): ?>
                            <p class="item-desc"><?= htmlspecialchars($item['description']) ?></p>
                        <?php endif; ?>

                        <div class="item-meta">
                            <div class="item-meta-row">
                                <?= icon('map-pin') ?>
                                <span><?= htmlspecialchars($item['location'] ?: ($item['location_area'] ?: 'قطاع غزة')) ?></span>
                            </div>
                            <?php if ($eventDate): ?>
                                <div class="item-meta-row">
                                    <?= icon('clock') ?>
                                    <span><?= $isLost ? 'تاريخ الفقدان' : 'تاريخ العثور' ?>: <?= $eventDate ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($item['transport_route'])): ?>
                                <div class="item-meta-row route">
                                    <?= icon('car') ?>
                                    <span><?= htmlspecialchars($item['transport_route']) ?> (<?= htmlspecialchars($item['transport_vehicle'] ?? '') ?>)</span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($item['drop_point'])): ?>
                            <div class="drop-point-pill">
                                <?= icon('package') ?>
                                <span>مكان الاستلام:</span>
                                <strong><?= htmlspecialchars($item['drop_point']) ?></strong>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div>
                        <div class="item-card-footer">
                            <?php if ($hasSecret): ?>
                                <button type="button" class="btn btn-primary btn-sm" data-action="verify" data-id="<?= (int)$item['id'] ?>" data-question="<?= htmlspecialchars($item['secret_question']) ?>">
                                    <?= icon('lock') ?> هذا لي، أريد التواصل
                                </button>
                            <?php elseif (!empty($item['has_contact'])): ?>
                                <button type="button" class="btn btn-primary btn-sm" data-action="reveal" data-id="<?= (int)$item['id'] ?>">
                                    <?= icon('phone') ?><span class="lbl">إظهار رقم الجوال</span>
                                </button>
                            <?php endif; ?>

                            <button type="button" class="btn btn-whatsapp btn-sm" data-action="share" data-text="<?= htmlspecialchars($shareText) ?>">
                                <?= icon('share') ?> مشاركة واتساب
                            </button>
                        </div>
                        <a href="manage.php?id=<?= (int)$item['id'] ?>" class="owner-link">أنا صاحب هذا البلاغ: إغلاقه أو حذفه</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="pagination-container" aria-label="الصفحات">
                <a href="index.php?page=<?= max(1, $currentPage - 1) . $pageQs ?>#browseSection" class="page-btn <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                    <?= icon('chevron-right') ?> السابق
                </a>

                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <?php if ($p === 1 || $p === $totalPages || abs($p - $currentPage) <= 2): ?>
                        <a href="index.php?page=<?= $p . $pageQs ?>#browseSection" class="page-btn <?= $p === $currentPage ? 'active' : '' ?>" <?= $p === $currentPage ? 'aria-current="page"' : '' ?>><?= $p ?></a>
                    <?php elseif (abs($p - $currentPage) === 3): ?>
                        <span class="page-btn disabled">...</span>
                    <?php endif; ?>
                <?php endfor; ?>

                <a href="index.php?page=<?= min($totalPages, $currentPage + 1) . $pageQs ?>#browseSection" class="page-btn <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                    التالي <?= icon('chevron-left') ?>
                </a>
            </nav>
            <p class="page-caption">صفحة <?= $currentPage ?> من <?= $totalPages ?></p>
        <?php endif; ?>
    <?php endif; ?>
</section>

<!-- Safety Tag Lookup -->
<section class="band" id="tagSection">
    <div class="band-inner">
        <div class="band-icon"><?= icon('tag', 'ic-lg') ?></div>
        <h2 class="band-title">وجدت غرضاً مكتوباً عليه رمز أمانة؟</h2>
        <p class="band-text">أدخل الرمز المكتوب على الغرض لتحصل على رقم صاحبه وتتصل به مباشرة. يشبه الرمز هذا الشكل: <bdi dir="ltr">GZ-7A3F</bdi></p>
        <form onsubmit="return handleTagLookup(event)" class="inline-form">
            <label for="tagLookupInput" class="sr-only">رمز الأمانة</label>
            <input type="text" id="tagLookupInput" class="form-control code-input" placeholder="GZ-7A3F" dir="ltr" required autocomplete="off">
            <button type="submit" class="btn btn-primary"><?= icon('search') ?> ابحث عن صاحبه</button>
        </form>
        <div id="tagLookupResult" class="hidden mt-2" aria-live="polite"></div>
        <p class="band-note">ليس عندك رمز بعد؟ <a href="report.php?type=tag">أنشئ رمزاً لأغراضك</a></p>
    </div>
</section>

<!-- Area Poster Print -->
<section class="container" id="posterSection" style="padding: 3rem 1rem;">
    <div class="band-inner">
        <div class="band-icon"><?= icon('printer', 'ic-lg') ?></div>
        <h2 class="band-title">اطبع نشرة مفقودات حيّك</h2>
        <p class="band-text">صفحة A4 بالأبيض والأسود (توفّر الحبر) فيها كل بلاغات المنطقة، لتعليقها في المساجد ونقاط الشحن والأفران.</p>
        <div class="inline-form">
            <label for="posterAreaSelect" class="sr-only">المنطقة</label>
            <select id="posterAreaSelect" class="form-control">
                <option value="">اختر المنطقة</option>
                <?php foreach ($areas as $a): ?>
                    <option value="<?= htmlspecialchars($a) ?>"><?= htmlspecialchars($a) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" onclick="printNeighborhoodPoster()" class="btn btn-primary"><?= icon('printer') ?> اطبع النشرة</button>
        </div>
    </div>
</section>

<?php
$script = <<<'JS'
// Search
function handleSearchSubmit(e) {
    e.preventDefault();
    var input = document.getElementById('mainSearchInput');
    var q = input.value.trim();
    if (q.length < 2) return false;

    var btn = document.getElementById('mainSearchBtn');
    var resBox = document.getElementById('liveSearchResults');
    btn.disabled = true;
    setLabel(btn, 'جارٍ البحث...');
    resBox.classList.remove('hidden');
    resBox.innerHTML = '<div class="loading-note">جاري البحث...</div>';

    fetch('api/search.php?q=' + encodeURIComponent(q))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            btn.disabled = false;
            setLabel(btn, 'ابحث');
            if (data.results && data.results.length > 0) {
                resBox.innerHTML = renderResults(data.results);
            } else {
                resBox.innerHTML = renderNoResults(data, q);
            }
        })
        .catch(function() {
            btn.disabled = false;
            setLabel(btn, 'ابحث');
            resBox.innerHTML = '<div class="alert alert-danger">' + ic('alert') + '<span>تعذر الاتصال بالإنترنت، حاول مرة أخرى.</span></div>';
        });
    return false;
}

function renderResults(results) {
    var html = '<h3 class="results-heading">وجدنا ' + results.length + ' ' + (results.length === 1 ? 'نتيجة' : 'نتائج') + '</h3>';
    results.forEach(function(item) {
        var isTag = item.type === 'tag';
        var isLost = item.type === 'lost';
        html += '<div class="result-card">';
        html += '<div class="result-head">';
        html += '<span class="badge ' + (isTag ? 'badge-found' : (isLost ? 'badge-lost' : 'badge-found')) + '">' + (isTag ? '' : '<span class="dot ' + (isLost ? 'dot-lost' : 'dot-found') + '"></span>') + escHtml(item.type_label) + ' · ' + escHtml(item.category_label || item.category) + '</span>';
        html += '<span class="item-time">' + escHtml(item.time_ago || '') + '</span>';
        html += '</div>';
        html += '<div class="result-title">' + escHtml(item.title) + '</div>';
        if (item.description) html += '<p class="item-desc">' + escHtml(item.description) + '</p>';
        html += '<div class="item-meta-row result-loc">' + ic('map-pin') + '<span>' + escHtml(item.location) + '</span></div>';
        if (item.drop_point) {
            html += '<div class="drop-point-pill">' + ic('package') + '<span>مكان الاستلام:</span><strong>' + escHtml(item.drop_point) + '</strong></div>';
        }
        html += '<div class="item-card-footer" style="border-top:0; padding-top:0.25rem;">';
        if (isTag && item.contact_info) {
            html += '<a href="tel:' + escAttr(item.contact_info) + '" class="btn btn-primary btn-sm">' + ic('phone') + ' اتصل بصاحبه: <span dir="ltr">' + escHtml(item.contact_info) + '</span></a>';
        } else if (item.has_secret_question) {
            html += '<button type="button" class="btn btn-primary btn-sm" data-action="verify" data-id="' + item.id + '" data-question="' + escAttr(item.secret_question) + '">' + ic('lock') + ' هذا لي، أريد التواصل</button>';
        } else if (item.has_contact) {
            html += '<button type="button" class="btn btn-primary btn-sm" data-action="reveal" data-id="' + item.id + '">' + ic('phone') + '<span class="lbl">إظهار رقم الجوال</span></button>';
        }
        if (item.whatsapp_text) {
            html += '<button type="button" class="btn btn-whatsapp btn-sm" data-action="share" data-text="' + escAttr(item.whatsapp_text) + '">' + ic('share') + ' مشاركة واتساب</button>';
        }
        html += '</div></div>';
    });
    return html;
}

function renderNoResults(data, q) {
    var html = '<div class="alert alert-warning" style="flex-direction:column;">';
    html += '<div class="alert-title" style="display:flex; align-items:center; gap:0.4rem;">' + ic('info') + ' لا توجد نتائج حتى الآن</div>';
    html += '<p style="margin-bottom:0.75rem;">' + escHtml(data.radar_message || '') + '</p>';
    if (data.radar_available) {
        html += '<form onsubmit="return handleRadarSubmit(event, \'' + escAttr(q) + '\')" class="radar-form">';
        html += '<label for="radarPhone" class="sr-only">رقم جوالك</label>';
        html += '<input type="tel" id="radarPhone" name="phone" class="form-control" placeholder="رقم جوالك" dir="ltr" required>';
        html += '<button type="submit" class="btn btn-primary btn-sm">' + ic('radar') + ' أبلغوني عند العثور عليه</button>';
        html += '</form>';
    }
    html += '<a href="report.php?type=lost" class="btn btn-outline-primary btn-sm" style="margin-top:0.75rem;">' + ic('plus') + ' سجّل بلاغ مفقود</a>';
    html += '</div>';
    return html;
}

function handleRadarSubmit(e, query) {
    e.preventDefault();
    var form = e.target;
    var phone = form.phone.value.trim();
    if (!phone) return false;
    postJSON('api/radar.php', {identifier: query, phone: phone})
        .then(function(data) {
            if (data.success) {
                form.outerHTML = '<div class="alert alert-success" style="margin:0;">' + ic('check-circle') + '<span>' + escHtml(data.message) + '</span></div>';
            } else {
                showToast(data.error || 'تعذر حفظ رقمك.', 'error');
            }
        })
        .catch(function() { showToast('تعذر الاتصال بالإنترنت، حاول مرة أخرى.', 'error'); });
    return false;
}

// "Check a phone before buying" -> focus the search box in IMEI mode
function focusSearch(e, mode) {
    e.preventDefault();
    var input = document.getElementById('mainSearchInput');
    document.getElementById('searchSection').scrollIntoView({behavior: 'smooth', block: 'center'});
    if (mode === 'imei') {
        // ⁦...⁩ keeps "*#06#" left-to-right inside the Arabic text
        input.placeholder = 'اكتب رقم IMEI (اطلب ⁦*#06#⁩ من الهاتف)';
        input.setAttribute('inputmode', 'numeric');
    }
    setTimeout(function() { input.focus(); }, 350);
}

// Safety tag lookup
function handleTagLookup(e) {
    e.preventDefault();
    var code = document.getElementById('tagLookupInput').value.trim();
    var box = document.getElementById('tagLookupResult');
    box.classList.remove('hidden');
    box.innerHTML = '<div class="loading-note">جاري البحث...</div>';

    fetch('api/tag_lookup.php?code=' + encodeURIComponent(code))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.found) {
                var html = '<div class="alert alert-success" style="text-align:right;">' + ic('check-circle') + '<div>';
                html += '<div class="alert-title">وجدنا صاحب الغرض</div>';
                html += '<div>الاسم: <strong>' + escHtml(data.owner_name) + '</strong></div>';
                html += '<div>الغرض: ' + escHtml(data.item_description || 'غير محدد') + '</div>';
                html += '<div style="margin-top:0.75rem;"><a href="tel:' + escAttr(data.phone) + '" class="btn btn-primary btn-sm">' + ic('phone') + ' اتصل به: <span dir="ltr">' + escHtml(data.phone) + '</span></a></div>';
                html += '</div></div>';
                box.innerHTML = html;
            } else {
                box.innerHTML = '<div class="alert alert-danger">' + ic('alert') + '<span>' + escHtml(data.error || 'لم نجد هذا الرمز. تأكد من كتابته كما هو على الغرض.') + '</span></div>';
            }
        })
        .catch(function() { box.innerHTML = '<div class="alert alert-danger">تعذر الاتصال بالإنترنت، حاول مرة أخرى.</div>'; });
    return false;
}

// Neighbourhood poster
function printNeighborhoodPoster() {
    var area = document.getElementById('posterAreaSelect').value;
    if (!area) {
        showToast('اختر المنطقة أولاً.', 'error');
        document.getElementById('posterAreaSelect').focus();
        return;
    }
    window.open('api/poster.php?area=' + encodeURIComponent(area), '_blank');
}

// Splash ("غزة لسا فيها خير") - ~1s, once per session
(function initSplash() {
    var splash = document.getElementById('splashScreen');
    window.dismissSplash = function() {};
    if (!splash) return;

    var fill = document.getElementById('splashProgressFill');
    var txt = document.getElementById('splashLoadingText');
    var duration = 1100;
    var start = Date.now();
    var timer = setInterval(function() {
        var pct = Math.min(100, Math.round(((Date.now() - start) / duration) * 100));
        if (fill) fill.style.width = pct + '%';
        if (pct >= 50 && txt) txt.textContent = 'غزة لسا فيها خير... أهلاً بكم';
        if (pct >= 100) dismissSplash();
    }, 25);

    window.dismissSplash = function() {
        clearInterval(timer);
        try { sessionStorage.setItem('lfSplashSeen', '1'); } catch (e) {}
        splash.classList.add('fade-out');
        setTimeout(function() {
            if (splash.parentNode) splash.parentNode.removeChild(splash);
        }, 450);
    };
})();
JS;
pageEnd($script);
