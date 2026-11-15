<?php
/**
 * admin/instance-api.php - settings for the whole installation.
 *
 *   GET   instance name, default public tree, new-tree defaults
 *   POST  save those settings
 *
 * These are not tree settings: changing them never changes an existing tree.
 * Every tree owner may read and change them.
 */
define('FT_APP', 1);
require_once __DIR__ . '/config.php';
require_once dirname(__DIR__) . '/includes/privacy.php';
require_once dirname(__DIR__) . '/includes/trees.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . (getenv('APP_URL') ?: ''));
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

if (empty($_SESSION['admin_logged_in']) || empty($_SESSION['admin_user']['id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$userId = (int)$_SESSION['admin_user']['id'];
if (!ft_can_manage_instance($pdo, $userId)) {
    http_response_code(403);
    echo json_encode(['error' => 'Only a tree owner can change instance settings.']);
    exit;
}

const INSTANCE_FIELD_LABELS = [
    'birth_date' => 'Date of Birth', 'birth_location' => 'Birth Location', 'death_date' => 'Death Date', 'death_location' => 'Death Location',
    'current_location' => 'Current Location', 'social_media' => 'Social Media (Instagram / Facebook)', 'photos' => 'Photos', 'bio' => 'Biography / Wiki', 'anniversary_date' => 'Marriage Anniversary',
];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $settings = ft_instance_settings($pdo);
    $effectivePrivacy = array_merge(PRIVACY_FIELD_DEFAULTS, $settings['new_tree_privacy']);
    $default = ft_instance_default_tenant($pdo);

    echo json_encode([
        'settings' => [
            'instance_name'           => $settings['instance_name'],
            'default_tenant_id'       => $settings['default_tenant_id'],
            'new_tree_visibility'     => $settings['new_tree_visibility'],
            'new_tree_theme'          => $settings['new_tree_theme'],
            'new_tree_hindu_calendar' => $settings['new_tree_hindu_calendar'],
            'new_tree_privacy'        => $effectivePrivacy,
        ],
        'effective_default_tenant_id' => $default['id'] ?? '',
        'trees'          => array_map(fn($t) => [
            'id' => $t['id'], 'slug' => $t['slug'], 'display_name' => $t['display_name'], 'visibility' => $t['visibility'],
        ], ft_instance_trees($pdo)),
        'field_labels'   => INSTANCE_FIELD_LABELS,
        'field_defaults' => PRIVACY_FIELD_DEFAULTS,
        'looks'          => FT_TREE_LOOKS,
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

validateCsrf();
$input = json_decode(file_get_contents('php://input'), true) ?? [];

$name = trim((string)($input['instance_name'] ?? ''));
if ($name === '') {
    http_response_code(422);
    echo json_encode(['error' => 'Give this instance a name.']);
    exit;
}
if (mb_strlen($name) > 80) $name = mb_substr($name, 0, 80);

$defaultId = trim((string)($input['default_tenant_id'] ?? ''));
if ($defaultId !== '') {
    $chk = $pdo->prepare('SELECT 1 FROM tenants WHERE id = ? AND is_active = 1 LIMIT 1');
    $chk->execute([$defaultId]);
    if (!$chk->fetchColumn()) {
        http_response_code(422);
        echo json_encode(['error' => 'That default tree does not exist or is inactive.']);
        exit;
    }
}

$visibility = (string)($input['new_tree_visibility'] ?? 'private');
if (!in_array($visibility, ['public', 'unlisted', 'private'], true)) {
    http_response_code(422);
    echo json_encode(['error' => 'Choose a valid visibility for new trees.']);
    exit;
}

$theme = (string)($input['new_tree_theme'] ?? 'heritage');
if (!in_array($theme, FT_TREE_LOOKS, true)) {
    http_response_code(422);
    echo json_encode(['error' => 'Choose a valid tree look.']);
    exit;
}

$privacy = [];
foreach (($input['new_tree_privacy'] ?? []) as $field => $value) {
    if (!array_key_exists($field, PRIVACY_FIELD_DEFAULTS)) continue;
    if (!in_array($value, ['public', 'members', 'private'], true)) continue;
    $privacy[$field] = $value;
}
$privacy = array_merge(PRIVACY_FIELD_DEFAULTS, $privacy);

ft_instance_set($pdo, 'instance_name', $name);
ft_instance_set($pdo, 'default_tenant_id', $defaultId);
ft_instance_set($pdo, 'new_tree_visibility', $visibility);
ft_instance_set($pdo, 'new_tree_theme', $theme);
ft_instance_set($pdo, 'new_tree_hindu_calendar', !empty($input['new_tree_hindu_calendar']));
ft_instance_set($pdo, 'new_tree_privacy', $privacy);

echo json_encode(['ok' => true, 'settings' => [
    'instance_name'           => $name,
    'default_tenant_id'       => $defaultId,
    'new_tree_visibility'     => $visibility,
    'new_tree_theme'          => $theme,
    'new_tree_hindu_calendar' => !empty($input['new_tree_hindu_calendar']),
    'new_tree_privacy'        => $privacy,
]]);
