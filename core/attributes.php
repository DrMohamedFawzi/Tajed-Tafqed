<?php
/**
 * فَقَدتُ ووَجَدتُ - Item specifications (structured fields per category)
 *
 * Each category has its own fields (brand, colour, contents...). They are stored
 * as JSON in items.attributes, shown on cards (except private ones) and used by
 * matchItems() to rank lost/found reports against each other.
 *
 * Field keys:
 *   label    Arabic label
 *   type     select | text | number
 *   options  for select
 *   weight   importance when matching (higher = more telling)
 *   private  true = used for matching only, never shown publicly
 *   hint     optional help text
 */

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/icons.php';

function colorOptions(): array
{
    return ['أسود', 'أبيض', 'رمادي', 'فضي', 'ذهبي', 'أزرق', 'كحلي', 'أحمر', 'أخضر', 'بني', 'بيج',
            'وردي', 'بنفسجي', 'أصفر', 'برتقالي', 'متعدد الألوان'];
}

function attributeSchema(): array
{
    $color = ['label' => 'اللون', 'type' => 'select', 'options' => colorOptions(), 'weight' => 2];

    return [
        'id_card' => [
            'owner_name' => ['label' => 'الاسم المكتوب على الهوية', 'type' => 'text', 'weight' => 5],
            'birth_year' => ['label' => 'سنة الميلاد', 'type' => 'number', 'weight' => 2],
        ],
        'passport' => [
            'owner_name' => ['label' => 'الاسم المكتوب على الجواز', 'type' => 'text', 'weight' => 5],
            'issuer' => ['label' => 'جهة الإصدار', 'type' => 'select', 'options' => ['فلسطيني', 'مصري', 'أردني', 'أخرى'], 'weight' => 2],
        ],
        'unrwa_card' => [
            'owner_name' => ['label' => 'اسم صاحب الكرت', 'type' => 'text', 'weight' => 5],
        ],
        'phone' => [
            'brand' => ['label' => 'الماركة', 'type' => 'select', 'options' => ['سامسونج', 'آيفون', 'شاومي', 'هواوي', 'أوبو', 'ريلمي', 'إنفنكس', 'تكنو', 'نوكيا', 'أخرى'], 'weight' => 3],
            'model' => ['label' => 'الموديل', 'type' => 'text', 'weight' => 2, 'hint' => 'مثال: A52 أو 13 برو'],
            'color' => $color,
            'case' => ['label' => 'الغطاء (الجراب)', 'type' => 'text', 'weight' => 1, 'hint' => 'مثال: جراب شفاف، بدون غطاء'],
            'wallpaper' => ['label' => 'علامة يعرفها صاحبه فقط', 'type' => 'text', 'weight' => 3, 'private' => true, 'hint' => 'مثل صورة الخلفية أو كسر في الشاشة. لا تظهر لأحد.'],
        ],
        'laptop' => [
            'brand' => ['label' => 'الماركة', 'type' => 'select', 'options' => ['ديل', 'إتش بي', 'لينوفو', 'أسوس', 'أيسر', 'أبل', 'سامسونج', 'أخرى'], 'weight' => 3],
            'device' => ['label' => 'نوع الجهاز', 'type' => 'select', 'options' => ['لابتوب', 'تابلت', 'آيباد'], 'weight' => 2],
            'color' => $color,
            'model' => ['label' => 'الموديل', 'type' => 'text', 'weight' => 2],
        ],
        'bag' => [
            'bag_type' => ['label' => 'النوع', 'type' => 'select', 'options' => ['حقيبة ظهر', 'حقيبة يد', 'محفظة', 'شنطة سفر', 'حقيبة لابتوب', 'كيس'], 'weight' => 3],
            'color' => $color,
            'material' => ['label' => 'الخامة', 'type' => 'select', 'options' => ['جلد', 'قماش', 'بلاستيك', 'أخرى'], 'weight' => 1],
            'contents' => ['label' => 'ما بداخلها', 'type' => 'text', 'weight' => 4, 'private' => true, 'hint' => 'يُستخدم للمطابقة فقط ولا يظهر لأحد.'],
        ],
        'document' => [
            'doc_type' => ['label' => 'نوع الوثيقة', 'type' => 'select', 'options' => ['شهادة ميلاد', 'شهادة جامعية', 'شهادة مدرسية', 'عقد أو ورقة رسمية', 'تقارير طبية', 'دفتر عائلة', 'أخرى'], 'weight' => 3],
            'owner_name' => ['label' => 'الاسم المكتوب عليها', 'type' => 'text', 'weight' => 5],
        ],
        'money' => [
            'currency' => ['label' => 'العملة', 'type' => 'select', 'options' => ['شيكل', 'دولار', 'دينار', 'ذهب', 'أخرى'], 'weight' => 2],
            'container' => ['label' => 'كانت في', 'type' => 'select', 'options' => ['محفظة', 'ظرف', 'كيس', 'بدون شيء', 'أخرى'], 'weight' => 2],
            'amount' => ['label' => 'المبلغ أو الوصف التقريبي', 'type' => 'number', 'weight' => 4, 'private' => true, 'hint' => 'يُستخدم للمطابقة فقط ولا يظهر لأحد.'],
        ],
        'keys' => [
            'key_type' => ['label' => 'مفاتيح ماذا؟', 'type' => 'select', 'options' => ['بيت', 'سيارة', 'دراجة نارية', 'محل', 'خزانة', 'أخرى'], 'weight' => 3],
            'count' => ['label' => 'عدد المفاتيح', 'type' => 'number', 'weight' => 2],
            'keychain' => ['label' => 'الميدالية (السلسلة)', 'type' => 'text', 'weight' => 3, 'hint' => 'مثال: ميدالية جلد بنية عليها حرف م'],
        ],
        'other' => [
            'item_name' => ['label' => 'ما هو الغرض؟', 'type' => 'text', 'weight' => 4],
            'color' => $color,
        ],
    ];
}

/**
 * Read the submitted specifications for one category (only known keys, cleaned).
 */
function readAttributes(string $category, $input): array
{
    $schema = attributeSchema()[$category] ?? [];
    $input = is_array($input) ? $input : [];
    $out = [];
    foreach ($schema as $key => $f) {
        $v = trim((string)($input[$key] ?? ''));
        if ($v === '') continue;
        if ($f['type'] === 'select') {
            if (!in_array($v, $f['options'], true)) continue;
        } elseif ($f['type'] === 'number') {
            $v = preg_replace('/[^\d.]/', '', strtr($v, '٠١٢٣٤٥٦٧٨٩', '0123456789'));
            if ($v === '' || !is_numeric($v)) continue;
            $v = (string)(0 + $v);
        } else {
            $v = mb_substr(sanitize($v), 0, 80);
        }
        $out[$key] = $v;
    }
    return $out;
}

/**
 * Public specifications as label/value pairs (private fields left out).
 */
function publicAttributes(string $category, ?array $attrs): array
{
    $schema = attributeSchema()[$category] ?? [];
    $out = [];
    foreach ((array)$attrs as $key => $v) {
        if (!isset($schema[$key]) || !empty($schema[$key]['private']) || $v === '') continue;
        $out[] = ['label' => $schema[$key]['label'], 'value' => $v];
    }
    return $out;
}

/**
 * Searchable text from public specifications (feeds normalized_name).
 */
function attributesText(string $category, array $attrs): string
{
    return implode(' ', array_column(publicAttributes($category, $attrs), 'value'));
}

/**
 * A short title built from the specifications, e.g. "هاتف سامسونج A52 أزرق".
 */
function buildTitle(string $categoryLabel, array $attrs, string $category): string
{
    $parts = [$categoryLabel];
    foreach (publicAttributes($category, $attrs) as $a) {
        $parts[] = $a['value'];
        if (count($parts) >= 4) break;
    }
    return mb_substr(implode(' ', $parts), 0, 100);
}

/**
 * Decode the JSON column safely.
 */
function decodeAttributes(?string $json): array
{
    if (empty($json)) return [];
    $a = json_decode($json, true);
    return is_array($a) ? $a : [];
}

/**
 * Print the specification fields for every category; JS shows only the chosen one.
 * Inputs are named attr[key]; inactive groups are rendered disabled (so their same-named
 * fields are never submitted) and site.js enables the group of the chosen type.
 */
function renderAttributeFields(string $selected, array $values = []): void
{
    foreach (attributeSchema() as $cat => $fields) {
        $active = $cat === $selected;
        $dis = $active ? '' : ' disabled';
        echo '<fieldset class="attr-group' . ($active ? ' active' : '') . '" data-cat="' . htmlspecialchars($cat) . '">';
        echo '<legend class="sr-only">المواصفات</legend>';
        echo '<div class="attr-grid">';
        foreach ($fields as $key => $f) {
            $id = 'a-' . $cat . '-' . $key;
            $val = $active ? (string)($values[$key] ?? '') : '';
            echo '<div class="form-group">';
            echo '<label class="form-label" for="' . $id . '">' . htmlspecialchars($f['label'])
                . (!empty($f['private']) ? ' <span class="private-tag">' . icon('lock') . ' خاص</span>' : '') . '</label>';
            if ($f['type'] === 'select') {
                echo '<select class="form-control" name="attr[' . $key . ']" id="' . $id . '"' . $dis . '><option value="">اختر</option>';
                foreach ($f['options'] as $o) {
                    echo '<option' . ($val === $o ? ' selected' : '') . '>' . htmlspecialchars($o) . '</option>';
                }
                echo '</select>';
            } else {
                $mode = $f['type'] === 'number' ? ' inputmode="numeric"' : '';
                echo '<input type="text" class="form-control" name="attr[' . $key . ']" id="' . $id . '" value="'
                    . htmlspecialchars($val) . '"' . $mode . $dis . ' maxlength="80" autocomplete="off">';
            }
            if (!empty($f['hint'])) {
                echo '<div class="form-text">' . htmlspecialchars($f['hint']) . '</div>';
            }
            echo '</div>';
        }
        echo '</div></fieldset>';
    }
}
