<?php
require_once 'utils.php';
require_once 'db.php';

if (!empty($_SESSION['user_id'])) {
    try {
        getPdo()->prepare('UPDATE users SET remember_token = NULL, remember_expires = NULL WHERE id = ?')
            ->execute([$_SESSION['user_id']]);
    } catch (Throwable $e) {
        // Ignore logout cleanup failures.
    }
}

$secure = isHttpsRequest();
$domain = cookieDomain();
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'] ?? '/', $params['domain'] ?? $domain, $secure, true);
}
setcookie('remember_selector', '', time() - 42000, '/', $domain, $secure, true);
setcookie('remember_token', '', time() - 42000, '/', $domain, $secure, true);

session_unset();
session_destroy();
header('Location: login.php');
exit;
?>
