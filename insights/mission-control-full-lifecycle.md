# Insight: Mission Control — Full Lifecycle System

## The Vision
CRM Entity 35 (Insights) becomes Mission Control. Every instigated insight has a lifecycle:
Instigated → Active → Completed (or back to Instigated if not done).

Kyle opens CRM, sees everything that needs doing, clicks one, works on it, shuts it down. Nothing to remember.

## Status Lifecycle
1. **Instigated** — saved in queue, primer written, ready to spawn
2. **Active** — Kyle clicked it, session spawned, work in progress
3. **Completed** — "Shut her down" + confirmed done → archived
4. **Back to queue** — "Shut her down" + not done → status returns to Instigated

## Categories (dropdown — the 7 branches/trunks)
- Mechanic Business
- Lead Gen / Websites
- Legal
- CRM / Infrastructure
- Money In/Out
- Family Time
- Ready to Move

## Sub-categories (nested under Mechanic Business as example)
The mechanic pipeline stages — these are the "leaves" on the Mechanic branch:
- Call / Voicemail Transcription
- Estimate Generated
- Lead Created in CRM
- Estimate Sent to Customer
- Customer Accepts
- Smart Scheduling
- 24-Hour Reminder
- Kyle Does the Work
- Tap to Pay (→ also links to Money In/Out)
- Review Request Email

## What Needs Building

### Entity 35 field changes:
- Add STATUS dropdown: Instigated / Active / Completed
- Add CATEGORY dropdown: the 7 branches above
- Add LOCATION field: working directory path
- Add PRIMER PATH field: path to the primer file
- Existing field_319 (Insight text) stays as the summary
- Existing field_320 (Category) may already be usable — check current choices

### Checkout script update (crm_checkout.php):
- When "shut her down" runs, check if there's an Active insight linked to this session
- If yes, prompt: "Did you complete [insight name]?"
  - Yes → set status to Completed
  - No → set status back to Instigated

### Mission Control iPage:
- Shows all Instigated insights grouped by category
- Color coded by branch (mechanic=yellow, legal=red, infrastructure=green, etc.)gggggggggggggggggggggggggggggggggggggggggggggggggggg😀😀😀
The red, yellow, green labeling is graded. Shit how is that graded? I can't remember how we, isgg *performatted ck Preformatted Text this note from today or is this note from when we built this?😀🚩gggggggggggggggggggggggggggggggggggggggggggggggggggg
- Clickable — clicking one:
  1. Changes status to Active
  2. Spawns a primed terminal in the insight's working directory
  3. Loads the primer file

### Checkin script update (crm_checkin.php):
- When a session starts, if it was spawned from an insight, link the session to that insight
- Set insight status to Active

## The Flow (user experience)
1. Mid-session: Kyle says "instigate" → insight saved, status=Instigated, appears in Mission Control
2. Later: Kyle opens CRM → Mission Control → sees all branches with pending work
3. Kyle clicks "OpenContracts corpus upload" → status=Active → terminal opens in ~/Desktop/pro se/ → Claude loads primer
4. Kyle works on it
5. Kyle says "shut her down" → Claude asks "Did you complete OpenContracts corpus upload?"
   - Yes → Completed, off the board
   - No → back to Instigated, stays on the board for next time

## Key Principle
Kyle never has to remember anything. It's all in the CRM. Open it, pick what to work on, go.

## Location
/var/www/ezlead-hq/ (CRM infrastructure) 

## To Resume
```bash
cd /var/www/ezlead-hq
claude
# Then: "Read /var/www/ezlead-hq/insights/mission-control-full-lifecycle.md and build it."
```





