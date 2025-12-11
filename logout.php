<?php
/**
 * Logout Handler
 */

session_start();

// 1. Xóa tất cả các biến session
$_SESSION = array();

// 2. Xóa cookie session (để đảm bảo sạch sẽ hoàn toàn)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. Hủy session
session_destroy();

// 4. Chuyển hướng về Trang Chủ (thay vì auth-login.php)
header('Location: index.html');
exit;
?>