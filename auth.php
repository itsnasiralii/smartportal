<?php
// Smart System — Corporate NOC Portal Authentication & Access Control
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Strict',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
    ]);
    session_start();
}

require_once __DIR__ . '/db.php';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

if (!function_exists('has_feature_access')) {
    function has_feature_access($feature_key) {
        if (($_SESSION['noc_role'] ?? '') === 'admin') return true;
        $perms = $_SESSION['noc_permissions'] ?? 'all';
        if ($perms === 'all' || !is_array($perms)) return true;
        return in_array($feature_key, $perms);
    }
}

$is_api = basename($_SERVER['SCRIPT_NAME'] ?? '') === 'api.php';

// Handle sign out
if (($_POST['action'] ?? '') === 'noc_logout' || ($_GET['action'] ?? '') === 'noc_logout') {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    session_destroy();
    header('Location: index.php');
    exit;
}

// Check if user is authenticated; if not, lock the dashboard
if (empty($_SESSION['noc_logged_in'])) {
    if ($is_api) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Authentication required. Please sign in.']);
        exit;
    }

    $login_error = '';
    if (($_POST['action'] ?? '') === 'noc_login') {
        if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
            $login_error = 'Security session expired. Please refresh and retry.';
        } else {
            $input_user = trim($_POST['username'] ?? '');
            $input_pass = (string)($_POST['password'] ?? '');

            if (!empty($input_user) && !empty($input_pass)) {
                $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? COLLATE NOCASE LIMIT 1");
                $stmt->execute([$input_user]);
                $user = $stmt->fetch();

                if ($user && (password_verify($input_pass, $user['password_hash']) || hash_equals($user['password_hash'], $input_pass))) {
                    session_regenerate_id(true);
                    $_SESSION['noc_logged_in'] = true;
                    $_SESSION['noc_user'] = $user['username'];
                    $_SESSION['noc_role'] = $user['role'] ?? 'user';
                    $perms = $user['permissions'] ?? 'all';
                    if (!empty($perms) && $perms !== 'all') {
                        $decoded = json_decode($perms, true);
                        $_SESSION['noc_permissions'] = is_array($decoded) ? $decoded : 'all';
                    } else {
                        $_SESSION['noc_permissions'] = 'all';
                    }
                    if (($user['role'] ?? '') === 'admin') {
                        $_SESSION['admin_logged_in'] = true;
                    }

                    $redirect = !empty($_GET['redirect']) ? $_GET['redirect'] : 'index.php';
                    header("Location: {$redirect}");
                    exit;
                } else {
                    $login_error = 'Invalid username or password.';
                }
            } else {
                $login_error = 'Please enter both username and password.';
            }
        }
    }
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart System — Corporate Portal Sign In</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .login-wrap { min-height: 80vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .login-card { max-width: 440px; width: 100%; }
        .login-badge { display: inline-block; background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; font-size: 0.75rem; font-weight: 700; padding: 3px 8px; border-radius: 4px; margin-bottom: 8px; }
        .login-banner { text-align: center; margin-bottom: 24px; }
        .login-banner h1 { font-size: 1.45rem; font-weight: 800; color: #0f172a; margin-top: 4px; }
        .login-banner p { font-size: 0.88rem; color: #64748b; margin-top: 4px; }
    </style>
</head>
<body style="background: #f1f5f9;">
    <div class="login-wrap">
        <div class="panel-card login-card">
            <div class="panel-header" style="text-align: center; border-bottom: 1px solid #e2e8f0; padding: 24px 28px;">
                <div class="login-badge">🔒 Corporate NOC Portal</div>
                <h2 style="font-size: 1.35rem; color: #0f172a; margin: 0;">Smart System Sign In</h2>
                <p style="margin-top: 6px; font-size: 0.88rem; color: #64748b;">Authorized Company Personnel Only</p>
            </div>
            <div class="panel-body" style="padding: 28px;">
                <?php if ($login_error): ?>
                    <div class="alert-box error" style="margin-bottom: 18px; font-size: 0.88rem;">
                        ⚠️ <?= htmlspecialchars($login_error) ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="index.php">
                    <input type="hidden" name="action" value="noc_login">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">

                    <div class="form-group">
                        <label for="username">Corporate Username</label>
                        <input id="username" name="username" type="text" placeholder="e.g. ijaz" autocomplete="username" autofocus required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <input id="password" name="password" type="password" placeholder="Enter password" autocomplete="current-password" required>
                    </div>

                    <button type="submit" class="btn-primary" style="width: 100%; padding: 12px; font-size: 0.95rem; margin-top: 6px;">
                        🔐 Sign In to Console
                    </button>
                </form>

                <div style="margin-top: 20px; padding-top: 16px; border-top: 1px solid #f1f5f9; text-align: center; font-size: 0.8rem; color: #94a3b8;">
                    Protected corporate environment. All actions are logged and audited.
                </div>
            </div>
        </div>
    </div>
</body>
</html>
    <?php
    exit;
}

// Session is active and authenticated. Verify CSRF for POST actions.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = $_SERVER['HTTP_X_NOC_CSRF'] ?? $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'], $token)) {
        http_response_code(403);
        if ($is_api) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Security session verification failed. Please reload and retry.']);
        } else {
            echo 'Security session verification failed. Please reload and retry.';
        }
        exit;
    }
}
