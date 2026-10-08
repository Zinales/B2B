# B2B Wholesale System — Core (`wb-core`) 0.3.1

The engine for a B2B wholesale business. Business data in JetEngine CCTs
(`wp_jet_cct_wb_*`), engine records in plugin tables (`wp_wb_*`), settings in `wp_options`
(`wb_*`), files off the database. The data model is
[`System Integrity Framework/DATA-ARCHITECTURE.md`](../../System%20Integrity%20Framework/DATA-ARCHITECTURE.md)
(in the project root, two folders up); this plugin follows it, and the few additions are listed below.

Working name only. Code prefix `wb_`, classes `WB_*`, text domain `wb`. Pick the real name before
the first live tenant (table names would need a one-time migration after that).

## Install
1. Upload and activate. Activation creates the engine tables, the roles, the private file folder
   and the default options (it never overwrites existing options).
2. **Settings → Permalinks**: anything except "Plain" (the screens live at `/workspace/…`).
3. Open `/workspace/setup/` signed in as an administrator. Under **Business tables**, click
   *Create the missing tables*: every JetEngine content type in `schema/wb-ccts.json` is created
   (JetEngine's Custom Content Types module must be switched on). Then work down the checklist.
4. `wp-config.php`: `define( 'WB_ENCRYPTION_KEY', '<bin2hex(random_bytes(32))>' );` — escrow it with
   the backups; encrypted fields (supplier bank details, staff ID numbers) cannot be read without it.
5. nginx: `location ^~ /wp-content/wb-private { deny all; }` (Apache uses the shipped `.htaccess`).
6. Real cron: `define( 'DISABLE_WP_CRON', true );` and call `wp-cron.php` every 5 minutes. The
   nightly run is at 02:00 site time.
7. Give each staff login a `wb_staff` record (`wp_user_id`). Approvals, adjustments and stocktakes
   carry staff names, so a login without a staff record cannot approve anything (fail closed).

No pages need creating. The plugin serves `/workspace/` (Today), `/workspace/<screen>/` and
`/portal/` itself, with its own frame (`WB_Workspace`, `assets/wb-workspace.css`). The screens are
listed in `WB_Workspace::SCREENS`; the `dashboards/*.html` files are only a reference for putting the
same shortcodes on ordinary pages.

## The rules the code enforces
| Rule | Where |
|---|---|
| Every mutation is ledgered (hash-chained, verified nightly, a break notifies the owner) | `WB_Ledger`, `wb_ledger_write()`; `WB_CCT` ledgers every insert/update |
| Capabilities, never role names; administrators keep every cap | `WB_Roles`; every engine entry point checks its cap |
| Column-safe writes; fail closed on a missing table or column | `WB_CCT` (`array_intersect_key` against cached `SHOW COLUMNS`) |
| Nothing hard-deleted (`record_status`) | `WB_CCT::set_status()`; no delete method exists (demo wipe is the documented exception) |
| Numbered documents are immutable; numbering is gapless | `WB_Sequences::issue()` takes the number and writes the document in one transaction |
| Stock is a ledger | `WB_Stock` — levels are sums of `wb_stock_movements`; `WB_CCT` refuses to update that table |
| Two names on adjustments, write-offs, stocktakes, credit notes, below-floor prices, timesheets, leave | each engine checks the approver is a different person and is the one recording the approval |
| Prices frozen onto lines | `WB_Orders::insert_quote_line()` → order lines → invoice `lines_json` |
| No automatic emails | `WB_Notifications` is in-app; a group emails only if `wb_notify_<group>_email` is switched on. Quotes, invoices, datasheet links are never sent by the system |

## Engines
- `WB_Pricing` — check one (product rule → category rule up the tree → tier → list) and check two
  (floor = cost × (1 + margin), ceiling = list, validity window). Below-floor, out-of-date and
  no-cost lines need a `wb_pricing_approvals` row decided by someone else.
- `WB_Orders` — quotes (draft → sent → converted/declined/expired), acceptance by single-use
  7-day link (`/quote-accept`, GET only shows the page) or by staff, the §7 state machine,
  `may_release()` (terms + credit limit + payment + no overdue), delivery/collection notes
  (sale movements + reservation release inside the DN's numbering transaction), close, cancel.
- `WB_Invoices` — issue (VAT frozen), derived status (issued / part_paid / paid / overdue /
  credited), payments applied, credit notes requested by one person and approved (and numbered)
  by another; returns put stock back.
- `WB_Payments` — per-bank CSV mapping, de-duplicated by line hash, reference match → matched;
  one exact-amount candidate → *suggested* only; anything ambiguous stays unmatched with a note.
- `WB_Stock` — movements, two-person adjustments (`wp_wb_stock_requests`), reorder alerts,
  purchase orders and receiving, stocktakes (counter + different checker).
- `WB_Demand` — per customer × product rhythm and confidence, seasonal index, journey stage,
  13-week cashflow (kept nightly as snapshots).
- `WB_Staff` — hours, SA public holidays, working days, BCEA leave balances, KPIs, reviews.
- `WB_Integrity` — monthly report by person (§8).
- `WB_Setup` — the `wb_brand` option (identity, colours, fonts), contrast
  checks that refuse an unreadable pair, and the first-run checklist.
- `WB_Payroll` — pay runs drafted, checked by a different person and finalised; PAYE, UIF, SDL,
  medical credits and retirement from the tax-year settings; payslips stored as HTML; EMP201
  figures and a net-pay bank file for the owner. Feeds the cashflow wages line.
- `WB_Portal` — the customer portal, scoped to the login's own company and failing closed.

## Additions to [DATA-ARCHITECTURE.md](../../System%20Integrity%20Framework/DATA-ARCHITECTURE.md) (decide whether to adopt them in the spec)
- **Engine table `wb_stock_requests`** — the first of the two names on an adjustment/write-off,
  held until a second person approves. A movement can't be "pending" because movements are append-only.
- **Extension columns** (marked `"extension": true` in the schema): `wb_invoices.amount_credited`,
  `lines_json`; `wb_credit_notes.status`, `requested_by_staff_id`, `customer_id`, `approved_at`,
  `return_stock`, `payment_id`; `wb_payments.line_hash`, `amount_allocated`,
  `suggested_invoice_id`, `match_note`; `wb_order_lines` copies of the quote line (product,
  description, prices, `quote_line_id`, `order_id`); `wb_orders` totals + `cancel_reason`;
  `wb_quote_lines.list_price`, `cost_price`, `out_of_date`, `approval_id`; `wb_quotes.sent_at`;
  `wb_stocktakes.lines_json`, `location`; `wb_delivery_notes.issued_by_staff_id`;
  `wb_leave_types.code`, `cycle_months`; `wb_staff.days_per_week`; `wb_timesheets.query_note`;
  `wb_kpis.role_key`, `staff_id`; `wb_purchase_orders.total`, `notes`; `wb_statements`
  number/customer/balance; `wb_staff_notes.written_by_staff_id`.
- **Capability `wb_portal`** for the `wb_customer` role (the brief's list had no portal cap).
- **Option `wb_cashflow`** (`payroll_monthly`, `opening_balance`). Since 0.2.0 the forecast's wages
  line comes from the active payroll profiles (gross pay plus employer UIF and SDL, spread by week,
  `WB_Payroll::monthly_cost()`). `payroll_monthly` is the fallback, used only when there are no
  payroll profiles yet.
- `wb_leave_types.days_per_year` means *days per cycle* when `cycle_months` is not 12 (sick leave: 30 per 36 months).

## New in 0.2.0
| Area | Shortcode | Who can use it |
|---|---|---|
| Setup and branding | `[wb_setup]` | *Manage settings* |
| Bank CSV import and mapping | on the payments screen | *Import bank statements* |
| Customer portal | `[wb_portal]` (the whole portal on one page, as `dashboards/portal.html` uses it), or the parts on separate pages: `[wb_portal_home]`, `[wb_portal_quotes]`, `[wb_portal_invoices]`, `[wb_portal_datasheets]`, `[wb_portal_request]`, `[wb_portal_details]` | the *Customer* role, scoped to its own company |
| Payroll | `[wb_payroll]` | *Run payroll*, *Check payroll* (a different person), *View payroll*; staff see only their own payslips |

Payroll tax figures are seeded from [`docs/PAYROLL-RULES-2027.md`](../../docs/PAYROLL-RULES-2027.md)
(project root) and must be refreshed each March. New capabilities are owner and administrator only by default; payroll is not given to
managers unless the owner ticks it.

Notifications: most staff dashboards show `[wb_notify_bar]` (the newest few, unread only).
`[wb_notifications]` is the full list with *mark as read*, and can go on any dashboard page
(for example under `[wb_home]`); none of the shipped pages uses it yet.

## Known limits in 0.2.0
- No PDFs are generated for quotes, invoices, credit notes or delivery notes yet (`pdf_key`
  columns are ready; the download route serves any filed document).
- Payslips are stored as self-contained HTML pages, not PDF. PDF payslips come later.
- IRP5 certificates and the EMP501 reconciliation figures are not built yet; the owner prepares
  them from the finalised payslips. Nothing is ever submitted to SARS.
- Storage driver is `local` only; the R2 driver can be added behind `wb_storage_driver` without schema change.
- Screens are deliberately thin; the engines carry the rules.
- The nightly cashflow forecast reads up to 5,000 customers and 5,000 invoices of each status.

## Tests
`php tests/regress-frame.php` — the workspace frame rendered for every screen and every kind of
login with WordPress stood in for (1,105 checks). Catches what pure-function tests cannot: a PHP
fatal in the frame, a blank refusal, a menu item or "Next" link the login cannot open.

`php tests/selftest.php` — 288 assertions over the pure maths (payroll, setup colours and
contrast, bank CSV parsing, pricing, demand, seasonality,
cashflow, leave and holidays, KPIs, ledger chain, invoice status, release gate, state machine,
stock rules, statement parsing and matching, numbering). Stubs only `ABSPATH` and, when the CLI
lacks mbstring, `mb_substr`.
