<?php
/**
 * api-family.php - Public read-only family tree API
 * Privacy & visibility controls applied; data comes from the tree's data home (includes/store.php).
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
// a big tree is megabytes of JSON; gzip makes it ~10x smaller (ob_gzhandler checks Accept-Encoding itself)
if (extension_loaded('zlib') && !ini_get('zlib.output_compression')) ob_start('ob_gzhandler');

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

define('FT_APP', 1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/privacy.php';
require_once __DIR__ . '/includes/store.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Read-only API']);
    exit;
}

try {
    $treeSlug = trim($_GET['tree'] ?? $_GET['slug'] ?? '');
    $tenant = $treeSlug !== '' ? getPublicTenantBySlug($pdo, $treeSlug) : loadTenant($pdo, getPublicTenantId());
    if (!$tenant) {
        http_response_code(404);
        echo json_encode(['error' => 'Tree not found']);
        exit;
    }
    $tenantId = $tenant['id'];

    // Who is asking? A member of THIS tree (even when their admin session is
    // on another tree) sees members-level fields; editors and owners see all.
    $audience = ft_tree_audience($pdo, $tenantId);
    enforceTreeVisibility($tenant['visibility'], $audience !== 'public');
    if (($tenant['visibility'] ?? 'public') === 'public') {
        header('Access-Control-Allow-Origin: *');   // embeddable only when the tree itself is public
    }

    $privacySettings = loadPrivacySettings($pdo, $tenantId);
    $store = ft_store($pdo, $tenant);

    $rows = $store->members();
    foreach ($rows as &$row) {
        if (!$row['birth_date']) $row['birth_date'] = '';
        $row['nickname'] = $row['nickname'] ?: '';
    }
    unset($row);

    $output = [];
    foreach ($rows as $row) {
        $output[] = applyPublicPrivacy($row, $privacySettings, $audience);
    }
    echo json_encode($output);

} catch (Throwable $e) {
    error_log('api-family.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
