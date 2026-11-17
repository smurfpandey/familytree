<?php
/**
 * api-events.php?tree={slug} - the family events of a tree (read only).
 * Same access as api-family.php: the tree's visibility decides who may read.
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json');

define('FT_APP', 1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/privacy.php';
require_once __DIR__ . '/includes/store.php';
require_once __DIR__ . '/includes/events.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); echo json_encode(['error' => 'Read-only API']); exit; }

try {
    $treeSlug = trim($_GET['tree'] ?? $_GET['slug'] ?? '');
    $tenant = $treeSlug !== '' ? getPublicTenantBySlug($pdo, $treeSlug) : loadTenant($pdo, getPublicTenantId());
    if (!$tenant) { http_response_code(404); echo json_encode(['error' => 'Tree not found']); exit; }
    $audience = ft_tree_audience($pdo, $tenant['id']);
    enforceTreeVisibility($tenant['visibility'], $audience !== 'public');
    if (($tenant['visibility'] ?? 'public') === 'public') header('Access-Control-Allow-Origin: *');

    $events = ft_family_events($pdo, $tenant['id']);
    // A private person's id must not leak through an event's tag list.
    if ($audience !== 'owner') {
        try {
            $private = [];
            foreach (ft_store($pdo, $tenant)->members() as $m) if (!empty($m['is_private'])) $private[] = (int)$m['id'];
            $events = ft_events_hide_private($events, $private);
        } catch (Throwable $e) {
            // We cannot tell who is private: show the events without their tags.
            foreach ($events as &$ev) $ev['people'] = [];
            unset($ev);
        }
    }
    echo json_encode($events);
} catch (Throwable $e) {
    error_log('api-events.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
