# Insight: Mission Control IS Entity 35 (Insights)

## The Idea
Don't build a separate Mission Control dashboard. CRM entity 35 (Insights) already IS Mission Control.
Each insight record is a saved context — a branch of work that can be resumed with one click.

## How It Works
1. Mid-session, Kyle says "insight" or Rule 7 flags a lane change
2. Claude writes a primer file to the correct working directory's insights/ folder
3. Claude creates a CRM record (entity 35) with:
   - Insight summary
   - Category (color = business area)
   - File path to the primer
   - Clickable link that launches a primed terminal session
4. Kyle opens CRM → Insights → sees all active threads across all businesses
5. Clicks one → terminal opens in the right directory → Claude loads the primer → picks up with full context

## What This Replaces
- The D3.js tree dashboard idea (Feb 24 vision) — same concept, simpler implementation
- Random notes scattered across CLAUDE.md files — everything funnels to CRM
- Lost context between sessions — every idea is a resumable session

## What Needs Building
- Entity 35 field for file path (or use the existing insight text field)
- Entity 35 field for working directory
- A small PHP script or CRM plugin that generates the terminal launch command from the record
- Optionally: clickable link in the CRM listing that triggers the launch
- Color coding by category already exists in Rukovoditel — just map categories to business areas

## Working Directory
/var/www/ezlead-hq/ (CRM infrastructure)

## Priority
This is the "trunk" of the whole system. Everything else branches from this.

## To Resume This Work
```bash
cd /var/www/ezlead-hq
claude
# Then: "Read /var/www/ezlead-hq/insights/mission-control-is-insights.md and build it."
```
