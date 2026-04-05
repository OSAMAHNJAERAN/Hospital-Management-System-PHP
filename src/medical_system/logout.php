<?php
// Include database configuration and functions
require_once 'config/database.php';
require_once 'includes/functions.php';

// Process logout
session_start();
session_unset();
session_destroy();

// Redirect to login page
header("Location: login.php");
exit();
?>
