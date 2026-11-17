<?php
/**
 * tools/test-auth-privacy.php - who may write, and what each audience may see.
 *
 *   php tools/test-auth-privacy.php        (needs pdo_sqlite)
 *
 * Covers the multi-tree authorization rules and the audience-aware privacy
 * filter:
 *   - role helpers: viewer cannot edit or manage, editor cannot manage
 *   - ft_admin_membership() reads the session tree
 *   - ft_tree_audience() resolves the REQUESTED tree, not the session tree
 *   - public / members / owner field filtering, living-person protection,
 *     private-member redaction
 *   - event tag lists lose private people for non-owners
 *   - re-running the schema does not bring back a removed membership
 */
$root = dirname(__DIR__) . '/public_html';
$tmp  = sys_get_temp_dir() . '/ft-auth-test-' . getmypid();
@mkdir("$tmp/uploads", 0777, true);
putenv("FT_STORAGE_DIR=$tmp");
putenv("FT_UPLOADS_DIR=$tmp/uploads");

require $root . '/includes/db.php';
require $root . '/includes/store.php';
require $root . '/includes/trees.php';
define('FT_APP', 1);
require $root . '/includes/privacy.php';
require $root . '/includes/events.php';

$pdo = ft_connect(['driver' => 'sqlite', 'file' => "$tmp/ft.sqlite"]);
ft_run_sql_file($pdo, $root . '/install/schema-sqlite.sql');
$q = fn(string $sql, array $a = []) => (function () use ($pdo, $sql, $a) {
    $s = $pdo->prepare($sql);
    $s->execute($a);
    return $s;
})();
$n = fn(string $sql, array $a = []) => (int)$q($sql, $a)->fetchColumn();

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

$viewTree = $tree('T-view', 'view', null);
$editTree = $tree('T-edit', 'edit', null);
$ownTree  = $tree('T-own',  'own',  null);
$otherTree = $tree('T-other', 'other', null);

$asha = $account('asha@example.test', 'Asha', 'T-view');
$q("INSERT INTO tenant_users (tenant_id, user_id, role) VALUES ('T-view', ?, 'viewer')", [$asha]);
$q("INSERT INTO tenant_users (tenant_id, user_id, role) VALUES ('T-edit', ?, 'editor')", [$asha]);
$q("INSERT INTO tenant_users (tenant_id, user_id, role) VALUES ('T-own',  ?, 'owner')",  [$asha]);
$q("UPDATE tenants SET owner_user_id = ? WHERE id = 'T-own'", [$asha]);

// ── role helpers ─────────────────────────────────────────────────────────
$viewer = ft_user_membership($pdo, $asha, 'T-view');
$editor = ft_user_membership($pdo, $asha, 'T-edit');
$owner  = ft_user_membership($pdo, $asha, 'T-own');
assert($viewer !== null && !ft_can_edit_tree($viewer) && !ft_can_manage_tree($viewer), 'viewer may read only');
assert($editor !== null && ft_can_edit_tree($editor) && !ft_can_manage_tree($editor), 'editor may edit, not manage');
assert($owner !== null && ft_can_edit_tree($owner) && ft_can_manage_tree($owner), 'owner may edit and manage');
assert(ft_user_membership($pdo, $asha, 'T-other') === null, 'no membership means no access');

// ft_admin_membership() looks at the tree the session is on.
$_SESSION = ['admin_logged_in' => true, 'tenant_id' => 'T-view', 'admin_user' => ['id' => $asha]];
$sessionM = ft_admin_membership($pdo);
assert($sessionM !== null && $sessionM['id'] === 'T-view' && $sessionM['role'] === 'viewer', 'session membership found');
$_SESSION['tenant_id'] = 'T-other';
assert(ft_admin_membership($pdo) === null, 'a tree the account does not belong to is not a membership');
$_SESSION = [];
assert(ft_admin_membership($pdo) === null, 'logged out has no membership');

// ── audience is resolved for the requested tree, not the session tree ────
$_SESSION = ['admin_logged_in' => true, 'tenant_id' => 'T-view', 'admin_user' => ['id' => $asha]];
assert(ft_tree_audience($pdo, 'T-view') === 'members', 'viewer of the requested tree is a member');
assert(ft_tree_audience($pdo, 'T-edit') === 'owner', 'editor of the requested tree sees owner data');
assert(ft_tree_audience($pdo, 'T-own')  === 'owner', 'owner of the requested tree sees owner data');
assert(ft_tree_audience($pdo, 'T-other') === 'public', 'stranger to the requested tree is the public');
assert(ft_tree_audience($pdo, 'T-own') === 'owner', 'session on another tree does not change the audience');
unset($_SESSION['admin_user']);
assert(ft_tree_audience($pdo, 'T-own') === 'public', 'a guest is the public even with a tenant id');
$_SESSION = [];

// ── member vs public field filtering ─────────────────────────────────────
$q("DELETE FROM privacy_settings WHERE tenant_id = 'T-view'");
foreach (['birth_date' => 'public', 'death_date' => 'public',
          'birth_location' => 'private', 'current_location' => 'private'] as $field => $vis)
    $q("INSERT INTO privacy_settings (tenant_id, field_key, visibility) VALUES ('T-view', ?, ?)", [$field, $vis]);
$settings = loadPrivacySettings($pdo, 'T-view');

$grandma = [
    'id' => 1, 'name' => 'Grandma', 'nickname' => '', 'gender' => 'F',
    'is_alive' => 0, 'privacy_override' => 0, 'is_private' => 0, 'tenant_id' => 'T-view',
    'birth_date' => '1940-01-01', 'birth_location' => 'Delhi', 'death_date' => '2020-01-01',
    'death_location' => 'Mumbai', 'current_location' => 'Mumbai', 'anniversary_date' => '1960-01-01',
    'img' => '/admin/uploads/grandma.jpg',
    'extra' => ['birth_approx' => true, 'native_place' => 'Lahore', 'sources' => ['family bible']],
];

$pub = applyPublicPrivacy($grandma, $settings, 'public');
assert($pub['birth_date'] === '1940-01-01', 'public sees a public birth date of a deceased person');
assert($pub['death_date'] === '2020-01-01' && $pub['death_location'] === 'Mumbai', 'public sees public death fields');
assert($pub['current_location'] === '', 'public does not see a private location');
assert($pub['img'] === '/admin/uploads/grandma.jpg', 'profile photo is not privacy-filtered');
assert(!isset($pub['privacy_override']) && !isset($pub['tenant_id']), 'internal fields never leave');
assert(!isset($pub['extra']['native_place']), 'hidden field takes its extra details with it');
assert(in_array('family bible', $pub['extra']['sources'] ?? [], true), 'sources travel with the member');

$mem = applyPublicPrivacy($grandma, $settings, 'members');
assert($mem['birth_date'] === '1940-01-01', 'members see a public birth date');
assert($mem['current_location'] === '', 'members do not see a private location');
assert($mem['img'] !== '', 'members see the profile photo');

$own = applyPublicPrivacy($grandma, $settings, 'owner');
assert($own['current_location'] === 'Mumbai', 'owner sees private fields');
assert($own['img'] !== '', 'owner sees the profile photo');
assert(!isset($own['privacy_override']) && !isset($own['tenant_id']), 'owner output drops internal fields too');

// Living-person auto-protection applies to guests only, even when the field
// setting is public.
$living = $grandma;
$living['is_alive'] = 1;
$living['death_date'] = '';
$living['birth_date'] = '1990-05-05';
assert(applyPublicPrivacy($living, $settings, 'public')['birth_date'] === '', 'living birth date hidden from guests');
assert(applyPublicPrivacy($living, $settings, 'members')['birth_date'] === '1990-05-05', 'living birth date shown to members');
$living['privacy_override'] = 1;
assert(applyPublicPrivacy($living, $settings, 'public')['birth_date'] === '1990-05-05', 'an explicit override allows a public living birth date');
$living['privacy_override'] = 0;

// A private member is redacted for everyone except the tree's own editors.
$private = $grandma;
$private['is_private'] = 1;
foreach (['public' => 'public', 'members' => 'members'] as $label => $audience) {
    $r = applyPublicPrivacy($private, $settings, $audience);
    assert($r['name'] === 'Private Member' && $r['img'] === '', "$label sees only the private placeholder");
    assert($r['id'] === 1 && $r['gender'] === 'F', "$label keeps the placeholder's structural fields");
}
$r = applyPublicPrivacy($private, $settings, 'owner');
assert($r['name'] === 'Grandma' && $r['img'] !== '', 'owner sees a private member in full');

// ── removing a membership is permanent, even across a schema re-run ──────
$q("INSERT INTO tenant_users (tenant_id, user_id, role) VALUES ('T-other', ?, 'viewer')", [$asha]);
assert(ft_user_membership($pdo, $asha, 'T-other') !== null, 'membership added');
ft_remove_membership($pdo, 'T-other', $asha);
assert(ft_user_membership($pdo, $asha, 'T-other') === null, 'membership removed');

// The schema used to backfill tenant_users from family_users.tenant_id; it
// must not do that any more, or a removed membership comes back.
ft_run_sql_file($pdo, $root . '/install/schema-sqlite.sql');
assert(ft_user_membership($pdo, $asha, 'T-other') === null, 're-running the schema does not resurrect a removed membership');
assert($n("SELECT COUNT(*) FROM tenant_users WHERE user_id = ?", [$asha]) === 3, 'only the real memberships remain');

// ── owner sync names an owner who is still a member ──────────────────────
$q("INSERT INTO tenant_users (tenant_id, user_id, role) VALUES ('T-other', ?, 'owner')", [$asha]);
$second = $account('second@example.test', 'Second', 'T-other');
$q("INSERT INTO tenant_users (tenant_id, user_id, role) VALUES ('T-other', ?, 'owner')", [$second]);
ft_sync_tree_owner($pdo, 'T-other');
$main = (int)$q("SELECT owner_user_id FROM tenants WHERE id = 'T-other'")->fetchColumn();
assert($main === $asha, 'the oldest remaining owner is named main owner');
$q("UPDATE tenant_users SET role = 'viewer' WHERE tenant_id = 'T-other' AND user_id = ?", [$asha]);
ft_sync_tree_owner($pdo, 'T-other');
assert((int)$q("SELECT owner_user_id FROM tenants WHERE id = 'T-other'")->fetchColumn() === $second, 'a demotion hands the main-owner column to the remaining owner');
$q("UPDATE tenant_users SET role = 'viewer' WHERE tenant_id = 'T-other' AND user_id = ?", [$second]);
ft_sync_tree_owner($pdo, 'T-other');
assert($q("SELECT owner_user_id FROM tenants WHERE id = 'T-other'")->fetchColumn() === null, 'a tree with no owner names none');

// ── event tags: private people disappear for non-owners ──────────────────
$events = [
    ['id' => 1, 'title' => 'Reunion', 'people' => [1, 2, 3], 'place' => 'Pune'],
    ['id' => 2, 'title' => 'Quiet day', 'people' => [], 'place' => ''],
    ['id' => 3, 'title' => 'No tags at all'],
];
$filtered = ft_events_hide_private($events, [2]);
assert($filtered[0]['people'] === [1, 3], 'a private id is removed from an event tag list');
assert($filtered[1]['people'] === [], 'an untagged event stays untagged');
assert(!array_key_exists('people', $filtered[2]), 'an event without a tag key is left alone');
assert($filtered[0]['title'] === 'Reunion' && $filtered[0]['place'] === 'Pune', 'event text is untouched');
assert(ft_events_hide_private($events, []) === $events, 'no private people means no change');
assert(ft_events_hide_private($events, [9]) === $events, 'an id not in any event changes nothing');
$allPrivate = ft_events_hide_private($events, [1, 2, 3]);
assert($allPrivate[0]['people'] === [], 'removing every tag leaves an empty list');

array_map('unlink', glob("$tmp/uploads/*") ?: []);
echo "auth + privacy: ok\n";
