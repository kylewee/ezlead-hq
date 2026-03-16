# EzLead Platform

Multi-site PHP platform serving mechanicstaugustine.com and mobilemechanic.best.
Master-template codebase at /var/www/master-template/.

## Phone-to-CRM Pipeline

```
Incoming Call -> SignalWire -> Forward to Kyle -> No answer -> Voicemail
-> OpenAI Whisper transcription -> GPT extracts customer data
-> Auto-generate repair estimate (mechanic sites)
-> Create CRM lead (entity 42) -> Distribute to buyers
```

## Key Files

| File | Purpose |
|------|---------|
| `core/lib/EstimateEngine.php` | Estimates: catalog -> Mitchell1 -> GPT fallback |
| `core/lib/PDFGenerator.php` | Branded PDFs (estimate, invoice, diagnostic) |
| `/var/www/master-template/voice/incoming.php` | Incoming call handler |
| `/var/www/master-template/voice/recording_processor.php` | Transcribe + extract + estimate + CRM |
| `/var/www/master-template/api/form-submit.php` | Website forms -> CRM leads |
| `/var/www/master-template/buyer/LeadDistributor.php` | Bills buyers, sends notifications |
| `/var/www/master-template/config/bootstrap.php` | Multi-site domain detection |
| `/var/www/master-template/get-estimate.php` | Customer estimate form |

## Multi-Site Architecture

`config/bootstrap.php` detects domain and loads site-specific config.
Same codebase, different branding/phone/CRM config per site.

## EstimateEngine

- Input: Year, Make, Model, Problem
- Chain: Parts catalog -> Mitchell1 API -> GPT-4o fallback
- Labor rate: $150 first hour, $100/hr after
- Mitchell1 cookie: Chrome extension -> mobilemechanic.best/api/mitchell1-cookie.php

## Lead Buyer System

Prepaid accounts, per-domain campaigns, daily/weekly caps, auto-charge, email + SMS notifications.

## SignalWire

- Phone numbers per site, call forwarding with timeout
- Recording: use .wav (not .mp3 - 403 errors)
- Ported numbers need voice_url configured separately
- SMS needs separate webhook handling from voice

## Video Chat

`/var/www/ezlead-platform/video/` - WebRTC/PeerJS.
Served at mechanicstaugustine.com/video/ and mobilemechanic.best/video/.

## Project Locations

| Directory | Purpose |
|-----------|---------|
| `/var/www/master-template` | Canonical multi-tenant codebase |
| `/var/www/ezlead-platform` | This directory - hybrid deployment |
| `/var/www/ezlead-hq` | Admin/HQ + CRM |
| `/var/www/inbred` | Separate estimation automation project |

## Website Conversion Checklist

The 5 things that make service sites convert:
1. Trust in 3 seconds - Hero with headline, trust badges, real photo
2. Easy contact - Phone visible, form above fold, multiple contact methods
3. Proof it works - Before/after photos, reviews with names, years in business
4. Answer objections - FAQ section, pricing transparency, "why us"
5. CTAs everywhere - "Get Free Quote" buttons, not just once

## CRM Rules

- **API for data, MySQL for admin.** Use the REST API for entity CRUD. Use MySQL for admin tasks the API can't do (kanban config, menu items, field creation, report setup, highlight rules).
- **Don't edit CRM settings through the browser.** Use the API or MySQL for configuration. Browser is fine for everything else.

## CRM API Gotchas

- Integer fields need `0` not `''` for empty values (MySQL strict mode)
- Date fields = Unix timestamps. Use `UNIX_TIMESTAMP()` not `NOW()`
- Fields with `forms_tabs_id=0` are invisible in API
- Dropdown fields store choice IDs (integers), not display text

## Domains

| Domain | Web Root | CRM ID |
|--------|----------|--------|
| sodjax.com | /var/www/sodjax.com/ | 4 |
| sodjacksonville.com | /var/www/sodjacksonville.com/ | 5 |
| sodjacksonvillefl.com | /var/www/sodjacksonvillefl.com/ | 6 |
| sod.company | /var/www/sod.company.new/ | 7 |
| mechanicstaugustine.com | /var/www/ezlead-platform/ | 8 |
| mobilemechanic.best | /var/www/ezlead-platform/ | 14 |
| nearby.contractors | /var/www/nearby.contractors/ | 12 |
| ezlead4u.com | /var/www/ezlead-hq/ | 9 |
| drainagejax.com | /var/www/drainagejax.com/ | 10 |
| kyle.weerts.us | /var/www/kyle.weerts.us/ | 13 |
| mobilemechanic.best | /var/www/mobilemechanic.best/ | 14 |

Check this list for ALL site-wide tasks. sod.company and nearby.contractors get forgotten.
