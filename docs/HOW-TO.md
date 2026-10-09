# B2BGro: how to

_Written by tools/guide-md.php from WB_Guide::HOWTO; edit the class, not this file. One thing to press or read per step, written for the demo company. The flows these belong to are in FLOWS.md._

## Sell something from quote to delivery

For sales and the owner. Part of _Selling: from a quote to the goods going out_.

1. **Quotes.** Press "New quote". Choose a customer (Karoo Agri is on 30-day terms; Bayside Hardware is a cash customer). In Product, type "epoxy": the list shows each match with Karoo Agri's price and the stock. Choose one, give a quantity, and press "Start the quote".
2. **Quotes.** Add a second product the same way. Then type a price well below cost in its Price each box and press "Save changes": the line turns red and says, in a sentence, which rule it breaks.
3. **Quotes.** On that line press "Ask for price approval" with a reason. Sign in as someone else (or, in the demo, read it under "Prices waiting for your approval") and approve or decline it.
4. **Quotes.** Press "Send by email" on the quote's menu. Read who is ticked and the message, then press Send. In the demo nothing leaves; it is recorded on the customer's timeline as if it had gone. Then press "Record acceptance" to stand in for the customer.
5. **Orders.** Find the order. It carries the quote's lines and prices. Open the invoice from its menu: issued, numbered, with VAT frozen.
6. **Orders.** Press "Release for collection / delivery". A terms customer inside their limit is released and the stock is put aside; a cash customer is refused until the invoice is paid.
7. **Deliveries.** Issue the note for the quantities going out and record who signed. Watch the product's on hand drop on its page.
8. **Orders.** Close the order once everything has gone and the invoice is paid or inside its terms.

## Match a bank statement

For accounts. Part of _Getting paid: the bank statement, matching and credit notes_.

1. **Payments.** Press "Import a bank statement", choose the layout and the CSV, and upload. If the layout is new, pick the header row and name the columns; it is saved for next time.
2. **Payments.** Read the result: matched by reference, suggested by amount, unmatched with the reason.
3. **Payments.** Under "To match", each suggested payment names the invoice it looks like; press "Confirm suggested match" if it is right.
4. **Payments.** For an unmatched payment, use "Match a payment by hand": choose the invoice and give a note. It will be listed in the Integrity report as a hand match.
5. **Invoices.** Open Invoices: the matched invoices now read paid or part paid.

## Find anything

For everyone. Part of _Knowing: demand, cashflow and the Integrity report_.

1. **Invoices.** Type part of a number or a customer's name in the search box above any list and press Search. The words stay in the box; Clear puts the whole list back.
2. **Invoices.** Press a status chip (Overdue, Part paid) to see only those. Press it again, or All, to see everything. Search and chip work together: "karoo" and Overdue shows Karoo Agri's late invoices.
3. **Invoices.** Press a column heading to sort by it; press it again to turn it round. Long lists come in pages of fifty with Previous and Next. Every one of these is in the address, so a filtered list can be bookmarked or sent to a colleague.
4. **Customers.** Click a customer's name in any list to open their page; click a product code to open the product's.

## Make a counter sale

For whoever serves at the counter. Part of _Selling: from a quote to the goods going out_.

1. **Quotes.** Open "Quick sale at the counter" on Quotes. Choose the customer (Bayside Hardware pays cash), find the product, give the quantity.
2. **Quotes.** Choose how it was paid, type the slip number and the name of the person taking the goods, and press "Sold and paid".
3. **Orders.** The order opens with its invoice paid and its collection note signed. Each has its own number and PDF, exactly as if every button had been pressed.

## Chase a customer who is late

For accounts. Part of _Getting paid: the bank statement, matching and credit notes_.

1. **Invoices.** Open the Chase fold at the top of Invoices: everything owed by how late, then the customers to chase, the most overdue money first. Click a customer's name.
2. **Customers.** Their page shows what they owe and how late, and every open invoice with what is still owing on it.
3. **Customers.** Press "Send a reminder". Their accounts contact is ticked, the message is filled in, and the statement PDF is attached. Send it.
4. **Customers.** The reminder is on their timeline with the date and who it went to. When their payment comes in on the bank statement it matches the invoice by its number.

## Issue a credit note

For accounts and a second approver. Part of _Getting paid: the bank statement, matching and credit notes_.

1. **Invoices.** Open "Ask for a credit note". Choose the invoice, the amount and the reason, and press "Ask for the credit note".
2. **Invoices.** A different person opens "Credit notes waiting for approval" and presses "Approve credit note" (or Decline).
3. **Invoices.** The credit note now has its CRN number and a PDF; the invoice's outstanding amount has dropped. Both names are on it in the Integrity report.

## Correct stock or write some off

For stores and a second approver. Part of _Stock: a ledger of movements, corrections with two names, stocktakes_.

1. **Stock.** Press "Correct stock". Choose the product, what happened (a correction up or down, a write-off, a return), the quantity and the reason.
2. **Stock.** Someone else opens "Stock changes waiting for your approval" and approves or declines.
3. **Stock.** Open the product's movements: the new movement carries who asked and who approved.

## Do a stocktake

For stores and a checker. Part of _Stock: a ledger of movements, corrections with two names, stocktakes_.

1. **Stock.** Under "Stocktakes" press "Start a stocktake". A sheet of products is made; the book figure is not shown to the counter.
2. **Stock.** Type the counted quantities and "Save count". Then press "Hand over for checking" on the stocktake's menu.
3. **Stock.** A different person presses "Check and post the count". The differences are posted as movements with both names, and show in the Integrity report.

## Reorder from a supplier and receive it

For purchasing and stores. Part of _Purchasing: ordering from suppliers and receiving_.

1. **Purchasing.** Read "To reorder": products at or below their reorder point.
2. **Purchasing.** Open "New purchase order": supplier, lines, expected date. Save, then "Mark sent" on its menu and open the PDF. The alert it covers is resolved.
3. **Cashflow.** Open Cashflow tomorrow: the order sits in the week its payment falls due.
4. **Purchasing.** When it arrives, "Receive stock" with the quantities that came. On hand rises on the Stock screen.

## Give a customer a portal login

For sales. Part of _Customers and their portal_.

1. **Customers.** Open the customer. Under "Contacts and portal logins", on the contact's menu press "Give a portal login (sends the set-password email now)".
2. **Customers.** The set-password email is sent to that contact, once, now. "Send the set-password email again" is on the same menu if it did not arrive; so is "Turn the portal login off".
3. **Your account.** The customer signs in at Sign in and lands on their account: quotes to accept, invoices, statement, datasheets, and the two request forms.

## Load your products and customers from a spreadsheet

For the owner, once. Part of _Setting up: the company, the tables, who can do what_.

1. **Products.** Open "Upload categories from a file" (the fold under Categories) and download the template. Fill it, save as CSV, choose it and press "Check file (no import)". Fix what it lists, then "Validate and import". Then do the same for Price tiers, then Products.
2. **Customers.** Customers next, then Contacts (a contact names its customer by the exact name or the _ID).
3. **Purchasing.** Suppliers.
4. **Documents.** Datasheets: one row per product with the summary, description, applications and handling notes, uploaded under "Upload datasheets from a file". The specification rows came in with the products.
5. **Staff.** Staff, Leave types and KPIs.
6. **Products.** Later changes: download the export, edit it, import it back. Rows with an _ID are updated, rows without one are added.

## Run the month's payroll

For the owner and a checker. Part of _Payroll: a monthly run with two people on it_.

1. **Payroll.** Make sure every person paid has a payroll profile, and that the month's timesheets and leave are approved.
2. **Payroll.** Under "Start a pay run" choose the month and press "Calculate draft". Read each payslip; press "Recalculate" after any change to profiles, timesheets or leave.
3. **Payroll.** A different person presses "I have checked this pay run". Then press "Finalise".
4. **Payroll.** Download the payslip PDFs (each person can open their own), capture the EMP201 figures shown, and pay through your bank.

## Record and approve time and leave

For everyone, and their approver. Part of _Team: timesheets, leave, KPIs and reviews_.

1. **Staff.** My timesheet: today's start, end and break. Save, then Submit.
2. **Staff.** Ask for leave: the type and the dates; the working days and the balance are shown.
3. **Staff.** The approver opens "Timesheets to approve" and "Leave to approve" and approves, queries or declines each one. The person's own rows are never theirs to approve.

## Read the Integrity report

For the owner, monthly. Part of _Knowing: demand, cashflow and the Integrity report_.

1. **Integrity.** Choose the month. Read each section by person: adjustments and write-offs, count differences, credit notes with the payment each reverses, hand-matched payments, prices approved below the floor.
2. **Integrity.** Read the last section, the audit-trail check: when the chain verifies, no record has been changed or removed since it was written. Under Settings, "Check the audit trail now" runs it on demand.

## Set up a new company

For the owner, once. Part of _Setting up: the company, the tables, who can do what_.

1. **System Settings.** Company details, logo and colours, bank details and the document footer. Save and read the checklist.
2. **Settings.** Tax rate, numbering prefixes, when to invoice, the lowest margin and quote validity. Leave the alert emails off until you want them.
3. **Settings.** Who can do what: tick the screens each person works in.
4. **Products.** Load the master tables (see "Load your products and customers from a spreadsheet").
5. **System Settings.** Add the encryption key to wp-config.php before payroll, and a real cron for the 02:00 run.

