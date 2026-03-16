# Ez Mobile Mechanic - CRM Workflow Guide

## How Jobs Move Through the System

Every mechanic job goes through these stages. Some transitions are **automatic** (the cron job handles them), some are **manual** (you change the stage yourself).

---

## The 11 Stages

### 1. New Lead
**How it gets here:** Phone call comes in → voicemail transcribed → auto-creates job
**What's in it:** Customer name, phone, address, vehicle info, problem description
**What happens automatically:** AI generates a repair estimate (labor hours, parts cost, total range)
**What YOU do:** Review the estimate, tweak if needed
**Next step:** Automation moves it to "Estimate Sent" and emails the customer

### 2. Estimate Sent
**How it gets here:** Automation sends estimate email to customer
**What happens automatically:** Email sent with estimate details
**What YOU do:** Wait for customer to respond. If they call back and accept, change stage to "Accepted"
**Next step:** YOU manually change to "Accepted"

### 3. Accepted
**How it gets here:** YOU change it when customer approves the estimate
**What YOU do:** Schedule the appointment - set the Appointment date/time field
**Next step:** YOU manually change to "Scheduled"

### 4. Scheduled
**How it gets here:** YOU set it after booking the appointment
**What YOU do:** Order any parts needed, fill in "Parts to Order" field
**Automation:** 24 hours before appointment → sends reminder email → moves to "Confirmed"
**Next step:** Automation moves to "Confirmed" (or you can move to "Parts Ordered" first if parts needed)

### 5. Parts Ordered
**How it gets here:** YOU set it when parts are on the way
**What YOU do:** Update Parts Status and Parts ETA fields
**Next step:** YOU manually change to "Confirmed" when ready

### 6. Confirmed
**How it gets here:** Automation (24hr reminder) or YOU set it manually
**What YOU do:** Show up and do the work
**Next step:** YOU manually change to "In Progress" when you arrive

### 7. In Progress
**How it gets here:** YOU set it when you start working
**What YOU do:** Do the repair
**Next step:** YOU manually change to "Complete" when done

### 8. Complete
**How it gets here:** YOU set it when the job is finished
**What happens automatically:**
- Generates invoice with total
- Creates Stripe payment link
- Emails invoice + payment link to customer
**What YOU do:** Collect payment (cash, card, or Stripe link)
**Next step:** YOU manually change to "Paid" after payment received

### 9. Paid
**How it gets here:** YOU set it after payment collected
**What happens automatically:** 1 day later → queues check-in text ("How's everything? Any concerns?") for YOUR approval → moves to "Follow Up"
**What YOU do:** Approve or deny the check-in message (reply A or D to the text you get)

### 10. Follow Up
**How it gets here:** Automation (1 day after paid, after you approve check-in)
**What happens automatically:** 1 day later → queues review request for YOUR approval → moves to "Review Request"
**What YOU do:** Approve or deny the review request

### 11. Review Request
**How it gets here:** Automation (1 day after check-in)
**What happens:** Final stage. Customer gets Google review link (after you approve).
**Job is done.**

---

## Step-by-Step: Managing a Job in the CRM

### Viewing Jobs
1. Log into https://ezlead4u.com/crm/
2. Click **Mechanic Jobs** in the left menu
3. You'll see all jobs listed with their current stage

### Editing a Job
1. Click on the job name to open it
2. Click the blue **Edit** button
3. Change fields as needed:
   - **Stage** dropdown - move to next stage
   - **Appointment** - click calendar icon, pick date and time
   - **Parts to Order** - list what you need
   - **Notes** - add any notes
4. Click **Save** at the bottom

### Creating a New Job Manually
1. Go to Mechanic Jobs
2. Click **+ Add New Record** (or the + button)
3. Fill in: Customer Name, Phone, Address, Year, Make, Model, Problem
4. Set Stage to "New Lead"
5. Save

### Checking Diagnostics
1. Open a job
2. Click the **Diagnostics** tab
3. Create a diagnostic record if needed
4. When diagnostic status is set to "Complete" → automation generates estimate + PDF + emails customer

---

## What's Automated (Cron Job)

The automation script runs every 5 minutes:
`/var/www/ezlead-hq/crm/plugins/claude/mechanic_automation.php`

| Trigger | Action |
|---------|--------|
| New Lead with no estimate | AI generates estimate, queues for Kyle's approval |
| Scheduled + 24hrs before appointment | Queues reminder for Kyle's approval, moves to Confirmed |
| Complete | Generates invoice + payment link, queues for Kyle's approval |
| Paid + 1 day | Queues check-in text for Kyle's approval, moves to Follow Up |
| Follow Up + 1 day | Queues review request for Kyle's approval, moves to Review Request |
| Estimate Sent + 2 days, no response | Queues nudge text for Kyle's approval |
| Diagnostic Complete | Generates estimate + PDF, queues for Kyle's approval |

---

## Quick Reference

| Stage | Who Changes It | Automation |
|-------|---------------|------------|
| New Lead | Auto (phone pipeline) | AI estimate → queued for approval |
| Estimate Sent | Auto | Nudge after 2 days if no response |
| Accepted | YOU | Scheduling slots sent (queued) |
| Scheduled | YOU | 24hr reminder (queued) |
| Parts Ordered | YOU (optional) | - |
| Confirmed | Auto or YOU | - |
| In Progress | YOU | - |
| Complete | YOU | Invoice + payment link (queued) |
| Paid | YOU | Check-in in 1 day (queued) |
| Follow Up | Auto | Review request in 1 day (queued) |
| Review Request | Auto | Final stage |

**ALL customer messages are queued for your approval.** You get a text preview and reply:
- `A 5` = approve and send message #5
- `D 5` = deny message #5

---

## Important Links

- **CRM:** https://ezlead4u.com/crm/
- **Mechanic Jobs:** https://ezlead4u.com/crm/index.php?module=items/items&path=42
- **Google Reviews:** https://g.page/r/CQepHCWnvxq4EAE/review
- **Automation Log:** /home/kylewee/logs/mechanic.log

---

*Last updated: Mar 5, 2026*
