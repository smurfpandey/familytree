<?php
/**
 * admin/config.php
 * Phase 0: Delegates entirely to the main config.
 * All credentials, session setup, CSRF, and helpers live in config.php.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/trees.php';

// Keep the session in step with the account's memberships. Checked once a
// minute per session, so it costs one small query now and then.
if (!empty($_SESSION['admin_logged_in']) && !empty($_SESSION['admin_user']['id']) && $pdo
    && (time() - (int)($_SESSION['ft_user_checked'] ?? 0)) > 60) {
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
                $_SESSION = [];
                session_destroy();
                session_start();
            }
        }
    } catch (Throwable $e) { /* never lock people out over a failed check */ }
}
