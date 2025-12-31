<?php
/**
 * Configuration file for Diary Application
 * Contains authentication credentials and security functions
 */

// Session Security Settings - MUST be set before session_start()
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1); // For HTTPS
ini_set('session.cookie_samesite', 'Strict');

// Error handling for production
ini_set('display_errors', 0);
error_reporting(0);

// Security: Change these credentials!
define('AUTH_USERNAME', 'yourusername');
define('AUTH_PASSWORD', password_hash('yourpassword', PASSWORD_DEFAULT));

// Session configuration
define('SESSION_NAME', 'diary_app_session');
define('SESSION_TIMEOUT', 3600); // 1 hour in seconds

/**
 * Generate CSRF token
 */
function generateCsrfToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 */
function verifyCsrfToken($token) {
    if (!isset($_SESSION['csrf_token']) || !isset($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Output CSRF token input field
 */
function csrfTokenField() {
    $token = generateCsrfToken();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

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
        // Generate new CSRF token on login
        unset($_SESSION['csrf_token']);
        generateCsrfToken();
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
