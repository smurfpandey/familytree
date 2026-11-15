<?php
// The home page is the family's tree. Before setup, config.php sends visitors to /install/.
define('FT_APP', 1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/privacy.php';
require_once __DIR__ . '/includes/trees.php';

// A signed-in account goes to the tree it is currently working on; visitors
// get the install's default tree.
$t = null;
if (!empty($_SESSION['admin_logged_in']) && !empty($_SESSION['admin_user']['id']) && !empty($_SESSION['tenant_id'])) {
    $m = ft_user_membership($pdo, (int)$_SESSION['admin_user']['id'], (string)$_SESSION['tenant_id']);
    if ($m) $t = loadTenant($pdo, (string)$m['id']);
}
if (!$t && getPublicTenantId()) $t = loadTenant($pdo, getPublicTenantId());
header('Location: ' . ($t ? '/tree/' . rawurlencode($t['slug']) : '/admin/'), true, 302);
