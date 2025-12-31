<?php
/**
 * Login page for Diary Application
 * Now with CSRF protection
 */

session_name('diary_app_session');
session_start();

require_once 'config.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

// Generate CSRF token
generateCsrfToken();

$error = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrfToken)) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            $error = 'Please enter both username and password.';
        } else {
            if (login($username, $password)) {
                header('Location: index.php');
                exit;
            } else {
                $error = 'Invalid username or password.';
                // Add delay to prevent brute force attacks
                sleep(2);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - My Diary</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="login-container">
        <div class="login-box">
            <div class="login-header">
                <h1>📔 My Personal Diary</h1>
                <p>Please log in to continue</p>
            </div>

            <?php if ($error): ?>
                <div class="message error">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" class="login-form">
                <?php echo csrfTokenField(); ?>
                
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" 
                           placeholder="Enter your username" 
                           required autofocus>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" 
                           placeholder="Enter your password" 
                           required>
                </div>

                <button type="submit" class="btn btn-primary btn-block">
                    🔒 Login
                </button>
            </form>

            <div class="login-footer">
                <p><small>Your diary is private and secure</small></p>
            </div>
        </div>
    </div>
</body>
</html>
