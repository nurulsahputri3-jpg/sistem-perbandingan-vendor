<?php
require 'includes/auth.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
	session_start();
}

unset($_SESSION['user_id'], $_SESSION['role'], $_SESSION['vendor_id']);
$_SESSION = [];

if (ini_get('session.use_cookies')) {
	$cookie = session_get_cookie_params();
	setcookie(
		session_name(),
		'',
		time() - 42000,
		$cookie['path'],
		$cookie['domain'],
		$cookie['secure'],
		$cookie['httponly']
	);
}

session_destroy();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Location: login.php', true, 303);
exit;
