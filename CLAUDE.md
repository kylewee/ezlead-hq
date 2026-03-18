# EzLead HQ — CRM + Multi-Site Platform

## Architecture

Multi-tenant PHP platform. `config/bootstrap.php` detects domain, loads site config. One codebase, different branding/phone/CRM per site.

| Directory | Purpose |
|-----------|---------|
| `/var/www/master-template` | Canonical multi-tenant codebase |
| `/var/www/ezlead-platform` | Hybrid deployment (mechanic sites) |
| `/var/www/ezlead-hq` | Admin/HQ + CRM (Rukovoditel) |

## CRM (Rukovoditel 3.6.4)

Cheatsheets for all CRM internals: `crm/plugins/claude/docs/cheatsheets/`
Full field map: `crm/plugins/claude/CLAUDE.md`

### Rules
- **API for data, MySQL for admin.** API for entity CRUD. MySQL for kanban config, menu items, field creation, reports, highlight rules.
- **Don't edit CRM settings through the browser.** API or MySQL for config. Browser for everything else.
- Integer fields need `0` not `''` (MySQL strict mode)
- Date fields = Unix timestamps, not `NOW()`
- `forms_tabs_id=0` = invisible in API
- Dropdown fields store choice IDs (integers), not text

## Phone-to-CRM Pipeline

```
Call -> SignalWire -> Forward -> No answer -> Voicemail -> Whisper -> GPT extract
  -> Customer(47) + Vehicle(48) + Estimate(53) + Lead(25)
  -> Cron: mechanic_automation.php sends estimate, tracks stages, invoices
```

## Key Files

| File | Purpose |
|------|---------|
| `crm/plugins/claude/mechanic_automation.php` | 11-stage job workflow (5-min cron) |
| `crm/plugins/claude/CLAUDE.md` | Full entity/field/choice map |
| `crm/plugins/claude/docs/cheatsheets/` | 8 Rukovoditel admin cheatsheets |
| `/var/www/master-template/voice/recording_processor.php` | Transcribe + extract + estimate + CRM |
| `/var/www/master-template/api/form-submit.php` | Website forms -> CRM leads |
| `core/lib/EstimateEngine.php` | Parts catalog -> Mitchell1 -> GPT fallback |
| `core/lib/PDFGenerator.php` | Branded PDFs (estimate, invoice, diagnostic) |

## EstimateEngine

Input: Year/Make/Model/Problem. Chain: catalog -> Mitchell1 API -> GPT-4o fallback. Labor: $150 first hour, $100/hr after.

## SignalWire

Phone numbers per site, call forwarding with timeout. Use .wav not .mp3 (403 errors). Ported numbers need voice_url configured separately. SMS needs separate webhook from voice.

## Domains

| Domain | Web Root | CRM ID |
|--------|----------|--------|
| mechanicstaugustine.com | /var/www/ezlead-platform/ | 8 |
| mobilemechanic.best | /var/www/ezlead-platform/ | 14 |
| ezlead4u.com | /var/www/ezlead-hq/ | 9 |
| sodjax.com | /var/www/sodjax.com/ | 4 |
| sodjacksonville.com | /var/www/sodjacksonville.com/ | 5 |
| sodjacksonvillefl.com | /var/www/sodjacksonvillefl.com/ | 6 |
| sod.company | /var/www/sod.company.new/ | 7 |
| nearby.contractors | /var/www/nearby.contractors/ | 12 |
| drainagejax.com | /var/www/drainagejax.com/ | 10 |
| kyle.weerts.us | /var/www/kyle.weerts.us/ | 13 |

Check this list for ALL site-wide tasks. sod.company and nearby.contractors get forgotten.
