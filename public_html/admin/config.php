<?php
/**
 * admin/config.php
 * Phase 0: Delegates entirely to the main config.
 * All credentials, session setup, CSRF, and helpers live in config.php.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/trees.php';

// Keep the session in step with the account's memberships. Checked once a
// minute per session, and always before a write, so a role change made on
// another device cannot be used for up to a minute.
$ft_force_check = ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET';
if (!empty($_SESSION['admin_logged_in']) && !empty($_SESSION['admin_user']['id']) && $pdo
    && ($ft_force_check || (time() - (int)($_SESSION['ft_user_checked'] ?? 0)) > 60)) {
    try {
        $uid = (int)$_SESSION['admin_user']['id'];
        $membership = !empty($_SESSION['tenant_id'])
            ? ft_user_membership($pdo, $uid, (string)$_SESSION['tenant_id'])
            : null;

        if ($membership) {
            // Refresh the per-tree role in case it changed on another device.
            ft_apply_tree_session($membership);
            $_SESSION['ft_user_checked'] = time();
        } else {
            // The tree was deleted, deactivated or the account was removed from
            // it. Move to another tree when there is one; otherwise sign out.
            $next = ft_login_membership($pdo, $uid);
            if ($next) {
                ft_apply_tree_session($next);
                $_SESSION['ft_user_checked'] = time();
            } else {
                // Log out without leaving a session whose CSRF token was wiped:
                // the login form that follows needs a working one.
                $_SESSION = [];
                if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                $_SESSION['created_at'] = time();
            }
        }
    } catch (Throwable $e) { /* never lock people out over a failed check */ }
}
