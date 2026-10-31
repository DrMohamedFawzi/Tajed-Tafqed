<?php
/**
 * فَقَدتُ ووَجَدتُ - Report card (shared by the homepage list, spec search and match results)
 */

require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/attributes.php';

/**
 * Category key => name + icon.
 */
function itemCategories(): array
{
    return [
        'id_card' => ['name' => 'هوية شخصية', 'icon' => 'id-card'],
        'passport' => ['name' => 'جواز سفر', 'icon' => 'book'],
        'unrwa_card' => ['name' => 'كرت وكالة', 'icon' => 'ticket'],
        'phone' => ['name' => 'هاتف محمول', 'icon' => 'smartphone'],
        'laptop' => ['name' => 'لابتوب / تابلت', 'icon' => 'laptop'],
        'bag' => ['name' => 'حقيبة / محفظة', 'icon' => 'bag'],
        'document' => ['name' => 'شهادات ووثائق', 'icon' => 'file-text'],
        'money' => ['name' => 'نقود ومصاغ ذهبي', 'icon' => 'wallet'],
        'keys' => ['name' => 'مفاتيح', 'icon' => 'key'],
        'other' => ['name' => 'أغراض أخرى', 'icon' => 'package'],
    ];
}

if (!function_exists('formatCardTime')) {
    function formatCardTime($datetime): string {
        if (!$datetime) return 'الآن';
        try {
            $diff = (new DateTime('now'))->diff(new DateTime($datetime));
            if ($diff->y > 0) return "قبل {$diff->y} سنة";
            if ($diff->m > 0) return "قبل {$diff->m} شهر";
            if ($diff->d > 0) return "قبل {$diff->d} يوم";
            if ($diff->h > 0) return "قبل {$diff->h} ساعة";
            if ($diff->i > 0) return "قبل {$diff->i} دقيقة";
            return 'الآن';
        } catch (Exception $e) {
            return 'مؤخراً';
        }
    }
}

// "2026-10-29" -> "29/10/2026"
if (!function_exists('formatEventDate')) {
    function formatEventDate(?string $date): string {
        if (empty($date) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) return '';
        return "{$m[3]}/{$m[2]}/{$m[1]}";
    }
}

/**
 * Print one report card. A match result ($item['match_score']) gets a match badge.
 */
function renderItemCard(array $item): void
{
    $categories = itemCategories();
    $catInfo = $categories[$item['category']] ?? ['name' => $item['category'], 'icon' => 'package'];
    $isLost = $item['type'] === 'lost';
    $hasSecret = !empty($item['secret_question']);
    $eventDate = formatEventDate($item['event_date'] ?? null);
    $specs = publicAttributes($item['category'], decodeAttributes($item['attributes'] ?? null));
    $shareText = ($isLost ? 'مفقود' : 'معثور عليه') . ': ' . $item['title_or_name'] . ' - ' . ($item['location'] ?: 'قطاع غزة') . "\nعلى منصة فَقَدتُ ووَجَدتُ";
    ?>
    <article class="item-card <?= $isLost ? 'type-lost' : 'type-found' ?>">
        <div>
            <?php if (isset($item['match_score'])): ?>
                <div class="match-badge" title="نسبة التطابق مع المواصفات التي أدخلتها">
                    <span class="match-meter"><span style="width: <?= (int)$item['match_score'] ?>%"></span></span>
                    <span>تطابق <?= (int)$item['match_score'] ?>٪</span>
                    <?php if (!empty($item['matched_labels'])): ?>
                        <span class="match-fields">(<?= htmlspecialchars(implode('، ', $item['matched_labels'])) ?>)</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="item-card-header">
                <span class="badge <?= $isLost ? 'badge-lost' : 'badge-found' ?>">
                    <span class="dot <?= $isLost ? 'dot-lost' : 'dot-found' ?>"></span>
                    <span><?= $isLost ? 'مفقود' : 'معثور عليه' ?></span>
                    <span>·</span>
                    <span><?= htmlspecialchars($catInfo['name']) ?></span>
                </span>
                <span class="item-time" title="وقت النشر"><?= icon('clock') ?> <?= formatCardTime($item['created_at']) ?></span>
            </div>

            <h3 class="item-title">
                <?= icon($catInfo['icon']) ?>
                <span><?= htmlspecialchars($item['title_or_name']) ?></span>
            </h3>

            <?php if ($specs): ?>
                <dl class="spec-list">
                    <?php foreach ($specs as $s): ?>
                        <div><dt><?= htmlspecialchars($s['label']) ?></dt><dd><?= htmlspecialchars($s['value']) ?></dd></div>
                    <?php endforeach; ?>
                </dl>
            <?php endif; ?>

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
    <?php
}
