(function() {
    var DATA_URL = 'plugins/claude/pipeline_data.php';
    var ACTION_URL = 'plugins/claude/pipeline_action.php';
    var CRM_BASE = window.location.pathname.replace(/\/index\.php.*/, '/index.php');

    // Section definitions: key, label, icon, color, actions
    var SECTIONS = [
        { key: 'new_leads',     label: 'New Leads',        icon: 'fa-user-plus',    color: '#1abc9c', entity: 'lead' },
        { key: 'triage',         label: 'Triage',           icon: 'fa-inbox',        color: '#e74c3c', entity: 'job' },
        { key: 'awaiting_reply', label: 'Awaiting Reply',   icon: 'fa-clock-o',      color: '#f39c12', entity: 'estimate' },
        { key: 'needs_schedule', label: 'Needs Scheduling', icon: 'fa-calendar-plus-o', color: '#3498db', entity: 'job' },
        { key: 'upcoming',       label: 'Upcoming',         icon: 'fa-calendar',     color: '#2ecc71', entity: 'job' },
        { key: 'in_progress',    label: 'In Progress',      icon: 'fa-wrench',       color: '#9b59b6', entity: 'job' },
        { key: 'needs_invoice',  label: 'Needs Invoice',    icon: 'fa-file-text-o',  color: '#e67e22', entity: 'job' },
        { key: 'awaiting_pay',   label: 'Awaiting Payment', icon: 'fa-usd',          color: '#27ae60', entity: 'job' },
    ];

    function timeAgo(dateStr) {
        if (!dateStr) return '';
        var ts = parseInt(dateStr);
        if (isNaN(ts)) ts = new Date(dateStr).getTime() / 1000;
        var diff = Math.floor(Date.now() / 1000) - ts;
        if (diff < 60) return 'just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
        return Math.floor(diff / 604800) + 'w ago';
    }

    function fmt$(n) {
        if (!n) return '';
        return '$' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }

    function apptStr(ts) {
        if (!ts) return '';
        var d = new Date(ts * 1000);
        var days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
        var h = d.getHours();
        var ampm = h >= 12 ? 'pm' : 'am';
        h = h % 12 || 12;
        return days[d.getDay()] + ' ' + (d.getMonth()+1) + '/' + d.getDate() + ' ' + h + ampm;
    }

    function postAction(action, id, extra, callback) {
        var data = jQuery.extend({ action: action, id: id }, extra || {});
        jQuery.post(ACTION_URL, data, function(resp) {
            if (resp.ok) {
                if (callback) callback(resp);
                else loadData();
            } else {
                alert('Error: ' + (resp.error || 'Unknown'));
            }
        }, 'json').fail(function() { alert('Request failed'); });
    }

    function renderCard(section, item) {
        var card = document.createElement('div');
        card.className = 'pl-card';
        card.dataset.id = item.id;

        // Header line: name + phone
        var phone = item.phone ? '<a href="tel:' + item.phone + '" class="pl-phone">' + item.phone + '</a>' : '';
        var header = '<div class="pl-card-header">'
            + '<span class="pl-name">' + esc(item.name) + '</span>'
            + phone
            + '</div>';

        // Detail line: vehicle + problem (or source for leads)
        var detail;
        if (section.key === 'new_leads') {
            detail = '<div class="pl-card-detail">'
                + (item.source ? 'Source: ' + esc(item.source) : '')
                + (item.notes ? ' &bull; ' + esc(item.notes.substring(0, 60)) : '')
                + '</div>';
        } else {
            var problem = item.problem ? ' &bull; ' + esc(item.problem.substring(0, 60)) : '';
            detail = '<div class="pl-card-detail">'
                + esc(item.vehicle) + problem
                + '</div>';
        }

        // Meta line: price + time
        var price = '';
        if (section.key === 'awaiting_reply') {
            if (item.total_low || item.total_high) price = fmt$(item.total_low) + '–' + fmt$(item.total_high);
        } else if (item.total) {
            price = fmt$(item.total);
        }
        var appt = item.appt ? apptStr(item.appt) : '';
        var age = timeAgo(item.updated || item.created);
        var metaParts = [price, appt, age].filter(Boolean);
        var meta = metaParts.length ? '<div class="pl-card-meta">' + metaParts.join(' &bull; ') + '</div>' : '';

        // Actions
        var actions = '<div class="pl-card-actions">' + getActions(section, item) + '</div>';

        card.innerHTML = header + detail + meta + actions;

        // Wire up action buttons
        card.querySelectorAll('[data-action]').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                handleAction(btn, section, item);
            });
        });

        return card;
    }

    function esc(s) {
        if (!s) return '';
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function getActions(section, item) {
        switch (section.key) {
            case 'new_leads':
                return '<button data-action="convert_lead" class="pl-btn pl-btn-primary">Convert to Job</button>'
                    + '<button data-action="dismiss_lead" class="pl-btn pl-btn-danger">Dismiss</button>'
                    + '<a href="' + CRM_BASE + '?module=items/info&path=25-' + item.id + '" class="pl-btn pl-btn-link">View</a>';
            case 'triage':
                return '<button data-action="accept_and_send" class="pl-btn pl-btn-primary">Accept & Send</button>'
                    + '<button data-action="mark_junk" class="pl-btn pl-btn-danger">Junk</button>'
                    + '<a href="' + CRM_BASE + '?module=items/info&path=42-' + item.id + '" class="pl-btn pl-btn-link">View</a>';
            case 'awaiting_reply':
                return '<button data-action="mark_accepted" class="pl-btn pl-btn-primary">Accepted</button>'
                    + '<button data-action="mark_dead" class="pl-btn pl-btn-muted">Dead</button>'
                    + '<a href="' + CRM_BASE + '?module=items/info&path=53-' + item.id + '" class="pl-btn pl-btn-link">View</a>';
            case 'needs_schedule':
                return '<button data-action="schedule_tomorrow" class="pl-btn pl-btn-primary">Tomorrow 9am</button>'
                    + '<button data-action="schedule_pick" class="pl-btn pl-btn-secondary">Pick Date</button>'
                    + '<a href="' + CRM_BASE + '?module=items/info&path=42-' + item.id + '" class="pl-btn pl-btn-link">View</a>';
            case 'upcoming':
                return '<button data-action="start_job" class="pl-btn pl-btn-primary">Start Job</button>'
                    + '<a href="' + CRM_BASE + '?module=items/info&path=42-' + item.id + '" class="pl-btn pl-btn-link">View</a>';
            case 'in_progress':
                return '<button data-action="complete_job" class="pl-btn pl-btn-primary">Complete</button>'
                    + '<a href="' + CRM_BASE + '?module=items/info&path=42-' + item.id + '" class="pl-btn pl-btn-link">View</a>';
            case 'needs_invoice':
                return '<button data-action="send_invoice" class="pl-btn pl-btn-primary">Send Invoice ' + fmt$(item.total) + '</button>'
                    + '<a href="' + CRM_BASE + '?module=items/info&path=42-' + item.id + '" class="pl-btn pl-btn-link">View</a>';
            case 'awaiting_pay':
                return '<button data-action="mark_paid" class="pl-btn pl-btn-primary">Mark Paid ' + fmt$(item.total) + '</button>'
                    + '<a href="' + CRM_BASE + '?module=items/info&path=42-' + item.id + '" class="pl-btn pl-btn-link">View</a>';
            default:
                return '';
        }
    }

    function handleAction(btn, section, item) {
        var action = btn.dataset.action;
        btn.disabled = true;
        btn.textContent = '...';

        switch (action) {
            case 'dismiss_lead':
            case 'accept_and_send':
            case 'mark_junk':
            case 'mark_accepted':
            case 'mark_dead':
            case 'start_job':
            case 'send_invoice':
            case 'mark_paid':
                postAction(action, item.id, {}, function() { loadData(); });
                break;

            case 'convert_lead':
                var year = prompt('Vehicle Year:');
                if (year === null) { btn.disabled = false; btn.textContent = 'Convert to Job'; break; }
                var make = prompt('Vehicle Make:');
                var model = prompt('Vehicle Model:');
                var prob = prompt('Problem/Issue:');
                postAction('convert_lead', item.id, { year: year, make: make || '', model: model || '', problem: prob || '' }, function() { loadData(); });
                break;

            case 'schedule_tomorrow':
                postAction('schedule_job', item.id, {}, function() { loadData(); });
                break;

            case 'schedule_pick':
                var dt = prompt('Enter date/time (YYYY-MM-DD HH:MM):', '');
                if (dt) {
                    postAction('schedule_job', item.id, { datetime: dt }, function() { loadData(); });
                } else {
                    btn.disabled = false;
                    btn.textContent = 'Pick Date';
                }
                break;

            case 'complete_job':
                var total = prompt('Final total ($):', item.total || '');
                if (total !== null) {
                    postAction('complete_job', item.id, { total: total }, function() { loadData(); });
                } else {
                    btn.disabled = false;
                    btn.textContent = 'Complete';
                }
                break;

            default:
                btn.disabled = false;
        }
    }

    function renderSection(section, items) {
        if (!items || items.length === 0) return '';

        var el = document.createElement('div');
        el.className = 'pl-section';
        el.innerHTML = '<div class="pl-section-header" style="border-left: 4px solid ' + section.color + '">'
            + '<i class="fa ' + section.icon + '" style="color:' + section.color + '"></i> '
            + '<span class="pl-section-label">' + section.label + '</span>'
            + '<span class="pl-section-count">' + items.length + '</span>'
            + '</div>';

        var body = document.createElement('div');
        body.className = 'pl-section-body';
        items.forEach(function(item) {
            body.appendChild(renderCard(section, item));
        });
        el.appendChild(body);

        return el;
    }

    function render(data) {
        var container = document.getElementById('pl-content');
        container.innerHTML = '';

        // Stats bar
        var stats = data.stats || {};
        var statsEl = document.createElement('div');
        statsEl.className = 'pl-stats';
        statsEl.innerHTML = '<div class="pl-stat"><span class="pl-stat-num">' + (stats.action_count || 0) + '</span><span class="pl-stat-label">Need Action</span></div>'
            + '<div class="pl-stat"><span class="pl-stat-num">' + (stats.triage_count || 0) + '</span><span class="pl-stat-label">To Triage</span></div>'
            + '<div class="pl-stat"><span class="pl-stat-num">' + (stats.total_unpaid || 0) + '</span><span class="pl-stat-label">Unpaid</span></div>'
            + '<div class="pl-stat"><span class="pl-stat-num">' + fmt$(stats.unpaid_total) + '</span><span class="pl-stat-label">Owed</span></div>';
        container.appendChild(statsEl);

        // Sections
        var hasContent = false;
        SECTIONS.forEach(function(section) {
            var items = data[section.key] || [];
            if (items.length > 0) {
                container.appendChild(renderSection(section, items));
                hasContent = true;
            }
        });

        if (!hasContent) {
            container.innerHTML += '<div class="pl-empty">Pipeline clear. No jobs need attention right now.</div>';
        }

        // Refresh timestamp
        document.getElementById('pl-refresh').textContent = 'Updated ' + new Date().toLocaleTimeString();
    }

    function loadData() {
        jQuery.getJSON(DATA_URL, function(data) {
            if (data.error) {
                document.getElementById('pl-content').innerHTML = '<div class="pl-error">' + data.error + '</div>';
                return;
            }
            render(data);
        }).fail(function() {
            document.getElementById('pl-content').innerHTML = '<div class="pl-error">Failed to load pipeline data</div>';
        });
    }

    // Initial load
    loadData();

    // Auto-refresh every 60 seconds
    setInterval(loadData, 60000);

    // Manual refresh button
    var refreshBtn = document.getElementById('pl-refresh-btn');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function() { loadData(); });
    }
})();
