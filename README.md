# B2B Wholesale System — project map

*Started 2 October 2026. A sample system. Not part of FindGro. Kaycie is the STYLING guide only;
the engines, rules and data design are our own and are decided here.*
*Working name only. Code prefix `wb_`. Pick the real name before the first live client.*

## Who it is for
Companies that sell technical products wholesale to other businesses: each product carries a
specification and a datasheet, each customer has its own rates, and stock and cash have to be
watched closely.

## Where things live
| Folder | What's in it |
|---|---|
| **System Integrity Framework/** | How it is built and why it holds together. Start with `DATA-ARCHITECTURE.md`. |
| **plugin-source/wb-core/** | The WordPress plugin master (engines: ledger, numbering, roles, pricing, stock, orders, invoices, payments, demand, staff, setup, customer portal, payroll). |
| **sample/** | The clickable sample. Double-click `sample/index.html`. Demo data only. |
| **docs/** | Working notes and decisions, the payroll rules, the review findings (`docs/review-*/`), and `BUILD-PATTERNS.md` (what Kaycie, FindGro and Brandzgro taught us about *how* to build, applied to wb-core). |
| **tools/** | `build.py`, the only way an upload zip is made (see *How we change this safely*). |
| **DEPLOY/** | The upload zips, one per version. Old ones are kept for rolling back. |

## The modules
| Group | Module | What it does for the business |
|---|---|---|
| Sell | **Customers** | One profile per company, its people, its rates, terms and credit limit. |
| Sell | **Quotes** | Every line runs the two pricing checks before it can be sent. |
| Sell | **Orders** | An accepted quote becomes an order with nothing retyped. |
| Sell | **Invoices & payments** | Invoice issued on acceptance. Bank statement import matches payments to invoices. |
| Sell | **Deliveries** | Delivery or collection note, signed, which closes the order. |
| Stock | **Products & datasheets** | Spec table per product, versioned datasheets sent on request. |
| Stock | **Stock** | Every change is a recorded movement. Reorder alerts below the reorder point. |
| Stock | **Purchasing** | Purchase orders to suppliers; receiving adds the stock. |
| Know | **Marketing (lite)** | Contacts, touchpoints, journey stage, who is likely to reorder soon, seasonality. |
| Know | **Cashflow** | 13 weeks ahead: money due in, likely orders, purchases committed, payroll. |
| Know | **Integrity** | Monthly report of every adjustment, write-off, credit note, manual match and below-floor price, by person. |
| Team | **Staff** | Staff files, timesheets, leave balances, KPIs, performance reviews, staff notes. |
| Team | **Payroll** | Monthly pay run from the SARS tax-year settings: PAYE, UIF, SDL, overtime and unpaid leave. Drafted by one person, checked by another, then locked. Payslips, EMP201 figures and a net-pay bank file; nothing is submitted or paid. |
| Customers | **Customer portal** | Each customer sees only their own company: quotes to accept, invoices, statement, datasheets, requests. |
| Admin | **Setup** | Name, company details, logo, colours and fonts per client, with a contrast check and a first-run checklist. |

## Extras added beyond the brief (recommended, each one small)
- **Credit control.** Credit limits, account on hold, overdue ageing, monthly statements.
- **Credit notes and returns.** Needed the first time something comes back.
- **Purchase orders and receiving.** Reorder alerts need somewhere to go.
- **Batch and expiry tracking.** Common for adhesives, coatings and chemicals; switched on per product.
- **Certificates of analysis and safety data sheets** stored beside the datasheet.
- **Stocktakes with two names.** One counts, a different person checks.
- **Customer portal (version one).** Customers see their quotes, invoices, statements and datasheets.
- **Payroll.** Added on 2 October 2026 (see the decisions below).
- **Integrity report.** The owner's monthly view of every exception, by person.

## Styling vs everything else
Kaycie supplies the look: the dashboard stylesheet, the table pattern, the side menu.
Everything else (data design, controls, engines) is designed for this product on its own
merits and recorded in `System Integrity Framework/`.

## Decisions (Zina, 2 October 2026)
1. **Name, colours and logo are set on a Setup screen** and stored per client. Brandzgro colours
   are the default (navy #0B1F3A, rose #8A3B52, cream #F7F3EE, slate #47586D; Poppins).
2. **Bank statements come in as CSV** with a column-mapping step, so a full statement or a
   filtered spreadsheet saved as CSV both work. Mappings are saved per bank.
3. **Customer portal is in version one.**
4. **Payroll is added.** Rules and verified 2027 tax figures: `docs/PAYROLL-RULES-2027.md`.
5. **Deferred:** whether demo-data clean-up may hard-delete. Decide once the plugin has run.

## How we change this safely
The system holds money, stock and pay, so every change goes through the same few steps.

- **Git.** Each piece of work gets its own branch (for example `fix/review-2026-10-04`) and is
  merged once it is checked. Nothing is changed straight on `main`.
- **One way to build.** The upload zip only comes from `python tools/build.py`. It stops at the
  first gate that fails and writes nothing:
  1. every PHP file passes `php -l`;
  2. every test in `plugin-source/wb-core/tests/` passes (`selftest.php` and each `regress-*.php`);
  3. nothing that existed has disappeared: every shortcode, REST route, capability, role,
     default option and data field in `tools/inventory-baseline.json` must still be there.
     Removing one is a deliberate decision (`--accept-inventory`, committed with a reason),
     never an accident;
  4. the plugin header, `WB_VERSION` and the top `CHANGELOG.md` entry show the same version;
  5. the working tree is committed, so every zip maps to one commit;
  6. a version is never reused: if its zip already exists, a change needs a new version.

  `python tools/build.py --check` runs the gates without building.
- **Every bug gets a regression test.** When something is fixed, a test that would have caught
  it goes into `tests/regress-*.php`. These tests are never deleted, so the bug cannot quietly
  come back.
- **A findings register.** Each review is written up in `docs/review-<date>/FINDINGS.md` with an
  ID per finding. Fixes and the changelog refer to those IDs, so we can see what was found, what
  was fixed and what is still open.
- **Staging before live.** A new zip goes to a staging site first. Before it goes live there is a
  fresh backup, and the previous zip stays in `DEPLOY/` so we can roll back to it.
