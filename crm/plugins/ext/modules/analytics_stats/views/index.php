<style>
.analytics-stats { padding: 20px; }
.stats-row { display: flex; gap: 15px; margin-bottom: 25px; flex-wrap: wrap; }
.stat-card { 
    flex: 1; min-width: 150px; padding: 20px; border-radius: 12px; text-align: center; color: white;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}
.stat-card.green { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
.stat-card.blue { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
.stat-card.orange { background: linear-gradient(135deg, #f5576c 0%, #f093fb 100%); }
.stat-card h3 { margin: 0; font-size: 32px; font-weight: bold; }
.stat-card p { margin: 8px 0 0; opacity: 0.9; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; }
.panel-section { margin-bottom: 20px; }
.panel-section h4 { margin-bottom: 15px; font-size: 16px; color: #333; }
</style>

<div class="analytics-stats">
    <div class="stats-row">
        <div class="stat-card">
            <h3><?php echo $stats['websites']; ?></h3>
            <p>Websites Tracked</p>
        </div>
        <div class="stat-card green">
            <h3><?php echo $stats['today_views']; ?></h3>
            <p>Today's Pageviews</p>
        </div>
        <div class="stat-card blue">
            <h3><?php echo $stats['total_views']; ?></h3>
            <p>Total Pageviews</p>
        </div>
        <div class="stat-card orange">
            <h3><?php echo $stats['unique_visitors']; ?></h3>
            <p>Unique Visitors</p>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6">
            <div class="panel panel-default panel-section">
                <div class="panel-heading"><h4>🌐 Website Performance</h4></div>
                <div class="panel-body">
                    <table class="table table-striped">
                        <thead><tr><th>Website</th><th>Views</th><th>Visitors</th></tr></thead>
                        <tbody>
                        <?php while($row = db_fetch_array($stats['site_stats'])): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['domain']); ?></td>
                                <td><?php echo $row['views']; ?></td>
                                <td><?php echo $row['visitors']; ?></td>
                            </tr>
                        <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="panel panel-default panel-section">
                <div class="panel-heading"><h4>🕐 Recent Activity</h4></div>
                <div class="panel-body">
                    <table class="table table-striped">
                        <thead><tr><th>Page</th><th>Browser</th><th>Device</th></tr></thead>
                        <tbody>
                        <?php while($row = db_fetch_array($stats['recent'])): ?>
                            <tr>
                                <td title="<?php echo htmlspecialchars($row['url']); ?>">
                                    <?php echo htmlspecialchars(strlen($row['url']) > 35 ? '...' . substr($row['url'], -32) : $row['url']); ?>
                                </td>
                                <td><span class="label label-info"><?php echo $row['browser']; ?></span></td>
                                <td><span class="label label-success"><?php echo $row['device']; ?></span></td>
                            </tr>
                        <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <div style="text-align: center; margin-top: 20px;">
        <a href="<?php echo url_for('items/items', 'path=44'); ?>" class="btn btn-primary btn-lg">
            <i class="fa fa-table"></i> View Full Analytics Data
        </a>
    </div>
</div>
