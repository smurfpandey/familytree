<?php
/**
 * includes/trees.php - one account, many family trees.
 *
 * A login lives in family_users. Which trees it can open lives in tenant_users:
 * one row per account and tree, with the role, permissions and linked member
 * card for THAT tree. This file is the one place that reads and changes that
 * relationship.
 *
 * The active tree is session state, not a database column. A user picks any
 * tree they belong to; switching only rewrites the session.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/instance.php';

/** Trees this account can open, oldest membership first. */
function ft_user_memberships(PDO $pdo, int $userId): array {
    $st = $pdo->prepare("
        SELECT t.id, t.slug, t.display_name, t.visibility, t.settings, t.data_source,
               tu.role, tu.permissions, tu.member_id, tu.created_at AS joined_at
        FROM tenant_users tu
        JOIN tenants t ON t.id = tu.tenant_id
        WHERE tu.user_id = ? AND t.is_active = 1
        ORDER BY tu.created_at, t.display_name
    ");
    $st->execute([$userId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['permissions'] = $r['permissions'] ? json_decode($r['permissions'], true) : null;
        $r['member_id']   = $r['member_id'] !== null ? (int)$r['member_id'] : null;
    }
    unset($r);
    return $rows;
}

/** This account's membership in one tree, or null when it has none. */
function ft_user_membership(PDO $pdo, int $userId, string $tenantId): ?array {
    $st = $pdo->prepare("
        SELECT t.id, t.slug, t.display_name, t.visibility, t.settings, t.data_source,
               tu.role, tu.permissions, tu.member_id, tu.created_at AS joined_at
        FROM tenant_users tu
        JOIN tenants t ON t.id = tu.tenant_id
        WHERE tu.user_id = ? AND tu.tenant_id = ? AND t.is_active = 1
        LIMIT 1
    ");
    $st->execute([$userId, $tenantId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $r['permissions'] = $r['permissions'] ? json_decode($r['permissions'], true) : null;
    $r['member_id']   = $r['member_id'] !== null ? (int)$r['member_id'] : null;
    return $r;
}

/** The tree a fresh login opens. Any membership will do; the user can switch. */
function ft_login_membership(PDO $pdo, int $userId): ?array {
    $all = ft_user_memberships($pdo, $userId);
    return $all[0] ?? null;
}

/** Put one membership in the session: the only place the active tree is stored. */
function ft_apply_tree_session(array $m): void {
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['tenant_id']       = (string)$m['id'];
    $_SESSION['admin_user']['tenant_id']   = (string)$m['id'];
    $_SESSION['admin_user']['role']        = (string)$m['role'];
    $_SESSION['admin_user']['permissions'] = $m['permissions'] ?? null;
    $_SESSION['admin_user']['member_id']   = $m['member_id'] ?? null;
}

/** How many owners a tree has. */
function ft_tree_owner_count(PDO $pdo, string $tenantId): int {
    $st = $pdo->prepare("SELECT COUNT(*) FROM tenant_users WHERE tenant_id = ? AND role = 'owner'");
    $st->execute([$tenantId]);
    return (int)$st->fetchColumn();
}

/** Make a URL-safe link name. Returns '' when nothing usable is left. */
function ft_tree_slug(string $value): string {
    $slug = strtolower(trim($value));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    return trim(preg_replace('/-+/', '-', $slug) ?? '', '-');
}

/** A free link name based on the tree name, or the requested one when it is free. */
function ft_tree_unique_slug(PDO $pdo, string $base, ?string $requested = null): string {
    $slug = $requested !== null && trim($requested) !== '' ? ft_tree_slug($requested) : ft_tree_slug($base);
    if ($slug === '') $slug = 'tree';
    $slug = substr($slug, 0, 80);

    $exists = function (string $s) use ($pdo): bool {
        $st = $pdo->prepare('SELECT 1 FROM tenants WHERE slug = ? LIMIT 1');
        $st->execute([$s]);
        return (bool)$st->fetchColumn();
    };

    if ($requested !== null && trim($requested) !== '') {
        if ($exists($slug)) throw new InvalidArgumentException('The link name "' . $slug . '" is already used. Choose another.');
        return $slug;
    }

    $candidate = $slug;
    for ($i = 2; $i <= 99 && $exists($candidate); $i++) $candidate = substr($slug, 0, 76) . '-' . $i;
    if ($exists($candidate)) $candidate = substr($slug, 0, 70) . '-' . substr(bin2hex(random_bytes(4)), 0, 6);
    return $candidate;
}

/**
 * Create an empty tree and make $userId its owner.
 * Visibility, look, Hindu dates and per-field privacy come from the instance's
 * new-tree defaults, copied in once: later changes to those defaults never
 * touch trees that already exist.
 */
function ft_create_tree(PDO $pdo, int $userId, string $name, ?string $requestedSlug = null): array {
    $name = trim($name);
    if ($name === '') throw new InvalidArgumentException('A family name is required.');
    if (mb_strlen($name) > 120) $name = mb_substr($name, 0, 120);

    $slug     = ft_tree_unique_slug($pdo, $name, $requestedSlug);
    $tenantId = ft_uuid();
    $defaults = ft_instance_settings($pdo);

    $settings = ['theme' => $defaults['new_tree_theme']];
    if (!empty($defaults['new_tree_hindu_calendar'])) $settings['hindu_calendar'] = true;

    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO tenants (id, slug, display_name, is_active, visibility, settings, created_at)
                       VALUES (?, ?, ?, 1, ?, ?, NOW())")
            ->execute([$tenantId, $slug, $name, $defaults['new_tree_visibility'], json_encode($settings)]);
        $pdo->prepare("INSERT INTO tenant_users (tenant_id, user_id, role) VALUES (?, ?, 'owner')")
            ->execute([$tenantId, $userId]);
        $pdo->prepare("UPDATE tenants SET owner_user_id = ? WHERE id = ?")
            ->execute([$userId, $tenantId]);

        $privacy = $defaults['new_tree_privacy'];
        if (is_array($privacy)) {
            $insert = $pdo->prepare("INSERT INTO privacy_settings (tenant_id, field_key, visibility) VALUES (?, ?, ?)");
            foreach ($privacy as $field => $visibility) {
                if (!is_string($field) || !in_array($visibility, ['public', 'members', 'private'], true)) continue;
                $insert->execute([$tenantId, $field, $visibility]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    return ft_user_membership($pdo, $userId, $tenantId) ?? [
        'id' => $tenantId, 'slug' => $slug, 'display_name' => $name, 'role' => 'owner',
        'permissions' => null, 'member_id' => null, 'visibility' => $defaults['new_tree_visibility'],
    ];
}

/**
 * Change a tree's name and/or link name. The caller checks that the account
 * may manage the tree. Everything else in the tree stays exactly as it is.
 */
function ft_tree_rename(PDO $pdo, string $tenantId, string $name, ?string $requestedSlug = null): array {
    $name = trim($name);
    if ($name === '') throw new InvalidArgumentException('A family name is required.');
    if (mb_strlen($name) > 120) $name = mb_substr($name, 0, 120);

    $st = $pdo->prepare('SELECT id, slug, display_name FROM tenants WHERE id = ? LIMIT 1');
    $st->execute([$tenantId]);
    $tree = $st->fetch(PDO::FETCH_ASSOC);
    if (!$tree) throw new InvalidArgumentException('Tree not found.');

    $slug = (string)$tree['slug'];
    if ($requestedSlug !== null) {
        $wanted = ft_tree_slug($requestedSlug);
        if ($wanted === '') throw new InvalidArgumentException('The link name needs at least one letter or number.');
        if ($wanted !== $slug) $slug = ft_tree_unique_slug($pdo, $name, $wanted);
    }

    $pdo->prepare('UPDATE tenants SET display_name = ?, slug = ? WHERE id = ?')
        ->execute([$name, $slug, $tenantId]);

    return ['id' => $tenantId, 'slug' => $slug, 'display_name' => $name];
}

/**
 * Remove one membership. The account stays; other trees stay.
 * A tree cannot lose its last owner.
 */
function ft_remove_membership(PDO $pdo, string $tenantId, int $userId): void {
    $st = $pdo->prepare("SELECT role FROM tenant_users WHERE tenant_id = ? AND user_id = ? LIMIT 1");
    $st->execute([$tenantId, $userId]);
    $role = $st->fetchColumn();
    if ($role === false || $role === null) throw new InvalidArgumentException('That person is not a member of this tree.');
    if ($role === 'owner' && ft_tree_owner_count($pdo, $tenantId) <= 1) {
        throw new InvalidArgumentException('This is the tree’s last owner. Make someone else an owner first.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM tenant_users WHERE tenant_id = ? AND user_id = ?")->execute([$tenantId, $userId]);
        if ($role === 'owner') {
            // Keep tenants.owner_user_id pointing at someone who is still here.
            $st = $pdo->prepare("SELECT user_id FROM tenant_users WHERE tenant_id = ? AND role = 'owner' ORDER BY created_at, id LIMIT 1");
            $st->execute([$tenantId]);
            $next = $st->fetchColumn();
            $pdo->prepare("UPDATE tenants SET owner_user_id = ? WHERE id = ? AND owner_user_id = ?")
                ->execute([$next !== false ? (int)$next : null, $tenantId, $userId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Whether an account can edit a tree: owner or editor, not viewer. */
function ft_can_edit_tree(array $membership): bool {
    return in_array($membership['role'] ?? 'viewer', ['owner', 'editor'], true);
}

/** Whether an account can administer a tree: owner only. */
function ft_can_manage_tree(array $membership): bool {
    return ($membership['role'] ?? 'viewer') === 'owner';
}
