<?php
/**
 * فَقَدتُ ووَجَدتُ - Lost and Found Platform for Gaza
 * Matching & Search Algorithms
 * 
 * Provides identifier-based matching, fuzzy Arabic name search,
 * radar alerts, transport search, paginated browsing, and item resolution.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

/**
 * Search for active items by identifier hash.
 */
function searchByIdentifier(string $hash): array
{
    $db = getDB();
    $stmt = $db->prepare(
        "SELECT id, type, category, title_or_name, location, location_area, 
                drop_point, description, event_date, attributes, secret_question, created_at,
                (contact_info IS NOT NULL AND contact_info != '') AS has_contact
         FROM items 
         WHERE identifier_hash = :hash AND status = 'active'
         ORDER BY created_at DESC"
    );
    $stmt->bindValue(':hash', $hash);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Fuzzy Arabic name search with relevance scoring.
 */
function searchByName(string $name): array
{
    $db = getDB();

    $normalized = normalizeArabic($name);
    $words = array_filter(explode(' ', $normalized));

    if (empty($words)) {
        return [];
    }

    $conditions = [];
    $params = [];
    foreach ($words as $i => $word) {
        $paramName = ":word{$i}";
        $conditions[] = "normalized_name LIKE {$paramName}";
        $params[$paramName] = "%{$word}%";
    }

    $scoreExpressions = [];
    foreach ($words as $i => $word) {
        $paramName = ":score_word{$i}";
        $scoreExpressions[] = "(CASE WHEN normalized_name LIKE {$paramName} THEN 1 ELSE 0 END)";
        $params[$paramName] = "%{$word}%";
    }
    $scoreSQL = implode(' + ', $scoreExpressions);
    $whereSQL = implode(' OR ', $conditions);

    $sql = "SELECT id, type, category, title_or_name, normalized_name, location, 
                   location_area, drop_point, description, event_date, attributes, created_at,
                   ({$scoreSQL}) as relevance_score
            FROM items 
            WHERE status = 'active' AND ({$whereSQL})
            ORDER BY relevance_score DESC, created_at DESC
            LIMIT 50";

    $stmt = $db->prepare($sql);
    foreach ($params as $param => $value) {
        $stmt->bindValue($param, $value);
    }
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Search safety tags by tag code.
 */
function searchByTagCode(string $code)
{
    $db = getDB();
    $stmt = $db->prepare(
        "SELECT id, tag_code, owner_name, contact_phone, contact_alt, item_description, created_at
         FROM safety_tags 
         WHERE tag_code = :code"
    );
    $stmt->bindValue(':code', trim($code));
    $stmt->execute();
    $result = $stmt->fetch();
    return $result !== false ? $result : false;
}

/**
 * Check if any radar subscriber is waiting for a given identifier.
 */
function checkRadarMatch(string $identifierHash): array
{
    $db = getDB();
    $stmt = $db->prepare(
        "SELECT id, target_name, subscriber_phone, alt_phone, created_at
         FROM radar_subscribers 
         WHERE target_identifier_hash = :hash"
    );
    $stmt->bindValue(':hash', $identifierHash);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Register a new radar subscriber for automatic matching.
 */
function registerRadar(string $identifierHash, string $name, string $phone, ?string $altPhone = null): int
{
    $db = getDB();
    $stmt = $db->prepare(
        "INSERT INTO radar_subscribers (target_identifier_hash, target_name, subscriber_phone, alt_phone) 
         VALUES (:hash, :name, :phone, :alt)"
    );
    $stmt->bindValue(':hash', $identifierHash);
    $stmt->bindValue(':name', $name);
    $stmt->bindValue(':phone', $phone);
    $stmt->bindValue(':alt', $altPhone);
    $stmt->execute();

    logAction('radar_register', "Radar registered for: {$name}");

    return (int)$db->lastInsertId();
}

/**
 * Search for transport-related lost items.
 */
function searchTransport(?string $fromArea, ?string $toArea, ?string $date, ?string $timeRange): array
{
    $db = getDB();

    $conditions = ["status = 'active'"];
    $params = [];

    if (!empty($fromArea)) {
        $conditions[] = "(transport_route LIKE :from_area OR location_area = :from_area_exact)";
        $params[':from_area'] = "%{$fromArea}%";
        $params[':from_area_exact'] = $fromArea;
    }

    if (!empty($toArea)) {
        $conditions[] = "transport_route LIKE :to_area";
        $params[':to_area'] = "%{$toArea}%";
    }

    if (!empty($date)) {
        $conditions[] = "DATE(created_at) = :date";
        $params[':date'] = $date;
    }

    if (!empty($timeRange)) {
        $conditions[] = "transport_time LIKE :time";
        $params[':time'] = "%{$timeRange}%";
    }

    $whereSQL = implode(' AND ', $conditions);
    $sql = "SELECT id, type, category, title_or_name, location, location_area, 
                   transport_route, transport_time, transport_vehicle, 
                   drop_point, created_at
            FROM items 
            WHERE {$whereSQL}
            ORDER BY created_at DESC
            LIMIT 50";

    $stmt = $db->prepare($sql);
    foreach ($params as $param => $value) {
        $stmt->bindValue($param, $value);
    }
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Get items for a specific area, optionally filtered by type.
 */
function getItemsByArea(string $area, ?string $type = null): array
{
    $db = getDB();

    $sql = "SELECT id, type, category, title_or_name, location, location_area, 
                   drop_point, created_at
            FROM items 
            WHERE location_area = :area AND status = 'active'";
    $params = [':area' => $area];

    if ($type !== null && in_array($type, ['lost', 'found'], true)) {
        $sql .= " AND type = :type";
        $params[':type'] = $type;
    }

    $sql .= " ORDER BY created_at DESC LIMIT 100";

    $stmt = $db->prepare($sql);
    foreach ($params as $param => $value) {
        $stmt->bindValue($param, $value);
    }
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Get paginated items with optional filters.
 */
function getPaginatedItems(int $page = 1, int $perPage = 6, ?string $type = null, ?string $category = null, ?string $area = null, string $status = 'active'): array
{
    $db = getDB();
    $page = max(1, $page);
    $offset = ($page - 1) * $perPage;

    $conditions = [];
    $params = [];

    if ($status === 'active') {
        $conditions[] = "status = 'active'";
    }

    if (!empty($type) && in_array($type, ['lost', 'found'], true)) {
        $conditions[] = "type = :type";
        $params[':type'] = $type;
    }

    if (!empty($category)) {
        $conditions[] = "category = :category";
        $params[':category'] = $category;
    }

    if (!empty($area)) {
        $conditions[] = "(location_area = :area OR location LIKE :area_like)";
        $params[':area'] = $area;
        $params[':area_like'] = "%{$area}%";
    }

    $whereSQL = !empty($conditions) ? "WHERE " . implode(' AND ', $conditions) : "";
    $sql = "SELECT id, type, category, title_or_name, location, location_area, drop_point, 
                   secret_question, status, created_at, description, event_date, attributes,
                   (contact_info IS NOT NULL AND contact_info != '') AS has_contact,
                   transport_route, transport_time, transport_vehicle
            FROM items 
            {$whereSQL}
            ORDER BY created_at DESC 
            LIMIT :limit OFFSET :offset";

    $stmt = $db->prepare($sql);
    foreach ($params as $param => $value) {
        $stmt->bindValue($param, $value);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Get total count for paginated query.
 */
function getPaginatedCount(?string $type = null, ?string $category = null, ?string $area = null, string $status = 'active'): int
{
    $db = getDB();
    $conditions = [];
    $params = [];

    if ($status === 'active') {
        $conditions[] = "status = 'active'";
    }

    if (!empty($type) && in_array($type, ['lost', 'found'], true)) {
        $conditions[] = "type = :type";
        $params[':type'] = $type;
    }

    if (!empty($category)) {
        $conditions[] = "category = :category";
        $params[':category'] = $category;
    }

    if (!empty($area)) {
        $conditions[] = "(location_area = :area OR location LIKE :area_like)";
        $params[':area'] = $area;
        $params[':area_like'] = "%{$area}%";
    }

    $whereSQL = !empty($conditions) ? "WHERE " . implode(' AND ', $conditions) : "";
    $stmt = $db->prepare("SELECT COUNT(*) FROM items {$whereSQL}");
    foreach ($params as $param => $value) {
        $stmt->bindValue($param, $value);
    }
    $stmt->execute();
    return (int)$stmt->fetchColumn();
}

/**
 * Mark an item as resolved.
 */
function resolveItem(int $itemId, string $category, string $location): bool
{
    $db = getDB();

    try {
        $db->beginTransaction();

        $getStmt = $db->prepare("SELECT category as item_cat, location_area FROM items WHERE id = :id");
        $getStmt->bindValue(':id', $itemId, PDO::PARAM_INT);
        $getStmt->execute();
        $item = $getStmt->fetch();

        if (!$item) {
            $db->rollBack();
            return false;
        }

        $updateStmt = $db->prepare(
            "UPDATE items SET 
                status = 'resolved', 
                resolved_category = :category,
                contact_info = NULL, 
                contact_alt = NULL, 
                resolved_at = datetime('now')
             WHERE id = :id"
        );
        $updateStmt->bindValue(':id', $itemId, PDO::PARAM_INT);
        $updateStmt->bindValue(':category', $category);
        $updateStmt->execute();

        $logStmt = $db->prepare(
            "INSERT INTO success_log (item_category, item_location) 
             VALUES (:cat, :loc)"
        );
        $logStmt->bindValue(':cat', $category ?: $item['item_cat']);
        $logStmt->bindValue(':loc', $location ?: $item['location_area']);
        $logStmt->execute();

        logAction('item_resolved', "Item ID {$itemId} marked as resolved");

        $db->commit();
        return true;
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return false;
    }
}

/**
 * Close a report that is no longer needed (not counted as a returned item).
 * Contact details are erased like on resolve.
 */
function closeItem(int $itemId): bool
{
    $db = getDB();
    $stmt = $db->prepare(
        "UPDATE items SET 
            status = 'resolved', 
            resolved_category = 'withdrawn',
            contact_info = NULL, 
            contact_alt = NULL, 
            resolved_at = datetime('now')
         WHERE id = :id AND status = 'active'"
    );
    $stmt->bindValue(':id', $itemId, PDO::PARAM_INT);
    $stmt->execute();
    $done = $stmt->rowCount() > 0;
    if ($done) {
        logAction('item_withdrawn', "Item ID {$itemId} withdrawn by its owner");
    }
    return $done;
}

/**
 * Bulk insert items for organizations / hospitals.
 * All rows share the organisation's phone and one owner code.
 */
function bulkInsertItems(array $items, string $location, string $dropPoint, string $ip, ?string $phone = null, ?string $ownerCodeHash = null): int
{
    $db = getDB();
    $insertedCount = 0;

    try {
        $db->beginTransaction();

        $stmt = $db->prepare(
            "INSERT INTO items (type, category, identifier_hash, title_or_name, normalized_name, 
                               location, location_area, drop_point, contact_info, owner_code_hash, ip_address) 
             VALUES ('found', :category, :hash, :title, :norm, :loc, :area, :drop, :phone, :owner, :ip)"
        );

        foreach ($items as $item) {
            $identifier = trim($item['identifier'] ?? '');
            $name = trim($item['name'] ?? '');
            $category = $item['category'] ?? 'id_card';

            if (empty($name) && empty($identifier)) {
                continue;
            }

            $hash = !empty($identifier) ? hashIdentifier($identifier) : null;
            $normalizedName = !empty($name) ? normalizeArabic($name) : null;

            $stmt->bindValue(':category', $category);
            $stmt->bindValue(':hash', $hash);
            $stmt->bindValue(':title', $name ?: $identifier);
            $stmt->bindValue(':norm', $normalizedName);
            $stmt->bindValue(':loc', $location);
            $stmt->bindValue(':area', $location);
            $stmt->bindValue(':drop', $dropPoint);
            $stmt->bindValue(':phone', $phone ?: null);
            $stmt->bindValue(':owner', $ownerCodeHash);
            $stmt->bindValue(':ip', $ip);
            $stmt->execute();

            $insertedCount++;
        }

        $db->commit();
        logAction('bulk_insert', "Bulk inserted {$insertedCount} items from {$location}");
        return $insertedCount;
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return 0;
    }
}

/**
 * Rank active reports of one category against a set of specifications.
 *
 * Score (0-100) = 85% specification match + 10% same area + 5% date within a week.
 * - select fields: exact match; numbers: within 15% (half credit within 35%);
 *   text: share of the seeker's words found in the item's field, or half credit
 *   when found only in the item's title/description (older reports without specs).
 * - a field the item left empty counts as unknown (half weight in the total).
 *
 * @param string      $category  items.category
 * @param array       $attrs     readAttributes() output from the seeker
 * @param string|null $type      'lost' | 'found' to look only at that side
 * @return array items (public columns) with match_score and matched_labels
 */
function matchItems(string $category, array $attrs, ?string $type = null, ?string $area = null, ?string $date = null, int $limit = 12, int $excludeId = 0): array
{
    require_once __DIR__ . '/attributes.php';
    $schema = attributeSchema()[$category] ?? [];
    $attrs = array_intersect_key($attrs, $schema);
    if (empty($attrs)) {
        return [];
    }

    $db = getDB();
    $sql = "SELECT id, type, category, title_or_name, normalized_name, location, location_area, drop_point,
                   secret_question, status, created_at, description, event_date, attributes,
                   (contact_info IS NOT NULL AND contact_info != '') AS has_contact,
                   transport_route, transport_time, transport_vehicle
            FROM items
            WHERE status = 'active' AND category = :cat AND id != :ex";
    if ($type === 'lost' || $type === 'found') {
        $sql .= " AND type = :type";
    }
    $sql .= " ORDER BY created_at DESC LIMIT 400";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':cat', $category);
    $stmt->bindValue(':ex', $excludeId, PDO::PARAM_INT);
    if ($type === 'lost' || $type === 'found') {
        $stmt->bindValue(':type', $type);
    }
    $stmt->execute();

    $words = function (string $s): array {
        return array_values(array_filter(explode(' ', normalizeArabic($s)), fn($w) => mb_strlen($w) >= 2));
    };

    $results = [];
    foreach ($stmt->fetchAll() as $item) {
        $itemAttrs = decodeAttributes($item['attributes'] ?? null);
        $haystack = ' ' . normalizeArabic((string)$item['title_or_name']) . ' ' . ($item['normalized_name'] ?? '') . ' ' . normalizeArabic((string)($item['description'] ?? '')) . ' ';
        $got = 0.0;
        $total = 0.0;
        $matched = [];

        foreach ($attrs as $key => $want) {
            $f = $schema[$key];
            $w = (float)$f['weight'];
            $have = (string)($itemAttrs[$key] ?? '');

            if ($have === '') {
                $total += $w / 2;
                if ($f['type'] !== 'number') {
                    $tokens = $words($want);
                    $hits = count(array_filter($tokens, fn($t) => mb_strpos($haystack, $t) !== false));
                    if ($tokens && $hits) {
                        $got += ($w / 2) * ($hits / count($tokens));
                    }
                }
                continue;
            }

            $total += $w;
            $part = 0.0;
            if ($f['type'] === 'select') {
                $part = $want === $have ? 1.0 : 0.0;
            } elseif ($f['type'] === 'number') {
                $a = (float)$want;
                $b = (float)$have;
                $diff = abs($a - $b) / max(1.0, max($a, $b));
                $part = $diff <= 0.15 ? 1.0 : ($diff <= 0.35 ? 0.5 : 0.0);
            } else {
                $tokens = $words($want);
                $haveNorm = ' ' . normalizeArabic($have) . ' ';
                if ($tokens) {
                    $hits = count(array_filter($tokens, fn($t) => mb_strpos($haveNorm, $t) !== false));
                    $part = $hits / count($tokens);
                }
            }
            $got += $w * $part;
            if ($part >= 0.5 && empty($f['private'])) {
                $matched[] = $f['label'];
            }
        }

        $specScore = $total > 0 ? $got / $total : 0;
        $score = 85 * $specScore;
        if ($area && $area === $item['location_area']) {
            $score += 10;
        }
        if ($date && !empty($item['event_date'])) {
            $days = abs((strtotime($date) - strtotime($item['event_date'])) / 86400);
            if ($days <= 7) {
                $score += 5;
            }
        }

        if ($score >= 35) {
            $item['match_score'] = (int)round(min(100, $score));
            $item['matched_labels'] = $matched;
            unset($item['normalized_name']);
            $results[] = $item;
        }
    }

    usort($results, fn($x, $y) => $y['match_score'] <=> $x['match_score']);
    return array_slice($results, 0, $limit);
}
