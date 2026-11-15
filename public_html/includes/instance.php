<?php
/**
 * includes/instance.php - settings for this installation, not for one tree.
 *
 * The instance is the whole self-hosted FamilyTree: its name, the tree that
 * visitors see by default, and the defaults copied into every new tree.
 * Nothing here belongs to a tree, and nothing here is a secret.
 */
require_once __DIR__ . '/db.php';

const FT_INSTANCE_DEFAULTS = [
    'instance_name'             => 'FamilyTree',
    'default_tenant_id'         => '',
    'new_tree_visibility'       => 'private',
    'new_tree_theme'            => 'heritage',
    'new_tree_hindu_calendar'   => false,
    'new_tree_privacy'          => [],
];

/** All instance settings, with defaults filled in. */
function ft_instance_settings(PDO $pdo): array {
    $settings = FT_INSTANCE_DEFAULTS;

    try {
        foreach ($pdo->query('SELECT skey, svalue FROM instance_settings')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (array_key_exists($row['skey'], $settings)) $settings[$row['skey']] = $row['svalue'];
        }
    } catch (Throwable $e) {
        // An older database may not have the table yet; defaults are fine.
    }

    $name = trim((string)$settings['instance_name']);
    $settings['instance_name'] = $name !== '' ? $name : FT_INSTANCE_DEFAULTS['instance_name'];

    if (!in_array($settings['new_tree_visibility'], ['public', 'unlisted', 'private'], true)) {
        $settings['new_tree_visibility'] = FT_INSTANCE_DEFAULTS['new_tree_visibility'];
    }
    $settings['new_tree_hindu_calendar'] = filter_var($settings['new_tree_hindu_calendar'], FILTER_VALIDATE_BOOLEAN);

    $privacy = is_string($settings['new_tree_privacy']) ? json_decode($settings['new_tree_privacy'], true) : $settings['new_tree_privacy'];
    $settings['new_tree_privacy'] = is_array($privacy) ? $privacy : [];

    return $settings;
}

/** Write one instance setting. Booleans become 1/0; arrays become JSON. */
function ft_instance_set(PDO $pdo, string $key, mixed $value): void {
    if (is_bool($value)) $value = $value ? '1' : '0';
    elseif (is_array($value)) $value = json_encode($value);
    else $value = (string)$value;

    try {
        $pdo->prepare('INSERT INTO instance_settings (skey, svalue) VALUES (?, ?)')
            ->execute([$key, $value]);
    } catch (PDOException $e) {
        $pdo->prepare('UPDATE instance_settings SET svalue = ?, updated_at = CURRENT_TIMESTAMP WHERE skey = ?')
            ->execute([$value, $key]);
    }
}

/** The instance's display name. */
function ft_instance_name(PDO $pdo): string {
    return ft_instance_settings($pdo)['instance_name'];
}

/** Active trees, oldest first. Used by instance settings and tree management. */
function ft_instance_trees(PDO $pdo): array {
    try {
        $st = $pdo->query("SELECT t.id, t.slug, t.display_name, t.visibility, t.is_active,
                                  t.owner_user_id, t.created_at
                           FROM tenants t
                           WHERE t.is_active = 1
                           ORDER BY t.created_at, t.display_name");
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * The tree visitors see at /: the saved default tree when it is still active,
 * otherwise the oldest active tree. APP_TENANT_ID is handled in config.php.
 */
function ft_instance_default_tenant(PDO $pdo): ?array {
    $saved = (string)ft_instance_settings($pdo)['default_tenant_id'];
    if ($saved !== '') {
        try {
            $st = $pdo->prepare("SELECT id, slug, display_name, visibility
                                 FROM tenants WHERE id = ? AND is_active = 1 LIMIT 1");
            $st->execute([$saved]);
            if ($row = $st->fetch(PDO::FETCH_ASSOC)) return $row;
        } catch (Throwable $e) { /* fall through to the oldest tree */ }
    }

    try {
        $row = $pdo->query("SELECT id, slug, display_name, visibility
                            FROM tenants WHERE is_active = 1
                            ORDER BY created_at, display_name LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** The id of the tree visitors see at /, or '' when the instance has none. */
function ft_instance_default_tenant_id(PDO $pdo): string {
    return (string)(ft_instance_default_tenant($pdo)['id'] ?? '');
}

/** Forget a default tree that was deleted or deactivated. */
function ft_instance_forget_tree(PDO $pdo, string $tenantId): void {
    try {
        $pdo->prepare("DELETE FROM instance_settings WHERE skey = 'default_tenant_id' AND svalue = ?")
            ->execute([$tenantId]);
    } catch (Throwable $e) { /* no instance settings table yet */ }
}

/** Whether this account is an owner of any active tree, and so may use the Instance area. */
function ft_can_manage_instance(PDO $pdo, int $userId): bool {
    try {
        $st = $pdo->prepare("SELECT 1 FROM tenant_users tu
                             JOIN tenants t ON t.id = tu.tenant_id
                             WHERE tu.user_id = ? AND tu.role = 'owner' AND t.is_active = 1
                             LIMIT 1");
        $st->execute([$userId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}
