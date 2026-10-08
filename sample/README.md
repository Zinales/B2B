# B2B Wholesale System — clickable sample

A working sample of the wholesale system for **Demo Technical Supplies (Pty) Ltd**, a made-up
supplier of industrial adhesives, sealants, fasteners and protective coatings. All figures are
invented. It follows the Kaycie dashboard conventions and the data model in
`../System Integrity Framework/DATA-ARCHITECTURE.md`.

## How to open it

Double-click `index.html`. It opens in your browser and works offline (only the three Google
fonts, Poppins, Hanken Grotesk and Mulish, need the internet; without them it falls back to the
system font).

Nothing is saved. Every click changes the sample in memory only; reload the page to start again.

The **Working as** box in the top bar decides who you are. Anything that needs a second person
(a below-floor price, a stock adjustment or write-off) asks you to choose an approver who is not
you. In the live system that person would confirm with their own sign-in.

## Files

| File | What it is |
|---|---|
| `index.html` | The page: side menu, top bar, and a few sample-only styles (stepper, signature pad, charts, timeline) |
| `app.js` | Every screen, the engines (pricing checks, stock ledger, demand, cashflow, leave) and the flows |
| `data.js` | The demo data, generated from a fixed seed so it is the same every time |
| `kaycie-dashboard.css` | A copy of the Kaycie primitives, unchanged |
| `overrides.css` | Only the colour tokens, re-pointed to the **Brandzgro** palette (navy, rose, cream, slate), the default each client starts from. *Settings › Setup* changes them live |
| `sample-bank-full.csv`, `sample-bank-filtered.csv` | Two bank statements to try the CSV import with (see flow 9) |

## The flows to try

The Home screen has a "Walk through the sample" panel that links to each of these.

1. **Quote to closed order** — *Quotes › New quote.* Pick a customer and add lines. Each line
   shows where its price came from (*Customer rule*, *Tier: Distributor*, or *List*) and whether
   it clears the floor (*Above floor*, *Price out of date*, or *Below floor — needs approval*).
   Polokwane Plant Hire has a season rule on the zinc-rich primer that sits below the floor, so it
   shows the approval step. Typing over a price re-runs both checks as you type. Send the quote,
   then *Simulate: customer accepts*: the order and the invoice are created with the next numbers
   in sequence. On the order, simulate the customer's EFT three ways (with the invoice number,
   right amount with no reference, odd amount) and match it on *Invoices & payments* — by
   reference automatically, by amount with a click, or by hand. Then reserve the stock, issue a
   collection note (type the collector's name and sign in the box), and close the order. Every
   step is on the order's audit trail.
2. **Stock as a ledger** — *Stock.* On hand is never typed; it is the sum of movements, and the
   ledger shows the sum. Record an adjustment or a write-off: both need an approver who is not
   you. Push a product below its reorder point and an alert opens; *Purchasing › Raise PO*
   closes it, and *Receive everything outstanding* writes the receipt movements.
3. **Datasheet on demand** — any product page shows its specification table and the current
   datasheet version. *Send datasheet* logs a touchpoint against the customer.
4. **Marketing** — journey stages, a touchpoint timeline for each customer, *Likely to reorder
   soon* (last order + average interval = predicted date, with a confidence), and a 12-month
   seasonality chart per category (coatings peak September to November; adhesives dip in
   December and January). The chart reads the year of history to 14 September 2026; the
   orders after that are current work and still open.
5. **Cashflow** — 13 weeks: expected receipts from open invoices (adjusted for each customer's
   habit of paying early or late), orders predicted from the average interval, purchase orders
   already sent, payroll on the 25th (or the working day before: 25 October 2026 is a Sunday,
   so October's pay date is Friday 23 October), and the running balance. It starts from the
   closing balance on the 2 October statement in `sample-bank-full.csv`; September's EMP201 is
   on that statement, so it is not counted again. The assumptions are listed under the table.
6. **Team** — weekly timesheets (submit as the person, approve as their manager), leave
   requests checked against the balance (accrued less approved; BCEA defaults; weekends and
   public holidays left out), KPI tiles against target, and a review moving through its steps.
7. **Integrity** — the monthly report of adjustments, write-offs, credit notes and voids,
   manual payment matches and below-floor approvals, by staff member. *Check the audit trail*
   recomputes the SHA-256 hash chain. *Simulate tampering* edits one past entry (a write-off
   shrunk from −20 to −2) so the check can show exactly where the chain breaks; *Undo the
   tampering* puts it back.

8. **Setup** — *Settings › Setup.* Product name, company details, logo, colours and font, with
   Brandzgro as the starting palette. Colours change across the whole sample as you pick them.
   The contrast check shows each pair's ratio and refuses to save a pair under 4,5 : 1.
   *Reset to Brandzgro defaults* puts the look back. Home shows a setup checklist.
9. **Bank statement by CSV** — *Invoices & payments › Import a bank statement.* Try
   `sample-bank-full.csv` (a full statement with account lines above the columns, and debit and
   credit columns) and `sample-bank-filtered.csv` (a short filtered export, semicolons, one
   signed amount, comma decimals). Pick the header line, check the mapping, name it, import.
   Only money in becomes payment lines. Importing the same file again adds nothing. The
   filtered file is an extract of the same two days, so importing it after the full
   statement adds nothing either: the same lines are recognised in a different layout. Then
   *Run auto-match*.
   Setup also has a *Customer portal* fold: company-wide documents (an ISO certificate, a general
   handling guide) are hidden from customers until it is set to show them, and a document not
   marked for customers (the insurance certificate) never shows.
10. **Customer portal** — set *Working as* to a customer contact. They see only their own
    company: quotes to accept or decline, invoices, statements, datasheets, a quote request and
    a request to change their details, which staff approve. A privacy check under each page
    confirms no other customer's names or numbers appear. Opening another company's invoice
    link answers that it is not on the account.
11. **Payroll** — *Team › Payroll.* The October run: PAYE from the 2027 SARS tables, medical
    credits, retirement contributions, UIF, SDL, overtime from approved timesheets and unpaid
    leave. The person who drafted the run cannot check it. Finalising locks every payslip.
    Each payslip shows how its PAYE was worked out. The EMP201 figures and a net-pay bank file
    are produced; nothing is submitted to SARS or paid. Payroll feeds the cashflow forecast.
    Add `?selftest=1` to the address to run the payroll and CSV checks on the page.

## What is stubbed

- No emails are sent; sending a quote or datasheet only logs it.
- The customer's acceptance, the bank statement lines and the second person's sign-in are
  simulated with buttons.
- Credit notes, voids, cancelling an order, stocktakes and batch tracking are shown in the data
  and reports but cannot be created in the sample.
- Settings other than Setup are displayed, not editable. Setup is kept in this browser only.
- PDFs (quotes, invoices, delivery notes) are not generated.

## Notes

- Money is shown as R 12 345,00; VAT is 15 %.
- Document numbers are kept short in the sample (INV-10484, ORD-01890). The live system puts
  the year in every number and pads it to six digits, for example INV-2026-000042, and starts
  again each year.
- Cash customers (Karoo Agri) pay before collection, so their invoices are never shown as
  overdue or aged; the goods simply wait for the money.
- Phone widths: below 768 px every list folds into cards (tap a card to see every field), and
  the side menu becomes a drawer behind *Menu*.
- The side menu's colours are set in `index.html`, because the Kaycie sheet hard-codes them
  rather than reading the tokens. Everything else takes its colour from `overrides.css`.
