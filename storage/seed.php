<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/matcher.php';

echo "Testing DB initialization..." . PHP_EOL;
$db = getDB();
echo "DB connected successfully!" . PHP_EOL;

$cnt = (int)$db->query("SELECT COUNT(*) FROM items")->fetchColumn();
echo "Existing items count: {$cnt}" . PHP_EOL;

if ($cnt === 0) {
    echo "Seeding demo data..." . PHP_EOL;

    // 1. Found ID Card in Khan Younis
    $ident1 = '405123456';
    $hash1 = hashIdentifier($ident1);
    $name1 = 'أحمد محمد خليل النجار';
    $norm1 = normalizeArabic($name1);
    $stmt = $db->prepare("INSERT INTO items (type, category, identifier_hash, title_or_name, normalized_name, location, location_area, drop_point, contact_info, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute(['found', 'id_card', $hash1, $name1, $norm1, 'خانيونس - وسط البلد', 'خانيونس', 'صيدلية النصيرات الحديثة', '0599123456', 'active']);

    // 2. Lost ID Card with Radar Subscriber
    $ident2 = '901234567';
    $hash2 = hashIdentifier($ident2);
    $name2 = 'محمود سعيد عبد الرحمن أبو عودة';
    $norm2 = normalizeArabic($name2);
    $stmt->execute(['lost', 'id_card', $hash2, $name2, $norm2, 'دير البلح - البركة', 'دير البلح', null, '0598765432', 'active']);
    registerRadar($hash2, $name2, '0598765432', '0569876543');

    // 3. Valuables with Secret Question (Bag with Money/Gold)
    $stmtSecret = $db->prepare("INSERT INTO items (type, category, title_or_name, normalized_name, location, location_area, secret_question, secret_answer_hash, contact_info, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $ansHash = hashAnswer('محفظة جلد بنية بداخلها 400 شيكل');
    $stmtSecret->execute(['found', 'money', 'حقيبة يد صغيرة بداخلها مقتنيات ومبلغ مالي', normalizeArabic('حقيبة يد صغيرة بداخلها مقتنيات ومبلغ مالي'), 'رفح - تل السلطان', 'رفح', 'ما هو لون المحفظة وما هو المبلغ التقريبي بداخلها؟', $ansHash, '0592345678', 'active']);

    // 4. Transport lost item
    $stmtTrans = $db->prepare("INSERT INTO items (type, category, title_or_name, normalized_name, location, location_area, transport_route, transport_time, transport_vehicle, contact_info, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmtTrans->execute(['lost', 'bag', 'كيس أدوية وكروكا طبية', normalizeArabic('كيس أدوية وكروكا طبية'), 'مواصلات المواصي', 'المواصي', 'من موقف خانيونس إلى مواصي القرارة', '10:30 صباحاً', 'تكتك أزرق', '0591112233', 'active']);

    // 5. IMEI Phone
    $imei = '862034051234567';
    $stmt->execute(['lost', 'phone', 'هاتف سامسونج A52 أزرق (IMEI: ' . $imei . ')', normalizeArabic('هاتف سامسونج A52 ازرق'), 'النصيرات - مخيم 2', 'النصيرات', null, '0594445566', 'active']);

    // 6. Safety Tag
    $tagStmt = $db->prepare("INSERT INTO safety_tags (tag_code, owner_name, contact_phone, contact_alt, item_description) VALUES (?, ?, ?, ?, ?)");
    $tagStmt->execute(['GZ-4920', 'إبراهيم يوسف رضوان', '0597778899', '0561234567', 'لابتوب ديل أسود + حقيبة']);

    // 7. Success log entries for live counter
    $db->exec("INSERT INTO success_log (item_category, item_location, resolved_at) VALUES ('جواز سفر', 'النصيرات', datetime('now', '-1 hour'))");
    $db->exec("INSERT INTO success_log (item_category, item_location, resolved_at) VALUES ('حقيبة أدوية', 'خانيونس', datetime('now', '-3 hours'))");
    $db->exec("INSERT INTO success_log (item_category, item_location, resolved_at) VALUES ('هوية شخصية', 'دير البلح', datetime('now', '-1 day'))");

    echo "Seeding completed successfully!" . PHP_EOL;
}

echo "Total success count: " . getSuccessCount() . PHP_EOL;
$recent = getRecentSuccesses(5);
echo "Recent successes: " . count($recent) . PHP_EOL;
foreach ($recent as $r) {
    echo "- {$r['category']} في {$r['location']} ({$r['time_ago']})" . PHP_EOL;
}
