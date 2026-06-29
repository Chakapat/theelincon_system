# Impeccable rollout progress

Updated: 2026-06-01 — phase 2 deep polish

## Done

| Phase | Pages | Status |
|-------|-------|--------|
| Core | `index.php`, `sign-in.php`, `components/navbar.php` | Copper tokens, tnc-app.css |
| Global CSS | `assets/css/tnc-app.css` | Utilities, canvas, btn-orange, title icon copper override, tnc-sticky-bar |
| Purchase print | PO/PR view, batch-print, PR list | Multi-page A4 via `document-print.css` |
| Batch pass | 44 pages via `scripts/tnc-polish-pages.php` | tnc-app-body, #ea580c, btn-orange |
| Organization | `customer-manage`, `company-manage`, `sites`, `member-manage` | tnc-page-head |
| Invoices | `invoice-create`, `tax-invoice-list` | No glass modals/bars; copper rgba; tnc-page-head |
| Cash ledger | `cash-ledger-dashboard`, `cash-ledger-site-expenses` | tnc-page-head; master-* redirect only |
| Leave | `leave-request-list`, `create`, `view` | tnc-page-head; copper leave numbers |
| Payslips | `employee-payslip-form`, `request-list`, `my` | tnc-page-head |
| DSR | `daily-site-report-calendar`, `monthly-report` | tnc-page-head; solid sticky actions bar |
| Stock | `stock-list` (site picker + dashboard headers) | tnc-page-head; btn-outline-orange |
| Reports | `vat-report`, `site-spending-report` | tnc-page-head, copper tokens, no gradient stat pills |

## Remaining (lighter pass)

- `stock-adjust.php`, `stock-product-form.php` — small h5 headers (global CSS covers icon color)
- `tools/cement-volume-calculator.php` — page head
- `internal/audit-log.php` — list page header
- SweetAlert `confirmButtonColor` — batch script optional pass
- Per-page `btn-outline-primary` on tables (leave list view btn, payslip links) — low priority

## Re-run batch after new pages

```bash
php scripts/tnc-polish-pages.php
php scripts/tnc-polish-pages.php --dry-run
```

## Per-page deep polish (Impeccable)

```
/impeccable polish pages/internal/audit-log.php
```
