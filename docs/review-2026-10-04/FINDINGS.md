# Pre-review sweep — 4 October 2026

Four read-only reviewers swept wb-core 0.2.0 and the sample before the independent check.
76 findings. IDs below are referenced by the fixes in CHANGELOG 0.2.1.
Line numbers refer to 0.2.0 and will move as fixes land.

## S — Ledger, security, data layer
- **S1 HIGH** Ledger failure does not stop the business write and is undetectable. class-wb-ledger.php:160-172 (append returns 0, error_log only), class-wb-cct.php:212/:245 ignore wb_ledger_write() return, class-wb-sequences.php:106 flush_deferred after COMMIT. Fix: on append()==0 queue the row in a non-autoloaded option wb_ledger_pending; drain at next append() and in nightly_verify(); notify owners + show on Integrity while non-empty.
- **S2 HIGH** Acceptance / 7-day links never shown: class-wb-rowactions.php:397 puts URL in `<input readonly>`, wb_notice() runs wp_kses_post which strips input. Use `<code>`/`<a>`/`<textarea readonly>`. Also "works once" wording is wrong for multi-use datasheet links.
- **S3 MED** verify_chain() misses deleted tail rows / truncated table; plain SHA-256 rewritable. Store tail {entry_id, entry_hash} in an option after each append and check it; optionally HMAC keyed off WB_ENCRYPTION_KEY.
- **S4 MED** JetEngine switchers store "true"/"false"; empty('false') is false → switched-off documents become customer-visible (class-wb-documents.php:95; screens.php:870; pricing.php:225); equality filters on 1 never match "true". Use wb_truthy() (added to wb-core.php) and filter IN ('1','true').
- **S5 MED** Demo wipe deletes real records: class-wb-demo.php:68-74/:36-38/:139 captures any before===null event, incl. the admin's own wb_staff row and the tenant's real leave types from ensure_leave_types(). Never capture the owner's staff row; call ensure_leave_types() outside the capture window; capture only an explicit created-action list.
- **S6 LOW-MED** wb_event fires inside the numbering transaction before COMMIT (ledger.php:252, sequences.php:94). Defer do_action with the entry and fire in flush_deferred.
- **S7 LOW** Lock name 'wb_ledger_'.dbname can exceed 64 chars → GET_LOCK fails → every ledger write fails. Use md5(dbname.prefix).
- **S8 LOW** Path sanitiser order (storage.php:46-50): '..' removed before character filter, so ".%./.%./" becomes "../../". Filter chars first, then drop '', '.', '..' segments.
- **S9 LOW** Stored uploads keep uploader's extension (rest.php:198-200 → payments.php:590): evil.php lands in wb-private. Whitelist extensions in WB_Storage::unguessable() else .bin; apply csv|txt|tsv check on /bank-import.
- **S10 LOW** Expired _wpnonce on download links returns raw JSON before callback. In rest_authentication_errors map rest_cookie_invalid_nonce on wb/v1/download|private|payroll-bank-file to human_page('Link expired').
- **S11 LOW** wb_users_with_cap() only considers administrators + wb_* roles (wb-core.php:154); users with dashboard ticks on other roles never get notify_cap alerts.

## M — Money and stock engines
- **M1 HIGH** One quote → two orders/invoices on double Accept (orders.php:326 status check before the ORD lock; :351 unconditional). Re-read quote FOR UPDATE inside the callback, or conditional UPDATE WHERE status='sent' and check rows affected. (Same as P6.)
- **M2 HIGH** DN can ship stock reserved for other orders (stock.php:231-234 sale checks on-hand only; orders.php:509-511; rowactions.php:62-66). Require q <= qty_reserved + max(0, available).
- **M3 HIGH** Manual matches by logins without a staff record (staff id 0) vanish from Integrity (integrity.php:80 filters matched_by_staff_id>0; payments.php:663/:691). Refuse allocate/mark_unallocated/record_receipt when staff id 0; report on match_method, not staff id.
- **M4 MED** Genuine deposits dropped as "total rows" when description starts with Total/Totaal/Saldo/Balance (payments.php:315-320, :358). Test only when amount empty, or whole-cell match.
- **M5 MED** Release gate treats a fully credited invoice as paid (orders.php:61; close :589). Only 'paid' passes.
- **M6 MED** Double-submitted DN posts the sale twice (orders.php:505-512, :534 stale qty). Lock order row inside DN callback and re-check.
- **M7 MED** Stock request approvable twice (stock.php:281/:296); same in WB_Pricing::decide (:287). Claim with conditional UPDATE … WHERE decision='pending', check rows affected.
- **M8 MED** Counter receipt can be recorded twice (payments.php:699-714 line_hash never checked); EFT recorded at counter can be re-imported from CSV. Refuse existing hash / unique index; reconcile scheme.
- **M9 MED** Money owed back after a credit note invisible (invoices.php:36-46: paid 1000, credited 200 → 'paid', outstanding −200). Flag overpaid / write an unallocated customer-credit row + notify.
- **M10 MED** Credit-note return lines not checked against invoice/delivered (invoices.php:172-178; :228-233 writes return movements; :231 ignores WP_Error). Validate product ∈ invoice lines and qty ≤ delivered − returned; check move result.
- **M11 MED** Splitting a payment overwrites invoice_id (payments.php:661-662), breaking credit-note pairing (invoices.php:215). Keep allocations as rows or a JSON list.
- **M12 MED** Races: credit-note approve outside transaction (invoices.php:208) → CRN gap; apply_payment (:139) and credit total (:223) read-modify-write; issue_for_order (:64) existence check outside lock. Re-read FOR UPDATE inside each WB_Sequences::issue callback.
- **M13 LOW** Reference-match applied to another customer's invoice / tiny partials without human check (payments.php:454). Make low-ratio partials or customer mismatch 'suggested'.
- **M14 LOW** Line-by-line credit notes can't fully credit an invoice (rounding; 3×11.62 > 34.85). Cap last credit at remaining / 1c tolerance.
- **M15 LOW** List price ceiling not enforced (pricing.php:119 above_ceiling never stored/used; orders.php refresh_quote_status:297).
- **M16 LOW** Price checks fail open if columns missing (orders.php:123 require_columns omits out_of_date, cost_price, approval_id).
- **M17 LOW** Past-due invoices count as within terms until nightly sweep (orders.php:397-398, :591). Compare due_at < wb_today().
- **M18 LOW** Bare year parsed as invoice number ("INV 2026 500.00" → INV-2026-000500) (payments.php:429).
- **M19 LOW** Unicode minus U+2212 ignored in parse_money (payments.php:158).
- **M20 LOW** Pricing self-approval by proxy (pricing.php:259 records requester not pricer). Store priced_by on the line; block decider = pricer.
- **M21 LOW** Reorder alert not re-checked after 'release' movements (stock.php:236).
- **M22 LOW** Cancel can leave a reservation (orders.php:482-483 skips failed release yet cancels).
- **M23 LOW** Integrity: count variance signed sums net to 0 (integrity.php:56,60) → use absolute; write-offs not grouped by product.

## P — Payroll, staff, portal, setup, demand
- **P1 HIGH** Editing a payroll profile wipes DOB, medical members, retirement %, UIF-exempt, dates (payroll.php:348-359 always writes; form :775-784 not prefilled). Keep when empty on edit and prefill the form.
- **P2 HIGH** No pro-rating for joiners/leavers (payroll.php:370-374, :414-416). Add unpaid working days before start / after end.
- **P3 HIGH** Overtime lost when a week crosses a month (payroll.php:146-161 with :410). Fetch from the Monday of the first week; count earlier days to the weekly limit but pay only days in the period.
- **P4 HIGH** Checker ≠ preparer bypass: adjust()/recalc_run() (payroll.php:509-531, :539) don't record who changed the run. Track editors; check_run refuses preparer or any editor.
- **P5 MED-HIGH** Partial finalise + reopen → duplicate payslips/double pay (payroll.php:547-553, :469, :568). reopen_run refuses when any payslip finalised; build_payslips aborts on set_status error.
- **P6 MED** = M1 (portal accept path portal.php:136-141).
- **P7 MED** Datasheet access not scoped to products bought/quoted (documents.php:96; portal_contact :79-82 not failing closed for closed customers). Require WB_Portal::contact() and product ∈ WB_Portal::product_ids().
- **P8 MED** Demand reads the OLDEST 5 000 orders (demand.php:206 ASC limit) and caps lines at 5 000 per chunk (:218). Order DESC / page fully.
- **P9 MED** Hourly staff get nothing for public holidays (payroll.php:407-412); BCEA s18 normal pay; work on a holiday at double. Add ordinary daily hours per holiday on a working day + holiday-work rate setting.
- **P10 LOW-MED** Leavers lose their own payslips (staff.php:209-212 staff_for_user active only; payroll.php:626).
- **P11 LOW-MED** Retirement limit ignores bonus (payroll.php:213-214 base = regular only).
- **P12 LOW** Sunday-holiday cascade (staff.php:83-86): 25 Dec Sunday → 26 Dec already a holiday; Public Holidays Act s2(1) makes the next Monday a holiday, but no extra cascade day when 26 Dec is Monday — VERIFY against the Act before changing.
- **P13 LOW** Family-responsibility leave requires ≥4 days/week (BCEA s27) (staff.php:154-155).
- **P14 LOW** adjust() for staff not in the run is lost silently (payroll.php:521-530).
- **P15 LOW** Brand colours printed without re-validation (payroll.php:606-609, portal.php:256-258). Pass through WB_Setup::valid_hex() ?: default.

## D — Sample and docs
- **D1 HIGH** Sample stock goes negative: COT-BT30 −14, COT-HB200 −8, FST-HN16 −13, COT-ZR85 −2 (data.js:451-457).
- **D2 HIGH** Sample payments matched before they were imported (data.js:217, :398-404; ledger :259 vs :260).
- **D3 MED** Durban Steel hold reason "overdue >30 days" on 3 Sep; INV-10484 due 12 Aug = 22 days (data.js:129, :248).
- **D4 MED** Leave lv1 7–17 Apr 2026 = 9 working days, stored 8 (data.js:525).
- **D5 MED** Leave lv5 21–31 Dec 2026 = 8 working days, stored 7 (data.js:529).
- **D6 MED** Leave lv9 note claims no annual leave left after a declined request (data.js:533).
- **D7 MED** PO-00740 dated after PO-00741; "raised twice" has no twin (data.js:418-419).
- **D8 MED** Number format: plugin INV-2026-000042 vs sample INV-10484.
- **D9 MED** PAYROLL-RULES says payslip PDF; plugin stores HTML.
- **D10 MED** PAYROLL-RULES promises IRP5/EMP501 figures; not built.
- **D11 MED** Plugin README "Known limits in 0.1.0" stale.
- **D12 LOW** Plugin README wages line description stale (payroll feeds cashflow since 0.2.0).
- **D13 MED** Top README tables omit Payroll, Customer portal, Setup.
- **D14 MED** sample/README says placeholder teal palette; it is Brandzgro now.
- **D15 LOW** sample/README says two Google fonts; three load.
- **D16 LOW** schema stock_movements.ref_type 'stock_request' not flagged as extension.
- **D17 LOW** DATA-ARCHITECTURE names wb_company/wb_brand_colors; main option is wb_brand.
- **D18 LOW** Sample seasonality window runs to 30 Sep but history ends 14 Sep → false September dip (app.js:226, data.js:10).
- **D19 LOW** Sample CSV SARS payment 41 250,00 vs run EMP201 31 648,75, and EMP201 counted again in forecast.
- **D20 LOW** Sample bank balance R486 250,00 vs CSV closing R496 239,47.
- **D21 LOW** 52 payments point to missing import batch bi-hist (data.js:404).
- **D22 LOW** data.js monthly_cost unused and disagrees with payroll salaries.
- **D23 LOW** sample/README "payroll on the 25th" vs pay date 23 Oct.
- **D24 LOW** Cash customer invoice due same day shows Overdue and counts in Home overdue.
- **D25 LOW** wb_notifications shortcode unused by dashboards; [wb_portal] not in README table.
- **D26 LOW** Dead private portal_v1() in class-wb-screens.php:862-875.
- **D27 LOW** Plugin README links to project-root docs as if plugin-relative.

## Checked and fine (by the reviewers)
Tax figures identical across rules/plugin/sample and internally consistent; 87 sample invoices = lines + 15% VAT; due dates = issue + terms; bank CSV references real; seasonality claims hold; no undefined/NaN/TODO/exclamation marks on 101 sample routes; zip matches source; roles upgrade without re-activation; dbDelta formatting; cron; every REST route has permission_callback; nonces on handlers; gapless numbering with InnoDB FOR UPDATE; wb_enc random IV + GCM; payslips immutable once finalised; bank CSV formula-injection guarded; portal queries scoped; provisioning refuses existing emails; pricing precedence and inclusive dates; VAT once per document; over-receiving, negative stock, write-off sign, adjuster ≠ approver enforced.

## Status (5 October 2026)
All 76 findings are dealt with in wb-core 0.2.1 and the sample. P6 is the same fix as M1.
P12 needed no change: the code already follows the Public Holidays Act (no cascade); a setting
for extra days the President declares was added. D16 is flagged in the schema.

Found while fixing, and also fixed: the sample cashflow counted money already in the bank but
not yet matched to an invoice twice (in the balance and as an expected receipt); and the
filtered sample statement showed different money than the full statement for the same days.

### Decisions (Zina, 5 October 2026), built in 0.2.2
1. Company-wide certificates: a Setup control lets each organisation hide them (default) or show
   them to every portal customer.
2. Closing a fully credited order stays allowed.
3. Leavers keep their own payslips only while they have workspace access, and the owners and
   administrators are told in-app when someone with payslips loses access.

### Decisions as first raised
1. **Company-wide certificates** (an ISO certificate with no product) are no longer shown to
   customers in the portal, because access is now by product. Should they be?
2. **Closing an order** whose invoice was fully credited (a full return) is still allowed, so
   such orders can be closed. Releasing goods against a credited invoice is not.
3. **Leavers** keep access to their own payslips, but only while their login still has the
   workspace permission.

### Check after the first install
- The ledger table gains a `hash_version` column. If the database user cannot alter tables,
  every audit entry will queue and the owner will be told. Confirm the column exists.
- Set `WB_ENCRYPTION_KEY` before the first entry so the trail is signed from the start.
