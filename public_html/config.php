<?php
/**
 * config.php  –  Single shared configuration for all PHP files
 * CSRF, security headers, database connection.
 *
 * There is no .env file. Every setting has a sensible default, and Docker
 * Compose (or the desktop app) can override any of them through the
 * environment. See docker-compose.yml for the optional keys.
 */

// ── Versioning ─────────────────────────────────────────────────────────────
// Schema version: bump this only when install/schema.sql or
// install/schema-sqlite.sql changes. SQLite installations re-run the schema
// once per value (the file is safe to re-run).
const FT_SCHEMA_VERSION = '9';

/**
 * Cache-busting stamp for CSS and JS, supplied by the build through the
 * environment. CI passes the commit SHA for container images; local builds
 * that leave it empty use "dev" (hard-refresh to pick up asset changes).
 */
define('FT_ASSET_VERSION', getenv('FT_ASSET_VERSION') ?: 'dev');

// ── 1. Includes ────────────────────────────────────────────────────────────
require_once __DIR__ . '/includes/db.php';        // ft_storage_dir(), ft_db_config_from_env()
require_once __DIR__ . '/includes/instance.php';  // instance-wide settings

// ── 2. Session (secure, single start) ─────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 7200,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_name('family_tree_session');
    session_start();

    // Regenerate session ID every 30 minutes
    if (!isset($_SESSION['created_at'])) {
        $_SESSION['created_at'] = time();
    } elseif (time() - $_SESSION['created_at'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['created_at'] = time();
    }

    // Generate CSRF token once per session
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

// ── 3. Database connection ─────────────────────────────────────────────────
// Self-hosted FamilyTree is SQLite: storage/familytree.sqlite (DB_FILE can override).
// "Installed" means that file holds a family (a tenant). Until it does, every
// request is sent to the installer.
$pdo       = null;
$dbConfig  = ft_db_config_from_env();
$installed = false;

if ($dbConfig['driver'] !== 'sqlite' || is_file($dbConfig['file'] ?? '')) {
    try {
        $pdo = ft_connect($dbConfig);
    } catch (PDOException $e) {
        error_log('Database connection failed: ' . $e->getMessage());
        if (!defined('FT_INSTALLER')) {
            http_response_code(503);
            die(json_encode(['error' => 'Service temporarily unavailable']));
        }
    }
    if ($pdo instanceof PDO) {
        try { $installed = (bool)$pdo->query('SELECT 1 FROM tenants LIMIT 1')->fetchColumn(); }
        catch (PDOException $e) { $installed = false; }   // no schema yet
    }
}
if (!$installed && !defined('FT_INSTALLER') && !str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/install/')) {
    header('Location: /install/');
    exit;
}
// SQLite installs (and the desktop app) upgrade themselves: the schema file is
// safe to re-run, so run it once per schema version.
if ($installed && $pdo instanceof PDO && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
    $mark = ft_storage_dir() . '/schema-version';
    if (@file_get_contents($mark) !== FT_SCHEMA_VERSION) {
        ft_run_sql_file($pdo, ft_schema_file('sqlite'));
        @file_put_contents($mark, FT_SCHEMA_VERSION, LOCK_EX);
    }
}

// ── 4. Security headers ────────────────────────────────────────────────────
header('X-Content-Type-Options: nosniff');
// an embedded tree (/embed/{slug}) may be framed by any site; everything else only by this one
if (defined('FT_EMBEDDABLE') && FT_EMBEDDABLE) header('Content-Security-Policy: frame-ancestors *');
else header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// ── 5. CSRF validation helper ──────────────────────────────────────────────
/**
 * Call at the top of any admin endpoint that mutates data (POST/PUT/DELETE).
 * Reads the token from the X-CSRF-Token request header and compares it to the
 * session token. Exits with 403 on mismatch.
 */
function validateCsrf(): void {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, ['GET', 'OPTIONS', 'HEAD'], true)) {
        return; // read-only requests do not need CSRF validation
    }
    $token    = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['csrf_token']       ?? '';
    if (!$expected || !hash_equals($expected, $token)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid or missing CSRF token']);
        exit;
    }
}

// ── 6. Activity logging ────────────────────────────────────────────────────
function logActivity(PDO $pdo, string $action, ?string $entity_type = null, ?int $entity_id = null, ?array $changes = null): void {
    if (!isset($_SESSION['admin_user']['id'])) return;
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO family_activity_logs
             (user_id, action, entity_type, entity_id, changes, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $_SESSION['admin_user']['id'],
            $action,
            $entity_type,
            $entity_id,
            $changes ? json_encode($changes) : null,
            $_SERVER['REMOTE_ADDR']      ?? null,
            $_SERVER['HTTP_USER_AGENT']  ?? null,
        ]);
    } catch (PDOException $e) {
        error_log('logActivity failed: ' . $e->getMessage());
    }
}

// ── 7. Permission check ────────────────────────────────────────────────────
function hasPermission(string $permission): bool {
    if (empty($_SESSION['admin_user'])) return false;
    $user = $_SESSION['admin_user'];
    if ($user['role'] === 'owner') return true;
    $perms = $user['permissions'] ?? [];
    return isset($perms[$permission]) && $perms[$permission] === true;
}

// ── 8. Rate limiting (file-based, Hostinger-safe) ─────────────────────────
function checkRateLimit(string $key, int $limit = 100, int $window = 3600): void {
    $ip         = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $identifier = md5($ip . $key);
    $file       = sys_get_temp_dir() . '/ft_rl_' . $identifier;
    $now        = time();
    $cutoff     = $now - $window;

    $attempts = [];
    if (file_exists($file)) {
        $attempts = json_decode(file_get_contents($file), true) ?: [];
        $attempts = array_values(array_filter($attempts, fn($t) => $t > $cutoff));
    }

    if (count($attempts) >= $limit) {
        http_response_code(429);
        header('Retry-After: ' . $window);
        echo json_encode(['error' => 'Too many requests. Please try again later.']);
        exit;
    }

    $attempts[] = $now;
    file_put_contents($file, json_encode($attempts), LOCK_EX);
}

// ── 9. Input sanitisation ──────────────────────────────────────────────────
function sanitizeInput($input, string $type = 'string') {
    if (is_array($input)) {
        return array_map(fn($i) => sanitizeInput($i, $type), $input);
    }
    return match ($type) {
        'int'   => filter_var($input, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE),
        'email' => filter_var($input, FILTER_SANITIZE_EMAIL),
        'url'   => filter_var($input, FILTER_SANITIZE_URL),
        default => htmlspecialchars(trim((string)$input), ENT_QUOTES, 'UTF-8'),
    };
}

// ── 10. Tenant resolution ─────────────────────────────────────────────────
/**
 * The family this install serves: APP_TENANT_ID when set, otherwise the only
 * tenant in SQLite. A single-family self-host never needs to configure it.
 */
function ft_default_tenant_id(): string {
    static $id = null;
    if ($id !== null) return $id;
    $id = (string)(getenv('APP_TENANT_ID') ?: '');
    if ($id === '') {
        global $pdo;
        if ($pdo instanceof PDO) {
            $id = ft_instance_default_tenant_id($pdo);
        }
    }
    return $id;
}

/** Returns the tenant_id for the current ADMIN session, or the default family. */
function getAdminTenantId(): string {
    if (!empty($_SESSION['tenant_id'])) {
        return (string)$_SESSION['tenant_id'];
    }
    return ft_default_tenant_id();
}

/**
 * Returns the tenant_id for PUBLIC (unauthenticated) requests.
 * APP_TENANT_ID if set, otherwise the only family in SQLite.
 */
function getPublicTenantId(): string {
    return ft_default_tenant_id();
}

/**
 * Updates the activity log helper to also record the tenant.
 * Overrides the function defined above – this version adds tenant_id.
 */
function logActivityWithTenant(PDO $pdo, string $action, ?string $entity_type = null, ?int $entity_id = null, ?array $changes = null, ?string $tenant_id = null): void {
    if (!isset($_SESSION['admin_user']['id'])) return;
    $tid = $tenant_id ?? getAdminTenantId();
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO family_activity_logs
             (user_id, action, entity_type, entity_id, changes, ip_address, user_agent, tenant_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $_SESSION['admin_user']['id'],
            $action,
            $entity_type,
            $entity_id,
            $changes ? json_encode($changes) : null,
            $_SERVER['REMOTE_ADDR']     ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $tid,
        ]);
    } catch (PDOException $e) {
        error_log('logActivityWithTenant failed: ' . $e->getMessage());
    }
}
