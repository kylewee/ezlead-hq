<?php
/**
 * Buyer Portal - Shared Header & Navigation
 * Include at top of every portal page after auth
 *
 * Expects: $buyer (from BuyerAuth::requireAuth()), $pageTitle, $activePage
 */
$buyerName = htmlspecialchars($buyer['company'] ?: $buyer['name'] ?? 'there');
$balance = ($buyer['balance'] ?? 0) / 100;
$balanceClass = $balance >= 35 ? 'balance-healthy' : ($balance > 0 ? 'balance-warning' : 'balance-empty');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Buyer Portal') ?> - EzLead</title>
    <style>
        :root {
            --navy: #1e3a5f;
            --navy-light: #2a4a75;
            --navy-dark: #152d4a;
            --green: #27ae60;
            --green-light: #e8f8e8;
            --orange: #e67e22;
            --orange-light: #fef3e2;
            --red: #c0392b;
            --red-light: #fde8e8;
            --gray-50: #f8f9fb;
            --gray-100: #f0f2f5;
            --gray-200: #e0e4e8;
            --gray-400: #9ca3af;
            --gray-600: #6b7280;
            --gray-800: #1f2937;
            --radius: 10px;
            --shadow: 0 1px 3px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.06);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.08);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--gray-50);
            min-height: 100vh;
            color: var(--gray-800);
        }

        /* Top Navigation */
        .portal-nav {
            background: var(--navy);
            color: white;
            padding: 0 24px;
            display: flex;
            align-items: center;
            height: 60px;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }

        .portal-brand {
            font-size: 18px;
            font-weight: 700;
            letter-spacing: -0.3px;
            margin-right: 32px;
            white-space: nowrap;
        }

        .portal-links {
            display: flex;
            gap: 4px;
            flex: 1;
        }

        .portal-links a {
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            padding: 8px 14px;
            border-radius: 6px;
            transition: all 0.15s;
        }

        .portal-links a:hover {
            color: white;
            background: rgba(255,255,255,0.1);
        }

        .portal-links a.active {
            color: white;
            background: rgba(255,255,255,0.15);
        }

        .portal-right {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-left: auto;
        }

        .portal-balance {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
        }

        .portal-balance.balance-healthy { background: rgba(39,174,96,0.2); color: #7dffb3; }
        .portal-balance.balance-warning { background: rgba(230,126,34,0.2); color: #ffc078; }
        .portal-balance.balance-empty { background: rgba(192,57,43,0.2); color: #ff8a80; }

        .portal-balance .label { font-weight: 400; opacity: 0.8; }

        .portal-user {
            font-size: 13px;
            color: rgba(255,255,255,0.6);
        }

        .portal-logout {
            color: rgba(255,255,255,0.5);
            text-decoration: none;
            font-size: 13px;
            padding: 6px 10px;
            border-radius: 4px;
        }

        .portal-logout:hover {
            color: white;
            background: rgba(255,255,255,0.1);
        }

        /* Welcome Banner */
        .welcome-banner {
            background: linear-gradient(135deg, var(--navy) 0%, var(--navy-light) 100%);
            color: white;
            padding: 28px 32px;
        }

        .welcome-banner h1 {
            font-size: 22px;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .welcome-banner p {
            font-size: 14px;
            opacity: 0.7;
        }

        /* Main Content */
        .portal-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 24px;
        }

        /* Cards */
        .card {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 24px;
            margin-bottom: 20px;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .card-header h2 {
            font-size: 16px;
            font-weight: 600;
            color: var(--gray-800);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border-left: 3px solid var(--gray-200);
        }

        .stat-card .stat-label {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--gray-400);
            margin-bottom: 6px;
        }

        .stat-card .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: var(--gray-800);
        }

        .stat-card.stat-balance { border-left-color: var(--green); }
        .stat-card.stat-balance .stat-value { color: var(--green); }
        .stat-card.stat-today { border-left-color: var(--navy); }
        .stat-card.stat-week { border-left-color: var(--navy-light); }
        .stat-card.stat-total { border-left-color: var(--gray-400); }
        .stat-card.stat-free { border-left-color: var(--orange); }
        .stat-card.stat-free .stat-value { color: var(--orange); }

        /* Lead Cards */
        .leads-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 16px;
        }

        .lead-card {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 20px;
            border-left: 3px solid var(--navy);
            transition: box-shadow 0.15s, transform 0.15s;
            position: relative;
        }

        .lead-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-1px);
        }

        .lead-card.lead-new {
            border-left-color: var(--green);
        }

        .lead-card.lead-returned {
            border-left-color: var(--red);
            opacity: 0.7;
        }

        .lead-card-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }

        .lead-name {
            font-size: 16px;
            font-weight: 600;
            color: var(--gray-800);
        }

        .lead-badge {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 3px 8px;
            border-radius: 4px;
        }

        .lead-badge.badge-new {
            background: var(--green-light);
            color: var(--green);
        }

        .lead-badge.badge-delivered {
            background: #e8f0fe;
            color: var(--navy);
        }

        .lead-badge.badge-returned {
            background: var(--red-light);
            color: var(--red);
        }

        .lead-details {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-bottom: 14px;
        }

        .lead-detail {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: var(--gray-600);
        }

        .lead-detail .icon {
            width: 16px;
            text-align: center;
            font-size: 13px;
            color: var(--gray-400);
            flex-shrink: 0;
        }

        .lead-detail a {
            color: var(--navy);
            text-decoration: none;
            font-weight: 500;
        }

        .lead-detail a:hover {
            text-decoration: underline;
        }

        .lead-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 12px;
            border-top: 1px solid var(--gray-100);
            font-size: 13px;
            color: var(--gray-400);
        }

        .lead-source {
            background: var(--gray-100);
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 12px;
        }

        .lead-price {
            font-weight: 600;
            color: var(--gray-600);
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            border: none;
            transition: all 0.15s;
        }

        .btn-primary {
            background: var(--navy);
            color: white;
        }

        .btn-primary:hover { background: var(--navy-dark); }

        .btn-green {
            background: var(--green);
            color: white;
        }

        .btn-green:hover { background: #219a52; }

        .btn-outline {
            border: 1px solid var(--gray-200);
            background: white;
            color: var(--gray-600);
        }

        .btn-outline:hover {
            border-color: var(--navy);
            color: var(--navy);
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 13px;
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray-400);
        }

        .empty-state h3 {
            font-size: 18px;
            color: var(--gray-600);
            margin-bottom: 8px;
        }

        .empty-state p {
            font-size: 14px;
            max-width: 400px;
            margin: 0 auto;
        }

        /* Section headers */
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .section-header h2 {
            font-size: 18px;
            font-weight: 600;
        }

        /* Tables */
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th, .data-table td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid var(--gray-100);
            font-size: 14px;
        }

        .data-table th {
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--gray-400);
            background: var(--gray-50);
        }

        .data-table tr:hover td { background: var(--gray-50); }
        .data-table tr:last-child td { border-bottom: none; }

        .amount-positive { color: var(--green); font-weight: 600; }
        .amount-negative { color: var(--red); font-weight: 600; }

        /* Responsive */
        @media (max-width: 768px) {
            .portal-nav { padding: 0 12px; }
            .portal-brand { margin-right: 16px; font-size: 16px; }
            .portal-links a { padding: 6px 10px; font-size: 13px; }
            .portal-user { display: none; }
            .portal-content { padding: 16px; }
            .leads-grid { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .welcome-banner { padding: 20px 16px; }
            .welcome-banner h1 { font-size: 18px; }
        }

        @media (max-width: 480px) {
            .portal-links { overflow-x: auto; }
            .stats-grid { grid-template-columns: 1fr; }
        }

        /* Alerts */
        .alert {
            padding: 12px 16px;
            border-radius: var(--radius);
            margin-bottom: 16px;
            font-size: 14px;
        }

        .alert-warning {
            background: var(--orange-light);
            color: #92400e;
            border: 1px solid #fcd34d;
        }

        .alert-success {
            background: var(--green-light);
            color: #065f46;
        }

        .alert-error {
            background: var(--red-light);
            color: var(--red);
        }

        /* Quick action buttons */
        .quick-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
    </style>
</head>
<body>
    <nav class="portal-nav">
        <div class="portal-brand">EzLead</div>
        <div class="portal-links">
            <a href="/buyer/" class="<?= ($activePage ?? '') === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
            <a href="/buyer/fund.php" class="<?= ($activePage ?? '') === 'billing' ? 'active' : '' ?>">Billing</a>
            <a href="/buyer/account.php" class="<?= ($activePage ?? '') === 'account' ? 'active' : '' ?>">Account</a>
        </div>
        <div class="portal-right">
            <div class="portal-balance <?= $balanceClass ?>">
                <span class="label">Bal:</span> $<?= number_format($balance, 2) ?>
            </div>
            <span class="portal-user"><?= $buyerName ?></span>
            <a href="/buyer/logout.php" class="portal-logout">Sign Out</a>
        </div>
    </nav>
