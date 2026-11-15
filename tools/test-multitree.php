<?php
/**
 * tools/test-multitree.php - one account, several family trees.
 *
 *   php tools/test-multitree.php        (needs pdo_sqlite)
 *
 * Asha and Bina are two accounts (a couple). They are co-owners of both
 * "sharma" and "iyer", each tree keeps its own people. Then Asha plants a
 * third tree, and later deletes one; the accounts and the other trees stay.
 */
$root = dirname(__DIR__) . '/public_html';
$tmp = sys_get_temp_dir() . '/ft-multitree-test-' . getmypid();
@mkdir("$tmp/uploads", 0777, true);
putenv("FT_STORAGE_DIR=$tmp");
putenv("FT_UPLOADS_DIR=$tmp/uploads");
require $root . '/includes/db.php';
require $root . '/includes/store.php';
require $root . '/includes/trees.php';
require $root . '/includes/account-delete.php';

$pdo = ft_connect(['driver' => 'sqlite', 'file' => "$tmp/ft.sqlite"]);
ft_run_sql_file($pdo, $root . '/install/schema-sqlite.sql');
$q = fn(string $sql, array $a = []) => (function () use ($pdo, $sql, $a) { $s = $pdo->prepare($sql); $s->execute($a); return $s; })();
$n = fn(string $sql, array $a = []) => (int)$q($sql, $a)->fetchColumn();

/** An account created with its first tree, the way users-api.php does it. */
$account = function (string $email, string $name, string $tid) use ($q, $pdo): int {
    $q("INSERT INTO family_users (email, password_hash, display_name, role, tenant_id) VALUES (?, ?, ?, 'owner', ?)",
       [$email, password_hash('pw', PASSWORD_DEFAULT), $name, $tid]);
    return (int)$pdo->lastInsertId();
};
$tree = function (string $tid, string $slug, ?int $owner) use ($q): array {
    $q("INSERT INTO tenants (id, slug, display_name, owner_user_id, visibility) VALUES (?, ?, ?, ?, 'private')",
       [$tid, $slug, ucfirst($slug), $owner]);
    return ['id' => $tid, 'slug' => $slug, 'display_name' => ucfirst($slug), 'visibility' => 'private'];
};
$member = fn(array $t, string $name) => ft_store($pdo, $t)->insertMember(['name' => $name]);

$sharma = $tree('T-sharma', 'sharma', null);
$iyer   = $tree('T-iyer', 'iyer', null);
$asha   = $account('asha@example.test', 'Asha', 'T-sharma');
$bina   = $account('bina@example.test', 'Bina', 'T-iyer');

// Both accounts are co-owners of both trees.
foreach ([[$sharma, $asha], [$sharma, $bina], [$iyer, $asha], [$iyer, $bina]] as [$t, $u])
    $q("INSERT INTO tenant_users (tenant_id, user_id, role) VALUES (?, ?, 'owner')", [$t['id'], $u]);
$q("UPDATE tenants SET owner_user_id = ? WHERE id = ?", [$asha, 'T-sharma']);
$q("UPDATE tenants SET owner_user_id = ? WHERE id = ?", [$bina, 'T-iyer']);

$member($sharma, 'Sharma Grandma');
$member($iyer, 'Iyer Grandma');

// Each account sees exactly the two trees, and can open either one.
$ashaTrees = ft_user_memberships($pdo, $asha);
assert(array_column($ashaTrees, 'slug') === ['iyer', 'sharma'], 'Asha belongs to both trees');
assert(count(ft_user_memberships($pdo, $bina)) === 2, 'Bina belongs to both trees');
assert(ft_login_membership($pdo, $asha)['slug'] === 'iyer', 'login opens the oldest membership');
assert(ft_user_membership($pdo, $asha, 'T-sharma')['role'] === 'owner', 'Asha is owner of sharma');
assert(ft_user_membership($pdo, $bina, 'T-iyer')['role'] === 'owner', 'Bina is owner of iyer');
assert(ft_user_membership($pdo, $asha, 'T-nope') === null, 'a stranger tree is not a membership');

// Switching is session-only: no database writes.
$_SESSION = ['admin_user' => ['id' => $asha, 'display_name' => 'Asha']];
$before = $n("SELECT COUNT(*) FROM tenant_users") + $n("SELECT COUNT(*) FROM family_users");
ft_apply_tree_session(ft_user_membership($pdo, $asha, 'T-sharma'));
assert($_SESSION['tenant_id'] === 'T-sharma', 'session moved to sharma');
assert($_SESSION['admin_user']['role'] === 'owner' && $_SESSION['admin_user']['id'] === $asha, 'session role comes from tenant_users');
ft_apply_tree_session(ft_user_membership($pdo, $asha, 'T-iyer'));
assert($_SESSION['tenant_id'] === 'T-iyer', 'session moved back to iyer');
assert($n("SELECT COUNT(*) FROM tenant_users") + $n("SELECT COUNT(*) FROM family_users") === $before, 'switching wrote nothing');

// Each tree keeps its own people.
assert(count(ft_store($pdo, $sharma)->members()) === 1 && count(ft_store($pdo, $iyer)->members()) === 1, 'trees hold their own people');

// A new tree starts empty, private, and owned by its creator.
$new = ft_create_tree($pdo, $asha, 'The Kapoor Family');
assert($new['display_name'] === 'The Kapoor Family' && $new['slug'] === 'the-kapoor-family', 'new tree named and slugged');
assert($new['role'] === 'owner' && $new['visibility'] === 'private', 'new tree private, creator is owner');
assert($n("SELECT COUNT(*) FROM tenant_users WHERE tenant_id = ?", [$new['id']]) === 1, 'only the creator is a member');
assert($n("SELECT COUNT(*) FROM tenants WHERE id = ?", [$new['id']]) === 1, 'tree row exists');
assert(count(ft_store($pdo, $new)->members()) === 0, 'new tree has no people');
assert(count(ft_user_memberships($pdo, $asha)) === 3, 'Asha now has three trees');

// A requested link name is honoured, and a duplicate is refused.
$named = ft_create_tree($pdo, $asha, 'Named Tree', 'kapoor-branch');
assert($named['slug'] === 'kapoor-branch', 'requested link name kept');
try { ft_create_tree($pdo, $asha, 'Duplicate', 'kapoor-branch'); assert(false, 'duplicate link name must throw'); }
catch (InvalidArgumentException $e) {}
try { ft_create_tree($pdo, $asha, '   '); assert(false, 'empty name must throw'); }
catch (InvalidArgumentException $e) {}

// Adding the spouse to the new tree makes them a co-owner too.
$q("INSERT INTO tenant_users (tenant_id, user_id, role) VALUES (?, ?, 'owner')", [$new['id'], $bina]);
assert(ft_user_membership($pdo, $bina, $new['id'])['role'] === 'owner', 'Bina co-owns the new tree');
assert(count(ft_user_memberships($pdo, $bina)) === 3, 'Bina sees it too');

// A view-only member can read but not edit or manage.
$q("UPDATE tenant_users SET role = 'viewer' WHERE tenant_id = ? AND user_id = ?", [$new['id'], $asha]);
assert(!ft_can_edit_tree(ft_user_membership($pdo, $asha, $new['id'])), 'viewer cannot edit');
assert(!ft_can_manage_tree(ft_user_membership($pdo, $asha, $new['id'])), 'viewer cannot manage');
$q("UPDATE tenant_users SET role = 'owner' WHERE tenant_id = ? AND user_id = ?", [$new['id'], $asha]);

// Removing one membership leaves the account and its other trees alone.
ft_remove_membership($pdo, 'T-sharma', $bina);
assert(ft_user_membership($pdo, $bina, 'T-sharma') === null, 'Bina left sharma');
assert($n("SELECT COUNT(*) FROM family_users WHERE id = ?", [$bina]) === 1, 'Bina account stays');
assert(ft_user_membership($pdo, $bina, 'T-iyer') !== null && ft_user_membership($pdo, $bina, $new['id']) !== null, 'Bina keeps her other trees');
assert(count(ft_store($pdo, $sharma)->members()) === 1, 'sharma data stays when a member leaves');

// A tree cannot lose its last owner, but an owner can leave while another stays.
ft_remove_membership($pdo, $new['id'], $asha);
assert(ft_user_membership($pdo, $asha, $new['id']) === null, 'one of two owners may leave');
try { ft_remove_membership($pdo, $new['id'], $bina); assert(false, 'the only owner must not be removable'); }
catch (InvalidArgumentException $e) {}
assert(ft_user_membership($pdo, $bina, $new['id']) !== null, 'the remaining owner keeps the tree');
assert($n("SELECT COUNT(*) FROM tenant_users WHERE tenant_id = ?", [$new['id']]) === 1, 'last owner is still there');
$q("INSERT INTO tenant_users (tenant_id, user_id, role) VALUES (?, ?, 'owner')", [$new['id'], $asha]);
assert(ft_user_membership($pdo, $asha, $new['id'])['role'] === 'owner', 'Asha co-owns it again');

// Deleting a tree removes only that tree; accounts and sister trees survive.
$r = ft_delete_tree($pdo, $new);
assert($n("SELECT COUNT(*) FROM tenants WHERE id = ?", [$new['id']]) === 0, 'tree row gone');
assert($n("SELECT COUNT(*) FROM tenant_users WHERE tenant_id = ?", [$new['id']]) === 0, 'memberships of the tree gone');
assert($n("SELECT COUNT(*) FROM family_users WHERE id IN (?, ?)", [$asha, $bina]) === 2, 'both accounts stay');
assert($n("SELECT COUNT(*) FROM tenants WHERE id IN ('T-sharma','T-iyer')") === 2, 'the other trees stay');
assert(count(ft_user_memberships($pdo, $asha)) === 3 && count(ft_user_memberships($pdo, $bina)) === 1, 'membership lists shrank by one each');
assert(ft_user_membership($pdo, $asha, $named['id']) !== null, 'the other new tree also survives');
assert(!$r['external_home'], 'app-database tree is fully local');

// The surviving trees still hold their people.
assert(count(ft_store($pdo, $sharma)->members()) === 1 && count(ft_store($pdo, $iyer)->members()) === 1, 'surviving trees intact');

// ── Instance-wide settings ──────────────────────────────────────────────
assert(ft_instance_name($pdo) === 'FamilyTree', 'instance starts with the app name');
assert(ft_instance_settings($pdo)['new_tree_visibility'] === 'private', 'new trees start private');
assert(ft_can_manage_instance($pdo, $asha), 'a tree owner may manage the instance');
assert(ft_can_manage_instance($pdo, $bina), 'Bina owns trees, so she may manage the instance');

ft_instance_set($pdo, 'instance_name', 'The Sharma Family Archive');
ft_instance_set($pdo, 'default_tenant_id', 'T-iyer');
ft_instance_set($pdo, 'new_tree_visibility', 'public');
ft_instance_set($pdo, 'new_tree_theme', 'royal');
ft_instance_set($pdo, 'new_tree_hindu_calendar', true);
ft_instance_set($pdo, 'new_tree_privacy', ['birth_date' => 'private', 'bio' => 'members']);

assert(ft_instance_name($pdo) === 'The Sharma Family Archive', 'instance name saved');
assert(ft_instance_default_tenant($pdo)['slug'] === 'iyer', 'saved default tree wins');
assert(ft_instance_settings($pdo)['new_tree_hindu_calendar'] === true, 'boolean setting saved');

$defaulted = ft_create_tree($pdo, $asha, 'Defaulted Tree');
assert($defaulted['visibility'] === 'public', 'new tree copies the instance visibility');
$tenantRow = $q("SELECT settings FROM tenants WHERE id = ?", [$defaulted['id']])->fetchColumn();
$savedSettings = json_decode($tenantRow, true);
assert(($savedSettings['theme'] ?? '') === 'royal', 'new tree copies the tree look');
assert(!empty($savedSettings['hindu_calendar']), 'new tree copies the Hindu-date choice');
assert($q("SELECT visibility FROM privacy_settings WHERE tenant_id = ? AND field_key = 'birth_date'", [$defaulted['id']])->fetchColumn() === 'private', 'field privacy copied');
assert($q("SELECT visibility FROM privacy_settings WHERE tenant_id = ? AND field_key = 'bio'", [$defaulted['id']])->fetchColumn() === 'members', 'field privacy copied for each field');

// Afterwards, changing the defaults does not change an existing tree.
ft_instance_set($pdo, 'new_tree_visibility', 'private');
assert($q("SELECT visibility FROM tenants WHERE id = ?", [$defaulted['id']])->fetchColumn() === 'public', 'existing tree keeps its copied defaults');

// Renaming changes the tree's own name and link, not the instance.
$renamed = ft_tree_rename($pdo, $defaulted['id'], 'Renamed Tree', 'renamed-tree');
assert($renamed['display_name'] === 'Renamed Tree' && $renamed['slug'] === 'renamed-tree', 'tree renamed');
try { ft_tree_rename($pdo, $defaulted['id'], 'Renamed Tree', 'sharma'); assert(false, 'a taken link name must throw'); }
catch (InvalidArgumentException $e) {}

// Deleting the saved default tree clears the preference.
ft_instance_set($pdo, 'default_tenant_id', $defaulted['id']);
ft_delete_tree($pdo, $defaulted);
assert(ft_instance_settings($pdo)['default_tenant_id'] === '', 'deleted default tree is forgotten');
assert(ft_instance_default_tenant($pdo)['slug'] === 'iyer', 'falls back to the oldest active tree');

array_map('unlink', glob("$tmp/uploads/*") ?: []);
echo "multitree: ok\n";