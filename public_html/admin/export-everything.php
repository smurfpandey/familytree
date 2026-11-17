<?php
/**
 * admin/export-everything.php - the whole tree as one JSON file (familytree/1): people, photos and
 * their tags, the tree's settings. Import it into any FamilyTree with Members > Import.
 */
if (!defined('FT_APP')) define('FT_APP', 1);
require_once __DIR__ . '/config.php';
require_once dirname(__DIR__) . '/includes/privacy.php';
require_once dirname(__DIR__) . '/includes/store.php';
require_once dirname(__DIR__) . '/includes/events.php';
if (empty($_SESSION['admin_logged_in'])) { http_response_code(401); header('Content-Type: application/json'); echo json_encode(['error' => 'Unauthorized']); exit; }
ft_require_tree_role($pdo, ['owner']);

$tenantId = getAdminTenantId();
$tenant = loadTenant($pdo, $tenantId);
if (!$tenant) { http_response_code(404); header('Content-Type: application/json'); echo json_encode(['error' => 'Tree not found']); exit; }

$store = ft_store($pdo, $tenant);
$doc = [
    'format' => 'familytree/1', 'name' => $tenant['display_name'], 'slug' => $tenant['slug'],
    'exported_at' => date('c'), 'settings' => ft_tenant_settings($tenant),
    'members' => $store->members(), 'media' => $store->media(),
    'family_events' => ft_family_events($pdo, $tenantId),
];
logActivityWithTenant($pdo, 'export_json', 'tree', null, ['members' => count($doc['members'])], $tenantId);
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9-]/', '', $tenant['slug']) . '-export-' . date('Y-m-d') . '.json"');
echo json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
