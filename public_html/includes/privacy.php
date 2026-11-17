<?php
/**
 * includes/privacy.php
 * Phase 3: Privacy & Visibility Controls
 *
 * Provides helpers for:
 *   - Loading per-tenant privacy settings
 *   - Checking tree-level visibility
 *   - Applying per-field privacy filters on public output
 *   - Living-person auto-protection (P3.3)
 *   - Private member redaction (P3.4)
 */
if (!defined('FT_APP')) { http_response_code(403); exit; }

require_once __DIR__ . '/trees.php';

/** Who is asking for this tree: 'public', 'members', or 'owner' (editors included). */
function ft_tree_audience(PDO $pdo, string $tenantId): string {
    $uid = (int)($_SESSION['admin_user']['id'] ?? 0);
    if ($uid <= 0) return 'public';
    $m = ft_user_membership($pdo, $uid, $tenantId);
    if (!$m) return 'public';
    return ft_can_edit_tree($m) ? 'owner' : 'members';
}

// ──────────────────────────────────────────────────────────────
// Field definitions with their defaults
// Visibility levels: 'public' | 'members' | 'private'
// ──────────────────────────────────────────────────────────────
const PRIVACY_FIELD_DEFAULTS = [
    'birth_date'       => 'members',   // DOB – sensitive
    'birth_location'   => 'members',   // birth place
    'death_date'       => 'public',    // death date – generally public
    'death_location'   => 'public',    // death place – generally public
    'current_location' => 'members',   // home/current location
    'anniversary_date' => 'members',   // marriage anniversary
];

// Fields that are ALWAYS hidden for living people (P3.3) unless privacy_override = 1
const LIVING_PERSON_PROTECTED_FIELDS = [
    'birth_date', 'birth_location', 'current_location',
];

/**
 * Load privacy settings for a tenant.
 * Returns an array keyed by field_key with visibility values.
 * Falls back to PRIVACY_FIELD_DEFAULTS for any missing key.
 */
function loadPrivacySettings(PDO $pdo, string $tenantId): array {
    $settings = PRIVACY_FIELD_DEFAULTS; // start with defaults
    try {
        $stmt = $pdo->prepare(
            "SELECT field_key, visibility FROM privacy_settings WHERE tenant_id = ?"
        );
        $stmt->execute([$tenantId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $settings[$row['field_key']] = $row['visibility'];
        }
    } catch (PDOException $e) {
        error_log('privacy.php loadPrivacySettings: ' . $e->getMessage());
    }
    return $settings;
}

/**
 * Load a tenant row (visibility + id) from the DB.
 * Returns null if tenant not found.
 */
function loadTenant(PDO $pdo, string $tenantId): ?array {
    try {
        $stmt = $pdo->prepare(
            "SELECT id, slug, display_name, visibility, settings, data_source FROM tenants WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (PDOException $e) {
        error_log('privacy.php loadTenant: ' . $e->getMessage());
        return null;
    }
}

/**
 * Load a tenant row by its public URL slug (e.g. /tree/karn → 'karn').
 * Only returns active tenants. Returns null if not found.
 */
function getPublicTenantBySlug(PDO $pdo, string $slug): ?array {
    try {
        $stmt = $pdo->prepare(
            "SELECT id, slug, display_name, visibility, settings, data_source FROM tenants
             WHERE slug = ? AND is_active = 1
             LIMIT 1"
        );
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (PDOException $e) {
        error_log('privacy.php getPublicTenantBySlug: ' . $e->getMessage());
        return null;
    }
}

/**
 * Check tree-level visibility and abort with 403 if the tree is private
 * and the current request is a public (unauthenticated) call.
 *
 * @param string $visibility  'public' | 'unlisted' | 'private'
 * @param bool   $isAdmin     true when called from an authenticated admin session
 */
function enforceTreeVisibility(string $visibility, bool $isAdmin = false): void {
    if ($isAdmin) return; // admin always has access
    if ($visibility === 'private') {
        http_response_code(403);
        echo json_encode([
            'error'      => 'private_tree',
            'message'    => 'This family tree is private. Please log in to view it.',
        ]);
        exit;
    }
    // 'unlisted' is accessible via direct link — no block, but noindex header
    if ($visibility === 'unlisted') {
        header('X-Robots-Tag: noindex, nofollow');
    }
}

/**
 * Return a redacted placeholder for a private member (P3.4).
 * Structural fields (id, fid, mid, pids, gender) are preserved
 * so tree connections remain intact.
 */
function redactPrivateMember(array $member): array {
    return [
        'id'             => $member['id'],
        'fid'            => $member['fid'] ?? null,
        'mid'            => $member['mid'] ?? null,
        'pids'           => $member['pids'] ?? [],
        'gender'         => $member['gender'] ?? '',
        'name'           => 'Private Member',
        'nickname'       => '',
        'img'            => '',
        'birth_date'     => '',
        'birth_location' => '',
        'death_date'     => '',
        'death_location' => '',
        'current_location' => '',
        'anniversary_date' => '',
        'is_alive'       => (int)($member['is_alive'] ?? 1),
        'is_private'     => 1,
        'extra'          => [],
        'tenant_id'      => $member['tenant_id'] ?? '',
    ];
}

/**
 * Strip non-public fields from a member for the public-facing API.
 *
 * Rules applied (in order):
 *  1. If is_private = 1 → redact entirely
 *  2. Living-person protection: if is_alive = 1 and privacy_override = 0,
 *     force LIVING_PERSON_PROTECTED_FIELDS to hidden regardless of settings
 *  3. Per-field privacy: if visibility = 'members' or 'private' → strip field
 *
 * @param array  $member    Full member row
 * @param array  $settings  Output of loadPrivacySettings()
 * @param string $audience  'public' | 'members' | 'owner' (see ft_tree_audience)
 * @return array            Filtered member safe for the audience
 */
function applyPublicPrivacy(array $member, array $settings, string $audience = 'public'): array {
    // Owners and editors see the tree as it is in the admin area.
    if ($audience === 'owner') {
        unset($member['privacy_override'], $member['tenant_id']);
        $member['is_private'] = !empty($member['is_private']) ? 1 : 0;
        return $member;
    }

    // P3.4 private member redaction (owners handled above)
    if (!empty($member['is_private'])) {
        return redactPrivateMember($member);
    }

    $isLiving    = !empty($member['is_alive']);
    $hasOverride = !empty($member['privacy_override']);

    // Helper: should this field be visible to this audience?
    $visible = function(string $fieldKey) use ($settings, $isLiving, $hasOverride, $audience): bool {
        $vis = $settings[$fieldKey] ?? 'members';

        if ($audience === 'public') {
            // Living-person auto-protection (P3.3) applies to guests only.
            if ($isLiving && !$hasOverride && in_array($fieldKey, LIVING_PERSON_PROTECTED_FIELDS, true)) {
                return false;
            }
            return $vis === 'public';
        }

        // Signed-in members see everything except fields marked private.
        return $vis !== 'private';
    };

    // Apply field-by-field visibility
    if (!$visible('birth_date'))       { $member['birth_date']       = ''; }
    if (!$visible('birth_location'))   { $member['birth_location']   = ''; }
    if (!$visible('death_date'))       { $member['death_date']       = ''; }
    if (!$visible('death_location'))   { $member['death_location']   = ''; }
    if (!$visible('current_location')) { $member['current_location'] = ''; }
    if (!$visible('anniversary_date')) { $member['anniversary_date'] = ''; }

    // extra details follow the field they belong with
    $x = is_array($member['extra'] ?? null) ? $member['extra'] : [];
    if (!$visible('birth_date'))       unset($x['birth_approx'], $x['birth_tithi']);
    if (!$visible('death_date'))       unset($x['death_approx'], $x['death_tithi']);
    if (!$visible('birth_location'))   unset($x['native_place']);
    if (!$visible('anniversary_date')) unset($x['partners']);
    $member['extra'] = $x;

    // Strip internal/admin-only fields from public output
    unset($member['privacy_override'], $member['tenant_id']);
    // Keep is_private flag so the frontend can render the placeholder badge
    $member['is_private'] = 0;

    return $member;
}

/* ── per-tree settings (emblem, colours) ──────────────────────────────────── */
const FT_SETTINGS_DEFAULTS = [
    'emblem' => ['kind' => 'none', 'id' => 'banyan', 'color' => '#D4A643', 'url' => null],   // kind: none | builtin | upload
    'accent' => null,
    'theme'  => 'heritage',   // tree look, see assets/tree-themes.js
    'cover'  => null,         // cover picture on member cards: /admin/uploads/covers/{tenant}.jpg
];
const FT_TREE_LOOKS = ['heritage', 'banyan', 'royal', 'minimal', 'parchment', 'marigold'];
/** The tree's settings with defaults filled in. Accepts a tenant row or the raw JSON. */
function ft_tenant_settings(array|string|null $tenant): array {
    $raw = is_array($tenant) ? ($tenant['settings'] ?? null) : $tenant;
    $s = $raw ? json_decode((string)$raw, true) : null;
    $s = is_array($s) ? $s : [];
    $s['emblem'] = array_merge(FT_SETTINGS_DEFAULTS['emblem'], is_array($s['emblem'] ?? null) ? $s['emblem'] : []);
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string)$s['emblem']['color'])) $s['emblem']['color'] = FT_SETTINGS_DEFAULTS['emblem']['color'];
    if (!in_array($s['emblem']['kind'], ['none', 'builtin', 'upload'], true)) $s['emblem']['kind'] = 'none';
    $s['theme'] = in_array($s['theme'] ?? '', FT_TREE_LOOKS, true) ? $s['theme'] : 'heritage';
    if (isset($s['hindu_calendar'])) $s['hindu_calendar'] = (bool)$s['hindu_calendar'];
    $s['cover'] = (isset($s['cover']) && preg_match('~^/admin/uploads/covers/[A-Za-z0-9-]+\.(jpg|png|webp)(\?v=\d+)?$~', (string)$s['cover'])) ? $s['cover'] : null;
    $s['accent'] = (isset($s['accent']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string)$s['accent'])) ? $s['accent'] : null;
    return $s;
}
