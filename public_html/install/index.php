<?php
/**
 * install/index.php - first-run installer (self-hosted, SQLite only).
 *
 * One page: family details -> storage/familytree.sqlite + tables + owner account
 * + tree, then send the first login to /admin/. Configuration comes from default
 * values and the environment, never from a file written here. Once a family
 * exists the installer answers 404.
 */
define('FT_APP', 1);
define('FT_INSTALLER', 1);
$root = dirname(__DIR__);
require_once $root . '/includes/db.php';
require_once $root . '/includes/brand.php';

$storage = ft_storage_dir();
$dbFile  = getenv('DB_FILE') ?: ($storage . '/familytree.sqlite');

// Installed means a family (tenant) exists in the SQLite file.
$installed = false;
if (is_file($dbFile)) {
    try {
        $t = ft_connect(['driver' => 'sqlite', 'file' => $dbFile]);
        $installed = (bool)$t->query('SELECT 1 FROM tenants LIMIT 1')->fetchColumn();
    } catch (Throwable $e) {}
}
if ($installed && empty($_GET['force'])) {
    http_response_code(404);
    echo 'FamilyTree is already installed. Delete ' . htmlspecialchars($dbFile, ENT_QUOTES, 'UTF-8') . ' to run the installer again.';
    exit;
}

$h   = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$err = '';

$in = [
    'admin_name'  => trim((string)($_POST['admin_name'] ?? '')),
    'admin_email' => strtolower(trim((string)($_POST['admin_email'] ?? ''))),
    'tree_name'   => trim((string)($_POST['tree_name'] ?? '')),
    'tree_slug'   => trim((string)($_POST['tree_slug'] ?? '')),
];

// The self-hosted app only needs SQLite and the usual PHP bits.
$problems = [];
if (version_compare(PHP_VERSION, '8.1.0', '<')) $problems[] = 'PHP 8.1 or newer';
if (!extension_loaded('pdo_sqlite')) $problems[] = 'the pdo_sqlite extension';
if (!extension_loaded('mbstring')) $problems[] = 'the mbstring extension';
if (!extension_loaded('openssl')) $problems[] = 'the OpenSSL extension';
if (!is_writable($storage)) $problems[] = 'a writable ' . $storage . ' folder';
$dbDir = dirname($dbFile);
if (is_dir($dbDir) ? !is_writable($dbDir) : !is_writable(dirname($dbDir))) $problems[] = 'a writable ' . $dbDir . ' folder';
$problems = array_values(array_unique($problems));
if ($problems) $err = 'Before installing, this server needs ' . implode(', ', $problems) . '.';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$problems) {
    $name     = $in['admin_name'];
    $email    = $in['admin_email'];
    $pass     = (string)($_POST['admin_pass'] ?? '');
    $treeName = $in['tree_name'] !== '' ? $in['tree_name'] : ($name !== '' ? $name . "'s family" : '');
    $slug     = strtolower($in['tree_slug'] !== '' ? $in['tree_slug'] : preg_replace('/[^a-z0-9]+/', '-', strtolower($treeName)));
    $slug     = trim(preg_replace('/-+/', '-', $slug), '-') ?: 'family';

    if ($name === '') {
        $err = 'Your name, please.';
    } elseif ($treeName === '') {
        $err = 'A family name, please.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'A valid email, please.';
    } elseif (strlen($pass) < 8) {
        $err = 'Password: at least 8 characters.';
    } else {
        $pdo = null;
        try {
            $pdo = ft_connect(['driver' => 'sqlite', 'file' => $dbFile]);
            ft_run_sql_file($pdo, ft_schema_file('sqlite'));

            $dupe = $pdo->prepare('SELECT 1 FROM tenants WHERE slug = ? LIMIT 1');
            $dupe->execute([$slug]);
            if ($dupe->fetchColumn()) throw new RuntimeException('The link name "' . $slug . '" is already used. Choose another.');

            $dupe = $pdo->prepare('SELECT 1 FROM family_users WHERE email = ? LIMIT 1');
            $dupe->execute([$email]);
            if ($dupe->fetchColumn()) throw new RuntimeException('The email "' . $email . '" is already used.');

            $pdo->beginTransaction();
            $tenantId = ft_uuid();
            $pdo->prepare("INSERT INTO tenants (id, slug, display_name, is_active, visibility, created_at) VALUES (?, ?, ?, 1, 'private', NOW())")
                ->execute([$tenantId, $slug, $treeName]);
            $pdo->prepare("INSERT INTO family_users (tenant_id, email, password_hash, display_name, role, created_at) VALUES (?, ?, ?, ?, 'owner', NOW())")
                ->execute([$tenantId, $email, password_hash($pass, PASSWORD_DEFAULT), $name]);
            $userId = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE tenants SET owner_user_id = ? WHERE id = ?')->execute([$userId, $tenantId]);
            try { $pdo->prepare("INSERT INTO tenant_users (tenant_id, user_id, role) VALUES (?, ?, 'owner')")->execute([$tenantId, $userId]); } catch (Throwable $e) {}
            $pdo->commit();

            header('Location: /admin/', true, 302);
            exit;
        } catch (Throwable $e) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $e2) {} }
            $err = 'Could not finish: ' . $e->getMessage();
        }
    }
}
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Set up FamilyTree</title>
<?= ft_brand_head() ?>
<style>
:root{--bg:var(--ft-paper);--card:var(--ft-surface);--ink:var(--ft-ink);--dim:var(--ft-ink2);--line:var(--ft-line);--accent:var(--ft-green);--bad:var(--ft-danger)}
*{box-sizing:border-box}body{margin:0;font:15px/1.5 var(--ft-sans);background:var(--bg);color:var(--ink);display:flex;justify-content:center;padding:32px 16px}
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;max-width:560px;width:100%;padding:28px 28px 24px;box-shadow:var(--ft-shadow)}
h1{margin:0 0 4px}h1 .ft-brand{font-size:26px}h3{font-family:var(--ft-serif);font-weight:600}.sub{color:var(--dim);margin:0 0 20px}
a{color:var(--accent)}:focus-visible{outline:3px solid var(--ft-focus);outline-offset:2px;border-radius:4px}
input{color:var(--ink)}
label{display:block;font-size:13px;color:var(--dim);margin:12px 0 4px}label small{color:var(--dim)}
input[type=text],input[type=email],input[type=password]{width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:10px;font:inherit;background:var(--card)}
.row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.btn{display:inline-block;margin-top:18px;background:var(--accent);color:#fff;border:0;border-radius:999px;padding:11px 22px;font:600 14px inherit;cursor:pointer}
.err{background:var(--ft-danger-soft);color:var(--bad);border-radius:10px;padding:10px 12px;margin:0 0 10px;font-size:14px}
code{background:var(--ft-sunk);padding:1px 5px;border-radius:5px;font-size:13px}
</style></head><body><div class="card">
<h1><?= ft_brand('/', '', 30) ?></h1>
<p class="sub">Set up your family tree. It lives in a SQLite file on this server.</p>
<?php if ($err): ?><div class="err"><?= $h($err) ?></div><?php endif; ?>
<?php if ($problems): ?>
  <p class="sub" style="font-size:14px;margin:0">Fix the items above, then reload.</p>
<?php else: ?>
<form method="post">
  <h3 style="margin:0 0 4px">Your family</h3>
  <label>Family name</label>
  <input type="text" name="tree_name" value="<?= $h($in['tree_name']) ?>" placeholder="The Sharma family" required autofocus>
  <label>Link name <small>(the last part of /tree/…)</small></label>
  <input type="text" name="tree_slug" value="<?= $h($in['tree_slug']) ?>" placeholder="sharma" pattern="[A-Za-z0-9-]*">
  <h3 style="margin:22px 0 4px">Your account</h3>
  <div class="row">
    <div><label>Your name</label><input type="text" name="admin_name" value="<?= $h($in['admin_name']) ?>" required></div>
    <div><label>Email (you log in with this)</label><input type="email" name="admin_email" value="<?= $h($in['admin_email']) ?>" required></div>
  </div>
  <label>Password (8+ characters)</label>
  <input type="password" name="admin_pass" minlength="8" required>
  <button class="btn">Create the tree</button>
</form>
<?php endif; ?>
</div></body></html>
