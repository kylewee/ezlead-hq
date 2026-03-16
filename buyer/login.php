<?php
/**
 * Buyer Portal - Login
 */
require_once __DIR__ . '/BuyerAuth.php';
$auth = new BuyerAuth();

$error = '';

if ($auth->getCurrentBuyer()) {
    header('Location: /buyer/');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    if ($auth->login($email, $password)) {
        header('Location: /buyer/');
        exit;
    } else {
        $error = 'Invalid email or password';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - EzLead</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f2f5;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-wrap {
            width: 100%;
            max-width: 420px;
            padding: 20px;
        }
        .login-brand {
            text-align: center;
            margin-bottom: 32px;
        }
        .login-brand h1 {
            font-size: 28px;
            font-weight: 700;
            color: #1e3a5f;
            letter-spacing: -0.5px;
        }
        .login-brand p {
            color: #6b7280;
            font-size: 15px;
            margin-top: 4px;
        }
        .login-box {
            background: white;
            padding: 32px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08), 0 4px 12px rgba(0,0,0,0.05);
        }
        label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 6px;
            color: #333;
        }
        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 12px 14px;
            border: 1.5px solid #e0e4e8;
            border-radius: 8px;
            font-size: 15px;
            margin-bottom: 18px;
            transition: border-color 0.15s;
        }
        input:focus {
            outline: none;
            border-color: #1e3a5f;
        }
        button {
            width: 100%;
            padding: 13px;
            background: #1e3a5f;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s;
        }
        button:hover { background: #2a4a75; }
        .error {
            background: #fde8e8;
            color: #c0392b;
            padding: 10px 14px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 14px;
        }
        .login-footer {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
            color: #6b7280;
        }
        .login-footer a { color: #1e3a5f; text-decoration: none; font-weight: 500; }
        .login-footer a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="login-wrap">
        <div class="login-brand">
            <h1>EzLead</h1>
            <p>Buyer Portal</p>
        </div>
        <div class="login-box">
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
        </div>
        <p class="login-footer">
            New contractor? <a href="/buyer/signup.php">Create an account</a>
        </p>
    </div>
</body>
</html>
