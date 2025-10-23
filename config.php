<?php
/**
 * Configuration file for Diary Application
 * Contains authentication credentials
 */

// Security: Change these credentials!
define('AUTH_USERNAME', 'admin');
define('AUTH_PASSWORD', password_hash('changeme123', PASSWORD_DEFAULT));

// Session configuration
define('SESSION_NAME', 'diary_app_session');
define('SESSION_TIMEOUT', 3600); // 1 hour in seconds

/**
 * Verify user credentials
 */
function verifyCredentials($username, $password) {
    return $username === AUTH_USERNAME && password_verify($password, AUTH_PASSWORD);
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        return false;
    }
    
    // Check session timeout
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT)) {
        logout();
        return false;
    }
    
    // Update last activity time
    $_SESSION['last_activity'] = time();
    return true;
}

/**
 * Login user
 */
function login($username, $password) {
    if (verifyCredentials($username, $password)) {
        session_regenerate_id(true); // Prevent session fixation
        $_SESSION['logged_in'] = true;
        $_SESSION['username'] = $username;
        $_SESSION['last_activity'] = time();
        return true;
    }
    return false;
}

/**
 * Logout user
 */
function logout() {
    $_SESSION = [];
    
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    session_destroy();
}

/**
 * Require authentication - redirect to login if not logged in
 */
function requireAuth() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}
