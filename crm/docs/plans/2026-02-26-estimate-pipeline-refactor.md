# Estimate Pipeline Refactor - Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Rewire the phone-to-CRM pipeline so incoming calls create an Estimate (entity 53) + Lead (25) instead of a Job (42) directly. Jobs are only created when a customer accepts an estimate.

**Architecture:** The recording processor creates Customer → Vehicle → Estimate + Lead. A 5-minute cron picks up Pending estimates, generates PDF, notifies the customer, and sets status to Sent. When an estimate is Accepted (manually or via future accept handler), the cron creates a Job from the Estimate data and the existing Job automation takes over.

**Tech Stack:** PHP 8.3, Rukovoditel CRM REST API, EstimateEngine, SignalWire SMS, OpenAI GPT

---

## Context & Entity Reference

### Estimate Entity (53) — Already Created
| Field | Name | Type | Purpose |
|-------|------|------|---------|
| 515 | Title | input | "Estimate - {name} - {vehicle}" |
| 516 | Customer | entity_ajax(47) | Link to Customer record |
| 517 | Vehicle | entity_ajax(48) | Link to Vehicle record |
| 518 | Lead | entity_ajax(25) | Link to Lead record |
| 519 | Status | dropdown | 205=Pending, 206=Sent, 207=Accepted, 208=Declined |
| 520 | Problem | textarea | Issue described by customer |
| 521 | Business | entity(50) | 2=Ez Mobile Mechanic |
| 522 | Labor Hours | numeric | From EstimateEngine |
| 523 | Parts Cost | numeric | From EstimateEngine |
| 524 | Labor Cost | numeric | From EstimateEngine |
| 525 | Total Low | numeric | From EstimateEngine |
| 526 | Total High | numeric | From EstimateEngine |
| 527 | Estimate Details | textarea | Full estimate text + transcript |
| 528 | PDF | attachments | Generated PDF |
| 529 | Job | entity_ajax(42) | Linked Job (set when accepted) |

### Stage Value Finding (NOT a bug)
The user reported that `smartcall.php` line 373 writes `82` as stage value and called it invalid. **Investigation shows 82 IS correct.** The choice IDs in `app_fields_choices` for field 362 are:
- 82=New Lead, 83=Estimate Sent, 84=Accepted, 85=Scheduled, etc.
The automation code at line 147 (`$stage_ids['new_lead'] ?? 1`) resolves to 82 via the DB lookup at lines 36-41. The fallback `?? 1` would be wrong if the lookup failed, but it doesn't fail. If kanban display has issues, it's a kanban config problem (field `exclude_choices` is empty in kanban ID 4, which is correct). **No code change needed for this.**

### Files to Modify
1. `/var/www/ezlead-platform/core/voice/smartcall.php` — Add estimate + lead creation functions
2. `/var/www/ezlead-platform/core/voice/recording_processor.php` — Switch from Job to Estimate + Lead
3. `/var/www/ezlead-hq/crm/plugins/claude/mechanic_automation.php` — Process Estimates instead of Jobs for initial stage

### Existing Functions (keep unchanged)
- `smartcall_find_or_create_customer()` — Reused by estimate creation
- `smartcall_find_or_create_vehicle()` — Reused by estimate creation
- `smartcall_api()` — Reused everywhere
- `smartcall_create_job()` — Keep for future use (called by automation when estimate accepted)

---

## Task 1: Add Estimate + Lead Creation to smartcall.php

**Files:**
- Modify: `/var/www/ezlead-platform/core/voice/smartcall.php` (append after `smartcall_get_customer_jobs()` at line 444)

**Step 1: Add `smartcall_create_estimate()` function**

Append to end of `smartcall.php`:

```php
/**
 * Create an Estimate (entity 53) linked to Customer + Vehicle
 * Called from recording_processor instead of smartcall_create_job()
 * Returns estimate ID
 */
function smartcall_create_estimate(array $customerData, ?array $estimate = null): ?string {
    $customerId = smartcall_find_or_create_customer($customerData);
    if (!$customerId) return null;

    $vehicleId = smartcall_find_or_create_vehicle($customerId, $customerData);

    // Build title
    $name = $customerData['name'] ?? 'Unknown';
    $vehicleStr = trim(($customerData['year'] ?? '') . ' ' . ($customerData['make'] ?? '') . ' ' . ($customerData['model'] ?? ''));
    $title = "Estimate - {$name}" . ($vehicleStr ? " - {$vehicleStr}" : '');

    $params = [
        'action'              => 'insert',
        'entity_id'           => 53,
        'items[field_515]'    => $title,
        'items[field_516]'    => $customerId,
        'items[field_519]'    => '205',  // Pending
        'items[field_520]'    => $customerData['problem'] ?? '',
        'items[field_521]'    => '2',    // Ez Mobile Mechanic
    ];

    if ($vehicleId) {
        $params['items[field_517]'] = $vehicleId;
    }

    // Fill estimate data if EstimateEngine already ran
    if ($estimate) {
        $params['items[field_522]'] = $estimate['labor_hours'] ?? 0;
        $params['items[field_523]'] = $estimate['parts_low'] ?? 0;
        $params['items[field_524]'] = $estimate['labor_cost'] ?? 0;
        $params['items[field_525]'] = $estimate['total_low'] ?? 0;
        $params['items[field_526]'] = $estimate['total_high'] ?? 0;
        $params['items[field_527]'] = sprintf(
            "Repair: %s\nDescription: %s\nLabor: %s hrs = $%s\nParts: $%s - $%s\nTotal: $%s - $%s\n%s",
            $estimate['repair_name'] ?? '',
            $estimate['description'] ?? '',
            $estimate['labor_hours'] ?? '',
            $estimate['labor_cost'] ?? '',
            $estimate['parts_low'] ?? '',
            $estimate['parts_high'] ?? '',
            $estimate['total_low'] ?? '',
            $estimate['total_high'] ?? '',
            $estimate['notes'] ?? ''
        );
    }

    $result = smartcall_api($params);
    $estimateId = $result['id'] ?? null;

    @file_put_contents(dirname(__FILE__) . '/voice.log', json_encode([
        'ts'          => date('c'),
        'event'       => 'smartcall_estimate_created',
        'estimate_id' => $estimateId,
        'customer_id' => $customerId,
        'vehicle_id'  => $vehicleId,
    ]) . "\n", FILE_APPEND);

    return $estimateId;
}

/**
 * Create a Lead (entity 25) for a phone call contact record
 * Links to Customer if available. Created for ALL calls (mechanic or not).
 * Returns lead ID
 */
function smartcall_create_lead(array $customerData, ?string $customerId = null, string $source = 'Phone Call'): ?string {
    $params = [
        'action'              => 'insert',
        'entity_id'           => 25,
        'items[field_210]'    => $customerData['name'] ?? 'Unknown',
        'items[field_211]'    => $customerData['phone'] ?? '',
        'items[field_212]'    => $customerData['email'] ?? '',
        'items[field_213]'    => $customerData['address'] ?? '',
        'items[field_215]'    => $_SERVER['HTTP_HOST'] ?? 'phone',
        'items[field_217]'    => $customerData['notes'] ?? '',
        'items[field_267]'    => '72',   // Lead Source = Phone Call
        'items[field_268]'    => '75',   // Lead Stage = New
    ];

    if ($customerId) {
        $params['items[field_444]'] = $customerId;
    }

    $result = smartcall_api($params);
    $leadId = $result['id'] ?? null;

    @file_put_contents(dirname(__FILE__) . '/voice.log', json_encode([
        'ts'       => date('c'),
        'event'    => 'smartcall_lead_created',
        'lead_id'  => $leadId,
        'source'   => $source,
    ]) . "\n", FILE_APPEND);

    return $leadId;
}

/**
 * Link an Estimate to a Lead (after both are created)
 */
function smartcall_link_estimate_lead(string $estimateId, string $leadId): void {
    smartcall_api([
        'action'                    => 'update',
        'entity_id'                 => 53,
        'update_by_field[id]'       => $estimateId,
        'data[field_518]'           => $leadId,
    ]);
}
```

**Step 2: Verify the new functions parse correctly**

Run: `php -l /var/www/ezlead-platform/core/voice/smartcall.php`
Expected: `No syntax errors detected`

**Step 3: Commit**

```bash
cd /var/www/ezlead-platform
git add core/voice/smartcall.php
git commit -m "feat: add smartcall_create_estimate() and smartcall_create_lead()"
```

---

## Task 2: Add `update_estimate_with_details()` to recording_processor.php

**Files:**
- Modify: `/var/www/ezlead-platform/core/voice/recording_processor.php` (add after `update_job_with_details()` ending at line 246)

**Step 1: Add the function**

Insert after line 246 (after `update_job_with_details` function):

```php
/**
 * Update an Estimate (entity 53) with recording, transcript, and estimate data
 * Called after smartcall_create_estimate() creates the initial record
 */
function update_estimate_with_details(string $estimateId, array $details): void {
    $fields = [];

    if (!empty($details['transcript'])) {
        // Append transcript to Estimate Details field
        $existing = $details['existing_details'] ?? '';
        $fields['data[field_527]'] = $existing
            ? $existing . "\n\nTranscript:\n" . $details['transcript']
            : "Transcript:\n" . $details['transcript'];
    }

    if (!empty($details['recording_path'])) {
        // Note: Estimate entity uses field_528 (PDF/attachments) for files
        // Recording is stored in the Estimate Details text for now
        $current = $fields['data[field_527]'] ?? '';
        if ($current) {
            $fields['data[field_527]'] = $current . "\n\nRecording: " . $details['recording_path'];
        }
    }

    // Estimate cost fields (if EstimateEngine ran)
    if (!empty($details['estimate'])) {
        $est = $details['estimate'];
        $fields['data[field_522]'] = $est['labor_hours'] ?? 0;
        $fields['data[field_523]'] = $est['parts_low'] ?? 0;
        $fields['data[field_524]'] = $est['labor_cost'] ?? 0;
        $fields['data[field_525]'] = $est['total_low'] ?? 0;
        $fields['data[field_526]'] = $est['total_high'] ?? 0;

        $detailText = sprintf(
            "Repair: %s\nLabor: %s hrs = $%s\nParts: $%s - $%s\nTotal: $%s - $%s\nSource: %s",
            $est['repair_name'] ?? '',
            $est['labor_hours'] ?? '',
            $est['labor_cost'] ?? '',
            $est['parts_low'] ?? '',
            $est['parts_high'] ?? '',
            $est['total_low'] ?? '',
            $est['total_high'] ?? '',
            $est['source'] ?? 'unknown'
        );
        // Prepend estimate details before transcript
        if (!empty($fields['data[field_527]'])) {
            $fields['data[field_527]'] = $detailText . "\n\n" . $fields['data[field_527]'];
        } else {
            $fields['data[field_527]'] = $detailText;
        }
    }

    if (!empty($details['source'])) {
        $source = "Source: {$details['source']}";
        if (!empty($fields['data[field_527]'])) {
            $fields['data[field_527]'] = $source . "\n" . $fields['data[field_527]'];
        } else {
            $fields['data[field_527]'] = $source;
        }
    }

    if (empty($fields)) return;

    $fields['action'] = 'update';
    $fields['entity_id'] = 53;
    $fields['update_by_field[id]'] = $estimateId;

    smartcall_api($fields);

    voice_log(['event' => 'estimate_updated', 'estimate_id' => $estimateId, 'fields_updated' => array_keys($fields)]);
}
```

**Step 2: Verify syntax**

Run: `php -l /var/www/ezlead-platform/core/voice/recording_processor.php`
Expected: `No syntax errors detected`

**Step 3: Commit**

```bash
cd /var/www/ezlead-platform
git add core/voice/recording_processor.php
git commit -m "feat: add update_estimate_with_details() for estimate pipeline"
```

---

## Task 3: Rewire `process_call_recording()` to Create Estimate + Lead

**Files:**
- Modify: `/var/www/ezlead-platform/core/voice/recording_processor.php` lines 550-594

This is the main answered-call path. Currently creates a Job; needs to create Estimate + Lead.

**Step 1: Replace the Job creation block (lines 550-594)**

Replace lines 550-594 (from `// 5. Create CRM job` to the closing `}` of the notification block) with:

```php
    // 5. Create Estimate + Lead via SmartCall
    $estimateId = null;
    $leadId = null;
    $callSource = $isAnsweredCall ? 'Phone - Answered' : 'Phone - Voicemail';

    // Create Lead for ALL calls (contact record)
    $customerId = smartcall_find_or_create_customer($customerData);
    $leadId = smartcall_create_lead($customerData, $customerId, $callSource);

    // Create Estimate for mechanic calls only
    if (config('business.type', 'service') === 'mechanic') {
        $estimateId = smartcall_create_estimate($customerData, $estimate);

        // Link Lead to Estimate
        if ($estimateId && $leadId) {
            smartcall_link_estimate_lead($estimateId, $leadId);
        }

        // 6. Update estimate with recording, transcript, and estimate details
        if ($estimateId) {
            $host = $_SERVER['HTTP_HOST'] ?? config('site.domain', 'mechanicstaugustine.com');
            update_estimate_with_details($estimateId, [
                'transcript' => $transcript,
                'recording_path' => $crmAttachmentName,
                'recording_url' => $recordingPath ? "https://{$host}/voice/recordings/" . basename($recordingPath) : '',
                'estimate' => $estimate,
                'source' => $callSource,
            ]);

            // Notify Kyle if vehicle data is missing (can't auto-estimate)
            if (empty($extracted['year']) || empty($extracted['make']) || empty($extracted['model'])) {
                $notifyPhone = config('phone.forward_to', '');
                if ($notifyPhone) {
                    $callerPhone = $customerData['phone'] ?? 'unknown';
                    $callerName = $customerData['name'] ?? 'Unknown';
                    $notifyMsg = "New call from {$callerName} ({$callerPhone}) - missing vehicle info for auto-estimate. Check estimate #{$estimateId} in CRM.";
                    $projectId = config('phone.project_id', '');
                    $apiToken = config('phone.api_token', '');
                    $space = config('phone.space', '');
                    $smsFrom = '+19042175152';
                    $smsUrl = "https://{$space}/api/laml/2010-04-01/Accounts/{$projectId}/Messages.json";
                    $ch = curl_init($smsUrl);
                    curl_setopt_array($ch, [
                        CURLOPT_POST => true,
                        CURLOPT_USERPWD => "{$projectId}:{$apiToken}",
                        CURLOPT_POSTFIELDS => http_build_query(['From' => $smsFrom, 'To' => $notifyPhone, 'Body' => $notifyMsg]),
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 10,
                    ]);
                    curl_exec($ch);
                    curl_close($ch);
                }
            }
        }
    }

    voice_log([
        'event' => 'call_recording_processed',
        'recording_sid' => $recordingSid,
        'estimate_id' => $estimateId,
        'lead_id' => $leadId,
        'customer_data' => $customerData,
        'has_estimate' => $estimate !== null,
    ]);

    echo json_encode(['ok' => true, 'customer' => $customerData, 'estimate_id' => $estimateId, 'lead_id' => $leadId, 'estimate' => $estimate]);
```

**Step 2: Verify syntax**

Run: `php -l /var/www/ezlead-platform/core/voice/recording_processor.php`
Expected: `No syntax errors detected`

**Step 3: Commit**

```bash
cd /var/www/ezlead-platform
git add core/voice/recording_processor.php
git commit -m "feat: process_call_recording creates Estimate+Lead instead of Job"
```

---

## Task 4: Rewire `process_session()` to Create Estimate + Lead

**Files:**
- Modify: `/var/www/ezlead-platform/core/voice/recording_processor.php` lines 357-391

The IVR session path. Currently creates a Job; needs to create Estimate + Lead.

**Step 1: Replace the Job creation block (lines 357-391)**

Replace lines 357-391 (from `$customerData['notes'] = $fullTranscript;` through the echo at the end) with:

```php
    $customerData['notes'] = $fullTranscript;
    $estimateId = null;
    $leadId = null;

    // Create Lead for ALL calls
    $customerId = smartcall_find_or_create_customer($customerData);
    $leadId = smartcall_create_lead($customerData, $customerId, 'Phone - IVR Session');

    // Create Estimate for mechanic calls only
    if (config('business.type', 'service') === 'mechanic') {
        $estimateId = smartcall_create_estimate($customerData);

        if ($estimateId && $leadId) {
            smartcall_link_estimate_lead($estimateId, $leadId);
        }

        if ($estimateId) {
            update_estimate_with_details($estimateId, [
                'transcript' => $fullTranscript,
                'source' => 'Phone - IVR Session',
            ]);
        }
    }

    // Update session file
    $session['customer_data'] = $customerData;
    $session['estimate_id'] = $estimateId;
    $session['lead_id'] = $leadId;
    $session['processed'] = true;
    $session['processed_at'] = date('c');
    file_put_contents($sessionFile, json_encode($session, JSON_PRETTY_PRINT));

    voice_log([
        'event' => 'session_processed',
        'call_sid' => $callSid,
        'estimate_id' => $estimateId,
        'lead_id' => $leadId,
        'customer_data' => $customerData,
    ]);

    echo json_encode(['ok' => true, 'customer' => $customerData, 'estimate_id' => $estimateId, 'lead_id' => $leadId]);
```

**Step 2: Verify syntax**

Run: `php -l /var/www/ezlead-platform/core/voice/recording_processor.php`
Expected: `No syntax errors detected`

**Step 3: Commit**

```bash
cd /var/www/ezlead-platform
git add core/voice/recording_processor.php
git commit -m "feat: process_session creates Estimate+Lead instead of Job"
```

---

## Task 5: Rewrite mechanic_automation.php Block 1 for Estimates

**Files:**
- Modify: `/var/www/ezlead-hq/crm/plugins/claude/mechanic_automation.php` lines 138-208

Block 1 currently: queries `app_entity_42` for `field_362 = New Lead` and generates estimates.
New behavior: queries `app_entity_53` for `field_519 = 205 (Pending)` with estimate data populated, generates PDF, emails/SMS customer, sets status to Sent (206).

**Step 1: Replace Block 1 (lines 138-208)**

Replace the entire "1. AUTO-ESTIMATE" section with:

```php
// ============================================
// 1. ESTIMATE DELIVERY: Pending estimates with data -> send to customer
// ============================================
echo "Checking for pending estimates ready to send...\n";

$sql = "SELECT e.id, e.field_515 as title, e.field_516 as customer_id, e.field_517 as vehicle_id,
               e.field_519 as status, e.field_520 as problem,
               e.field_522 as labor_hours, e.field_523 as parts_cost,
               e.field_524 as labor_cost, e.field_525 as total_low, e.field_526 as total_high,
               e.field_527 as estimate_details,
               c.field_427 as cust_name, c.field_428 as cust_phone, c.field_429 as cust_email,
               v.field_434 as year, v.field_435 as make, v.field_436 as model
        FROM app_entity_53 e
        LEFT JOIN app_entity_47 c ON e.field_516 = c.id
        LEFT JOIN app_entity_48 v ON e.field_517 = v.id
        WHERE e.field_519 = 205
        AND (e.field_522 > 0 OR e.field_527 != '')";

$result = $conn->query($sql);
$estimates_sent = 0;

while ($row = $result->fetch_assoc()) {
    if (empty($row['cust_email']) && empty($row['cust_phone'])) continue;

    $vehicleStr = trim("{$row['year']} {$row['make']} {$row['model']}");

    // If estimate fields are empty but we have vehicle+problem, generate now
    if (empty($row['estimate_details']) && !empty($row['year']) && !empty($row['make']) && !empty($row['model']) && !empty($row['problem'])) {
        $estimateText = generate_estimate($row['year'], $row['make'], $row['model'], $row['problem']);
        $estimate_escaped = $conn->real_escape_string($estimateText);
        $conn->query("UPDATE app_entity_53 SET field_527 = '{$estimate_escaped}' WHERE id = " . intval($row['id']));
        $row['estimate_details'] = $estimateText;
    }

    // Generate PDF estimate
    $pdfPath = PDFGenerator::estimate([
        'id' => $row['id'], 'name' => $row['cust_name'], 'phone' => $row['cust_phone'],
        'email' => $row['cust_email'], 'year' => $row['year'], 'make' => $row['make'],
        'model' => $row['model'], 'problem' => $row['problem'],
        'estimate' => $row['estimate_details'],
    ]);
    $pdfUrl = $pdfPath ? PDFGenerator::getPublicUrl($pdfPath) : '';
    $pdfLink = $pdfUrl ? "<p><a href='$pdfUrl' style='background:#1e40af; color:white; padding:10px 20px; text-decoration:none; border-radius:6px; display:inline-block;'>View PDF Estimate</a></p>" : '';

    // Email customer
    $subject = "Your Vehicle Repair Estimate - " . $vehicleStr;
    $body = "
    <h2>Hello " . htmlspecialchars($row['cust_name']) . ",</h2>
    <p>Thank you for contacting Ez Mobile Mechanic! Here's your estimate:</p>
    <div style='background:#f5f5f5; padding:15px; border-radius:8px; margin:20px 0;'>
        <h3>Vehicle: " . htmlspecialchars($vehicleStr) . "</h3>
        <p><strong>Issue:</strong> " . htmlspecialchars($row['problem']) . "</p>
        <hr>
        <pre style='white-space:pre-wrap;'>" . htmlspecialchars($row['estimate_details']) . "</pre>
    </div>
    $pdfLink
    <p>To schedule your repair, simply reply to this email or call us at (904) 706-6669.</p>
    <p>Thanks,<br>Kyle<br>Ez Mobile Mechanic</p>
    ";

    if (!empty($row['cust_email'])) send_email($row['cust_email'], $subject, $body, $from_email, $from_name);

    // SMS customer
    if (!empty($row['cust_phone'])) {
        $totalLow = number_format(floatval($row['total_low']), 0);
        $totalHigh = number_format(floatval($row['total_high']), 0);
        $priceStr = ($totalLow && $totalHigh) ? "\${$totalLow}-\${$totalHigh}" : "see details";
        $sms = "Hi {$row['cust_name']}! Ez Mobile Mechanic here. Estimate for your {$vehicleStr}: {$priceStr}. Reply YES to approve or call (904) 706-6669. Thanks!";
        send_sms($row['cust_phone'], $sms);
    }

    // Update estimate status to Sent (206)
    $conn->query("UPDATE app_entity_53 SET field_519 = 206 WHERE id = " . intval($row['id']));

    // Notify business
    send_email($business_email, "Estimate Sent: " . $row['cust_name'],
               "Estimate #{$row['id']} sent to " . ($row['cust_email'] ?: $row['cust_phone']) . " for " . $vehicleStr,
               $from_email, $from_name);

    $estimates_sent++;
    echo "Estimate #{$row['id']} sent to " . ($row['cust_email'] ?: $row['cust_phone']) . "\n";
}
```

**Step 2: Verify syntax**

Run: `php -l /var/www/ezlead-hq/crm/plugins/claude/mechanic_automation.php`
Expected: `No syntax errors detected`

**Step 3: Commit**

```bash
cd /var/www/ezlead-hq/crm
git add plugins/claude/mechanic_automation.php
git commit -m "feat: automation Block 1 processes Estimates instead of Jobs"
```

---

## Task 6: Add Automation Block — Estimate Accepted → Create Job

**Files:**
- Modify: `/var/www/ezlead-hq/crm/plugins/claude/mechanic_automation.php` (insert after Block 1, before Block 1b "Smart Scheduling")

**Step 1: Insert new block after Block 1**

Insert after the estimates_sent echo, before `// 1b. SMART SCHEDULING`:

```php
// ============================================
// 1a. ESTIMATE ACCEPTED: Create Job from accepted Estimate
// ============================================
echo "Checking for accepted estimates...\n";

$sql = "SELECT e.id as estimate_id, e.field_515 as title, e.field_516 as customer_id,
               e.field_517 as vehicle_id, e.field_518 as lead_id,
               e.field_520 as problem, e.field_522 as labor_hours,
               e.field_523 as parts_cost, e.field_524 as labor_cost,
               e.field_525 as total_low, e.field_526 as total_high,
               e.field_527 as estimate_details, e.field_529 as existing_job,
               c.field_427 as cust_name, c.field_428 as cust_phone,
               c.field_429 as cust_email, c.field_430 as cust_address,
               v.field_434 as year, v.field_435 as make, v.field_436 as model
        FROM app_entity_53 e
        LEFT JOIN app_entity_47 c ON e.field_516 = c.id
        LEFT JOIN app_entity_48 v ON e.field_517 = v.id
        WHERE e.field_519 = 207
        AND (e.field_529 IS NULL OR e.field_529 = '' OR e.field_529 = '0')";

$result = $conn->query($sql);
$jobs_created = 0;

while ($row = $result->fetch_assoc()) {
    // Create Job (entity 42) from Estimate data
    $jobFields = [
        'field_354' => $row['cust_name'] ?? 'Unknown',
        'field_355' => $row['cust_phone'] ?? '',
        'field_356' => $row['cust_email'] ?? '',
        'field_357' => $row['cust_address'] ?? '',
        'field_358' => $row['year'] ?? '',
        'field_359' => $row['make'] ?? '',
        'field_360' => $row['model'] ?? '',
        'field_361' => $row['problem'] ?? '',
        'field_362' => ($stage_ids['accepted'] ?? 84),
        'field_363' => $row['labor_hours'] ?? 0,
        'field_364' => $row['parts_cost'] ?? 0,
        'field_365' => $row['labor_cost'] ?? 0,
        'field_366' => $row['total_high'] ?? 0,
        'field_367' => $row['estimate_details'] ?? '',
        'field_371' => 91,  // Payment Status = Pending
        'field_372' => "Created from Estimate #{$row['estimate_id']}",
    ];

    if ($row['customer_id']) $jobFields['field_439'] = $row['customer_id'];
    if ($row['vehicle_id']) $jobFields['field_440'] = $row['vehicle_id'];

    // Build insert params
    $insertParams = ['field_362' => 'items[field_362]'];  // dummy to trigger build
    $sqlCols = [];
    $sqlVals = [];
    foreach ($jobFields as $field => $value) {
        $sqlCols[] = $field;
        $sqlVals[] = "'" . $conn->real_escape_string((string)$value) . "'";
    }

    $insertSql = "INSERT INTO app_entity_42 (" . implode(', ', $sqlCols) . ", date_added, created_by)
                   VALUES (" . implode(', ', $sqlVals) . ", NOW(), 0)";
    $conn->query($insertSql);
    $jobId = $conn->insert_id;

    if ($jobId) {
        // Link Job back to Estimate
        $conn->query("UPDATE app_entity_53 SET field_529 = {$jobId} WHERE id = " . intval($row['estimate_id']));

        // Link Lead to Job if available
        if ($row['lead_id']) {
            $conn->query("UPDATE app_entity_42 SET field_445 = " . intval($row['lead_id']) . " WHERE id = {$jobId}");
        }

        // Notify business
        $vehicleStr = trim("{$row['year']} {$row['make']} {$row['model']}");
        send_email($business_email, "Estimate Accepted: {$row['cust_name']} - {$vehicleStr}",
                   "Job #{$jobId} created from accepted Estimate #{$row['estimate_id']}. "
                   . "Customer: {$row['cust_name']} ({$row['cust_phone']}). "
                   . "Vehicle: {$vehicleStr}. Total: \${$row['total_high']}",
                   $from_email, $from_name);

        $jobs_created++;
        echo "Job #{$jobId} created from Estimate #{$row['estimate_id']}\n";
    }
}
```

**Step 2: Update the summary section at the bottom to include new counters**

Add `$jobs_created` to the summary echo block (around line 806):

After `echo "Estimates sent: $estimates_sent\n";` add:
```php
echo "Jobs from estimates: $jobs_created\n";
```

**Step 3: Verify syntax**

Run: `php -l /var/www/ezlead-hq/crm/plugins/claude/mechanic_automation.php`
Expected: `No syntax errors detected`

**Step 4: Commit**

```bash
cd /var/www/ezlead-hq/crm
git add plugins/claude/mechanic_automation.php
git commit -m "feat: add automation block to create Jobs from accepted Estimates"
```

---

## Task 7: Manual Verification

**Step 1: Test Estimate creation via CRM API**

```bash
curl -s https://ezlead4u.com/crm/api/rest.php \
  -d "username=claude" -d "password=badass" \
  -d "key=dZrvuC3Q1A8Cv82X1dQsugRiCpmU3FSMHn9BFWlY" \
  -d "action=insert" -d "entity_id=53" \
  -d "items[field_515]=Test Estimate - Pipeline" \
  -d "items[field_519]=205" \
  -d "items[field_520]=Test problem" \
  -d "items[field_521]=2" | python3 -m json.tool
```

Expected: `{"status": "success", "data": {"id": "..."}}`

**Step 2: Verify Estimate shows in CRM**

```bash
curl -s https://ezlead4u.com/crm/api/rest.php \
  -d "username=claude" -d "password=badass" \
  -d "key=dZrvuC3Q1A8Cv82X1dQsugRiCpmU3FSMHn9BFWlY" \
  -d "action=select" -d "entity_id=53" | python3 -m json.tool
```

Expected: Returns the test estimate with field values populated.

**Step 3: Dry-run the automation**

```bash
php /var/www/ezlead-hq/crm/plugins/claude/mechanic_automation.php 2>&1 | head -20
```

Expected: Runs without errors, prints "Checking for pending estimates ready to send..." and "Checking for accepted estimates..."

**Step 4: Clean up test data**

```bash
curl -s https://ezlead4u.com/crm/api/rest.php \
  -d "username=claude" -d "password=badass" \
  -d "key=dZrvuC3Q1A8Cv82X1dQsugRiCpmU3FSMHn9BFWlY" \
  -d "action=delete" -d "entity_id=53" \
  -d "items_id=TEST_ID"
```

**Step 5: Commit verification notes**

No code changes — just confirm all tests pass.

---

## Task 8: Update Memory Files

**Files:**
- Modify: `/home/kylewee/.claude/projects/-var-www-ezlead-hq/memory/field-map.md`
- Modify: `/home/kylewee/.claude/projects/-var-www-ezlead-hq/memory/MEMORY.md`

**Step 1: Add Estimate entity to field-map.md**

Append to field-map.md:

```markdown
## Entity 53 - Estimates

| Field | Name | Type | Aliases | Choices |
|-------|------|------|---------|---------|
| 515 | Title | input (heading) | title, estimate name |  |
| 516 | Customer | entity_ajax(47) | customer |  |
| 517 | Vehicle | entity_ajax(48) | vehicle |  |
| 518 | Lead | entity_ajax(25) | lead |  |
| 519 | Status | dropdown | status, estimate status | 205=Pending, 206=Sent, 207=Accepted, 208=Declined |
| 520 | Problem | textarea | problem, issue |  |
| 521 | Business | entity(50) | business |  |
| 522 | Labor Hours | numeric | labor hours |  |
| 523 | Parts Cost | numeric | parts cost |  |
| 524 | Labor Cost | numeric | labor cost |  |
| 525 | Total Low | numeric | total low, low estimate |  |
| 526 | Total High | numeric | total high, high estimate |  |
| 527 | Estimate Details | textarea | details, estimate text |  |
| 528 | PDF | attachments | pdf, document |  |
| 529 | Job | entity_ajax(42) | job, linked job |  |
```

**Step 2: Add pipeline note to MEMORY.md**

Add under "Key File Locations":

```markdown
## Pipeline Flow (Feb 2026 refactor)

Phone call → recording_processor.php → smartcall_create_estimate() + smartcall_create_lead()
  → Creates: Customer(47) + Vehicle(48) + Estimate(53, Pending) + Lead(25)
  → Cron: mechanic_automation.php Block 1 sends estimate, sets Sent(206)
  → Cron: Block 1a picks up Accepted(207) → creates Job(42, stage=Accepted)
  → Rest of Job automation unchanged (scheduling, reminders, invoicing, follow-up)
```

**Step 3: Commit memory updates**

No git commit needed — memory files are not in the repo.

---

## Summary of Changes

| File | Change | Risk |
|------|--------|------|
| `smartcall.php` | Add 3 new functions (no existing code changed) | Low — additive only |
| `recording_processor.php` | Add 1 new function + rewrite 2 code blocks | Medium — changes live pipeline |
| `mechanic_automation.php` | Rewrite Block 1 + add Block 1a | Medium — changes live cron |

### What stays the same
- `smartcall_create_job()` — kept, now called by automation Block 1a
- `update_job_with_details()` — kept, usable for future direct job updates
- All existing Job automation (Blocks 1b-8) — unchanged, still operate on entity 42
- Customer + Vehicle lookup/creation — unchanged
- EstimateEngine — unchanged
- All SignalWire/OpenAI integrations — unchanged

### What's new
- Estimates (53) are the first CRM record created from a call
- Leads (25) are created for ALL calls (not just mechanic)
- Jobs (42) are only created when an Estimate is accepted
- Non-mechanic calls get a Lead only (no Estimate, no Job)
