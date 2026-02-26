/**
 * Quick Edit - Inline field editing for Rukovoditel record detail pages.
 * Vanilla JS, no dependencies.
 */
(function() {
    'use strict';

    // Only run on record detail pages
    if (window.location.href.indexOf('module=items/info') === -1) return;

    // Extract entity_id and record_id from URL path param
    var pathMatch = window.location.href.match(/path=(\d+)-(\d+)/);
    if (!pathMatch) return;

    var entityId = parseInt(pathMatch[1], 10);
    var recordId = parseInt(pathMatch[2], 10);
    var ajaxUrl  = '/crm/plugins/claude/ajax_quick_edit.php';

    // State
    var fieldConfig = {};   // { fieldId: { id, name, type, configuration } }
    var choicesMap  = {};    // { fieldId: [ { id, name, bg_color, parent_id } ] }
    var changes     = {};    // { field_XXX: newValue }
    var activeEdit  = null;  // Currently editing { fieldId, td, originalHTML }
    var saveBtn     = null;

    // Non-editable types (skipped in config endpoint, but double-check here)
    var skipTypes = [
        'fieldtype_entity', 'fieldtype_entity_ajax', 'fieldtype_entity_multilevel',
        'fieldtype_attachments', 'fieldtype_action', 'fieldtype_id',
        'fieldtype_date_added', 'fieldtype_date_updated', 'fieldtype_created_by',
        'fieldtype_parent_item_id', 'fieldtype_users'
    ];

    // -----------------------------------------------------------------------
    // Inject minimal styles
    // -----------------------------------------------------------------------
    var style = document.createElement('style');
    style.textContent = [
        '.qe-editable { cursor: pointer; position: relative; transition: background 0.15s; }',
        '.qe-editable:hover { background: rgba(52,152,219,0.07); }',
        '.qe-editable:hover::after { content: " \\270E"; font-size: 12px; color: #999; margin-left: 4px; }',
        '.qe-editing input, .qe-editing select, .qe-editing textarea {',
        '  width: 100%; padding: 4px 6px; font-size: 13px; border: 1px solid #3498db;',
        '  border-radius: 3px; box-sizing: border-box; outline: none; }',
        '.qe-editing textarea { min-height: 60px; resize: vertical; }',
        '#qe-save-btn {',
        '  position: fixed; bottom: 20px; right: 20px; z-index: 10000;',
        '  background: #3498db; color: #fff; border: none; padding: 10px 24px;',
        '  font-size: 14px; font-weight: 600; border-radius: 4px; cursor: pointer;',
        '  box-shadow: 0 2px 8px rgba(0,0,0,0.25); transition: background 0.2s; }',
        '#qe-save-btn:hover { background: #2980b9; }',
        '#qe-save-btn.qe-saving { background: #95a5a6; cursor: wait; }',
    ].join('\n');
    document.head.appendChild(style);

    // -----------------------------------------------------------------------
    // Fetch field config
    // -----------------------------------------------------------------------
    var xhr = new XMLHttpRequest();
    xhr.open('GET', ajaxUrl + '?action=config&entity_id=' + entityId, true);
    xhr.onload = function() {
        if (xhr.status !== 200) return;
        try {
            var data = JSON.parse(xhr.responseText);
            if (!data.success) return;
            fieldConfig = data.fields || {};
            choicesMap  = data.choices || {};
            initEditableFields();
        } catch(e) {
            console.error('QuickEdit: config parse error', e);
        }
    };
    xhr.send();

    // -----------------------------------------------------------------------
    // Make matching fields editable
    // -----------------------------------------------------------------------
    function initEditableFields() {
        var panel = document.querySelector('.col-md-4');
        if (!panel) return;

        for (var fid in fieldConfig) {
            if (!fieldConfig.hasOwnProperty(fid)) continue;
            var fc = fieldConfig[fid];
            if (skipTypes.indexOf(fc.type) !== -1) continue;

            var row = panel.querySelector('tr.form-group-' + fid);
            if (!row) continue;

            var td = row.querySelector('td');
            if (!td) continue;

            td.classList.add('qe-editable');
            td.setAttribute('data-field-id', fid);
            td.addEventListener('click', onFieldClick);
        }
    }

    // -----------------------------------------------------------------------
    // Field click handler
    // -----------------------------------------------------------------------
    function onFieldClick(e) {
        var td = this;
        var fid = td.getAttribute('data-field-id');
        var fc = fieldConfig[fid];
        if (!fc) return;

        // Don't re-enter if already editing this field
        if (activeEdit && activeEdit.fieldId === fid) return;

        // Cancel any active edit first
        if (activeEdit) cancelEdit();

        // Store original state
        activeEdit = {
            fieldId: fid,
            td: td,
            originalHTML: td.innerHTML
        };

        td.classList.remove('qe-editable');
        td.classList.add('qe-editing');
        td.removeEventListener('click', onFieldClick);

        var currentVal = extractCurrentValue(td, fc);

        switch (fc.type) {
            case 'fieldtype_dropdown':
            case 'fieldtype_dropdown_multilevel':
                renderDropdown(td, fid, currentVal);
                break;
            case 'fieldtype_checkboxes':
                renderCheckboxes(td, fid, currentVal);
                break;
            case 'fieldtype_boolean_checkbox':
                toggleBoolean(td, fid, currentVal);
                break;
            case 'fieldtype_input':
            case 'fieldtype_input_numeric':
                renderTextInput(td, fid, currentVal, fc.type === 'fieldtype_input_numeric' ? 'number' : 'text');
                break;
            case 'fieldtype_textarea':
            case 'fieldtype_textarea_wysiwyg':
                renderTextarea(td, fid, currentVal);
                break;
            case 'fieldtype_input_date':
                renderDateInput(td, fid, currentVal);
                break;
            case 'fieldtype_input_datetime':
                renderDatetimeInput(td, fid, currentVal);
                break;
            case 'fieldtype_tags':
                renderTextInput(td, fid, currentVal, 'text');
                break;
            default:
                cancelEdit();
                break;
        }
    }

    // -----------------------------------------------------------------------
    // Extract the current display value from a td
    // -----------------------------------------------------------------------
    function extractCurrentValue(td, fc) {
        // For dropdowns, try to find the choice by matching displayed text
        if (fc.type === 'fieldtype_dropdown' || fc.type === 'fieldtype_dropdown_multilevel') {
            var span = td.querySelector('.bg-color-value');
            var displayText = span ? span.textContent.trim() : td.textContent.trim();
            var choices = choicesMap[fc.id] || [];
            for (var i = 0; i < choices.length; i++) {
                if (choices[i].name.trim() === displayText) {
                    return String(choices[i].id);
                }
            }
            return '';
        }

        // For checkboxes, find all checked choice IDs by matching text
        if (fc.type === 'fieldtype_checkboxes') {
            var labels = td.querySelectorAll('.bg-color-value, span');
            var choices = choicesMap[fc.id] || [];
            var ids = [];
            labels.forEach(function(lbl) {
                var t = lbl.textContent.trim();
                for (var i = 0; i < choices.length; i++) {
                    if (choices[i].name.trim() === t) {
                        ids.push(String(choices[i].id));
                    }
                }
            });
            return ids.join(',');
        }

        // For boolean: check for "Yes" / checked icon / "1"
        if (fc.type === 'fieldtype_boolean_checkbox') {
            var text = td.textContent.trim().toLowerCase();
            var hasCheck = td.querySelector('.fa-check, .glyphicon-ok');
            return (text === 'yes' || text === '1' || hasCheck) ? '1' : '0';
        }

        // For dates stored as unix timestamps, try to parse displayed date
        if (fc.type === 'fieldtype_input_date' || fc.type === 'fieldtype_input_datetime') {
            return td.textContent.trim();
        }

        return td.textContent.trim();
    }

    // -----------------------------------------------------------------------
    // Render dropdown select
    // -----------------------------------------------------------------------
    function renderDropdown(td, fid, currentVal) {
        var choices = choicesMap[fid] || [];
        var select = document.createElement('select');
        select.className = 'form-control';

        var emptyOpt = document.createElement('option');
        emptyOpt.value = '';
        emptyOpt.textContent = '-- None --';
        select.appendChild(emptyOpt);

        choices.forEach(function(ch) {
            // Only top-level choices (parent_id=0) for simple dropdowns
            if (ch.parent_id !== 0) return;
            var opt = document.createElement('option');
            opt.value = String(ch.id);
            opt.textContent = ch.name;
            if (ch.bg_color) {
                opt.style.background = ch.bg_color;
                opt.style.color = '#fff';
            }
            if (String(ch.id) === currentVal) opt.selected = true;
            select.appendChild(opt);
        });

        td.innerHTML = '';
        td.appendChild(select);
        select.focus();

        select.addEventListener('change', function() {
            registerChange(fid, select.value);
            commitEdit(fid, select.value);
        });

        select.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') cancelEdit();
        });
    }

    // -----------------------------------------------------------------------
    // Render checkboxes (multi-select)
    // -----------------------------------------------------------------------
    function renderCheckboxes(td, fid, currentVal) {
        var choices = choicesMap[fid] || [];
        var selected = currentVal ? currentVal.split(',') : [];
        var wrapper = document.createElement('div');

        choices.forEach(function(ch) {
            var label = document.createElement('label');
            label.style.display = 'block';
            label.style.fontWeight = 'normal';
            label.style.marginBottom = '2px';

            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.value = String(ch.id);
            cb.style.marginRight = '6px';
            if (selected.indexOf(String(ch.id)) !== -1) cb.checked = true;

            cb.addEventListener('change', function() {
                var vals = [];
                wrapper.querySelectorAll('input[type=checkbox]:checked').forEach(function(c) {
                    vals.push(c.value);
                });
                registerChange(fid, vals.join(','));
            });

            label.appendChild(cb);
            label.appendChild(document.createTextNode(ch.name));
            wrapper.appendChild(label);
        });

        // Done button
        var doneBtn = document.createElement('button');
        doneBtn.textContent = 'Done';
        doneBtn.className = 'btn btn-xs btn-primary';
        doneBtn.style.marginTop = '4px';
        doneBtn.addEventListener('click', function() {
            var vals = [];
            wrapper.querySelectorAll('input[type=checkbox]:checked').forEach(function(c) {
                vals.push(c.value);
            });
            commitEdit(fid, vals.join(','));
        });

        td.innerHTML = '';
        td.appendChild(wrapper);
        td.appendChild(doneBtn);
    }

    // -----------------------------------------------------------------------
    // Toggle boolean checkbox immediately
    // -----------------------------------------------------------------------
    function toggleBoolean(td, fid, currentVal) {
        var newVal = (currentVal === '1') ? '0' : '1';
        registerChange(fid, newVal);
        // Immediately show updated state
        var display = newVal === '1' ? '<i class="fa fa-check"></i> Yes' : 'No';
        td.innerHTML = display;
        td.classList.remove('qe-editing');
        td.classList.add('qe-editable');
        td.addEventListener('click', onFieldClick);
        activeEdit = null;
    }

    // -----------------------------------------------------------------------
    // Render text input
    // -----------------------------------------------------------------------
    function renderTextInput(td, fid, currentVal, inputType) {
        var input = document.createElement('input');
        input.type = inputType;
        input.className = 'form-control';
        input.value = currentVal;

        td.innerHTML = '';
        td.appendChild(input);
        input.focus();
        input.select();

        input.addEventListener('blur', function() {
            if (input.value !== currentVal) {
                registerChange(fid, input.value);
            }
            commitEdit(fid, input.value);
        });

        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                input.blur();
            } else if (e.key === 'Escape') {
                cancelEdit();
            }
        });
    }

    // -----------------------------------------------------------------------
    // Render textarea
    // -----------------------------------------------------------------------
    function renderTextarea(td, fid, currentVal) {
        var textarea = document.createElement('textarea');
        textarea.className = 'form-control';
        textarea.value = currentVal;

        td.innerHTML = '';
        td.appendChild(textarea);
        textarea.focus();

        // Done button for textarea (since blur can be unreliable)
        var doneBtn = document.createElement('button');
        doneBtn.textContent = 'Done';
        doneBtn.className = 'btn btn-xs btn-primary';
        doneBtn.style.marginTop = '4px';
        doneBtn.addEventListener('click', function() {
            if (textarea.value !== currentVal) {
                registerChange(fid, textarea.value);
            }
            commitEdit(fid, textarea.value);
        });
        td.appendChild(doneBtn);

        textarea.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') cancelEdit();
        });
    }

    // -----------------------------------------------------------------------
    // Render date input
    // -----------------------------------------------------------------------
    function renderDateInput(td, fid, currentVal) {
        var input = document.createElement('input');
        input.type = 'date';
        input.className = 'form-control';

        // Try to parse displayed date into yyyy-mm-dd
        var parsed = parseDisplayDate(currentVal);
        if (parsed) input.value = parsed;

        td.innerHTML = '';
        td.appendChild(input);
        input.focus();

        input.addEventListener('change', function() {
            // Convert date to unix timestamp for storage
            if (input.value) {
                var ts = Math.floor(new Date(input.value + 'T00:00:00').getTime() / 1000);
                registerChange(fid, String(ts));
            } else {
                registerChange(fid, '');
            }
            commitEdit(fid, input.value);
        });

        input.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') cancelEdit();
        });
    }

    // -----------------------------------------------------------------------
    // Render datetime input
    // -----------------------------------------------------------------------
    function renderDatetimeInput(td, fid, currentVal) {
        var input = document.createElement('input');
        input.type = 'datetime-local';
        input.className = 'form-control';

        // Try to parse displayed datetime into yyyy-mm-ddThh:mm
        var parsed = parseDisplayDatetime(currentVal);
        if (parsed) input.value = parsed;

        td.innerHTML = '';
        td.appendChild(input);
        input.focus();

        input.addEventListener('change', function() {
            if (input.value) {
                var ts = Math.floor(new Date(input.value).getTime() / 1000);
                registerChange(fid, String(ts));
            } else {
                registerChange(fid, '');
            }
            commitEdit(fid, input.value);
        });

        input.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') cancelEdit();
        });
    }

    // -----------------------------------------------------------------------
    // Helpers for date parsing
    // -----------------------------------------------------------------------
    function parseDisplayDate(str) {
        if (!str) return '';
        // Try common formats: m/d/Y, d.m.Y, Y-m-d
        var d = new Date(str);
        if (!isNaN(d.getTime())) {
            return d.toISOString().slice(0, 10);
        }
        // Try manual parse for d/m/Y or m/d/Y
        var parts = str.split(/[\/\.\-]/);
        if (parts.length === 3) {
            // Assume m/d/Y format
            var y = parts[2].length === 4 ? parts[2] : '20' + parts[2];
            var m = parts[0].padStart(2, '0');
            var day = parts[1].padStart(2, '0');
            return y + '-' + m + '-' + day;
        }
        return '';
    }

    function parseDisplayDatetime(str) {
        if (!str) return '';
        var d = new Date(str);
        if (!isNaN(d.getTime())) {
            return d.toISOString().slice(0, 16);
        }
        // Fall back to date-only parse
        var dateOnly = parseDisplayDate(str);
        if (dateOnly) return dateOnly + 'T00:00';
        return '';
    }

    // -----------------------------------------------------------------------
    // Register a field change
    // -----------------------------------------------------------------------
    function registerChange(fid, value) {
        changes['field_' + fid] = value;
        showSaveButton();
    }

    // -----------------------------------------------------------------------
    // Commit edit: restore td to non-editing state
    // -----------------------------------------------------------------------
    function commitEdit(fid, displayValue) {
        if (!activeEdit || activeEdit.fieldId !== fid) return;
        var td = activeEdit.td;
        var fc = fieldConfig[fid];

        // Build display HTML
        if (fc.type === 'fieldtype_dropdown' || fc.type === 'fieldtype_dropdown_multilevel') {
            var choices = choicesMap[fid] || [];
            var found = false;
            for (var i = 0; i < choices.length; i++) {
                if (String(choices[i].id) === displayValue) {
                    var bgStyle = choices[i].bg_color
                        ? 'background:' + choices[i].bg_color + ';color:#fff;padding:2px 8px;border-radius:3px;'
                        : '';
                    td.innerHTML = '<span class="bg-color-value" style="' + bgStyle + '">'
                        + escapeHtml(choices[i].name) + '</span>';
                    found = true;
                    break;
                }
            }
            if (!found) td.innerHTML = displayValue ? escapeHtml(displayValue) : '';
        } else if (fc.type === 'fieldtype_checkboxes') {
            var ids = displayValue ? displayValue.split(',') : [];
            var choices = choicesMap[fid] || [];
            var html = '';
            ids.forEach(function(cid) {
                for (var i = 0; i < choices.length; i++) {
                    if (String(choices[i].id) === cid) {
                        var bgStyle = choices[i].bg_color
                            ? 'background:' + choices[i].bg_color + ';color:#fff;padding:2px 6px;border-radius:3px;margin-right:4px;'
                            : 'margin-right:4px;';
                        html += '<span class="bg-color-value" style="' + bgStyle + '">'
                            + escapeHtml(choices[i].name) + '</span> ';
                    }
                }
            });
            td.innerHTML = html || '';
        } else if (fc.type === 'fieldtype_input_date') {
            td.innerHTML = escapeHtml(displayValue);
        } else if (fc.type === 'fieldtype_input_datetime') {
            td.innerHTML = escapeHtml(displayValue ? displayValue.replace('T', ' ') : '');
        } else {
            td.innerHTML = escapeHtml(displayValue);
        }

        td.classList.remove('qe-editing');
        td.classList.add('qe-editable');
        td.addEventListener('click', onFieldClick);
        activeEdit = null;
    }

    // -----------------------------------------------------------------------
    // Cancel the current edit
    // -----------------------------------------------------------------------
    function cancelEdit() {
        if (!activeEdit) return;
        var td = activeEdit.td;
        td.innerHTML = activeEdit.originalHTML;
        td.classList.remove('qe-editing');
        td.classList.add('qe-editable');
        td.addEventListener('click', onFieldClick);
        activeEdit = null;
    }

    // -----------------------------------------------------------------------
    // Show / hide the Save button
    // -----------------------------------------------------------------------
    function showSaveButton() {
        if (saveBtn) return;
        saveBtn = document.createElement('button');
        saveBtn.id = 'qe-save-btn';
        saveBtn.textContent = 'Save Changes';
        saveBtn.addEventListener('click', doSave);
        document.body.appendChild(saveBtn);
    }

    function hideSaveButton() {
        if (saveBtn) {
            saveBtn.remove();
            saveBtn = null;
        }
    }

    // -----------------------------------------------------------------------
    // Save changes via AJAX
    // -----------------------------------------------------------------------
    function doSave() {
        if (!Object.keys(changes).length) return;

        saveBtn.textContent = 'Saving...';
        saveBtn.classList.add('qe-saving');

        var formData = new FormData();
        formData.append('entity_id', entityId);
        formData.append('record_id', recordId);
        formData.append('fields', JSON.stringify(changes));

        var xhr2 = new XMLHttpRequest();
        xhr2.open('POST', ajaxUrl, true);
        xhr2.onload = function() {
            try {
                var resp = JSON.parse(xhr2.responseText);
                if (resp.success) {
                    // Reload to show authoritative values
                    window.location.reload();
                } else {
                    alert('Save failed: ' + (resp.error || 'Unknown error'));
                    saveBtn.textContent = 'Save Changes';
                    saveBtn.classList.remove('qe-saving');
                }
            } catch(e) {
                alert('Save failed: invalid response');
                saveBtn.textContent = 'Save Changes';
                saveBtn.classList.remove('qe-saving');
            }
        };
        xhr2.onerror = function() {
            alert('Save failed: network error');
            saveBtn.textContent = 'Save Changes';
            saveBtn.classList.remove('qe-saving');
        };
        xhr2.send(formData);
    }

    // -----------------------------------------------------------------------
    // Global Escape key handler
    // -----------------------------------------------------------------------
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') cancelEdit();
    });

    // -----------------------------------------------------------------------
    // Utility: escape HTML
    // -----------------------------------------------------------------------
    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

})();
