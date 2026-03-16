# Insight: Add Command Reference to CRM Dashboard

## What
Put the two key commands front and center on the CRM dashboard so Kyle sees them every time he opens the CRM.

## Commands to Display
**Instigate Insight** (Rule 8) — Say "instigate" to save an idea for later.
Claude captures full context, writes a primer file to the right project folder, creates a CRM Insight record pointing to it. Launchable anytime.

**Spawn [task]** (Rule 14) — Say "spawn [task]" to launch a session right now.
Claude figures out the right directory, writes a primer, opens a terminal. Examples: spawn keyword research, spawn fix sod.company, spawn legal.

## Technical Notes
- iPages table (app_ext_ipages) is currently empty — 3.6.4 upgrade may have moved things
- Sidebar references iPages (id=1 AI Chat, id=6 Mission Control, id=7 Quick Estimate, id=8 Pipeline) but table shows 0 rows — investigate
- Dashboard pages table (app_dashboard_pages) also empty
- May need to recreate iPages or use dashboard_pages to add a command reference card
- Keep it simple — just a styled HTML card with the two commands

## To Resume
```bash
cd /var/www/ezlead-hq
claude
# Then: "Read /var/www/ezlead-hq/insights/dashboard-command-reference.md and do what it says."
```
