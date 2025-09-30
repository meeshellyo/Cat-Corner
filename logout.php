<?php
// logout.php
declare(strict_types=1);
session_start();

// Clear all session data
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();

// Redirect to login page (adjust path as needed)
header('Location: ./index.php');
exit;
