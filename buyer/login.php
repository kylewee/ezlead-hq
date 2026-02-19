<?php
/**
 * Buyer Portal - Login
 */
require_once __DIR__ . '/auth.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    if (login($email, $password)) {
        header('Location: /buyer/');
        exit;
    } else {
        $error = 'Invalid email or password';
    }
}

// If already logged in, redirect
if (getCurrentUser()) {
    header('Location: /buyer/');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buyer Login - EzLead</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #1e3a5f 0%, #2a4a75 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-box {
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            width: 100%;
            max-width: 400px;
        }
        h1 { font-size: 24px; margin-bottom: 8px; color: #1e3a5f; }
        .subtitle { color: #666; margin-bottom: 30px; }
        label { display: block; font-size: 14px; font-weight: 500; margin-bottom: 6px; color: #333; }
        input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 16px;
            margin-bottom: 20px;
        }
        input:focus { outline: none; border-color: #1e3a5f; }
        button {
            width: 100%;
            padding: 14px;
            background: #1e3a5f;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
        }
        button:hover { background: #2a4a75; }
        .error { background: #fee; color: #c00; padding: 12px; border-radius: 8px; margin-bottom: 20px; }
        .register-link { text-align: center; margin-top: 20px; color: #666; }
        .register-link a { color: #1e3a5f; }
    </style>
</head>
<body>
    <div class="login-box">
        <h1>Buyer Portal</h1>
        <p class="subtitle">Sign in to view your leads</p>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" required autofocus>

            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>

            <button type="submit">Sign In</button>
        </form>

        <p class="register-link">
            New contractor? <a href="/crm/?module=users/registration">Register here</a>
        </p>
    </div>
<!-- CRM Analytics -->
<script>(function(){navigator.sendBeacon("https://ezlead4u.com/crm/plugins/claude/track.php",JSON.stringify({url:location.href,ref:document.referrer}));})()</script>
</body>
</html>
