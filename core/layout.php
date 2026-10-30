<?php
/**
 * فَقَدتُ ووَجَدتُ - Shared page layout (head, navbar, footer)
 *
 * Usage:
 *   pageStart('عنوان الصفحة', 'home');
 *   ... page content ...
 *   pageEnd();
 */

require_once __DIR__ . '/icons.php';

// Bump when style.css or site.js change (static files are cached long-term
// by nginx and Cloudflare). Keep public/sw.js in sync.
const ASSET_VER = '3';

// How people reach the platform admins (abuse reports, lost owner codes).
// Shown on about.php and manage.php when set.
const ADMIN_CONTACT = 'تيليجرام: AN0NYM0US2001';
const ADMIN_CONTACT_URL = 'https://t.me/AN0NYM0US2001';

/**
 * "contact the admins" sentence ending, with a link when a channel is set.
 */
function adminContactHtml(): string
{
    if (ADMIN_CONTACT === '') {
        return 'تواصل مع إدارة المنصة';
    }
    return 'تواصل مع إدارة المنصة عبر <a href="' . htmlspecialchars(ADMIN_CONTACT_URL) . '" target="_blank" rel="noopener"><bdi>'
        . htmlspecialchars(ADMIN_CONTACT) . '</bdi></a>';
}

/**
 * Areas of the Gaza Strip offered in forms and filters.
 */
function gazaAreas(): array
{
    return [
        'خانيونس', 'دير البلح', 'النصيرات', 'رفح', 'المواصي',
        'غزة المدينة', 'جباليا', 'بيت لاهيا', 'بيت حانون',
        'الزوايدة', 'البريج', 'المغازي', 'عبسان', 'بني سهيلا', 'القرارة',
        'منطقة أخرى'
    ];
}

/**
 * Print <head>, the icon sprite, the flag stripe and the navbar.
 *
 * @param string $title  Page title (without the site name)
 * @param string $active Active nav key: home | browse | about | report
 * @param string $extraHead Optional extra markup for <head>
 */
function pageStart(string $title, string $active = '', string $extraHead = ''): void
{
    $nav = function (string $key) use ($active): string {
        return $active === $key ? ' active' : '';
    };
    ?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <meta name="theme-color" content="#0b3b37">
    <meta name="description" content="منصة مجتمعية تساعد أهل غزة على إعادة الوثائق والأغراض المفقودة لأصحابها">
    <title><?= htmlspecialchars($title) ?> | فَقَدتُ ووَجَدتُ</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= ASSET_VER ?>">
    <link rel="manifest" href="manifest.json">
    <link rel="icon" type="image/svg+xml" href="assets/logo.svg">
    <link rel="apple-touch-icon" href="assets/logo.svg">
    <?= $extraHead ?>
</head>
<body>
<?= iconSprite() ?>
<a href="#main" class="skip-link">تخطَّ إلى المحتوى</a>
<div class="flag-stripe" aria-hidden="true"></div>

<nav class="navbar-top" aria-label="القائمة الرئيسية">
    <div class="container navbar-inner">
        <a href="index.php" class="brand-wrap">
            <img src="assets/logo.svg" alt="">
            <div>
                <div class="brand-title">فَقَدتُ ووَجَدتُ</div>
                <div class="brand-sub">منصة مجتمعية للمفقودات - غزة</div>
            </div>
        </a>
        <div class="nav-links">
            <a href="index.php" class="nav-link nav-home<?= $nav('home') ?>"><?= icon('home') ?> الرئيسية</a>
            <a href="index.php#browseSection" class="nav-link<?= $nav('browse') ?>"><?= icon('list') ?> تصفح البلاغات</a>
            <a href="about.php" class="nav-link<?= $nav('about') ?>"><?= icon('info') ?> كيف تعمل؟</a>
            <a href="report.php" class="nav-link btn-nav-add<?= $nav('report') ?>"><?= icon('plus') ?> سجّل بلاغاً</a>
        </div>
    </div>
</nav>
<main id="main">
    <?php
}

/**
 * Print the footer, the toast container and the shared script.
 *
 * @param string $extraScript Optional page script (raw JS, no <script> tag)
 */
function pageEnd(string $extraScript = ''): void
{
    ?>
</main>

<footer class="footer-wrap">
    <div class="container">
        <div class="sponsor-box">
            <div class="sponsor-title">مبادرة مجتمعية تطوعية لإسناد أهلنا الصامدين في قطاع غزة</div>
            <div class="sponsor-company">
                <span>تم دعمه وتطويره برعاية شركة</span>
                <strong dir="ltr">MSN-IT UK</strong>
            </div>
            <div class="sponsor-desc">حلول تكنولوجيا المعلومات المتقدمة والأنظمة السحابية - المملكة المتحدة</div>
        </div>

        <div class="footer-links">
            <a href="index.php">الرئيسية</a>
            <a href="index.php#browseSection">تصفح البلاغات</a>
            <a href="report.php?type=lost">فقدتُ شيئاً</a>
            <a href="report.php?type=found">وجدتُ شيئاً</a>
            <a href="report.php?type=tag">رمز الأمانة</a>
            <a href="index.php#posterSection">نشرة الحي للطباعة</a>
            <a href="about.php">كيف تعمل المنصة والخصوصية</a>
        </div>
        <p class="footer-copy">
            منصة "فَقَدتُ ووَجَدتُ" - منصة طوارئ مجتمعية لوجه الله تعالى | صُممت بحب لأهلنا الصامدين في قطاع غزة <span class="flag-mini" aria-hidden="true"></span>
        </p>
    </div>
</footer>

<div id="toast" class="toast" role="status" aria-live="polite"></div>

<script src="assets/js/site.js?v=<?= ASSET_VER ?>"></script>
<?php if ($extraScript !== ''): ?>
<script>
<?= $extraScript ?>
</script>
<?php endif; ?>
</body>
</html>
    <?php
}
