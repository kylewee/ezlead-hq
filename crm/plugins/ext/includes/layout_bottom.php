<?php
/**
 * Этот файл является частью программы "CRM Руководитель" - конструктор CRM систем для бизнеса
 * https://www.rukovoditel.net.ru/
 * 
 * CRM Руководитель - это свободное программное обеспечение, 
 * распространяемое на условиях GNU GPLv3 https://www.gnu.org/licenses/gpl-3.0.html
 * 
 * Автор и правообладатель программы: Харчишина Ольга Александровна (RU), Харчишин Сергей Васильевич (RU).
 * Государственная регистрация программы для ЭВМ: 2023664624
 * https://fips.ru/EGD/3b18c104-1db7-4f2d-83fb-2d38e1474ca3
 */

?>

<?php if(in_array($app_module_path,['ext/resource_timeline/view','ext/pivot_calendars/view','ext/calendar/personal','ext/calendar/public','ext/calendar/report','dashboard/dashboard','dashboard/reports','dashboard/reports_groups']) ): ?>
<script type="text/javascript" src="js/fullcalendar-scheduler/6.1.10/dist/index.global.min.js"></script>
<?php 
if(is_file($language_file_path = 'js/fullcalendar-scheduler/6.1.10/packages/core/locales/' . APP_LANGUAGE_SHORT_CODE . '.global.js')) 
  echo '<script type="text/javascript" src="' . $language_file_path . '"></script>';
?>
<?php endif ?>

<?php if(in_array($app_module_path,['ext/pivotreports/view','dashboard/dashboard','dashboard/reports','dashboard/reports_groups'])): ?>
<script type="text/javascript" src="js/PapaParse-master/papaparse.min.js"></script>
<script type="text/javascript" src="js/pivottable-master/dist/pivot.js"></script>
<script type="text/javascript" src="js/pivottable-master/dist/c3.min.js"></script>
<script type="text/javascript" src="js/pivottable-master/dist/d3.min.js"></script>
<script type="text/javascript" src="js/pivottable-master/dist/c3_renderers.js"></script>
<script type="text/javascript" src="js/pivottable-master/dist/export_renderers.js"></script>
<script type="text/javascript" src="<?php echo url_for('ext/pivotreports/view','id=' . (isset($_GET['id']) ? (int)$_GET['id']:0) . '&action=get_localization')?>"></script>
<?php endif ?>


<?php if($app_module=='timeline_reports' and $app_action=='view'): ?>
<script type="text/javascript" src="js/timeline-2.9.1/timeline.js"></script>
<?php endif ?>

<?php if(in_array($app_module_path,['report_page/view','ext/graphicreport/view','ext/funnelchart/view','dashboard/dashboard','dashboard/reports','dashboard/reports_groups','ext/pivot_tables/view']) ): ?>
<script src="js/highcharts/11.0.0/highcharts.js"></script>
<script src="js/highcharts/11.0.0/accessibility.js"></script>
<script type="text/javascript" src="js/highcharts/11.0.0/modules/funnel.js"></script>
<script type="text/javascript" src="js/highcharts/11.0.0/modules/exporting.js"></script>
<?php endif ?>

<script type="text/javascript" src="js/templates/templates.js"></script>
<script type="text/javascript" src="js/timer/timer.js?v=2"></script>

<!-- chat -->
<script type="text/javascript" src="js/ion.sound-master/js/ion.sound.js"></script>
<script type="text/javascript" src="js/ion.sound-master/js/init.js.php"></script>
<script type="text/javascript" src="js/app-chat/app-chat.js?v=1"></script>
<?php require(component_path('ext/app_chat/chat_button')) ?>


<!-- pivot table -->
<script src="js/webdatarocks/1.3.3/webdatarocks.toolbar.min.js"></script>
<script src="js/webdatarocks/1.3.3/webdatarocks.js"></script>
<script src="js/webdatarocks/1.3.3/webdatarocks.highcharts.js"></script>

<?php
//force print template
    echo export_templates::force_print_template();
?>




<?php if($app_module_path == 'dashboard/dashboard'): ?>
<!-- Analytics Widget -->
<script>
(function(){
    var widget = document.createElement('div');
    widget.id = 'analytics-widget';
    widget.innerHTML = '<style>.aw{background:#fff;border-radius:12px;padding:15px;margin-bottom:20px;box-shadow:0 2px 8px rgba(0,0,0,.08)}.aw h4{margin:0 0 12px;font-size:14px;color:#333}.aw-stats{display:flex;gap:10px}.aw-stat{flex:1;text-align:center;padding:12px;border-radius:8px;color:#fff;cursor:pointer;transition:transform .2s}.aw-stat:hover{transform:scale(1.02)}.aw-stat.purple{background:linear-gradient(135deg,#667eea,#764ba2)}.aw-stat.green{background:linear-gradient(135deg,#11998e,#38ef7d)}.aw-stat.blue{background:linear-gradient(135deg,#4facfe,#00f2fe)}.aw-stat.orange{background:linear-gradient(135deg,#f5576c,#f093fb)}.aw-stat h3{margin:0;font-size:22px}.aw-stat small{opacity:.85;font-size:10px;text-transform:uppercase}</style><div class="aw"><h4>📊 Analytics Overview <a href="index.php?module=reports/view&reports_id=108" style="float:right;font-size:12px">View Details →</a></h4><div class="aw-stats"><div class="aw-stat purple" onclick="location.href=\'index.php?module=items/items&path=37\'"><h3 id="aw-sites">-</h3><small>Websites</small></div><div class="aw-stat green" onclick="location.href=\'index.php?module=reports/view&reports_id=108\'"><h3 id="aw-today">-</h3><small>Today</small></div><div class="aw-stat blue" onclick="location.href=\'index.php?module=reports/view&reports_id=108\'"><h3 id="aw-total">-</h3><small>Total</small></div><div class="aw-stat orange" onclick="location.href=\'index.php?module=reports/view&reports_id=108\'"><h3 id="aw-visitors">-</h3><small>Visitors</small></div></div></div>';
    
    var container = document.querySelector('.page-content-inner') || document.querySelector('.page-content') || document.body;
    if(container.firstChild) container.insertBefore(widget, container.firstChild);
    
    fetch('plugins/claude/analytics_briefing.php?json=1').then(r=>r.json()).then(d=>{
        document.getElementById('aw-sites').textContent = d.websites || 0;
        document.getElementById('aw-today').textContent = d.today || 0;
        document.getElementById('aw-total').textContent = d.total || 0;
        document.getElementById('aw-visitors').textContent = d.visitors || 0;
    });
})();
</script>
<?php endif ?>
