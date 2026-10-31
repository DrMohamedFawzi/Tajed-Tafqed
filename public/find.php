<?php
/**
 * منصة فَقَدتُ ووَجَدتُ - البحث بالمواصفات
 * Pick the item type, fill in what you remember, get reports ranked by how well they match.
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/matcher.php';
require_once __DIR__ . '/../core/layout.php';
require_once __DIR__ . '/../core/cards.php';

setSecurityHeaders();

$categories = itemCategories();
$areas = gazaAreas();

$category = $_GET['category'] ?? '';
if (!isset($categories[$category])) {
    $category = '';
}
// Someone who lost something looks at found reports, and the other way round
$look = ($_GET['look'] ?? 'found') === 'lost' ? 'lost' : 'found';
$area = in_array($_GET['area'] ?? '', $areas, true) ? $_GET['area'] : '';
$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : '';

$attrs = $category ? readAttributes($category, $_GET['attr'] ?? []) : [];
$searched = $category !== '' && isset($_GET['go']);
$results = [];
if ($searched && $attrs) {
    if (checkRateLimit(getClientIP(), 'spec_search', 30, 1)) {
        $results = matchItems($category, $attrs, $look, $area ?: null, $date ?: null, 24);
        logAction('spec_search', "Category: {$category} - Found: " . count($results));
    }
}

pageStart('البحث بالمواصفات', 'browse');
?>

<div class="container" style="padding-top: 2rem; padding-bottom: 3rem;">
    <div class="page-heading">
        <h1>ابحث بالمواصفات</h1>
        <p>اختر نوع الغرض، واكتب ما تتذكره منه. نرتّب لك البلاغات من الأقرب إلى الأبعد.</p>
    </div>

    <form method="GET" action="find.php" class="form-card attr-form" style="max-width: 860px;">
        <input type="hidden" name="go" value="1">

        <fieldset class="form-group">
            <legend class="form-label">ما نوع الغرض؟ <span class="req">*</span></legend>
            <div class="type-tiles">
                <?php foreach ($categories as $key => $c): ?>
                    <label class="type-tile">
                        <input type="radio" name="category" value="<?= $key ?>" <?= $category === $key ? 'checked' : '' ?> required>
                        <span><?= icon($c['icon']) ?><?= htmlspecialchars($c['name']) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <div class="attr-area">
            <p class="attr-empty-note">اختر نوع الغرض لتظهر حقول مواصفاته.</p>
            <?php renderAttributeFields($category, $attrs); ?>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label" for="f-area">المنطقة (اختياري)</label>
                <select name="area" id="f-area" class="form-control">
                    <option value="">كل المناطق</option>
                    <?php foreach ($areas as $a): ?>
                        <option value="<?= htmlspecialchars($a) ?>" <?= $area === $a ? 'selected' : '' ?>><?= htmlspecialchars($a) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="f-date">التاريخ التقريبي (اختياري)</label>
                <input type="date" name="date" id="f-date" class="form-control" value="<?= htmlspecialchars($date) ?>" max="<?= date('Y-m-d') ?>">
            </div>
        </div>

        <fieldset class="form-group">
            <legend class="form-label">أبحث في</legend>
            <div class="seg-control">
                <label><input type="radio" name="look" value="found" <?= $look === 'found' ? 'checked' : '' ?>><span>المعثورات (فقدتُ شيئاً)</span></label>
                <label><input type="radio" name="look" value="lost" <?= $look === 'lost' ? 'checked' : '' ?>><span>المفقودات (وجدتُ شيئاً)</span></label>
            </div>
        </fieldset>

        <button type="submit" class="btn btn-primary btn-block"><?= icon('search') ?> ابحث</button>
    </form>

    <?php if ($searched): ?>
        <section class="find-results" aria-live="polite">
            <?php if (!$attrs): ?>
                <div class="alert alert-warning narrow"><?= icon('info') ?><span>املأ مواصفة واحدة على الأقل حتى نستطيع المطابقة.</span></div>
            <?php elseif (!$results): ?>
                <div class="empty-state narrow">
                    <?= icon('search', 'ic-xl') ?>
                    <h3>لا توجد بلاغات قريبة من هذه المواصفات حتى الآن</h3>
                    <p><?= $look === 'found' ? 'سجّل بلاغ مفقود بنفس المواصفات، فإن سجّل أحد غرضاً مطابقاً يظهر له بلاغك.' : 'سجّل ما وجدته، فإن بحث صاحبه يجده.' ?></p>
                    <a href="report.php?type=<?= $look === 'found' ? 'lost' : 'found' ?>&category=<?= $category ?>" class="btn btn-primary"><?= icon('plus') ?> <?= $look === 'found' ? 'سجّل بلاغ مفقود' : 'سجّل ما وجدته' ?></a>
                </div>
            <?php else: ?>
                <h2 class="section-title" style="margin-bottom: 1rem;"><?= icon('list') ?><span>الأقرب إلى مواصفاتك (<?= count($results) ?>)</span></h2>
                <div class="items-grid">
                    <?php foreach ($results as $item): ?>
                        <?php renderItemCard($item); ?>
                    <?php endforeach; ?>
                </div>
                <p class="band-note text-center">لم تجد غرضك؟ <a href="report.php?type=<?= $look === 'found' ? 'lost' : 'found' ?>&category=<?= $category ?>">سجّل بلاغاً بهذه المواصفات</a></p>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>

<?php
pageEnd();
