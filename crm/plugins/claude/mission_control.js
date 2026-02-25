(function() {
    var CRM_BASE = window.location.pathname.replace(/\/index\.php.*/, "/index.php");

    function loadTree() {
        jQuery.getJSON("plugins/claude/mission_control_data.php", function(data) {
            if (data.error) {
                jQuery("#mc-tree").html("<p style='color:red'>" + data.error + "</p>");
                return;
            }
            jQuery("#mc-dominoes").text(data.dominoes);
            var html = "";
            data.children.forEach(function(branch, i) {
                var blocker = branch.blocker ? "<span class='mc-blocker'>BLOCKER: " + branch.blocker + "</span>" : "";
                html += "<div class='mc-branch'>";
                html += "<div class='mc-branch-header' data-idx='" + i + "'>";
                html += "<span class='mc-dot " + branch.status + "'></span>";
                html += "<span class='mc-branch-name'>" + branch.name + blocker + "</span>";
                html += "<span class='mc-branch-count'>" + branch.done + "/" + branch.total + "</span>";
                html += "<span class='mc-arrow' id='mc-arrow-" + i + "'>&#9654;</span>";
                html += "</div>";
                html += "<div class='mc-pieces' id='mc-pieces-" + i + "'>";
                branch.children.forEach(function(piece) {
                    html += "<div class='mc-piece'>";
                    html += "<span class='mc-piece-dot " + piece.status + "'></span>";
                    html += "<span class='mc-piece-name'>" + piece.name + "</span>";
                    html += "<span class='mc-piece-note'>" + piece.note + "</span>";
                    html += "</div>";
                });
                if (branch.choice_id) {
                    html += "<a class='mc-tasks-link' href='" + CRM_BASE + "?module=items/items&path=36&search_in_filters[field_502]=" + branch.choice_id + "'>View " + branch.name + " tasks (" + branch.tasks_count + ")</a>";
                }
                html += "</div></div>";
            });
            jQuery("#mc-tree").html(html);
            jQuery("#mc-refresh").text("Last updated: " + new Date().toLocaleTimeString());
        }).fail(function(xhr, status, err) {
            jQuery("#mc-tree").html("<p style='color:red'>Failed to load: " + err + "</p>");
        });
    }

    jQuery(document).on("click", ".mc-branch-header", function() {
        var i = jQuery(this).data("idx");
        jQuery("#mc-pieces-" + i).toggleClass("open");
        jQuery("#mc-arrow-" + i).toggleClass("open");
    });

    loadTree();
    setInterval(loadTree, 300000);
})();
