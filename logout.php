<?php
/**
 * Logout page for Diary Application
 */

session_name('diary_app_session');
session_start();

require_once 'config.php';

// Perform logout
logout();

// Redirect to login page
header('Location: login.php');
exit;
