<?php
/**
 * Logout Handler
 * Destroy session and redirect to login page
 */

session_start();
session_destroy();

header('Location: auth-login.php');
exit;
?>
