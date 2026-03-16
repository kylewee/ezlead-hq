<?php
/**
 * Buyer Portal - Self-Service Signup
 */
require_once __DIR__ . '/BuyerAuth.php';
$auth = new BuyerAuth();

if ($auth->getCurrentBuyer()) {
    header('Location: /buyer/');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $company = trim($_POST['company'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $zipCodes = trim($_POST['zip_codes'] ?? '');

    if (!$name || !$email || !$password) {
        $error = 'Name, email, and password are required.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $db = $auth->getDb();
        $stmt = $db->prepare("SELECT id FROM buyers WHERE email = :email");
        $stmt->bindValue(':email', strtolower($email));
        $existing = $stmt->execute()->fetchArray();

        if ($existing) {
            $error = 'An account with this email already exists.';
        } else {
            $buyerId = $auth->createBuyer([
                'email' => $email,
                'password' => $password,
                'name' => $name,
                'company' => $company,
                'phone' => preg_replace('/[^0-9]/', '', $phone),
                'price_per_lead' => 3500,
            ]);

            if ($buyerId) {
                if ($zipCodes) {
                    $cleanZips = implode(',', array_map('trim', explode(',', preg_replace('/[^0-9,\s]/', '', $zipCodes))));
                    $stmt = $db->prepare("UPDATE buyers SET zip_codes = :zips WHERE id = :id");
                    $stmt->bindValue(':zips', $cleanZips);
                    $stmt->bindValue(':id', $buyerId);
                    $stmt->execute();
                }

                $auth->login($email, $password);
                header('Location: /buyer/');
                exit;
            } else {
                $error = 'Failed to create account. Please try again.';
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
    <title>Sign Up - EzLead</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f2f5;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .signup-wrap { width: 100%; max-width: 480px; }
        .signup-brand { text-align: center; margin-bottom: 28px; }
        .signup-brand h1 { font-size: 28px; font-weight: 700; color: #1e3a5f; }
        .signup-brand p { color: #6b7280; font-size: 15px; margin-top: 4px; }
        .signup-box {
            background: white;
            padding: 32px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08), 0 4px 12px rgba(0,0,0,0.05);
        }
        .perks {
            background: #f0f7ff;
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 24px;
            font-size: 14px;
            color: #1e3a5f;
            line-height: 1.6;
        }
        .perks strong { display: block; margin-bottom: 4px; }
        .row { margin-bottom: 16px; }
        .row-half { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px; }
        label { display: block; font-size: 14px; font-weight: 500; margin-bottom: 5px; color: #333; }
        input {
            width: 100%;
            padding: 11px 14px;
            border: 1.5px solid #e0e4e8;
            border-radius: 8px;
            font-size: 15px;
            transition: border-color 0.15s;
        }
        input:focus { outline: none; border-color: #1e3a5f; }
        .hint { font-size: 12px; color: #9ca3af; margin-top: 4px; }
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
            margin-top: 4px;
        }
        button:hover { background: #2a4a75; }
        .error { background: #fde8e8; color: #c0392b; padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
        .footer { text-align: center; margin-top: 20px; font-size: 14px; color: #6b7280; }
        .footer a { color: #1e3a5f; text-decoration: none; font-weight: 500; }
    </style>
</head>
<body>
    <div class="signup-wrap">
        <div class="signup-brand">
            <h1>EzLead</h1>
            <p>Start getting leads in 60 seconds</p>
        </div>
        <div class="signup-box">
            <div class="perks">
                <strong>What you get:</strong>
                3 free test leads, no card needed. Real-time delivery via email + SMS. Choose your zip codes. $35/lead after trial, cancel anytime.
            </div>

            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="row">
                    <label for="name">Your Name *</label>
                    <input type="text" id="name" name="name" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                </div>
                <div class="row">
                    <label for="email">Email *</label>
                    <input type="email" id="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>
                <div class="row">
                    <label for="password">Password *</label>
                    <input type="password" id="password" name="password" required minlength="8">
                    <div class="hint">At least 8 characters</div>
                </div>
                <div class="row-half">
                    <div>
                        <label for="company">Company</label>
                        <input type="text" id="company" name="company" value="<?= htmlspecialchars($_POST['company'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="phone">Phone</label>
                        <input type="tel" id="phone" name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                    </div>
                </div>
                <div class="row">
                    <label for="zip_codes">Zip Codes You Service</label>
                    <input type="text" id="zip_codes" name="zip_codes" placeholder="32080, 32084, 32092" value="<?= htmlspecialchars($_POST['zip_codes'] ?? '') ?>">
                    <div class="hint">Comma-separated. Leave blank for all areas.</div>
                </div>
                <button type="submit">Create Account</button>
            </form>
        </div>
        <p class="footer">Already have an account? <a href="/buyer/login.php">Sign in</a></p>
    </div>
</body>
</html>
