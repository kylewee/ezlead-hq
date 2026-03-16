(function() {
    var CRM_BASE = window.location.pathname.replace(/\/index\.php.*/, "/index.php");
    var DATA_URL = "plugins/claude/mc_data.php";

    function priClass(pri) {
        if (pri === 'High') return 'mc-pri-high';
        if (pri === 'Medium') return 'mc-pri-med';
        return 'mc-pri-low';
    }

    function timeAgo(dateStr) {
        if (!dateStr) return '';
        var ts = parseInt(dateStr);
        if (isNaN(ts)) ts = new Date(dateStr).getTime() / 1000;
        var diff = Math.floor(Date.now() / 1000) - ts;
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
        return Math.floor(diff / 604800) + 'w ago';
    }

    function copyCmd(text, btn) {
        navigator.clipboard.writeText(text).then(function() {
            var orig = btn.textContent;
            btn.textContent = 'Copied!';
            setTimeout(function() { btn.textContent = orig; }, 1500);
        }).catch(function() {
            // Fallback: select the text
            var el = btn.parentElement.querySelector('.mc-cmd-text');
            if (el) {
                var range = document.createRange();
                range.selectNodeContents(el);
                window.getSelection().removeAllRanges();
                window.getSelection().addRange(range);
            }
        });
    }

    function markDone(taskId, btn) {
        btn.disabled = true;
        btn.textContent = '...';
        jQuery.post(DATA_URL, { mark_done: taskId }, function(resp) {
            if (resp.success) {
                // Strikethrough instead of hiding
                var row = btn.closest('.mc-task-row');
                if (row) {
                    row.classList.add('mc-task-done');
                    btn.textContent = 'Undo';
                    btn.disabled = false;
                    btn.classList.add('mc-undo-btn');
                    btn.classList.remove('mc-done-btn');
                }
                // Refresh counts in header
                loadTree();
            } else {
                btn.textContent = 'Error';
            }
        }, 'json').fail(function() {
            btn.textContent = 'Error';
        });
    }

    function archiveTask(taskId, btn) {
        btn.disabled = true;
        btn.textContent = '...';
        jQuery.post(DATA_URL, { archive: taskId }, function(resp) {
            if (resp.success) { loadTree(); }
            else { btn.textContent = 'Error'; }
        }, 'json').fail(function() { btn.textContent = 'Error'; });
    }

    function loadTree() {
        jQuery.getJSON(DATA_URL, function(data) {
            if (data.error) {
                jQuery("#mc-tree").html("<p style='color:red'>" + data.error + "</p>");
                return;
            }
            jQuery("#mc-dominoes").text(data.dominoes);
            var html = "";
            data.children.forEach(function(branch, i) {
                var blocker = branch.blocker ? "<span class='mc-blocker'>BLOCKER: " + branch.blocker + "</span>" : "";
                var openCount = branch.open_tasks ? branch.open_tasks.length : 0;
                var nextTask = branch.open_tasks && branch.open_tasks[0] ? branch.open_tasks[0] : null;

                html += "<div class='mc-branch'>";

                // Header
                html += "<div class='mc-branch-header' data-idx='" + i + "'>";
                html += "<span class='mc-dot " + branch.status + "'></span>";
                html += "<span class='mc-branch-name'>" + branch.name + blocker + "</span>";
                if (openCount > 0) {
                    html += "<span class='mc-branch-count'>" + openCount + " open</span>";
                }
                html += "<span class='mc-arrow' id='mc-arrow-" + i + "'>&#9654;</span>";
                html += "</div>";

                // Next task + last session preview (always visible, no expand needed)
                html += "<div class='mc-preview'>";

                if (nextTask) {
                    html += "<div class='mc-next-preview'>";
                    html += "<span class='mc-next-label'>NEXT</span> ";
                    html += "<span class='mc-next-task'>" + nextTask.task + "</span>";
                    html += "<button class='mc-done-btn mc-done-inline' data-task-id='" + nextTask.id + "'>Done</button>";
                    html += "</div>";
                } else {
                    html += "<div class='mc-next-preview mc-no-tasks'>";
                    html += "<span class='mc-next-label'>NEXT</span> ";
                    html += "<span class='mc-next-task'>No open tasks</span>";
                    html += "</div>";
                }

                if (branch.last_session) {
                    html += "<div class='mc-session-preview'>";
                    html += "<span class='mc-session-label'>LAST</span> ";
                    html += "<span class='mc-session-time'>" + timeAgo(branch.last_session.date) + "</span> ";
                    html += "<span class='mc-session-title'>" + (branch.last_session.summary || branch.last_session.title || '') + "</span>";
                    html += "</div>";
                }

                if (branch.choice_id) {
                    var tasksUrl = CRM_BASE + "?module=items/items&path=36&search_in_filters[field_502]=" + branch.choice_id;
                    html += "<div class='mc-cmd-preview'>";
                    html += "<a class='mc-tasks-btn' href='" + tasksUrl + "'>Open Tasks</a>";
                    if (branch.work_cmd) {
                        html += "<span class='mc-cmd-text'>" + branch.work_cmd + "</span>";
                        html += "<button class='mc-copy-btn' data-cmd='" + branch.work_cmd + "'>Copy</button>";
                    }
                    html += "</div>";
                }

                html += "</div>"; // end mc-preview

                // Expandable content (tasks, workflow, files)
                html += "<div class='mc-details' id='mc-pieces-" + i + "'>";

                // All open tasks with Done buttons
                if (openCount > 1) {
                    html += "<div class='mc-section-label'>All Tasks (" + openCount + ")</div>";
                    branch.open_tasks.forEach(function(t) {
                        html += "<div class='mc-task-row'>";
                        html += "<button class='mc-done-btn' data-task-id='" + t.id + "'>Done</button>";
                        html += "<span class='" + priClass(t.priority) + " mc-task-pri'>" + t.priority[0] + "</span>";
                        html += "<span class='mc-task-name'>" + t.task + "</span>";
                        html += "</div>";
                    });
                }

                // Files
                var files = branch.files || {};
                var fileKeys = Object.keys(files);
                if (fileKeys.length > 0) {
                    html += "<div class='mc-section-label'>Files</div>";
                    fileKeys.forEach(function(path) {
                        html += "<div class='mc-file-row'>";
                        html += "<span class='mc-file-path'>" + path + "</span>";
                        html += "<span class='mc-file-desc'>" + files[path] + "</span>";
                        html += "</div>";
                    });
                }

                // Workflow pieces
                html += "<div class='mc-section-label'>Workflow (" + branch.done + "/" + branch.total + " green)</div>";
                branch.children.forEach(function(piece) {
                    html += "<div class='mc-piece'>";
                    html += "<span class='mc-piece-dot " + piece.status + "'></span>";
                    html += "<span class='mc-piece-name'>" + piece.name + "</span>";
                    html += "<span class='mc-piece-note'>" + piece.note + "</span>";
                    html += "</div>";
                });

                // Done tasks (collapsible)
                var doneCount = branch.done_tasks ? branch.done_tasks.length : 0;
                if (doneCount > 0) {
                    html += "<div class='mc-section-label mc-done-toggle' data-didx='" + i + "'>";
                    html += "Done (" + doneCount + ") <span class='mc-done-arrow' id='mc-done-arrow-" + i + "'>&#9654;</span></div>";
                    html += "<div class='mc-done-list' id='mc-done-list-" + i + "'>";
                    branch.done_tasks.forEach(function(t) {
                        html += "<div class='mc-done-row'>";
                        html += "<button class='mc-archive-btn' data-task-id='" + t.id + "'>Archive</button>";
                        html += "<span class='mc-done-task-name'>#" + t.id + " " + t.task + "</span>";
                        html += "</div>";
                    });
                    html += "</div>";
                }

                // CRM link
                if (branch.choice_id) {
                    html += "<a class='mc-tasks-link' href='" + CRM_BASE + "?module=items/items&path=36&search_in_filters[field_502]=" + branch.choice_id + "'>View all in CRM &rarr;</a>";
                }

                html += "</div></div>"; // end mc-details, mc-branch
            });
            jQuery("#mc-tree").html(html);
            jQuery("#mc-refresh").text("Updated " + new Date().toLocaleTimeString());
        }).fail(function(xhr, status, err) {
            jQuery("#mc-tree").html("<p style='color:red'>Failed to load: " + err + "</p>");
        });
    }

    // Toggle branch expand/collapse
    jQuery(document).on("click", ".mc-branch-header", function() {
        var i = jQuery(this).data("idx");
        jQuery("#mc-pieces-" + i).toggleClass("open");
        jQuery("#mc-arrow-" + i).toggleClass("open");
    });

    // Mark done button (both inline and in task list)
    jQuery(document).on("click", ".mc-done-btn", function(e) {
        e.stopPropagation();
        var taskId = jQuery(this).data("task-id");
        markDone(taskId, this);
    });

    // Copy command
    jQuery(document).on("click", ".mc-copy-btn", function(e) {
        e.stopPropagation();
        var cmd = jQuery(this).data("cmd");
        copyCmd(cmd, this);
    });

    // Toggle done folder
    jQuery(document).on("click", ".mc-done-toggle", function(e) {
        e.stopPropagation();
        var i = jQuery(this).data("didx");
        jQuery("#mc-done-list-" + i).toggleClass("open");
        jQuery("#mc-done-arrow-" + i).toggleClass("open");
    });

    // Archive button
    jQuery(document).on("click", ".mc-archive-btn", function(e) {
        e.stopPropagation();
        var taskId = jQuery(this).data("task-id");
        archiveTask(taskId, this);
    });

    loadTree();
    setInterval(loadTree, 300000);
})();
