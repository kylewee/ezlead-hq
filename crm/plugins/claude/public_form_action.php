<?php
/**
 * Claude Plugin - Public Form Action Hook
 * Runs before public form rendering/processing.
 * Sets default field values for hotline intake form (entity 42).
 */

// Only act on our Hotline Intake Form (id=3) or any entity 42 public form
if (isset($public_form) && $public_form['entities_id'] == 42) {
    // If form is being submitted, inject default values for hidden fields
    if ($app_module_action === 'save' && isset($_POST['fields'])) {
        // Stage = 217 (Incoming) if not set
        if (!isset($_POST['fields'][362]) || empty($_POST['fields'][362])) {
            $_POST['fields'][362] = 217;
        }
        // Business = 2 (Ez Mobile Mechanic) if not set
        if (!isset($_POST['fields'][475]) || empty($_POST['fields'][475])) {
            $_POST['fields'][475] = 2;
        }
    }
}
