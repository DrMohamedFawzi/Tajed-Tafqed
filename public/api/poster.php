<?php
/**
 * Printable Neighborhood Bulletin Poster - فَقَدتُ ووَجَدتُ
 * Generates an ink-saving Black & White A4 printable poster for mosques, charging stations, and pharmacies.
 *
 * GET api/poster.php?area=...
 */

require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/security.php';
require_once __DIR__ . '/../../core/matcher.php';

$area = trim($_GET['area'] ?? '');
if ($area === '') {
    die('يرجى تحديد المنطقة لطباعة النشرة.');
}

$db = getDB();

// Fetch active items in this area or nearby
$stmt = $db->prepare("
    SELECT id, type, category, title_or_name, location, drop_point, created_at 
    FROM items 
    WHERE (location_area LIKE :area OR location LIKE :area) AND status = 'active'
    ORDER BY created_at DESC 
    LIMIT 35
");
$stmt->bindValue(':area', "%{$area}%");
$stmt->execute();
$items = $stmt->fetchAll();

$today = date('Y/m/d');

$categoryLabels = [
    'id_card' => 'هوية شخصية',
    'passport' => 'جواز سفر',
    'unrwa_card' => 'كرت وكالة',
    'phone' => 'هاتف محمول',
    'laptop' => 'لابتوب/تابلت',
    'bag' => 'حقيبة',
    'document' => 'وثائق',
    'money' => 'نقود/ذهب',
    'other' => 'أخرى'
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>نشرة مفقودات حي <?= htmlspecialchars($area, ENT_QUOTES, 'UTF-8') ?> - <?= $today ?></title>
    <style>
        @page {
            size: A4 portrait;
            margin: 12mm 10mm;
        }
        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
            background: #fff;
            color: #000;
            margin: 0;
            padding: 15px;
            font-size: 13px;
            line-height: 1.4;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 8px;
            margin-bottom: 12px;
        }
        .header h1 {
            margin: 0 0 4px;
            font-size: 22px;
            font-weight: 900;
            letter-spacing: -0.5px;
        }
        .header .subtitle {
            font-size: 13px;
            font-weight: bold;
            margin: 0;
        }
        .notice-banner {
            border: 1px dashed #000;
            padding: 6px 10px;
            font-size: 11px;
            text-align: center;
            margin-bottom: 12px;
            background: #fafafa;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        th, td {
            border: 1px solid #000;
            padding: 6px 8px;
            text-align: right;
            vertical-align: middle;
        }
        th {
            background-color: #f0f0f0;
            font-weight: bold;
            font-size: 12px;
        }
        .badge {
            display: inline-block;
            font-weight: bold;
            padding: 2px 5px;
            border: 1px solid #000;
            border-radius: 3px;
            font-size: 11px;
        }
        .footer {
            border-top: 2px solid #000;
            padding-top: 8px;
            text-align: center;
            font-size: 11px;
            position: relative;
        }
        .no-print-bar {
            background: #222;
            color: #fff;
            padding: 10px;
            text-align: center;
            margin-bottom: 15px;
            border-radius: 6px;
        }
        .print-btn {
            background: #fff;
            color: #000;
            font-weight: bold;
            border: none;
            padding: 6px 18px;
            cursor: pointer;
            border-radius: 4px;
            font-size: 13px;
            margin-right: 10px;
        }
        @media print {
            .no-print-bar { display: none !important; }
            body { padding: 0; }
        }
    </style>
</head>
<body>

<div class="no-print-bar">
    <span>🖨️ جاهز للطباعة بالأبيض والأسود لتوفير الحبر في نقاط الشحن والمساجد.</span>
    <button class="print-btn" onclick="window.print()">طباعة الآن (A4)</button>
</div>

<div class="header">
    <h1>منصة "فَقَدتُ ووَجَدتُ" - قطاع غزة</h1>
    <p class="subtitle">نشرة المفقودات والمعثورات الرسمية | حي: <?= htmlspecialchars($area, ENT_QUOTES, 'UTF-8') ?> (تاريخ: <?= $today ?>)</p>
</div>

<div class="notice-banner">
    ⚠️ يُرجى تعليق هذه النشرة على جدار المسجد / الصيدلية / نقطة الشحن لإعانة الناس على استرداد أماناتهم ووثائقهم المفقودة.
</div>

<?php if (empty($items)): ?>
    <div style="text-align:center; padding: 40px; border: 1px dashed #000;">
        <h3>لا توجد بلاغات نشطة مسجلة حالياً في منطقة <?= htmlspecialchars($area, ENT_QUOTES, 'UTF-8') ?></h3>
        <p>إذا فقدت أو عثرت على شيء، تفضل بتسجيله في المنصة لتحديث هذه النشرة فوراً.</p>
    </div>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th style="width: 10%;">الحالة</th>
                <th style="width: 15%;">التصنيف</th>
                <th style="width: 35%;">الاسم / مواصفات الغرض</th>
                <th style="width: 25%;">مكان التسليم أو الفقدان</th>
                <th style="width: 15%;">التاريخ</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td style="text-align:center;">
                        <span class="badge">
                            <?= $item['type'] === 'lost' ? 'مفقود' : 'معثور عليه' ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($categoryLabels[$item['category']] ?? $item['category'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><strong><?= htmlspecialchars($item['title_or_name'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                    <td>
                        <?= htmlspecialchars($item['drop_point'] ?: ($item['location'] ?: $area), ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <td style="font-size:11px; text-align:center;">
                        <?= htmlspecialchars(substr($item['created_at'], 0, 10), ENT_QUOTES, 'UTF-8') ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<div class="footer">
    <p style="margin:2px 0;"><strong>للاستفسار أو فحص الهويات والأجهزة:</strong> افتح الموقع عبر هاتفك المحمول أو اسأل مشرف نقطة التسليم.</p>
    <p style="margin:2px 0; font-size:10px;">فَقَدتُ ووَجَدتُ - منصة طوارئ مجتمعية لوجه الله تعالى | صُمم بحب لأهلنا الصامدين في قطاع غزة 🇵🇸</p>
</div>

<script>
    window.addEventListener('load', function() {
        // Automatically suggest print if query parameter auto=1
        if (new URLSearchParams(window.location.search).get('auto') === '1') {
            window.print();
        }
    });
</script>
</body>
</html>
