/**
 * CRM Analytics Tracker
 * Add to websites: <script src="https://ezlead4u.com/crm/plugins/claude/tracker.js"></script>
 */
(function() {
    var endpoint = 'https://ezlead4u.com/crm/plugins/claude/track.php';
    
    // Get or create visitor ID
    var vid = localStorage.getItem('_crm_vid');
    if (!vid) {
        vid = 'v' + Math.random().toString(36).substr(2, 9) + Date.now().toString(36);
        localStorage.setItem('_crm_vid', vid);
    }
    
    // Track pageview
    function track() {
        var data = {
            url: window.location.href,
            ref: document.referrer,
            vid: vid
        };
        
        // Send as image (works even with ad blockers)
        var img = new Image();
        img.src = endpoint + '?img=1&' + new URLSearchParams(data).toString();
    }
    
    // Track on page load
    if (document.readyState === 'complete') {
        track();
    } else {
        window.addEventListener('load', track);
    }
})();
