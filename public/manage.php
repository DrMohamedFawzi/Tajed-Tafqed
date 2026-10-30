<?php
/**
 * منصة فَقَدتُ ووَجَدتُ - إدارة البلاغ
 * Lets the person who created a report close it (item returned) or withdraw it,
 * using the private owner code shown once when the report was created.
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/matcher.php';
require_once __DIR__ . '/../core/layout.php';

setSecurityHeaders();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db = getDB();
$itemId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$csrfToken = generateCSRFToken();
$error = null;
$done = null;

$item = null;
if ($itemId > 0) {
    $stmt = $db->prepare("SELECT id, type, category, title_or_name, location, location_area, status, owner_code_hash, resolved_category FROM items WHERE id = :id");
    $stmt->bindValue(':id', $itemId, PDO::PARAM_INT);
    $stmt->execute();
    $item = $stmt->fetch() ?: null;
}

if ($item && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'انتهت صلاحية الصفحة. حدّث الصفحة ثم حاول مرة أخرى.';
    } elseif (!checkRateLimit(getClientIP(), 'manage_item', 5, 1)) {
        // Owner codes are short, so attempts are limited tightly
        $error = 'محاولات كثيرة. انتظر دقيقة ثم حاول مرة أخرى.';
    } elseif ($item['status'] !== 'active') {
        $error = 'هذا البلاغ مغلق بالفعل.';
    } elseif (!verifyOwnerCode((string)($_POST['owner_code'] ?? ''), $item['owner_code_hash'])) {
        logAction('manage_denied', "Item ID {$itemId}: wrong owner code");
        $error = 'رمز الإدارة غير صحيح. تأكد من كتابته كما ظهر لك عند تسجيل البلاغ.';
    } elseif ($action === 'resolve') {
        if (resolveItem($itemId, $item['category'], $item['location_area'] ?: ($item['location'] ?: 'قطاع غزة'))) {
            $done = 'resolve';
        } else {
            $error = 'تعذر إغلاق البلاغ. حاول مرة أخرى.';
        }
    } elseif ($action === 'withdraw') {
        if (closeItem($itemId)) {
            $done = 'withdraw';
        } else {
            $error = 'تعذر حذف البلاغ. حاول مرة أخرى.';
        }
    } else {
        $error = 'اختر ما تريد فعله بالبلاغ.';
    }
    $csrfToken = generateCSRFToken();
}

pageStart('إدارة البلاغ', '');
?>

<div class="container" style="padding-top: 2rem; padding-bottom: 3rem;">
    <div class="form-card">
        <h1 class="form-title"><?= icon('key', 'ic-lg') ?><span>إدارة البلاغ</span></h1>

        <?php if (!$item): ?>
            <div class="alert alert-danger" role="alert"><?= icon('alert') ?><span>لم نجد هذا البلاغ. تأكد من الرابط أو من رقم البلاغ.</span></div>
            <a href="index.php" class="btn btn-outline-primary">العودة للرئيسية</a>

        <?php elseif ($done): ?>
            <div class="alert alert-success" role="status">
                <?= icon('check-circle') ?>
                <div>
                    <div class="alert-title"><?= $done === 'resolve' ? 'الحمد لله، تم إغلاق البلاغ' : 'تم حذف البلاغ' ?></div>
                    <p><?= $done === 'resolve'
                        ? 'سجّلنا أن الأمانة عادت لصاحبها، وحذفنا رقم الجوال من المنصة. جزاكم الله خيراً.'
                        : 'لم يعد البلاغ ظاهراً، وحذفنا رقم الجوال من المنصة.' ?></p>
                </div>
            </div>
            <a href="index.php" class="btn btn-primary">العودة للرئيسية</a>

        <?php else: ?>
            <div class="manage-summary">
                <span class="badge <?= $item['type'] === 'lost' ? 'badge-lost' : 'badge-found' ?>"><?= $item['type'] === 'lost' ? 'مفقود' : 'معثور عليه' ?></span>
                <strong><?= htmlspecialchars($item['title_or_name']) ?></strong>
                <span class="manage-meta">بلاغ رقم #<?= (int)$item['id'] ?> · <?= htmlspecialchars($item['location'] ?: ($item['location_area'] ?: '')) ?></span>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger" role="alert"><?= icon('alert') ?><span><?= htmlspecialchars($error) ?></span></div>
            <?php endif; ?>

            <?php if ($item['status'] !== 'active'): ?>
                <div class="alert alert-success"><?= icon('check-circle') ?><span>هذا البلاغ مغلق بالفعل.</span></div>
                <a href="index.php" class="btn btn-outline-primary">العودة للرئيسية</a>

            <?php elseif (empty($item['owner_code_hash'])): ?>
                <div class="alert alert-warning">
                    <?= icon('info') ?>
                    <span>هذا البلاغ سُجّل قبل إضافة رموز الإدارة، لذلك لا يمكن إغلاقه من هنا. تواصل مع إدارة المنصة وأرسل لهم رقم البلاغ.</span>
                </div>

            <?php else: ?>
                <p class="form-intro">يستطيع صاحب البلاغ فقط إغلاقه، باستخدام رمز الإدارة الذي ظهر له عند التسجيل، ويشبه هذا الشكل: <bdi dir="ltr">K7P-29M</bdi></p>
                <form method="POST" action="manage.php?id=<?= (int)$item['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <div class="form-group">
                        <label class="form-label" for="f-code">رمز الإدارة <span class="req">*</span></label>
                        <input type="text" name="owner_code" id="f-code" class="form-control code-input" dir="ltr" autocomplete="off" autocapitalize="characters" required placeholder="K7P-29M" style="max-width: 260px;">
                    </div>

                    <fieldset class="form-group choice-fieldset">
                        <legend class="form-label">ماذا تريد أن تفعل؟ <span class="req">*</span></legend>
                        <label class="check-row radio-row">
                            <input type="radio" name="action" value="resolve" required>
                            <span><strong>عادت الأمانة لصاحبها</strong><br><span class="radio-help">يُغلق البلاغ ويُضاف إلى عدّاد الأمانات المُعادة.</span></span>
                        </label>
                        <label class="check-row radio-row">
                            <input type="radio" name="action" value="withdraw">
                            <span><strong>لم يعد البلاغ مطلوباً</strong><br><span class="radio-help">يُحذف البلاغ دون احتسابه كأمانة مُعادة.</span></span>
                        </label>
                    </fieldset>

                    <div class="privacy-note"><?= icon('lock') ?><span>في الحالتين يُحذف رقم الجوال من المنصة فوراً.</span></div>
                    <button type="submit" class="btn btn-primary btn-block mt-2">تأكيد</button>
                </form>
                <p class="band-note" style="margin-top: 1rem;">أضعت رمز الإدارة؟ تواصل مع إدارة المنصة وأرسل لهم رقم البلاغ.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php
pageEnd();
