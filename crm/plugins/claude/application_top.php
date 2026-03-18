<?php
/**
 * Claude Plugin - Application Top
 * Loaded on every CRM page request via plugins system.
 */

// Load centralized credentials (SignalWire, OpenAI, etc.)
if (!defined('SIGNALWIRE_PROJECT_ID') && is_file(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

// Business field mapping: entity_id => field_id that links to entity 50 (Businesses)
define('CLAUDE_BIZ_FIELDS', serialize([
    21 => 480,  // Projects
    25 => 474,  // Leads
    26 => 477,  // Buyers
    29 => 479,  // Appointments
    30 => 501,  // Sessions
    36 => 496,  // Actions
    37 => 476,  // Websites
    42 => 475,  // Jobs
    47 => 478,  // Customers
    48 => 549,  // Vehicles
    53 => 521,  // Estimates
    54 => 537,  // Credit Accounts
]));

/**
 * Returns a SQL WHERE fragment to filter by the selected business (crm_biz cookie).
 * Called from items::add_access_query() after records_visibility rules.
 */
function claude_business_filter($entity_id)
{
    $biz_fields = unserialize(CLAUDE_BIZ_FIELDS);

    // No filter if entity doesn't have a business field
    if (!isset($biz_fields[$entity_id])) {
        return '';
    }

    // Read selected business from cookie
    $selected_biz = isset($_COOKIE['crm_biz']) ? (int)$_COOKIE['crm_biz'] : 0;
    if ($selected_biz <= 0) {
        return '';
    }

    $field_id = (int)$biz_fields[$entity_id];
    return " and e.field_{$field_id} = {$selected_biz} ";
}
