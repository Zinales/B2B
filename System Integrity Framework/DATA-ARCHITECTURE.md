# DATA ARCHITECTURE — where everything is saved

*Created 2 October 2026. The product is **B2BGro** (named 8 October 2026; the working name was B2B Wholesale System).
The code prefix stays `wb_`: every key below is an internal name, and the table names would need a
one-time migration to change, which buys nothing a person can see.*

Kaycie is the styling reference only. These rules are this product's own.

## The five rules everything obeys
1. **Config vs data** — settings live in `wp_options` (`wb_*`); records live in tables.
2. **Every write is witnessed** — each mutation is appended to the hash-chained `wb_ledger`.
3. **Secrets encrypted, files off-DB** — bank details and API credentials encrypted with a
   per-tenant key (`WB_ENCRYPTION_KEY`); PDFs, datasheets, proofs of delivery live on disk/R2,
   the DB keeps only a key.
4. **Nothing is hard-deleted** — `record_status` (active / archived / inactive / void).
5. **One tenant = one WordPress database** with its own encryption key.

Two rules that are new for this product:
6. **Numbered documents are immutable.** A quote, invoice, credit note or delivery note that has
   been issued is never edited. A correction is a new document that references the old one.
7. **Stock is a ledger, not a number.** `qty_on_hand` is always the sum of `wb_stock_movements`
   for that product (and batch). Nobody types a stock level; they record a movement with a reason
   and a reference. This is the control that makes shrinkage visible (see §8).

---

## 1 · Business data — JetEngine CCTs (`wp_jet_cct_wb_*`)
Every row carries `_ID, cct_status, cct_created, cct_modified, record_status`.

### Customers and people
| Table | Holds |
|---|---|
| `wb_customers` | The companies we sell to. `name`, `trading_name`, `reg_number`, `vat_number`, `billing_address`, `delivery_address`, `payment_terms_days` (0 = cash before collection), `credit_limit`, `account_status` (open / on_hold / closed), `price_tier_id`, `currency` (ISO, default ZAR), `rep_staff_id`, `industry`, `region`, `segment`, `journey_stage` (lead / quoted / first_order / repeat / at_risk / lapsed — computed nightly, stored for filtering), `notes`. |
| `wb_contacts` | People at a customer. `customer_id`, `first_name`, `last_name`, `role_title`, `email` (unique), `phone`, `is_primary`, `receives_invoices`, `receives_datasheets`, `marketing_optin`, `marketing_optin_at`, `portal_wp_user_id` (→ login when the customer portal is granted), `popia_consent_at`. |
| `wb_touchpoints` | Every contact moment (marketing lite). `customer_id`, `contact_id`, `type` (call / email / visit / quote_sent / quote_accepted / order / delivery / datasheet_sent / portal_login / complaint / note), `happened_at`, `staff_id`, `summary`, `next_action`, `next_action_date`, `source_ref` (quote/order/invoice id). Written automatically by the engines and by hand from the Contacts screen. |

### Products and stock
| Table | Holds |
|---|---|
| `wb_products` | Catalogue. `sku` (unique), `name`, `category_id`, `unit` (each / kg / m / box), `pack_size`, `barcode`, `cost_price`, `list_price`, `min_margin_pct`, `price_valid_from`, `price_valid_to`, `reorder_point`, `reorder_qty`, `lead_time_days`, `preferred_supplier_id`, `batch_tracked` (yes/no), `shelf_life_days`, `spec_json` (the technical specification as label/value/unit rows — rendered as the spec table), `datasheet_doc_id` → `wb_documents`, `status` (active / discontinued). |
| `wb_product_categories` | Category tree (`parent_id`), default `min_margin_pct`, `spec_template_json` (which spec fields this category carries, so every product in a category has the same spec rows). |
| `wb_price_tiers` | Named tiers (Trade / Distributor / Project / Retail). `name`, `discount_pct` off list, `is_default`. |
| `wb_price_rules` | Customer-specific pricing — **check one**. `customer_id`, `product_id` OR `category_id`, `rule_type` (fixed_price / pct_off_list / pct_on_cost), `value`, `valid_from`, `valid_to`, `approved_by_staff_id`, `approved_at`, `status`. Most specific wins: product rule → category rule → customer tier → list. |
| `wb_suppliers` | Who we buy from. `name`, contact fields, `lead_time_days`, `payment_terms_days`, `currency`, `bank_details_enc` (encrypted). |
| `wb_stock_movements` | **The stock ledger.** `product_id`, `batch_id`, `qty` (+ in / − out), `type` (receipt / sale / reserve / release / adjustment / return / write_off / transfer / count), `unit_cost`, `ref_type` + `ref_id` (order / purchase_order / delivery_note / credit_note / stocktake; the plugin also writes `stock_request` for an approved two-person adjustment or write-off, an extension added in 0.2.0), `location`, `staff_id`, `reason`, `approved_by_staff_id` (required for adjustment and write_off). Append-only. |
| `wb_batches` | Lots for batch-tracked products. `product_id`, `batch_no`, `received_at`, `expiry_at`, `supplier_id`, `coa_doc_id` (certificate of analysis) → documents. |
| `wb_purchase_orders` / `wb_po_lines` | Reorders to suppliers. `po_number`, `supplier_id`, `status` (draft / sent / part_received / received / cancelled), `expected_at`; lines carry `product_id`, `qty_ordered`, `qty_received`, `unit_cost`. Receiving a line writes a `receipt` movement. |
| `wb_stocktakes` | A count event: `started_at`, `counted_by_staff_id`, `checked_by_staff_id` (a second person), `status`, `variance_total`. Each counted line writes a `count` movement for the difference, so variance is in the ledger with two names on it. |

### Selling — the document chain
Quote → (accepted) Order → Invoice → Payment(s) → Delivery note → Closed. Each is its own
numbered record. Nothing is retyped; each document is built from the one before it.

| Table | Holds |
|---|---|
| `wb_quotes` | `quote_number`, `customer_id`, `contact_id`, `status` (draft / sent / accepted / declined / expired / converted), `valid_until`, `subtotal`, `vat`, `total`, `pricing_check_status` (passed / needs_approval / approved), `approved_by_staff_id`, `accepted_at`, `accepted_by` (contact id, or "signed PDF"), `acceptance_doc_id`, `pdf_key`, `rep_staff_id`, `notes_to_customer`. |
| `wb_quote_lines` | `quote_id`, `product_id`, `description` (frozen at quote time), `qty`, `unit_price`, `price_source` (rule / tier / list / manual), `floor_price` (check two, frozen), `below_floor` (yes/no), `discount_pct`, `line_total`, `spec_snapshot_json`, `datasheet_doc_id`. |
| `wb_orders` | Created the moment a quote is accepted. `order_number`, `quote_id`, `customer_id`, `status` (accepted / invoiced / awaiting_payment / paid / ready / part_delivered / delivered / closed / cancelled), `fulfilment` (collection / delivery), `required_by`, `closed_at`, `closed_by_staff_id`. |
| `wb_order_lines` | Copy of the accepted quote lines plus fulfilment: `qty_ordered`, `qty_reserved`, `qty_delivered`, `qty_backordered`, `batch_id`. |
| `wb_invoices` | **Issued on acceptance** (or on dispatch — a tenant setting). `invoice_number` (sequential, gapless, never reused), `order_id`, `customer_id`, `issued_at`, `due_at` (issued + terms), `subtotal`, `vat_rate`, `vat`, `total`, `amount_paid`, `status` (issued / part_paid / paid / overdue / credited / void), `pdf_key`. Immutable once issued. |
| `wb_credit_notes` | `credit_number`, `invoice_id`, `reason` (return / pricing / damage / goodwill), lines, totals, `approved_by_staff_id`, `pdf_key`. A return also writes a `return` stock movement. |
| `wb_payments` | Money received. `received_at`, `amount`, `method` (eft / card / cash), `bank_reference`, `bank_description`, `import_batch_id`, `invoice_id` (null until matched), `customer_id`, `match_status` (unmatched / suggested / matched / partial / unallocated), `match_method` (auto_reference / auto_amount / manual), `matched_by_staff_id`, `matched_at`. |
| `wb_delivery_notes` | `dn_number`, `order_id`, `issued_at`, `type` (collection / delivery), `vehicle_or_courier`, `lines_json` (product, qty, batch), `collected_by_name`, `collected_at`, `signature_key` / `pod_doc_id`, `status` (issued / collected / delivered / disputed). Issuing writes `sale` movements and reduces `qty_reserved`. |
| `wb_statements` | Monthly customer statements (generated, `pdf_key`, `period`). |

### Documents
| Table | Holds |
|---|---|
| `wb_documents` | The register. `type` (datasheet / coa / msds / certificate / quote_pdf / invoice_pdf / credit_pdf / dn_pdf / pod / signed_quote / contract / staff_doc), `title`, `product_id` / `customer_id` / `staff_id` / `order_id` (whichever applies), `version`, `supersedes_doc_id`, `storage_key` (never a URL), `mime`, `size`, `issued_at`, `expires_at`, `is_customer_visible`. A datasheet "on demand" = the current version for that product, streamed through a tokened download link. |

### Staff
| Table | Holds |
|---|---|
| `wb_staff` | `wp_user_id`, `first_name`, `last_name`, `employee_no`, `job_title`, `department`, `manager_staff_id`, `started_at`, `ended_at`, `employment_type` (permanent / contract / casual), `hours_per_week`, `id_number_enc` (encrypted), `emergency_contact_enc`, `status`. |
| `wb_timesheets` | One row per staff per day. `staff_id`, `work_date`, `start`, `end`, `break_minutes`, `hours` (computed), `activity` (sales / warehouse / admin / delivery / leave), `customer_id` (optional, for time against an account), `status` (draft / submitted / approved / queried), `approved_by_staff_id`. |
| `wb_leave_types` | Annual / sick / family responsibility / unpaid / study. `days_per_year`, `accrual` (monthly / upfront), `carry_over_max`. Defaults follow the SA Basic Conditions of Employment Act (15 annual, 30 sick per 3-year cycle, 3 family) — editable per tenant. |
| `wb_leave` | `staff_id`, `leave_type_id`, `from_date`, `to_date`, `days` (working days), `status` (requested / approved / declined / cancelled), `approved_by_staff_id`, `note`, `doc_id` (sick note). Balance = entitlement accrued − approved days, computed, never stored as a typed number. |
| `wb_kpis` | KPI definitions. `name`, `applies_to` (role / staff), `measure` (quotes_sent / quote_win_rate / revenue / on_time_delivery / stock_accuracy / timesheet_compliance / custom), `target`, `unit`, `period` (monthly / quarterly). |
| `wb_kpi_scores` | `kpi_id`, `staff_id`, `period_start`, `target`, `actual` (auto from the engines where the measure allows, else typed), `source` (auto / manual), `note`. |
| `wb_reviews` | Performance reviews. `staff_id`, `reviewer_staff_id`, `period`, `status` (scheduled / self_review / manager_review / discussed / signed), `self_json`, `manager_json`, `rating`, `goals_json`, `signed_at_staff`, `signed_at_manager`, `doc_id`. |
| `wb_staff_notes` | Dated notes against a staff file (commendation / warning / training / certification with `expires_at`). |

### Portal and payroll (added 0.2.0)
| Table | Holds |
|---|---|
| `wb_portal_requests` | Requests a customer makes in the portal that staff must approve, such as a change to their contact details. Never applied without a staff decision. |
| `wb_payroll_profiles` | Pay set-up per staff member: monthly or hourly, salary and hourly rate (encrypted), tax number and bank account (encrypted), date of birth for the age rebates, medical scheme members, retirement contribution, UIF exemption, start and end. |
| `wb_pay_runs` | One per pay period: pay date, status (draft / checked / finalised), prepared by, checked by (must be a different person), finalised at, totals. |
| `wb_payslips` | One per person per run: lines, gross, taxable, PAYE, UIF (employee and employer), SDL, other deductions, net, stored payslip. Locked once the run is finalised; a correction goes into the next run. |


---

## 2 · Engine records — plugin-owned tables (`wp_wb_*`, via dbDelta)
| Table | Holds |
|---|---|
| `wb_ledger` | Append-only hash-chained audit trail (`prev_hash → entry_hash`). Actor, action, record type/id, before/after JSON, IP. 7-year retention. Verified nightly; a broken chain raises a notification to the owner. |
| `wb_sequences` | Document numbering per type (`QUO`, `ORD`, `INV`, `CRN`, `DN`, `PO`, `STM`). Row-locked `SELECT … FOR UPDATE` so two invoices never share a number. |
| `wb_notifications` | In-app notifications per recipient: `group` (stock / money / orders / staff / marketing / integrity), `message`, `link`, `read_at`, `dismissed_at`. Emails are **never automatic** unless the tenant switches that specific alert on. |
| `wb_reorder_alerts` | One open row per product below its reorder point: `product_id`, `qty_on_hand`, `qty_reserved`, `qty_on_order`, `suggested_qty`, `raised_at`, `resolved_at` (closed when a PO covers it). |
| `wb_bank_imports` | Bank statement import batches: `file_name`, `bank`, `rows`, `matched`, `unmatched`, `imported_by`, `imported_at`, `storage_key`. |
| `wb_demand_stats` | Nightly computed per customer × product: `avg_interval_days`, `last_order_at`, `predicted_next_at`, `avg_qty`, `confidence`. Per product × month: `seasonal_index` (that month's average ÷ overall monthly average). Used by Marketing and Cashflow. |
| `wb_cashflow_forecast` | Weekly rows for the next 13 weeks: `expected_receipts` (invoices due by terms × customer pay-behaviour), `predicted_orders` (from demand stats), `committed_purchases` (open POs), `payroll` (from the active payroll profiles: gross pay plus employer UIF and SDL; the `wb_cashflow` option's `payroll_monthly` only when there are no profiles), `net`. Recomputed nightly; snapshots kept so forecast accuracy can be measured. |
| `wb_pricing_approvals` | Below-floor requests: quote line, floor, asked price, margin, `requested_by`, `decided_by`, `decision`, `decided_at`. |

---

## 3 · Configuration & state — `wp_options` (`wb_*`)
Identity (`wb_company`: name, reg, VAT number, addresses, logo key), money (`wb_currency`,
`wb_vat_rate` default 15, `wb_bank_details_enc`, `wb_invoice_trigger` acceptance|dispatch,
`wb_default_terms_days`), pricing policy (`wb_pricing`: default min margin, who may approve
below-floor, quote validity days), stock policy (`wb_stock`: default reorder lead, alert
recipients, stocktake cadence, adjustment approval threshold), numbering (`wb_number_prefixes`),
bank import mapping (`wb_bank_mapping` per bank), notification switches (`wb_notify_*`, all OFF
by default), marketing (`wb_journey_rules`: days without an order → at_risk / lapsed), staff
(`wb_leave_defaults`, `wb_review_cycle`), db-version stamps (`wb_*_version`), branding
(`wb_brand_colors`), tenant pointers (`wb_tenant_id`, `wb_mothership_url`).

Added 0.2.0: **`wb_brand` is the main setup option.** The Setup screen writes it: identity
(display name, legal name, registration and VAT numbers, addresses, logo storage key) and the
look (colours and heading and body fonts), with Brandzgro as the default and every readable
colour pair contrast-checked. Each save also keeps the 0.1.0 options `wb_company` and
`wb_brand_colors` in step, so older code that reads them still works; new code reads `wb_brand`. `wb_bank_mapping` holds named CSV mappings. `wb_payroll` holds
payroll settings (SDL registered, overtime rate and weekly hours). `wb_tax_years` holds the SARS
figures per tax year, seeded from `docs/PAYROLL-RULES-2027.md`.

Added 0.2.2: `wb_brand.portal_company_docs` (`hidden` | `all_customers`, default `hidden`) decides
whether customer-visible company-wide documents (certificate, msds or datasheet tied to no product
and no customer) appear to every portal customer. User meta `wb_payslip_access_lost` records that
the owners were told a person with finalised payslips lost workspace access (cleared if access
returns).

## 4 · Files — `WB_Storage` (off-database)
Default driver `wp-content/wb-private/` (web access denied); optional Cloudflare R2.
Datasheets, CoAs, quote/invoice/credit/DN PDFs, proofs of delivery, signatures, bank CSVs,
staff documents. Rows store a driver-relative `storage_key` — never a public URL. Customer
downloads go through `/wp-json/wb/v1/download?token=` (short-lived, single record).

## 5 · WordPress-native adjuncts
`wp_users` + `wp_usermeta`: staff logins (`wb_*` roles) and customer-portal logins
(`wb_customer`), linked to `wb_staff.wp_user_id` / `wb_contacts.portal_wp_user_id`.
Transients: download tokens, import results, quote-acceptance tokens (7 days, single use).

## 6 · The two pricing checks (where they live)
- **Check one — what this customer pays:** `wb_price_rules` (product → category) → `wb_price_tiers`
  via `wb_customers.price_tier_id` → `wb_products.list_price`. Returns a price and a `price_source`.
- **Check two — what the product allows:** `wb_products.cost_price × (1 + min_margin_pct/100)` is the
  **floor**; `list_price` is the ceiling; `price_valid_from/to` must cover today, else the line is
  flagged *price out of date*. A price below the floor can be quoted only through
  `wb_pricing_approvals`.
Both results are **frozen onto the quote line** (`unit_price`, `price_source`, `floor_price`,
`below_floor`) so a later cost change never rewrites history.

## 7 · The order state machine
```
quote.sent ─accept→ order.accepted ─invoice→ order.invoiced
  → awaiting_payment ─payment matched (full, or terms allow)→ paid
  → ready (stock reserved) ─delivery note issued→ part_delivered | delivered
  → closed (all lines delivered, invoice paid or within terms) ; cancelled from any pre-delivery state
```
Collection: the delivery note is the collection note; `collected_by_name` + signature closes it.
Terms customers: delivery may precede payment (`payment_terms_days > 0` and within
`credit_limit`); cash customers may not. The gate is one function, `WB_Orders::may_release()`.

## 8 · Where theft shows up, and what the data does about it
The ledger does not stop anyone taking stock off a shelf. What it does is remove every digital
way of *hiding* that it happened. In a wholesale business the digital cover-ups are few and known:

| Cover-up | What the design does |
|---|---|
| Type a lower stock figure so the count "matches" | Impossible: stock is a sum of movements. A correction is an `adjustment` movement with a reason, a staff name and a required approver, and it is hash-chained. |
| Write off "damaged" goods that left the door | `write_off` needs a second approver, and the Integrity report lists write-offs by person and by product every month. |
| Issue goods with no invoice | A delivery note can only be issued against an order; issuing it writes the `sale` movements. Stock that leaves without a DN shows as a count variance at the next stocktake, with the counter and checker named. |
| Invoice, collect cash, then void or credit the invoice | Invoices are immutable; a credit note is a new numbered document needing approval, and the report pairs every credit note with the payment it reverses. |
| Match a customer's payment to the wrong invoice to hide a shortfall | Every match records who, how and when; manual matches are listed separately from automatic ones. |
| Quote a friend below cost | Check two flags it; selling below the floor needs an approval row with a name on it. |
| Delete the evidence | Nothing is hard-deleted, the ledger is append-only, and the chain is verified nightly; a break notifies the owner. |

The remaining exposure is physical: goods leaving in a vehicle without a document, counts done
by one person, cash taken before it is recorded. The system's answer is procedural and it is
built in: two names on every stocktake, cash receipts recorded against an invoice before goods
are released, and the monthly Integrity report that puts adjustments, write-offs, credit notes,
manual matches and below-floor approvals in front of the owner, by staff member.
