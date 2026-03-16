# Estimate Pipeline v2 - Design Doc

**Date:** 2026-02-27
**Problem:** M1 API can't match hybrid/special models (missing submodel), GPT fallback gives garbage estimates, no approval step before customer receives estimate, no way to tell data source quality.
**Trigger:** Real job failure — 2013 Buick Regal eAssist Hybrid 2.4L belt replacement returned zero results from M1, customer got a generic "1 hr, $50-200 parts" GPT estimate.

## 1. M1 Vehicle Resolution

### Problem
`Mitchell1Client::getEstimate()` sends `model=Regal` with no `submodel` or minimal `engine` data. M1's API needs the full 5-step vehicle spec to return results for trims like eAssist/Hybrid.

### Solution
Add `resolveVehicle()` to `Mitchell1Client` that walks M1's 5-step vehicle selector cascade:

```
Year → Make → Model → Engine → Submodel
2013 → Buick → Regal → 2.4   → Hybrid
```

**Steps:**
1. Parse raw input — extract model name vs submodel hints (e.g., "Regal eAssist Hybrid 2.4L" → model="Regal", engine_hint="2.4", submodel_hints=["eAssist", "Hybrid"])
2. Query M1 vehicle selector API for available engines given year/make/model
3. Match best engine from available options using the engine hint
4. Query M1 for available submodels given year/make/model/engine
5. Match best submodel from available options using submodel hints
6. Return fully-populated vehicle array: `{year, make, model, engine, submodel, engineCode, fuelType, ...}`

**Matching strategy:** Fuzzy match — normalize strings, check for substring containment. "eAssist" matches "Hybrid" because we check available M1 options, not the input string. If only one submodel exists, use it without matching.

**Cache:** Cache resolved vehicles in a JSON file (`/tmp/m1_vehicle_cache.json`) keyed by `year|make|model|engine`. Same vehicle doesn't need re-resolution on every cron run.

### Files Changed
- `/home/kylewee/mitchell1/Mitchell1Client.php` — add `resolveVehicle()`, vehicle selector API methods
- `/var/www/ezlead-platform/core/lib/EstimateEngine.php` — call `resolveVehicle()` before `getEstimate()`

## 2. PartsTech Integration (Parts Pricing)

### Problem
M1 returns OEM dealer list prices. Current code applies 40-80% discount to estimate aftermarket cost. PartsTech has real aftermarket prices from actual suppliers.

### Solution
Add `PartsTechClient` class that queries PartsTech API for real aftermarket parts pricing.

**Credentials:**
- Account: sodjacksonville@gmail.com
- API Key: c522bfbb64174741b59c3a4681db7558

**Usage in pipeline:**
- PartsTech is the PRIMARY parts pricing source (always preferred over M1 OEM math)
- Input: year, make, model, engine, part/component name from M1 labor results
- Output: aftermarket part prices (low/high from available suppliers)
- Fallback: if PartsTech returns nothing, use M1 OEM→aftermarket conversion (existing 40-80% logic)

### Files Changed
- `/home/kylewee/mitchell1/PartsTechClient.php` — new file, PartsTech API wrapper
- `/var/www/ezlead-platform/core/lib/EstimateEngine.php` — integrate PartsTech after M1 labor lookup

## 3. Labor Rate Fallbacks (charm.li + Chilton)

### Problem
When M1 can't match a vehicle at all (even after resolution), GPT gives "1 hr labor" for everything.

### Solution
Add two labor data fallback sources before GPT:

**charm.li (Operation CHARM) — vehicles up to ~2013:**
- Free structured repair manuals with labor times
- URL pattern: `charm.li/{Make}/{Year}/{Model}%20{Engine}/Parts%20and%20Labor/`
- Scrape labor time pages for the specific repair category
- Example: `charm.li/Buick/2013/Regal%20L4-2.4L/Parts%20and%20Labor/Engine.../Labor%20Times/`

**Chilton via Multnomah County Library — broader coverage:**
- Access via library proxy: `proxy.multcolib.org/login?url=https://link.gale.com/apps/CHLL?u=multnomah_main`
- Credentials: card 971783, PIN rainonin
- Covers more recent vehicles than charm.li
- Scrape labor time data from Chilton's web interface

**Priority chain for labor:**
1. M1 ProDemand (with vehicle resolution)
2. charm.li (if vehicle year ≤ 2013)
3. Chilton via library (any year)
4. GPT with improved prompt (last resort, with low-confidence flag)

### Files Changed
- `/home/kylewee/mitchell1/CharmClient.php` — new file, charm.li scraper
- `/home/kylewee/mitchell1/ChiltonClient.php` — new file, Chilton library scraper
- `/var/www/ezlead-platform/core/lib/EstimateEngine.php` — integrate fallback chain

## 4. Approval Workflow

### Problem
Estimates auto-send to customers without review. No opportunity to classify leads or catch bad estimates.

### Solution
Add a Kyle-reviews-first step to the estimate pipeline.

**New estimate statuses (entity 53, field 519):**

| Choice ID | Status | Description |
|-----------|--------|-------------|
| 205 | Pending | Estimate being generated (existing) |
| NEW | Ready for Review | Estimate complete, waiting for Kyle |
| NEW | Approved | Kyle approved, ready to send to customer |
| 206 | Sent | Sent to customer (existing) |
| 207 | Accepted | Customer accepted (existing) |
| 208 | Declined | Customer declined (existing) |
| NEW | Rejected | Kyle rejected (bad lead, spam, etc.) |

**Flow:**
1. Estimate generates → status = "Ready for Review"
2. Cron sends estimate to Kyle (same format/content customer would get)
3. Kyle opens CRM, reviews estimate, classifies lead type
4. Kyle clicks Approve → status = "Approved"
5. Cron picks up Approved → sends to customer → status = "Sent"

**Kyle notification:** Same SMS/email template the customer would receive, sent to Kyle's number/email instead. Kyle sees exactly what the customer will see.

### Files Changed
- MySQL: add new choices to field 519 (app_fields_choices)
- `/var/www/ezlead-hq/crm/plugins/claude/mechanic_automation.php` — Block 1 changes: generate→Ready for Review→notify Kyle; new block for Approved→send to customer

## 5. Confidence Tracking

### Problem
No way to tell if an estimate came from real book time or a GPT guess.

### Solution
Add a source/confidence field to the Estimate entity (entity 53).

**New field on entity 53:**
- Field name: "Estimate Source"
- Type: Text (stores composite source string)
- Values: `m1+partstech`, `m1+oem`, `charm+partstech`, `chilton+partstech`, `gpt+partstech`, `gpt` (lowest)

`EstimateEngine::estimate()` already returns a `source` key. Extend it to track both labor and parts sources separately, then combine into a display string stored on the estimate record.

### Files Changed
- MySQL: add new field to entity 53
- `/var/www/ezlead-platform/core/lib/EstimateEngine.php` — return composite source
- `/var/www/ezlead-hq/crm/plugins/claude/mechanic_automation.php` — store source on estimate record

## 6. Improved GPT Fallback (Last Resort)

### Problem
Current GPT prompt is minimal: "You are a mobile mechanic. Estimate for: {vehicle} - {problem}. Respond ONLY with JSON."

### Solution
Improve the prompt with:
- Vehicle-specific context (engine size, common issues for that platform)
- Repair-specific context (what the job actually involves)
- Labor rate structure ($150 first hour, $100/hr after)
- Explicit instruction to NOT default to "1 hour" — estimate realistically
- Few-shot examples of known-good estimates

This is the last resort — only fires when M1, charm.li, and Chilton all fail. Will always carry a low-confidence flag.

### Files Changed
- `/var/www/ezlead-platform/core/lib/EstimateEngine.php` — rewrite `aiEstimate()` prompt

## Architecture Summary

```
Phone Call / Web Form
    ↓
recording_processor.php → creates Estimate (entity 53, status=Pending)
    ↓
mechanic_automation.php cron (every 5 min)
    ↓
┌─ Pending estimates:
│   1. Resolve vehicle via M1 selector API (5-step cascade)
│   2. Get labor hours:
│      M1 ProDemand → charm.li (≤2013) → Chilton → GPT
│   3. Get parts pricing:
│      PartsTech → M1 OEM fallback
│   4. Combine estimate, set source/confidence
│   5. Set status = "Ready for Review"
│   6. Send estimate preview to Kyle
│
├─ Ready for Review: (Kyle reviews in CRM)
│   → Kyle approves → status = "Approved"
│   → Kyle rejects → status = "Rejected"
│
├─ Approved estimates:
│   1. Send estimate to customer (same content Kyle previewed)
│   2. Set status = "Sent"
│
└─ (rest of pipeline unchanged: Sent → Accepted → Job created → etc.)
```
