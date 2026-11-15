<?php
/**
 * admin/tree-api.php - the account's family trees, and the instance's trees.
 *
 *   GET                         trees this account can open
 *   GET ?scope=instance         every active tree (tree owners only)
 *   GET ?tree_users=<id>        accounts with access to a tree this account owns
 *   POST {action:'switch', tenant_id}
 *   POST {action:'create', name, slug?, switch?}
 *   POST {action:'rename', tenant_id, display_name, slug}
 *   POST {action:'transfer', tenant_id, user_id}
 *   POST {action:'delete', tenant_id, confirm}
 *
 * Only the active tree lives in the session. Switching never writes to
 * family_users; tenant_users is the source of truth for access.
 */
define('FT_APP', 1);
require_once __DIR__ . '/config.php';
require_once dirname(__DIR__) . '/includes/trees.php';
require_once dirname(__DIR__) . '/includes/instance.php';

header('Content-Type: application/json; charset=utf-8');
$allowedOrigin = 'https://' . (getenv('APP_DOMAIN') ?: ($_SERVER['HTTP_HOST'] ?? 'localhost'));
header('Access-Control-Allow-Origin: ' . $allowedOrigin);
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
header('Vary: Origin');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

if (empty($_SESSION['admin_logged_in']) || empty($_SESSION['admin_user']['id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$userId = (int)$_SESSION['admin_user']['id'];

/** The shape the admin needs, without data-home secrets. */
function tree_api_list(PDO $pdo, int $userId): array {
    return array_map(fn($t) => [
        'id'           => $t['id'],
        'slug'         => $t['slug'],
        'display_name' => $t['display_name'],
        'role'         => $t['role'],
        'visibility'   => $t['visibility'],
    ], ft_user_memberships($pdo, $userId));
}

/** Every tree on the instance, with my role and a few counts. */
function tree_api_instance_list(PDO $pdo, int $userId): array {
    $roles = [];
    $st = $pdo->prepare('SELECT tenant_id, role FROM tenant_users WHERE user_id = ?');
    $st->execute([$userId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $roles[$r['tenant_id']] = $r['role'];

    $rows = $pdo->query("
        SELECT t.id, t.slug, t.display_name, t.visibility, t.created_at,
               u.display_name AS owner_name, u.email AS owner_email,
               (SELECT COUNT(*) FROM tenant_users tu WHERE tu.tenant_id = t.id) AS user_count,
               (SELECT COUNT(*) FROM tenant_users tu WHERE tu.tenant_id = t.id AND tu.role = 'owner') AS owner_count
        FROM tenants t
        LEFT JOIN family_users u ON u.id = t.owner_user_id
        WHERE t.is_active = 1
        ORDER BY t.created_at, t.display_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['my_role'] = $roles[$r['id']] ?? null;
        $r['can_manage'] = ($r['my_role'] ?? null) === 'owner';
        $r['is_active'] = ((string)($_SESSION['tenant_id'] ?? '') === (string)$r['id']);
        $r['user_count'] = (int)$r['user_count'];
        $r['owner_count'] = (int)$r['owner_count'];
    }
    unset($r);
    return $rows;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['tree_users'])) {
        $tenantId = trim((string)$_GET['tree_users']);
        $m = ft_user_membership($pdo, $userId, $tenantId);
        if (!$m || !ft_can_manage_tree($m)) {
            http_response_code(403);
            echo json_encode(['error' => 'Only a tree owner can see its access list.']);
            exit;
        }
        $st = $pdo->prepare("
            SELECT u.id, u.display_name, u.email, tu.role
            FROM tenant_users tu JOIN family_users u ON u.id = tu.user_id
            WHERE tu.tenant_id = ?
            ORDER BY tu.role = 'owner' DESC, u.display_name
        ");
        $st->execute([$tenantId]);
        echo json_encode(['users' => $st->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if (($_GET['scope'] ?? '') === 'instance') {
        if (!ft_can_manage_instance($pdo, $userId)) {
            http_response_code(403);
            echo json_encode(['error' => 'Only a tree owner can see every tree.']);
            exit;
        }
        echo json_encode([
            'trees'  => tree_api_instance_list($pdo, $userId),
            'active' => getAdminTenantId(),
        ]);
        exit;
    }

    echo json_encode(['trees' => tree_api_list($pdo, $userId), 'active' => getAdminTenantId()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

validateCsrf();
$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = (string)($input['action'] ?? '');

if ($action === 'switch') {
    $tenantId = trim((string)($input['tenant_id'] ?? ''));
    $m = $tenantId !== '' ? ft_user_membership($pdo, $userId, $tenantId) : null;
    if (!$m) {
        http_response_code(403);
        echo json_encode(['error' => 'You are not a member of that tree.']);
        exit;
    }
    ft_apply_tree_session($m);
    echo json_encode(['ok' => true, 'tree' => [
        'id' => $m['id'], 'slug' => $m['slug'], 'display_name' => $m['display_name'], 'role' => $m['role'],
    ]]);
    exit;
}

if ($action === 'create') {
    checkRateLimit('tree_create', 10, 3600);
    $name = trim((string)($input['name'] ?? ''));
    $slug = trim((string)($input['slug'] ?? ''));
    try {
        $m = ft_create_tree($pdo, $userId, $name, $slug !== '' ? $slug : null);
    } catch (InvalidArgumentException $e) {
        http_response_code(422);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    } catch (Throwable $e) {
        error_log('tree create: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Could not create the tree.']);
        exit;
    }
    // The Trees page creates without leaving the page; older callers switch.
    $switch = !array_key_exists('switch', $input) || !empty($input['switch']);
    if ($switch) ft_apply_tree_session($m);
    echo json_encode(['ok' => true, 'tree' => [
        'id' => $m['id'], 'slug' => $m['slug'], 'display_name' => $m['display_name'],
        'role' => $m['role'], 'visibility' => $m['visibility'] ?? null,
    ]]);
    exit;
}

if ($action === 'rename') {
    $tenantId = trim((string)($input['tenant_id'] ?? ''));
    $m = $tenantId !== '' ? ft_user_membership($pdo, $userId, $tenantId) : null;
    if (!$m) { http_response_code(404); echo json_encode(['error' => 'Tree not found.']); exit; }
    if (!ft_can_manage_tree($m)) { http_response_code(403); echo json_encode(['error' => 'Only a tree owner can rename it.']); exit; }
    try {
        $tree = ft_tree_rename($pdo, $tenantId, (string)($input['display_name'] ?? ''),
            array_key_exists('slug', $input) ? (string)$input['slug'] : null);
    } catch (InvalidArgumentException $e) {
        http_response_code(422);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    } catch (Throwable $e) {
        error_log('tree rename: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Could not rename the tree.']);
        exit;
    }
    // Keep the sidebar and this session's tree details in step.
    if ((string)($_SESSION['tenant_id'] ?? '') === $tenantId) {
        $_SESSION['admin_user']['tenant_id'] = $tenantId;
        $fresh = ft_user_membership($pdo, $userId, $tenantId);
        if ($fresh) ft_apply_tree_session($fresh);
    }
    echo json_encode(['ok' => true, 'tree' => $tree]);
    exit;
}

if ($action === 'transfer') {
    $tenantId = trim((string)($input['tenant_id'] ?? ''));
    $targetId = (int)($input['user_id'] ?? 0);
    $m = $tenantId !== '' ? ft_user_membership($pdo, $userId, $tenantId) : null;
    if (!$m) { http_response_code(404); echo json_encode(['error' => 'Tree not found.']); exit; }
    if (!ft_can_manage_tree($m)) { http_response_code(403); echo json_encode(['error' => 'Only a tree owner can hand it over.']); exit; }
    if ($targetId <= 0) { http_response_code(422); echo json_encode(['error' => 'Choose an account.']); exit; }

    $chk = $pdo->prepare('SELECT id FROM family_users WHERE id = ? LIMIT 1');
    $chk->execute([$targetId]);
    if (!$chk->fetchColumn()) { http_response_code(404); echo json_encode(['error' => 'Account not found.']); exit; }

    try {
        $pdo->beginTransaction();
        $has = $pdo->prepare('SELECT role FROM tenant_users WHERE tenant_id = ? AND user_id = ? LIMIT 1');
        $has->execute([$tenantId, $targetId]);
        if ($has->fetchColumn() === false) {
            $pdo->prepare("INSERT INTO tenant_users (tenant_id, user_id, role) VALUES (?, ?, 'owner')")
                ->execute([$tenantId, $targetId]);
        } else {
            $pdo->prepare("UPDATE tenant_users SET role = 'owner' WHERE tenant_id = ? AND user_id = ?")
                ->execute([$tenantId, $targetId]);
        }
        $pdo->prepare('UPDATE tenants SET owner_user_id = ? WHERE id = ?')->execute([$targetId, $tenantId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('tree transfer: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Could not hand over the tree.']);
        exit;
    }
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'delete') {
    $tenantId = trim((string)($input['tenant_id'] ?? ''));
    $m = $tenantId !== '' ? ft_user_membership($pdo, $userId, $tenantId) : null;
    if (!$m) {
        http_response_code(404);
        echo json_encode(['error' => 'Tree not found.']);
        exit;
    }
    if (!ft_can_manage_tree($m)) {
        http_response_code(403);
        echo json_encode(['error' => 'Only a tree owner can delete it.']);
        exit;
    }
    if (count(ft_user_memberships($pdo, $userId)) <= 1) {
        http_response_code(409);
        echo json_encode(['error' => 'This is your only tree. Create another tree first, or delete your account from Profile.']);
        exit;
    }
    if (!hash_equals((string)$m['display_name'], trim((string)($input['confirm'] ?? '')))) {
        http_response_code(422);
        echo json_encode(['error' => 'Type the tree name exactly to confirm.']);
        exit;
    }

    $wasActive = ((string)($_SESSION['tenant_id'] ?? '') === $tenantId);
    require_once dirname(__DIR__) . '/includes/account-delete.php';
    try {
        ft_delete_tree($pdo, $m);
    } catch (Throwable $e) {
        error_log('tree delete: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Could not delete the tree.']);
        exit;
    }

    if (!$wasActive) {
        echo json_encode(['ok' => true, 'active' => getAdminTenantId()]);
        exit;
    }

    $next = ft_user_memberships($pdo, $userId)[0] ?? null;
    if ($next) {
        ft_apply_tree_session($next);
        echo json_encode(['ok' => true, 'active' => $next['id'], 'slug' => $next['slug']]);
    } else {
        $_SESSION = [];
        session_destroy();
        echo json_encode(['ok' => true, 'logged_out' => true]);
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
