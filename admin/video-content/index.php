<?php
/**
 * "Mechanic Busted" Video Content System
 *
 * Short-form video content strategy for TikTok/Reels/Shorts
 * Positioning EzLead4U as the honest alternative
 */

session_start();
$authenticated = isset($_SESSION['admin_hub_auth']) && $_SESSION['admin_hub_auth'] === true;
if (!$authenticated && isset($_POST['password'])) {
    if ($_POST['password'] === 'Rain0nin') {
        $_SESSION['admin_hub_auth'] = true;
        $authenticated = true;
    }
}

if (!$authenticated) {
    ?>
    <!DOCTYPE html>
    <html>
    <head><title>Video Content Login</title>
    <style>
        body { font-family: system-ui; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; background: #f1f5f9; }
        form { background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
        input { display: block; width: 200px; padding: 0.5rem; margin: 0.5rem 0 1rem; border: 1px solid #ddd; border-radius: 4px; }
        button { background: #2563eb; color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 4px; cursor: pointer; }
    </style>
    </head>
    <body>
        <form method="post">
            <h2>Video Content System</h2>
            <label>Password</label>
            <input type="password" name="password" required autofocus>
            <button type="submit">Login</button>
        </form>
    <!-- CRM Analytics -->
<script>(function(){navigator.sendBeacon("https://ezlead4u.com/crm/plugins/claude/track.php",JSON.stringify({url:location.href,ref:document.referrer}));})()</script>
</body>
    </html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>"Mechanic Busted" Video Content System</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: system-ui, -apple-system, sans-serif;
            background: #0f172a;
            color: white;
            padding: 2rem;
        }
        .header {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
        }
        h1 { font-size: 2rem; margin-bottom: 0.5rem; }
        .subtitle { opacity: 0.9; }
        .content-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }
        .card {
            background: #1e293b;
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid #334155;
        }
        .card h2 {
            color: #ef4444;
            margin-bottom: 1rem;
            font-size: 1.25rem;
        }
        .card ul {
            list-style: none;
            padding: 0;
        }
        .card li {
            padding: 0.75rem 0;
            border-bottom: 1px solid #334155;
        }
        .card li:last-child { border-bottom: none; }
        .tag {
            display: inline-block;
            background: #ef4444;
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            margin-left: 0.5rem;
        }
        .btn {
            display: inline-block;
            background: #ef4444;
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            text-decoration: none;
            margin-top: 1rem;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🎬 "Mechanic Busted" Video Content System</h1>
        <p class="subtitle">Short-form content exposing dishonest mechanics & positioning EZ Mobile as the honest alternative</p>
    </div>

    <div class="content-grid">
        <div class="card">
            <h2>Video Series Concepts</h2>
            <ul>
                <li>"Mechanic Charged $800 for This..." <span class="tag">VIRAL POTENTIAL</span></li>
                <li>"Why Shops Lie About Check Engine Lights"</li>
                <li>"The $1200 Brake Job That Cost $150"</li>
                <li>"Air Filter Scam - Caught on Camera"</li>
                <li>"Dealer Said Replace Transmission... Watch This"</li>
                <li>"How I Diagnose vs How They Guess"</li>
            </ul>
        </div>

        <div class="card">
            <h2>Content Format</h2>
            <ul>
                <li><strong>Hook (3 sec):</strong> "This shop charged $800 for..."</li>
                <li><strong>Show Snap-on ZEUS (10 sec):</strong> Real diagnostic data</li>
                <li><strong>Reveal (5 sec):</strong> "Actual cost: $45 part"</li>
                <li><strong>CTA (3 sec):</strong> "Need honest work? (904) 217-5152"</li>
            </ul>
            <p style="margin-top: 1rem; opacity: 0.8;">Total: 20-30 second videos</p>
        </div>

        <div class="card">
            <h2>Recording Equipment Setup</h2>
            <ul>
                <li>Phone camera (vertical 9:16 format)</li>
                <li>Tripod for Snap-on ZEUS screen shots</li>
                <li>External mic for engine bay audio</li>
                <li>Ring light for under-hood shots</li>
            </ul>
        </div>

        <div class="card">
            <h2>Platform Strategy</h2>
            <ul>
                <li><strong>TikTok:</strong> Primary platform, #mechanic #cartok</li>
                <li><strong>Instagram Reels:</strong> Cross-post</li>
                <li><strong>YouTube Shorts:</strong> SEO benefits</li>
                <li><strong>Facebook Reels:</strong> Local audience</li>
            </ul>
        </div>

        <div class="card">
            <h2>Real Examples to Film</h2>
            <ul>
                <li>Show check engine light diagnostic (P0420 = cat vs O2 sensor)</li>
                <li>Brake pad measurement with calipers vs visual "inspection"</li>
                <li>Dealer quote vs actual repair cost comparison</li>
                <li>Oil change upsell tactics exposed</li>
                <li>Before/after Snap-on ZEUS data</li>
            </ul>
        </div>

        <div class="card">
            <h2>Content Calendar</h2>
            <p style="margin-bottom: 1rem;">Post 3x per week minimum:</p>
            <ul>
                <li><strong>Monday:</strong> Diagnostic deep-dive</li>
                <li><strong>Wednesday:</strong> "Mechanic Busted" expose</li>
                <li><strong>Friday:</strong> Customer success story</li>
            </ul>
        </div>

        <div class="card">
            <h2>Engagement Tactics</h2>
            <ul>
                <li>Comment: "Should I get this fixed?" → Free diagnosis</li>
                <li>Pin comment with phone number</li>
                <li>Duet/Stitch other mechanic videos with corrections</li>
                <li>Series: "Guess the diagnosis" (engagement bait)</li>
            </ul>
        </div>

        <div class="card">
            <h2>Lead Capture from Videos</h2>
            <ul>
                <li>Link in bio → mechanicstaugustine.com</li>
                <li>Pinned comment: "Free diagnostic quote: (904) 217-5152"</li>
                <li>CTA overlay: "Tap to call"</li>
                <li>Track with UTM: ?utm_source=tiktok&utm_medium=video</li>
            </ul>
        </div>

        <div class="card">
            <h2>Video Upload Workflow</h2>
            <ol style="list-style: decimal; padding-left: 1.5rem;">
                <li>Film during actual diagnostics</li>
                <li>Edit on phone (CapCut app - free)</li>
                <li>Add captions (critical for sound-off viewing)</li>
                <li>Export in 9:16 format</li>
                <li>Upload to all platforms</li>
                <li>Track views/comments/leads in CRM</li>
            </ol>
        </div>

        <div class="card" style="grid-column: 1 / -1; background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);">
            <h2>Next Steps</h2>
            <p style="margin-bottom: 1rem;">Ready to start filming:</p>
            <ul>
                <li>✓ Phone camera ready</li>
                <li>✓ Business number for CTA: (904) 217-5152</li>
                <li>✓ Snap-on ZEUS for visual proof</li>
                <li>✓ Quote system ready to handle inbound calls</li>
            </ul>
            <p style="margin-top: 1.5rem; padding: 1rem; background: rgba(239, 68, 68, 0.1); border-left: 4px solid #ef4444;">
                <strong>Pro Tip:</strong> Film every interesting diagnostic. Even if only 1 in 10 goes viral, that's thousands of local views. One viral video = months of marketing budget.
            </p>
        </div>
    </div>
<!-- CRM Analytics -->
<script>(function(){navigator.sendBeacon("https://ezlead4u.com/crm/plugins/claude/track.php",JSON.stringify({url:location.href,ref:document.referrer}));})()</script>
</body>
</html>
