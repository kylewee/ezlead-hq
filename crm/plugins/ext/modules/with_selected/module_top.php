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



$current_path = $app_path;

$current_path_array = explode('/', $current_path);
$current_item_array = explode('-', $current_path_array[count($current_path_array) - 1]);

$current_entity_id = (int) $current_item_array[0];
$current_item_id = (isset($current_item_array[1]) ? (int) $current_item_array[1] : 0);


if(count($current_path_array) > 1)
{
    $v = explode('-', $current_path_array[count($current_path_array) - 2]);
    $parent_entity_id = (int) $v[0];
    $parent_entity_item_id = (int) ($v[1]??0);
}
else
{
    $parent_entity_id = 0;
    $parent_entity_item_id = 0;
}
