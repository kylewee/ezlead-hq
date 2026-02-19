<?php
/**
 * EzLead Distribution Engine Config
 */

// Database
define('DB_HOST', 'localhost');
define('DB_NAME', 'rukovoditel');
define('DB_USER', 'kylewee');
define('DB_PASS', 'rainonin');

// CRM API
define('CRM_API_URL', 'https://ezlead4u.com/crm/api/rest.php');
define('CRM_API_KEY', 'dZrvuC3Q1A8Cv82X1dQsugRiCpmU3FSMHn9BFWlY');

// Entity IDs
define('ENTITY_LEADS', 25);
define('ENTITY_BUYERS', 26);
define('ENTITY_TRANSACTIONS', 27);
define('ENTITY_SOURCES', 28);

// Field IDs - Leads
define('FIELD_LEAD_NAME', 210);
define('FIELD_LEAD_PHONE', 211);
define('FIELD_LEAD_EMAIL', 212);
define('FIELD_LEAD_ADDRESS', 213);
define('FIELD_LEAD_ZIP', 214);
define('FIELD_LEAD_SOURCE', 215);
define('FIELD_LEAD_VERTICAL', 216);
define('FIELD_LEAD_NOTES', 217);
define('FIELD_LEAD_STAGE', 218);
define('FIELD_LEAD_ASSIGNED_BUYER', 219);
define('FIELD_LEAD_BUSINESS', 443);

// Field IDs - Buyers
define('FIELD_BUYER_COMPANY', 223);
define('FIELD_BUYER_CONTACT', 224);
define('FIELD_BUYER_PHONE', 225);
define('FIELD_BUYER_EMAIL', 226);
define('FIELD_BUYER_BALANCE', 227);
define('FIELD_BUYER_PRICE', 228);
define('FIELD_BUYER_ZIPS', 229);
define('FIELD_BUYER_VERTICALS', 230);
define('FIELD_BUYER_NOTIFY_PREF', 231);
define('FIELD_BUYER_STATUS', 232);

// Field IDs - Transactions
define('FIELD_TXN_TYPE', 237);
define('FIELD_TXN_AMOUNT', 238);
define('FIELD_TXN_LEAD_ID', 239);
define('FIELD_TXN_NOTES', 240);

// Field IDs - Sources
define('FIELD_SOURCE_DOMAIN', 244);
define('FIELD_SOURCE_VERTICAL', 245);
define('FIELD_SOURCE_PHONE', 246);
define('FIELD_SOURCE_DIST_METHOD', 247);
define('FIELD_SOURCE_STATUS', 248);

// Business Rules
define('MIN_BALANCE', 5.00);  // Minimum balance to receive leads
define('DEFAULT_LEAD_PRICE', 35.00);
define('FREE_LEADS_COUNT', 3);  // Number of free leads for new buyers

// Email settings
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'sodjacksonville@gmail.com');
define('SMTP_PASS', 'senrmlvgzmipqgrm');
define('FROM_EMAIL', 'sodjacksonville@gmail.com');
define('FROM_NAME', 'EzLead');
