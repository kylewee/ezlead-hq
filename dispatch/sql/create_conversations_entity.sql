-- ============================================================
-- EZ Dispatch: Create Conversations Entity for Rukovoditel CRM
-- Entity ID: 51 (auto-assigned, next after 50)
-- Created: 2026-02-22
-- ============================================================

-- -----------------------------------------------
-- 1. Create the entity
-- -----------------------------------------------
INSERT INTO app_entities (parent_id, group_id, name, notes, display_in_menu, sort_order)
VALUES (0, 1, 'Conversations', 'Communication log - calls, video, SMS, WebRTC', 1, 3);

SET @entity_id = LAST_INSERT_ID();

-- -----------------------------------------------
-- 2. Create form tab (required for fields to appear in API/UI)
-- -----------------------------------------------
INSERT INTO app_forms_tabs (entities_id, parent_id, is_folder, name, icon, icon_color, description, sort_order)
VALUES (@entity_id, 0, 0, 'Conversation Details', '', '', '', 0);

SET @tab_id = LAST_INSERT_ID();

-- -----------------------------------------------
-- 3. Create fields
-- -----------------------------------------------

-- Field 1: Customer (entity link to 47)
INSERT INTO app_fields (entities_id, forms_tabs_id, comments_forms_tabs_id, forms_rows_position, type, name, short_name, is_heading, tooltip, tooltip_display_as, tooltip_in_item_page, tooltip_item_page, notes, is_required, required_message, configuration, sort_order, listing_status, listing_sort_order, comments_status, comments_sort_order)
VALUES (@entity_id, @tab_id, 0, '', 'fieldtype_entity_ajax', 'Customer', 'customer', 0, '', '', 0, '', '', 0, '', '{"entity_id":"47"}', 0, 1, 0, 0, 0);
SET @fid_customer = LAST_INSERT_ID();

-- Field 2: Channel (dropdown: Call/Video/SMS/WebRTC)
INSERT INTO app_fields (entities_id, forms_tabs_id, comments_forms_tabs_id, forms_rows_position, type, name, short_name, is_heading, tooltip, tooltip_display_as, tooltip_in_item_page, tooltip_item_page, notes, is_required, required_message, configuration, sort_order, listing_status, listing_sort_order, comments_status, comments_sort_order)
VALUES (@entity_id, @tab_id, 0, '', 'fieldtype_dropdown', 'Channel', 'channel', 1, '', '', 0, '', '', 1, '', '{"use_global_list":"0"}', 1, 1, 0, 0, 0);
SET @fid_channel = LAST_INSERT_ID();

-- Field 3: Direction (dropdown: Inbound/Outbound)
INSERT INTO app_fields (entities_id, forms_tabs_id, comments_forms_tabs_id, forms_rows_position, type, name, short_name, is_heading, tooltip, tooltip_display_as, tooltip_in_item_page, tooltip_item_page, notes, is_required, required_message, configuration, sort_order, listing_status, listing_sort_order, comments_status, comments_sort_order)
VALUES (@entity_id, @tab_id, 0, '', 'fieldtype_dropdown', 'Direction', 'direction', 0, '', '', 0, '', '', 0, '', '{"use_global_list":"0"}', 2, 1, 0, 0, 0);
SET @fid_direction = LAST_INSERT_ID();

-- Field 4: Status (dropdown: Ringing/Answered/In Call/Ended/Declined/Missed/Timed Out/Recording Ready/Transcribed)
INSERT INTO app_fields (entities_id, forms_tabs_id, comments_forms_tabs_id, forms_rows_position, type, name, short_name, is_heading, tooltip, tooltip_display_as, tooltip_in_item_page, tooltip_item_page, notes, is_required, required_message, configuration, sort_order, listing_status, listing_sort_order, comments_status, comments_sort_order)
VALUES (@entity_id, @tab_id, 0, '', 'fieldtype_dropdown', 'Status', 'status', 0, '', '', 0, '', '', 0, '', '{"use_global_list":"0"}', 3, 1, 0, 0, 0);
SET @fid_status = LAST_INSERT_ID();

-- Field 5: Started (datetime/timestamp)
INSERT INTO app_fields (entities_id, forms_tabs_id, comments_forms_tabs_id, forms_rows_position, type, name, short_name, is_heading, tooltip, tooltip_display_as, tooltip_in_item_page, tooltip_item_page, notes, is_required, required_message, configuration, sort_order, listing_status, listing_sort_order, comments_status, comments_sort_order)
VALUES (@entity_id, @tab_id, 0, '', 'fieldtype_input_datetime', 'Started', 'started', 0, '', '', 0, '', '', 0, '', '{"date_format":"","date_format_in_calendar":"yyyy-mm-dd hh:ii","min_date":"","max_date":"","default_value":"","is_unique":"0","unique_error_msg":"","background":"","day_before_date":"","day_before_date_color":"","day_before_date2":"","day_before_date2_color":"","disable_color_by_field":""}', 4, 1, 0, 0, 0);
SET @fid_started = LAST_INSERT_ID();

-- Field 6: Duration (numeric seconds)
INSERT INTO app_fields (entities_id, forms_tabs_id, comments_forms_tabs_id, forms_rows_position, type, name, short_name, is_heading, tooltip, tooltip_display_as, tooltip_in_item_page, tooltip_item_page, notes, is_required, required_message, configuration, sort_order, listing_status, listing_sort_order, comments_status, comments_sort_order)
VALUES (@entity_id, @tab_id, 0, '', 'fieldtype_input_numeric', 'Duration', 'duration', 0, '', '', 0, '', '', 0, '', '{"width":"input-small","number_format":"0"}', 5, 1, 0, 0, 0);
SET @fid_duration = LAST_INSERT_ID();

-- Field 7: Recording (file attachment)
INSERT INTO app_fields (entities_id, forms_tabs_id, comments_forms_tabs_id, forms_rows_position, type, name, short_name, is_heading, tooltip, tooltip_display_as, tooltip_in_item_page, tooltip_item_page, notes, is_required, required_message, configuration, sort_order, listing_status, listing_sort_order, comments_status, comments_sort_order)
VALUES (@entity_id, @tab_id, 0, '', 'fieldtype_attachments', 'Recording', 'recording', 0, '', '', 0, '', '', 0, '', '', 6, 0, 0, 0, 0);
SET @fid_recording = LAST_INSERT_ID();

-- Field 8: Transcript (textarea)
INSERT INTO app_fields (entities_id, forms_tabs_id, comments_forms_tabs_id, forms_rows_position, type, name, short_name, is_heading, tooltip, tooltip_display_as, tooltip_in_item_page, tooltip_item_page, notes, is_required, required_message, configuration, sort_order, listing_status, listing_sort_order, comments_status, comments_sort_order)
VALUES (@entity_id, @tab_id, 0, '', 'fieldtype_textarea', 'Transcript', 'transcript', 0, '', '', 0, '', '', 0, '', '{}', 7, 0, 0, 0, 0);
SET @fid_transcript = LAST_INSERT_ID();

-- Field 9: Screenshots (file attachment)
INSERT INTO app_fields (entities_id, forms_tabs_id, comments_forms_tabs_id, forms_rows_position, type, name, short_name, is_heading, tooltip, tooltip_display_as, tooltip_in_item_page, tooltip_item_page, notes, is_required, required_message, configuration, sort_order, listing_status, listing_sort_order, comments_status, comments_sort_order)
VALUES (@entity_id, @tab_id, 0, '', 'fieldtype_attachments', 'Screenshots', 'screenshots', 0, '', '', 0, '', '', 0, '', '', 8, 0, 0, 0, 0);
SET @fid_screenshots = LAST_INSERT_ID();

-- Field 10: Job (entity link to 42)
INSERT INTO app_fields (entities_id, forms_tabs_id, comments_forms_tabs_id, forms_rows_position, type, name, short_name, is_heading, tooltip, tooltip_display_as, tooltip_in_item_page, tooltip_item_page, notes, is_required, required_message, configuration, sort_order, listing_status, listing_sort_order, comments_status, comments_sort_order)
VALUES (@entity_id, @tab_id, 0, '', 'fieldtype_entity_ajax', 'Job', 'job', 0, '', '', 0, '', '', 0, '', '{"entity_id":"42"}', 9, 1, 0, 0, 0);
SET @fid_job = LAST_INSERT_ID();

-- Field 11: Site (dropdown)
INSERT INTO app_fields (entities_id, forms_tabs_id, comments_forms_tabs_id, forms_rows_position, type, name, short_name, is_heading, tooltip, tooltip_display_as, tooltip_in_item_page, tooltip_item_page, notes, is_required, required_message, configuration, sort_order, listing_status, listing_sort_order, comments_status, comments_sort_order)
VALUES (@entity_id, @tab_id, 0, '', 'fieldtype_dropdown', 'Site', 'site', 0, '', '', 0, '', '', 0, '', '{"use_global_list":"0"}', 10, 1, 0, 0, 0);
SET @fid_site = LAST_INSERT_ID();

-- Field 12: Notes (textarea)
INSERT INTO app_fields (entities_id, forms_tabs_id, comments_forms_tabs_id, forms_rows_position, type, name, short_name, is_heading, tooltip, tooltip_display_as, tooltip_in_item_page, tooltip_item_page, notes, is_required, required_message, configuration, sort_order, listing_status, listing_sort_order, comments_status, comments_sort_order)
VALUES (@entity_id, @tab_id, 0, '', 'fieldtype_textarea', 'Notes', 'notes', 0, '', '', 0, '', '', 0, '', '{}', 11, 0, 0, 0, 0);
SET @fid_notes = LAST_INSERT_ID();

-- Field 13: Phone (text input)
INSERT INTO app_fields (entities_id, forms_tabs_id, comments_forms_tabs_id, forms_rows_position, type, name, short_name, is_heading, tooltip, tooltip_display_as, tooltip_in_item_page, tooltip_item_page, notes, is_required, required_message, configuration, sort_order, listing_status, listing_sort_order, comments_status, comments_sort_order)
VALUES (@entity_id, @tab_id, 0, '', 'fieldtype_input', 'Phone', 'phone', 0, '', '', 0, '', '', 0, '', '{}', 12, 1, 0, 0, 0);
SET @fid_phone = LAST_INSERT_ID();

-- Field 14: CF Session (text input for Cloudflare session ID)
INSERT INTO app_fields (entities_id, forms_tabs_id, comments_forms_tabs_id, forms_rows_position, type, name, short_name, is_heading, tooltip, tooltip_display_as, tooltip_in_item_page, tooltip_item_page, notes, is_required, required_message, configuration, sort_order, listing_status, listing_sort_order, comments_status, comments_sort_order)
VALUES (@entity_id, @tab_id, 0, '', 'fieldtype_input', 'CF Session', 'cf_session', 0, '', '', 0, '', '', 0, '', '{}', 13, 0, 0, 0, 0);
SET @fid_cf_session = LAST_INSERT_ID();

-- -----------------------------------------------
-- 4. Create dropdown choices
-- -----------------------------------------------

-- Channel choices
INSERT INTO app_fields_choices (parent_id, fields_id, is_active, name, icon, is_default, bg_color, sort_order, users, value, filename)
VALUES
(0, @fid_channel, 1, 'Call', '', 1, '#3498db', 1, '', '', ''),
(0, @fid_channel, 1, 'Video', '', 0, '#9b59b6', 2, '', '', ''),
(0, @fid_channel, 1, 'SMS', '', 0, '#2ecc71', 3, '', '', ''),
(0, @fid_channel, 1, 'WebRTC', '', 0, '#e67e22', 4, '', '', '');

-- Direction choices
INSERT INTO app_fields_choices (parent_id, fields_id, is_active, name, icon, is_default, bg_color, sort_order, users, value, filename)
VALUES
(0, @fid_direction, 1, 'Inbound', '', 1, '#27ae60', 1, '', '', ''),
(0, @fid_direction, 1, 'Outbound', '', 0, '#e74c3c', 2, '', '', '');

-- Status choices
INSERT INTO app_fields_choices (parent_id, fields_id, is_active, name, icon, is_default, bg_color, sort_order, users, value, filename)
VALUES
(0, @fid_status, 1, 'Ringing', '', 1, '#f39c12', 1, '', '', ''),
(0, @fid_status, 1, 'Answered', '', 0, '#27ae60', 2, '', '', ''),
(0, @fid_status, 1, 'In Call', '', 0, '#3498db', 3, '', '', ''),
(0, @fid_status, 1, 'Ended', '', 0, '#95a5a6', 4, '', '', ''),
(0, @fid_status, 1, 'Declined', '', 0, '#e74c3c', 5, '', '', ''),
(0, @fid_status, 1, 'Missed', '', 0, '#c0392b', 6, '', '', ''),
(0, @fid_status, 1, 'Timed Out', '', 0, '#7f8c8d', 7, '', '', ''),
(0, @fid_status, 1, 'Recording Ready', '', 0, '#8e44ad', 8, '', '', ''),
(0, @fid_status, 1, 'Transcribed', '', 0, '#16a085', 9, '', '', '');

-- Site choices
INSERT INTO app_fields_choices (parent_id, fields_id, is_active, name, icon, is_default, bg_color, sort_order, users, value, filename)
VALUES
(0, @fid_site, 1, 'mechanicstaugustine.com', '', 1, '#e74c3c', 1, '', '', ''),
(0, @fid_site, 1, 'sodjax.com', '', 0, '#27ae60', 2, '', '', ''),
(0, @fid_site, 1, 'jacksonvillesod.com', '', 0, '#2ecc71', 3, '', '', ''),
(0, @fid_site, 1, 'sodjacksonvillefl.com', '', 0, '#1abc9c', 4, '', '', ''),
(0, @fid_site, 1, 'drainagejax.com', '', 0, '#3498db', 5, '', '', ''),
(0, @fid_site, 1, 'sod.company', '', 0, '#f39c12', 6, '', '', ''),
(0, @fid_site, 1, 'nearby.contractors', '', 0, '#e67e22', 7, '', '', ''),
(0, @fid_site, 1, 'mobilemechanic.best', '', 0, '#9b59b6', 8, '', '', '');

-- -----------------------------------------------
-- 5. Create the data table
-- -----------------------------------------------
SET @create_table_sql = CONCAT(
  'CREATE TABLE IF NOT EXISTS app_entity_', @entity_id, ' (',
  'id int(11) unsigned NOT NULL AUTO_INCREMENT, ',
  'parent_id int(11) NOT NULL DEFAULT 0, ',
  'parent_item_id int(11) NOT NULL DEFAULT 0, ',
  'field_', @fid_customer, ' varchar(255) NOT NULL DEFAULT \'\', ',
  'field_', @fid_channel, ' int(11) NOT NULL DEFAULT 1, ',
  'field_', @fid_direction, ' int(11) NOT NULL DEFAULT 1, ',
  'field_', @fid_status, ' int(11) NOT NULL DEFAULT 1, ',
  'field_', @fid_started, ' bigint(11) DEFAULT 0, ',
  'field_', @fid_duration, ' decimal(10,2) DEFAULT 0.00, ',
  'field_', @fid_recording, ' varchar(255) NOT NULL DEFAULT \'\', ',
  'field_', @fid_transcript, ' text NOT NULL, ',
  'field_', @fid_screenshots, ' varchar(255) NOT NULL DEFAULT \'\', ',
  'field_', @fid_job, ' varchar(255) NOT NULL DEFAULT \'\', ',
  'field_', @fid_site, ' int(11) NOT NULL DEFAULT 1, ',
  'field_', @fid_notes, ' text NOT NULL, ',
  'field_', @fid_phone, ' varchar(255) NOT NULL DEFAULT \'\', ',
  'field_', @fid_cf_session, ' varchar(255) NOT NULL DEFAULT \'\', ',
  'date_added int(11) NOT NULL DEFAULT 0, ',
  'date_updated int(11) DEFAULT NULL, ',
  'created_by int(11) NOT NULL DEFAULT 1, ',
  'sort_order int(11) NOT NULL DEFAULT 0, ',
  'PRIMARY KEY (id)',
  ') ENGINE=InnoDB DEFAULT CHARSET=utf8'
);

PREPARE stmt FROM @create_table_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Values table (required for dropdown field storage)
SET @create_values_sql = CONCAT(
  'CREATE TABLE IF NOT EXISTS app_entity_', @entity_id, '_values (',
  'id int(11) NOT NULL AUTO_INCREMENT, ',
  'items_id int(11) NOT NULL, ',
  'fields_id int(11) NOT NULL, ',
  'value int(11) NOT NULL, ',
  'PRIMARY KEY (id), ',
  'KEY idx_items_id (items_id), ',
  'KEY idx_fields_id (fields_id), ',
  'KEY idx_items_fields_id (items_id, fields_id), ',
  'KEY idx_value_id (value)',
  ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
);

PREPARE stmt2 FROM @create_values_sql;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- -----------------------------------------------
-- 6. Update the dropdown configuration with choice references
-- -----------------------------------------------

-- Get Channel choice IDs
SET @ch_call = (SELECT id FROM app_fields_choices WHERE fields_id = @fid_channel AND name = 'Call');
SET @ch_video = (SELECT id FROM app_fields_choices WHERE fields_id = @fid_channel AND name = 'Video');
SET @ch_sms = (SELECT id FROM app_fields_choices WHERE fields_id = @fid_channel AND name = 'SMS');
SET @ch_webrtc = (SELECT id FROM app_fields_choices WHERE fields_id = @fid_channel AND name = 'WebRTC');

UPDATE app_fields SET configuration = CONCAT(
  '{"use_global_list":"0","choices":[',
  '{"id":"', @ch_call, '","value":"Call"},',
  '{"id":"', @ch_video, '","value":"Video"},',
  '{"id":"', @ch_sms, '","value":"SMS"},',
  '{"id":"', @ch_webrtc, '","value":"WebRTC"}',
  ']}'
) WHERE id = @fid_channel;

-- Get Direction choice IDs
SET @dir_in = (SELECT id FROM app_fields_choices WHERE fields_id = @fid_direction AND name = 'Inbound');
SET @dir_out = (SELECT id FROM app_fields_choices WHERE fields_id = @fid_direction AND name = 'Outbound');

UPDATE app_fields SET configuration = CONCAT(
  '{"use_global_list":"0","choices":[',
  '{"id":"', @dir_in, '","value":"Inbound"},',
  '{"id":"', @dir_out, '","value":"Outbound"}',
  ']}'
) WHERE id = @fid_direction;

-- Get Status choice IDs
SET @st_ringing = (SELECT id FROM app_fields_choices WHERE fields_id = @fid_status AND name = 'Ringing');
SET @st_answered = (SELECT id FROM app_fields_choices WHERE fields_id = @fid_status AND name = 'Answered');
SET @st_incall = (SELECT id FROM app_fields_choices WHERE fields_id = @fid_status AND name = 'In Call');
SET @st_ended = (SELECT id FROM app_fields_choices WHERE fields_id = @fid_status AND name = 'Ended');
SET @st_declined = (SELECT id FROM app_fields_choices WHERE fields_id = @fid_status AND name = 'Declined');
SET @st_missed = (SELECT id FROM app_fields_choices WHERE fields_id = @fid_status AND name = 'Missed');
SET @st_timedout = (SELECT id FROM app_fields_choices WHERE fields_id = @fid_status AND name = 'Timed Out');
SET @st_recready = (SELECT id FROM app_fields_choices WHERE fields_id = @fid_status AND name = 'Recording Ready');
SET @st_transcribed = (SELECT id FROM app_fields_choices WHERE fields_id = @fid_status AND name = 'Transcribed');

UPDATE app_fields SET configuration = CONCAT(
  '{"use_global_list":"0","choices":[',
  '{"id":"', @st_ringing, '","value":"Ringing"},',
  '{"id":"', @st_answered, '","value":"Answered"},',
  '{"id":"', @st_incall, '","value":"In Call"},',
  '{"id":"', @st_ended, '","value":"Ended"},',
  '{"id":"', @st_declined, '","value":"Declined"},',
  '{"id":"', @st_missed, '","value":"Missed"},',
  '{"id":"', @st_timedout, '","value":"Timed Out"},',
  '{"id":"', @st_recready, '","value":"Recording Ready"},',
  '{"id":"', @st_transcribed, '","value":"Transcribed"}',
  ']}'
) WHERE id = @fid_status;

-- Get Site choice IDs
SET @site_mech = (SELECT id FROM app_fields_choices WHERE fields_id = @fid_site AND name = 'mechanicstaugustine.com');
SET @site_sodjax = (SELECT id FROM app_fields_choices WHERE fields_id = @fid_site AND name = 'sodjax.com');
SET @site_jaxsod = (SELECT id FROM app_fields_choices WHERE fields_id = @fid_site AND name = 'jacksonvillesod.com');
SET @site_sodjaxfl = (SELECT id FROM app_fields_choices WHERE fields_id = @fid_site AND name = 'sodjacksonvillefl.com');
SET @site_drain = (SELECT id FROM app_fields_choices WHERE fields_id = @fid_site AND name = 'drainagejax.com');
SET @site_sodco = (SELECT id FROM app_fields_choices WHERE fields_id = @fid_site AND name = 'sod.company');
SET @site_nearby = (SELECT id FROM app_fields_choices WHERE fields_id = @fid_site AND name = 'nearby.contractors');
SET @site_mobile = (SELECT id FROM app_fields_choices WHERE fields_id = @fid_site AND name = 'mobilemechanic.best');

UPDATE app_fields SET configuration = CONCAT(
  '{"use_global_list":"0","choices":[',
  '{"id":"', @site_mech, '","value":"mechanicstaugustine.com"},',
  '{"id":"', @site_sodjax, '","value":"sodjax.com"},',
  '{"id":"', @site_jaxsod, '","value":"jacksonvillesod.com"},',
  '{"id":"', @site_sodjaxfl, '","value":"sodjacksonvillefl.com"},',
  '{"id":"', @site_drain, '","value":"drainagejax.com"},',
  '{"id":"', @site_sodco, '","value":"sod.company"},',
  '{"id":"', @site_nearby, '","value":"nearby.contractors"},',
  '{"id":"', @site_mobile, '","value":"mobilemechanic.best"}',
  ']}'
) WHERE id = @fid_site;

-- -----------------------------------------------
-- Done! Run these queries after to get actual IDs:
-- SELECT @entity_id AS entity_id;
-- SELECT id, name, short_name FROM app_fields WHERE entities_id = @entity_id ORDER BY sort_order;
-- SELECT id, fields_id, name FROM app_fields_choices WHERE fields_id IN (@fid_channel, @fid_direction, @fid_status, @fid_site) ORDER BY fields_id, sort_order;
-- -----------------------------------------------
