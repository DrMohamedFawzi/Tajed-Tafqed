<?php
/**
 * فَقَدتُ ووَجَدتُ - Lost and Found Platform for Gaza
 * Security Functions
 * 
 * Input sanitization, hashing, rate limiting, CSRF protection,
 * Arabic text normalization, and security headers.
 */

require_once __DIR__ . '/db.php';

/**
 * Sanitize a single input string.
 *
 * @param string|null $input
 * @return string
 */
function sanitize(?string $input): string
{
    if ($input === null) {
        return '';
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Sanitize all values in an associative array.
 *
 * @param array $array
 * @return array
 */
function sanitizeAll(array $array): array
{
    $sanitized = [];
    foreach ($array as $key => $value) {
        if (is_array($value)) {
            $sanitized[$key] = sanitizeAll($value);
        } elseif (is_string($value)) {
            $sanitized[$key] = sanitize($value);
        } else {
            $sanitized[$key] = $value;
        }
    }
    return $sanitized;
}

/**
 * Hash an identifier (e.g., ID number, serial number) using SHA-256.
 * Normalizes by trimming, lowercasing, and removing spaces/dashes.
 *
 * @param string $identifier
 * @return string
 */
function hashIdentifier(string $identifier): string
{
    // Normalize: trim, lowercase, remove spaces and dashes
    $normalized = strtolower(trim($identifier));
    $normalized = preg_replace('/[\s\-]/', '', $normalized);
    return hash('sha256', $normalized);
}

/**
 * Hash a secret answer using bcrypt.
 *
 * @param string $answer
 * @return string
 */
function hashAnswer(string $answer): string
{
    $normalized = normalizeArabic($answer);
    return password_hash($normalized, PASSWORD_BCRYPT);
}

/**
 * Verify a secret answer against its bcrypt hash.
 *
 * @param string $answer
 * @param string $hash
 * @return bool
 */
function verifyAnswer(string $answer, string $hash): bool
{
    $normalized = normalizeArabic($answer);
    return password_verify($normalized, $hash);
}

/**
 * Normalize Arabic text for consistent searching and comparison.
 * - Removes diacritics (tashkeel)
 * - Normalizes alef variants → ا
 * - Normalizes taa marbuta ة → ه
 * - Normalizes yaa ى → ي
 * - Removes extra spaces, trims
 * - Lowercases
 *
 * @param string $text
 * @return string
 */
function normalizeArabic(string $text): string
{
    // Remove Arabic diacritics (tashkeel)
    // ً (fathatan), ٌ (dammatan), ٍ (kasratan), َ (fatha), ُ (damma), ِ (kasra), ّ (shadda), ْ (sukun)
    $diacritics = [
        "\xD9\x8B", // ً fathatan
        "\xD9\x8C", // ٌ dammatan
        "\xD9\x8D", // ٍ kasratan
        "\xD9\x8E", // َ fatha
        "\xD9\x8F", // ُ damma
        "\xD9\x90", // ِ kasra
        "\xD9\x91", // ّ shadda
        "\xD9\x92", // ْ sukun
    ];
    $text = str_replace($diacritics, '', $text);

    // Normalize alef variants (أ إ آ ا) → ا
    $alefVariants = [
        "\xD8\xA3", // أ alef with hamza above
        "\xD8\xA5", // إ alef with hamza below
        "\xD8\xA2", // آ alef with madda
    ];
    $text = str_replace($alefVariants, "\xD8\xA7", $text); // Replace with ا

    // Normalize taa marbuta ة → ه
    $text = str_replace("\xD8\xA9", "\xD9\x87", $text);

    // Normalize alef maqsura ى → ي
    $text = str_replace("\xD9\x89", "\xD9\x8A", $text);

    // Remove extra spaces and trim
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = trim($text);

    // Lowercase (for any Latin characters that might be mixed in)
    $text = mb_strtolower($text, 'UTF-8');

    return $text;
}

/**
 * Check if an IP is rate limited for a specific action.
 * Also cleans up old rate limit entries.
 *
 * @param string $ip
 * @param string $action
 * @param int $maxAttempts Maximum attempts allowed in the window
 * @param int $windowMinutes Time window in minutes
 * @return bool True if the request is allowed, false if rate limited
 */
function checkRateLimit(string $ip, string $action, int $maxAttempts = 10, int $windowMinutes = 1): bool
{
    $db = getDB();

    // Clean up old entries for this action only (other actions use different windows)
    $cleanupStmt = $db->prepare(
        "DELETE FROM rate_limits WHERE action = :action AND timestamp < datetime('now', :window)"
    );
    $cleanupStmt->bindValue(':action', $action);
    $cleanupStmt->bindValue(':window', "-{$windowMinutes} minutes");
    $cleanupStmt->execute();

    // Count recent attempts
    $countStmt = $db->prepare(
        "SELECT COUNT(*) as cnt FROM rate_limits 
         WHERE ip_address = :ip AND action = :action 
         AND timestamp > datetime('now', :window)"
    );
    $countStmt->bindValue(':ip', $ip);
    $countStmt->bindValue(':action', $action);
    $countStmt->bindValue(':window', "-{$windowMinutes} minutes");
    $countStmt->execute();
    $count = (int)$countStmt->fetch()['cnt'];

    if ($count >= $maxAttempts) {
        return false; // Rate limited
    }

    // Record this attempt
    $insertStmt = $db->prepare(
        "INSERT INTO rate_limits (ip_address, action) VALUES (:ip, :action)"
    );
    $insertStmt->bindValue(':ip', $ip);
    $insertStmt->bindValue(':action', $action);
    $insertStmt->execute();

    return true; // Allowed
}

/**
 * Log an action to the audit log.
 *
 * @param string $action
 * @param string $details
 */
function logAction(string $action, string $details = ''): void
{
    $db = getDB();
    $ip = getClientIP();
    $stmt = $db->prepare(
        "INSERT INTO audit_logs (action, details, ip_address) VALUES (:action, :details, :ip)"
    );
    $stmt->bindValue(':action', $action);
    $stmt->bindValue(':details', $details);
    $stmt->bindValue(':ip', $ip);
    $stmt->execute();
}

/**
 * Get the real client IP address.
 * Uses REMOTE_ADDR only: the web server already restores the visitor IP from
 * trusted proxies (Cloudflare -> nginx -> Apache). Raw X-Forwarded-For is
 * client-controlled and would let anyone bypass the rate limits.
 *
 * @return string
 */
function getClientIP(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Generate a private owner code for managing a report, e.g. "K7P-29M".
 * Uses an alphabet without look-alike characters (0/O, 1/I/L).
 *
 * @return string
 */
function generateOwnerCode(): string
{
    $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    $code = '';
    for ($i = 0; $i < 6; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return substr($code, 0, 3) . '-' . substr($code, 3);
}

/**
 * Normalize an owner code typed by a user (case, spaces, dashes).
 *
 * @param string $code
 * @return string
 */
function normalizeOwnerCode(string $code): string
{
    return strtoupper(preg_replace('/[\s\-]/', '', $code));
}

/**
 * Hash an owner code for storage.
 *
 * @param string $code
 * @return string
 */
function hashOwnerCode(string $code): string
{
    return password_hash(normalizeOwnerCode($code), PASSWORD_BCRYPT);
}

/**
 * Verify an owner code against its stored hash.
 *
 * @param string $code
 * @param string|null $hash
 * @return bool
 */
function verifyOwnerCode(string $code, ?string $hash): bool
{
    if (empty($hash) || trim($code) === '') {
        return false;
    }
    return password_verify(normalizeOwnerCode($code), $hash);
}

/**
 * Set security response headers.
 */
function setSecurityHeaders(): void
{
    if (headers_sent()) {
        return;
    }
    // Content Security Policy - allow Tailwind CDN, DaisyUI, and Google Fonts
    header("Content-Security-Policy: default-src 'self' 'unsafe-inline' data: blob:; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://cdn.tailwindcss.com; font-src 'self' https://cdn.jsdelivr.net https://fonts.googleapis.com https://fonts.gstatic.com data:; img-src 'self' data: blob: https:; connect-src 'self'");
    
    // Prevent clickjacking
    header('X-Frame-Options: SAMEORIGIN');
    
    // Prevent MIME type sniffing
    header('X-Content-Type-Options: nosniff');
    
    // Referrer policy - don't leak info
    header('Referrer-Policy: strict-origin-when-cross-origin');
    
    // XSS Protection (legacy browsers)
    header('X-XSS-Protection: 1; mode=block');
}

/**
 * Generate a CSRF token and store it in the session.
 *
 * @return string
 */
function generateCSRFToken(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Verify a CSRF token against the one stored in the session.
 *
 * @param string $token
 * @return bool
 */
function verifyCSRFToken(string $token): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['csrf_token'])) {
        return false;
    }

    $valid = hash_equals($_SESSION['csrf_token'], $token);

    // Regenerate token after verification to prevent reuse
    if ($valid) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $valid;
}

/**
 * Mask a phone number, showing only the last 4 digits.
 * Example: 0591234567 → ****4567
 *
 * @param string $phone
 * @return string
 */
function maskPhone(string $phone): string
{
    $phone = trim($phone);
    $len = mb_strlen($phone);

    if ($len <= 4) {
        return $phone;
    }

    $visible = mb_substr($phone, -4);
    $masked = str_repeat('*', $len - 4);

    return $masked . $visible;
}
