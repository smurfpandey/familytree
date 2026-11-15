<?php
/**
 * admin/users-api.php - accounts on this whole installation, and which trees
 * each account may open.
 *
 *   GET                      all accounts with their tree assignments
 *   GET ?id=<user>           one account
 *   GET ?tree_members=<tree> member cards of a tree this account owns
 *   POST {action:'create', ...}          a new account, with optional tree access
 *   POST {action:'assign', ...}          give one account access to one owned tree
 *   POST {action:'remove_access', ...}   take that tree away, keeping the account
 *   DELETE ?id=<user>                    delete the account itself
 *
 * Accounts and trees are instance-level. A tree owner may change assignments
 * only for trees they own; an account can be deleted only when every tree it
 * owns is also managed by the person asking.
 */
define('FT_APP', 1);
require_once __DIR__ . '/config.php';
require_once dirname(__DIR__) . '/includes/trees.php';
require_once dirname(__DIR__) . '/includes/instance.php';
require_once dirname(__DIR__) . '/includes/store.php';

header('Content-Type: application/json; charset=utf-8');
$allowedOrigin = 'https://' . (getenv('APP_DOMAIN') ?: ($_SERVER['HTTP_HOST'] ?? 'localhost'));
header('Access-Control-Allow-Origin: ' . $allowedOrigin);
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
header('Vary: Origin');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

if (empty($_SESSION['admin_logged_in']) || empty($_SESSION['admin_user']['id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

validateCsrf();
global $pdo;

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;
$myId   = (int)$_SESSION['admin_user']['id'];

if (!ft_can_manage_instance($pdo, $myId)) {
    http_response_code(403);
    echo json_encode(['error' => 'Only a tree owner can manage accounts.']);
    exit;
}

function sanitizePermissions($value) {
    if ($value === null) return null;
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null;
    }
    return is_array($value) ? $value : null;
}

/** Trees this account owns, and so may change access to. */
function myOwnedTrees(PDO $pdo, int $userId): array {
    $out = [];
    foreach (ft_user_memberships($pdo, $userId) as $t) {
        if (($t['role'] ?? '') === 'owner') $out[$t['id']] = $t;
    }
    return $out;
}

/** A linked member must belong to the tree the assignment is for. */
function memberBelongsToTenant(PDO $pdo, ?int $memberId, string $tenantId): bool {
    if (!$memberId) return true;
    try {
        $st = $pdo->prepare("SELECT 1 FROM family_tree_data WHERE id = ? AND tenant_id = ? LIMIT 1");
        $st->execute([$memberId, $tenantId]);
        if ($st->fetchColumn()) return true;
    } catch (PDOException $e) { /* an external data home has no such table row here */ }

    try {
        $st = $pdo->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
        $st->execute([$tenantId]);
        if ($tenant = $st->fetch(PDO::FETCH_ASSOC)) {
            foreach (ft_store($pdo, $tenant)->members() as $m) {
                if ((int)($m['id'] ?? 0) === $memberId) return true;
            }
        }
    } catch (Throwable $e) { /* treat as not linked */ }
    return false;
}

/** Every tree assignment of one account, with the tree and member-card name. */
function accountAssignments(PDO $pdo, int $userId, array $ownedTrees): array {
    $st = $pdo->prepare("
        SELECT tu.tenant_id, tu.role, tu.permissions, tu.member_id,
               t.slug AS tree_slug, t.display_name AS tree_name,
               m.name AS member_name, m.nickname AS member_nickname, m.img AS member_img
        FROM tenant_users tu
        JOIN tenants t ON t.id = tu.tenant_id
        LEFT JOIN family_tree_data m ON m.id = tu.member_id AND m.tenant_id = tu.tenant_id
        WHERE tu.user_id = ? AND t.is_active = 1
        ORDER BY t.display_name
    ");
    $st->execute([$userId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['permissions'] = $r['permissions'] ? json_decode($r['permissions'], true) : null;
        $r['member_id']   = $r['member_id'] !== null ? (int)$r['member_id'] : null;
        $r['editable']    = isset($ownedTrees[$r['tenant_id']]);
    }
    unset($r);
    return $rows;
}

/** Whether the current account may delete this one: never itself, and every
 *  tree the target owns must also be owned by the current account. */
function canDeleteAccount(PDO $pdo, int $targetId, int $myId): bool {
    if ($targetId === $myId || $targetId <= 0) return false;
    $st = $pdo->prepare("
        SELECT t.id FROM tenant_users tu
        JOIN tenants t ON t.id = tu.tenant_id
        WHERE tu.user_id = ? AND tu.role = 'owner'
    ");
    $st->execute([$targetId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $tenantId) {
        $mine = $pdo->prepare("SELECT 1 FROM tenant_users WHERE tenant_id = ? AND user_id = ? AND role = 'owner' LIMIT 1");
        $mine->execute([$tenantId, $myId]);
        if (!$mine->fetchColumn()) return false;
    }
    return true;
}

function accountSummary(PDO $pdo, int $userId, int $myId, array $ownedTrees): ?array {
    $st = $pdo->prepare("SELECT id, email, display_name, created_at, last_login_at FROM family_users WHERE id = ? LIMIT 1");
    $st->execute([$userId]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) return null;
    $u['id'] = (int)$u['id'];
    $u['is_self'] = $u['id'] === $myId;
    $u['can_delete'] = canDeleteAccount($pdo, $u['id'], $myId);
    $u['assignments'] = accountAssignments($pdo, $u['id'], $ownedTrees);
    return $u;
}

/** Give one account access to one owned tree. */
function saveAssignment(PDO $pdo, int $userId, string $tenantId, string $role, ?int $memberId, $permissions, int $myId): void {
    if (!in_array($role, ['owner', 'editor', 'viewer'], true)) {
        throw new InvalidArgumentException('Invalid role. Must be one of: owner, editor, viewer.');
    }
    if (!memberBelongsToTenant($pdo, $memberId, $tenantId)) {
        throw new InvalidArgumentException('That linked person is not in this tree.');
    }

    $existing = $pdo->prepare("SELECT role FROM tenant_users WHERE tenant_id = ? AND user_id = ? LIMIT 1");
    $existing->execute([$tenantId, $userId]);
    $oldRole = $existing->fetchColumn();

    if ($userId === $myId && $oldRole === 'owner' && $role !== 'owner' && ft_tree_owner_count($pdo, $tenantId) <= 1) {
        throw new InvalidArgumentException('This is the tree’s last owner. Make someone else an owner first.');
    }

    $permJson = $permissions ? json_encode($permissions) : null;
    if ($oldRole === false) {
        $pdo->prepare("INSERT INTO tenant_users (tenant_id, user_id, role, permissions, member_id)
                       VALUES (?, ?, ?, ?, ?)")
            ->execute([$tenantId, $userId, $role, $permJson, $memberId]);
    } else {
        $pdo->prepare("UPDATE tenant_users SET role = ?, permissions = ?, member_id = ?
                       WHERE tenant_id = ? AND user_id = ?")
            ->execute([$role, $permJson, $memberId, $tenantId, $userId]);
    }

    // Keep this session in step when the manager changes their own access in
    // the tree they are currently in.
    if ($userId === $myId && (string)($_SESSION['tenant_id'] ?? '') === $tenantId) {
        $_SESSION['admin_user']['role'] = $role;
        $_SESSION['admin_user']['permissions'] = $permissions;
        $_SESSION['admin_user']['member_id'] = $memberId;
    }
}

if ($method === 'GET') {
    if (isset($_GET['tree_members'])) {
        $tenantId = trim((string)$_GET['tree_members']);
        $owned = myOwnedTrees($pdo, $myId);
        if (!isset($owned[$tenantId])) {
            http_response_code(403);
            echo json_encode(['error' => 'You can only link people in a tree you own.']);
            exit;
        }
        $st = $pdo->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
        $st->execute([$tenantId]);
        $tenant = $st->fetch(PDO::FETCH_ASSOC);
        $out = [];
        try {
            if ($tenant) {
                foreach (ft_store($pdo, $tenant)->members() as $m) {
                    $out[] = ['id' => (int)$m['id'], 'name' => (string)($m['name'] ?? ''), 'nickname' => (string)($m['nickname'] ?? '')];
                }
            }
        } catch (Throwable $e) {
            error_log('users-api tree members: ' . $e->getMessage());
        }
        echo json_encode(['members' => $out]);
        exit;
    }

    $ownedTrees = myOwnedTrees($pdo, $myId);

    if ($id) {
        $account = accountSummary($pdo, $id, $myId, $ownedTrees);
        if (!$account) { http_response_code(404); echo json_encode(['error' => 'Account not found']); exit; }
        echo json_encode($account);
        exit;
    }

    $accounts = [];
    foreach ($pdo->query("SELECT id FROM family_users ORDER BY display_name, email")->fetchAll(PDO::FETCH_COLUMN) as $uid) {
        if ($a = accountSummary($pdo, (int)$uid, $myId, $ownedTrees)) $accounts[] = $a;
    }

    echo json_encode([
        'accounts' => $accounts,
        'trees'    => array_values(array_map(fn($t) => [
            'id' => $t['id'], 'slug' => $t['slug'], 'display_name' => $t['display_name'], 'visibility' => $t['visibility'],
        ], $ownedTrees)),
        'me' => $myId,
    ]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

if ($method === 'POST') {
    $action = (string)($input['action'] ?? '');

    if ($action === 'create') {
        $email       = strtolower(trim((string)($input['email'] ?? '')));
        $displayName = trim((string)($input['display_name'] ?? ''));
        $password    = (string)($input['password'] ?? '');
        $assignments = is_array($input['assignments'] ?? null) ? $input['assignments'] : [];

        if ($displayName === '') { http_response_code(422); echo json_encode(['error' => 'A name is required.']); exit; }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { http_response_code(422); echo json_encode(['error' => 'A valid email is required.']); exit; }
        if (strlen($password) < 8) { http_response_code(422); echo json_encode(['error' => 'Password must be at least 8 characters.']); exit; }

        $chk = $pdo->prepare('SELECT 1 FROM family_users WHERE email = ? LIMIT 1');
        $chk->execute([$email]);
        if ($chk->fetchColumn()) {
            http_response_code(409);
            echo json_encode(['error' => 'An account with that email already exists. Open it below and add tree access instead.']);
            exit;
        }

        $ownedTrees = myOwnedTrees($pdo, $myId);
        $clean = [];
        foreach ($assignments as $a) {
            $tenantId = trim((string)($a['tenant_id'] ?? ''));
            if (!isset($ownedTrees[$tenantId])) {
                http_response_code(403);
                echo json_encode(['error' => 'You can only give access to trees you own.']);
                exit;
            }
            $role = (string)($a['role'] ?? 'viewer');
            if (!in_array($role, ['owner', 'editor', 'viewer'], true)) $role = 'viewer';
            $memberId = !empty($a['member_id']) ? (int)$a['member_id'] : null;
            if (!memberBelongsToTenant($pdo, $memberId, $tenantId)) {
                http_response_code(422);
                echo json_encode(['error' => 'That linked person is not in the chosen tree.']);
                exit;
            }
            $clean[$tenantId] = ['role' => $role, 'member_id' => $memberId, 'permissions' => sanitizePermissions($a['permissions'] ?? null)];
        }

        $legacyTenant = getAdminTenantId();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO family_users (tenant_id, email, password_hash, display_name, role)
                           VALUES (?, ?, ?, ?, 'viewer')")
                ->execute([$legacyTenant, $email, password_hash($password, PASSWORD_DEFAULT), $displayName]);
            $newId = (int)$pdo->lastInsertId();

            foreach ($clean as $tenantId => $a) {
                $pdo->prepare("INSERT INTO tenant_users (tenant_id, user_id, role, permissions, member_id)
                               VALUES (?, ?, ?, ?, ?)")
                    ->execute([$tenantId, $newId, $a['role'], $a['permissions'] ? json_encode($a['permissions']) : null, $a['member_id']]);
            }
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e->getCode() === '23000') {
                http_response_code(409);
                echo json_encode(['error' => 'An account with that email already exists.']);
            } else {
                error_log('users-api create: ' . $e->getMessage());
                http_response_code(500);
                echo json_encode(['error' => 'Could not create the account.']);
            }
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('users-api create: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Could not create the account.']);
            exit;
        }

        echo json_encode(accountSummary($pdo, $newId, $myId, $ownedTrees) ?: ['ok' => true]);
        exit;
    }

    if ($action === 'assign') {
        $targetId = (int)($input['user_id'] ?? 0);
        $tenantId = trim((string)($input['tenant_id'] ?? ''));
        $role     = (string)($input['role'] ?? 'viewer');
        $memberId = !empty($input['member_id']) ? (int)$input['member_id'] : null;
        $perms    = sanitizePermissions($input['permissions'] ?? null);

        $ownedTrees = myOwnedTrees($pdo, $myId);
        if (!isset($ownedTrees[$tenantId])) {
            http_response_code(403);
            echo json_encode(['error' => 'You can only change access to trees you own.']);
            exit;
        }
        $chk = $pdo->prepare('SELECT 1 FROM family_users WHERE id = ? LIMIT 1');
        $chk->execute([$targetId]);
        if (!$chk->fetchColumn()) { http_response_code(404); echo json_encode(['error' => 'Account not found.']); exit; }

        try {
            saveAssignment($pdo, $targetId, $tenantId, $role, $memberId, $perms, $myId);
        } catch (InvalidArgumentException $e) {
            http_response_code(422);
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        } catch (Throwable $e) {
            error_log('users-api assign: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Could not save that access.']);
            exit;
        }
        $account = accountSummary($pdo, $targetId, $myId, $ownedTrees);
        echo json_encode(['ok' => true, 'account' => $account]);
        exit;
    }

    if ($action === 'remove_access') {
        $targetId = (int)($input['user_id'] ?? 0);
        $tenantId = trim((string)($input['tenant_id'] ?? ''));
        $ownedTrees = myOwnedTrees($pdo, $myId);
        if (!isset($ownedTrees[$tenantId])) {
            http_response_code(403);
            echo json_encode(['error' => 'You can only change access to trees you own.']);
            exit;
        }
        try {
            ft_remove_membership($pdo, $tenantId, $targetId);
        } catch (InvalidArgumentException $e) {
            http_response_code(422);
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }

        $response = ['ok' => true];
        if ($targetId === $myId && (string)($_SESSION['tenant_id'] ?? '') === $tenantId) {
            $next = ft_login_membership($pdo, $myId);
            if ($next) {
                ft_apply_tree_session($next);
                $response['switched'] = $next['id'];
            } else {
                $_SESSION = [];
                session_destroy();
                $response['logged_out'] = true;
            }
        }
        echo json_encode($response);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

if ($method === 'DELETE' && $id) {
    if (!canDeleteAccount($pdo, $id, $myId)) {
        http_response_code(403);
        echo json_encode(['error' => 'You can delete an account only when you also own every tree it owns.']);
        exit;
    }
    require_once dirname(__DIR__) . '/includes/account-delete.php';
    try {
        ft_delete_account($pdo, $id, []);
    } catch (Throwable $e) {
        error_log('users-api delete: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Could not delete the account.']);
        exit;
    }
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
