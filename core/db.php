<?php
/**
 * فَقَدتُ ووَجَدتُ - Lost and Found Platform for Gaza
 * Database Connection & Schema Initialization
 * 
 * Provides singleton PDO connection to SQLite database
 * with WAL mode, foreign keys, and auto-schema creation.
 */

// Singleton PDO instance
$_dbInstance = null;

/**
 * Get the singleton PDO database connection.
 * Creates the database and tables on first run.
 *
 * @return PDO
 */
function getDB(): PDO
{
    global $_dbInstance;

    if ($_dbInstance !== null) {
        return $_dbInstance;
    }

    $dbDir = __DIR__ . '/../storage';
    $dbPath = $dbDir . '/database.sqlite';

    // Ensure storage directory exists
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0755, true);
    }

    $isNew = !file_exists($dbPath);

    try {
        $_dbInstance = new PDO('sqlite:' . $dbPath);
        $_dbInstance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $_dbInstance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $_dbInstance->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        // Enable WAL mode for better concurrency
        $_dbInstance->exec('PRAGMA journal_mode = WAL');
        // Enable foreign keys
        $_dbInstance->exec('PRAGMA foreign_keys = ON');
        // Set busy timeout to 5 seconds
        $_dbInstance->exec('PRAGMA busy_timeout = 5000');

        // Create tables on first run or if DB is new
        if ($isNew) {
            initializeSchema($_dbInstance);
        } else {
            // Verify tables exist; if not, create them
            $check = $_dbInstance->query("SELECT name FROM sqlite_master WHERE type='table' AND name='items'");
            if ($check->fetch() === false) {
                initializeSchema($_dbInstance);
            }
        }
    } catch (PDOException $e) {
        error_log('Database connection failed: ' . $e->getMessage());
        die('خطأ في الاتصال بقاعدة البيانات'); // Database connection error
    }

    return $_dbInstance;
}

/**
 * Initialize the database schema with all required tables and indexes.
 *
 * @param PDO $db
 */
function initializeSchema(PDO $db): void
{
    $schema = <<<'SQL'
-- Main items table
CREATE TABLE IF NOT EXISTS items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type TEXT NOT NULL CHECK(type IN ('lost', 'found')),
    category TEXT NOT NULL DEFAULT 'other',
    identifier_hash TEXT,
    title_or_name TEXT,
    normalized_name TEXT,
    location TEXT,
    location_area TEXT,
    transport_route TEXT,
    transport_time TEXT,
    transport_vehicle TEXT,
    secret_question TEXT,
    secret_answer_hash TEXT,
    contact_info TEXT,
    contact_alt TEXT,
    drop_point TEXT,
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active', 'resolved')),
    resolved_category TEXT,
    resolved_at DATETIME,
    created_at DATETIME DEFAULT (datetime('now')),
    ip_address TEXT
);

CREATE INDEX IF NOT EXISTS idx_items_identifier ON items(identifier_hash);
CREATE INDEX IF NOT EXISTS idx_items_status ON items(status);
CREATE INDEX IF NOT EXISTS idx_items_type ON items(type);
CREATE INDEX IF NOT EXISTS idx_items_category ON items(category);
CREATE INDEX IF NOT EXISTS idx_items_location ON items(location_area);
CREATE INDEX IF NOT EXISTS idx_items_created ON items(created_at);

-- Radar subscribers (auto-matching)
CREATE TABLE IF NOT EXISTS radar_subscribers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    target_identifier_hash TEXT NOT NULL,
    target_name TEXT,
    subscriber_phone TEXT NOT NULL,
    alt_phone TEXT,
    created_at DATETIME DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_radar_hash ON radar_subscribers(target_identifier_hash);

-- Audit logs
CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    action TEXT NOT NULL,
    details TEXT,
    ip_address TEXT,
    timestamp DATETIME DEFAULT (datetime('now'))
);

-- Rate limiting
CREATE TABLE IF NOT EXISTS rate_limits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip_address TEXT NOT NULL,
    action TEXT NOT NULL,
    timestamp DATETIME DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_rate_ip ON rate_limits(ip_address, action);

-- Safety tags (preventive QR codes)
CREATE TABLE IF NOT EXISTS safety_tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tag_code TEXT UNIQUE NOT NULL,
    owner_name TEXT,
    contact_phone TEXT NOT NULL,
    contact_alt TEXT,
    item_description TEXT,
    created_at DATETIME DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_tag_code ON safety_tags(tag_code);

-- Success counter
CREATE TABLE IF NOT EXISTS success_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    item_category TEXT,
    item_location TEXT,
    resolved_at DATETIME DEFAULT (datetime('now'))
);
SQL;

    // Execute each statement separately for SQLite compatibility
    $statements = array_filter(
        array_map('trim', explode(';', $schema)),
        function ($s) {
            // Filter out empty strings and comment-only blocks
            $s = trim($s);
            if ($s === '') return false;
            // Remove SQL comments and check if anything remains
            $cleaned = preg_replace('/--.*$/m', '', $s);
            return trim($cleaned) !== '';
        }
    );

    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (!empty($statement)) {
            $db->exec($statement);
        }
    }
}

/**
 * Get the total count of successfully resolved items.
 *
 * @return int
 */
function getSuccessCount(): int
{
    $db = getDB();
    $stmt = $db->query('SELECT COUNT(*) as total FROM success_log');
    $result = $stmt->fetch();
    return (int)($result['total'] ?? 0);
}

/**
 * Get recent successfully resolved items.
 *
 * @param int $limit Maximum number of results to return
 * @return array
 */
function getRecentSuccesses(int $limit = 5): array
{
    $db = getDB();
    $stmt = $db->prepare('SELECT id, item_category as category, item_location as location, resolved_at FROM success_log ORDER BY resolved_at DESC LIMIT :limit');
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['time_ago'] = 'مؤخراً';
        if (!empty($row['resolved_at'])) {
            try {
                $now = new DateTime('now');
                $ago = new DateTime($row['resolved_at']);
                $diff = $now->diff($ago);
                if ($diff->d > 0) $row['time_ago'] = "قبل {$diff->d} يوم";
                elseif ($diff->h > 0) $row['time_ago'] = "قبل {$diff->h} ساعة";
                elseif ($diff->i > 0) $row['time_ago'] = "قبل {$diff->i} دقيقة";
                else $row['time_ago'] = 'الآن';
            } catch (Exception $e) {}
        }
    }
    return $rows;
}
