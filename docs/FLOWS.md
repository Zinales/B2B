# B2BGro: how the work flows

_Written by tools/guide-md.php from WB_Guide::FLOWS; edit the class, not this file. The same words are on the How-to screen in the workspace and in the fold at the foot of every screen._

## Selling: from a quote to the goods going out

Everything in the sale is typed once, on the quote. The order, the invoice and the delivery note are built from it, never retyped, and each gets its number in sequence the moment it is issued.

1. **Quotes.** Write a quote: choose the customer, then find each product by typing part of its code or name; the list shows this customer's price, where it comes from and what is in stock. As each line is added, check one finds the price this customer pays (their own rule, else their category rule, else their price tier, else the list price) and check two makes sure the product allows it (not below the floor of cost plus the lowest margin, not above the list price, and inside the product's price dates). Quantities and prices can be changed on the lines and saved in one press; every changed line is checked again.
2. **Quotes.** A line that breaks check two cannot go out. Press "Ask for price approval" and a second person decides under "Prices waiting for your approval"; the Why column says in words what is wrong. A typed price is a manual price and goes through the same check.
3. **Quotes.** Press "Send by email": the customer's contact is ticked, the message is filled from your template, the PDF is attached, and the acceptance link goes in the message. Sending freezes the quote. The link lets the customer accept without a login for seven days; customers with a portal login also see it under their account. If it went out another way, "Mark sent and get the acceptance link" does the same without the email. If the customer tells you by phone, press "Record acceptance".
4. **Orders.** Acceptance builds the order (ORD) from the quote's frozen lines, prices and price sources. A declined quote is marked declined; a quote past its valid-until date is marked expired by the nightly run.
5. **Invoices.** The invoice (INV) is issued from the order, with the VAT rate of that moment frozen on it. By default this happens on acceptance. Under Settings, "Invoice when" can be set to "the goods leave" instead; a cash customer is always invoiced on acceptance so they can pay before collecting. "Issue invoice" on the order's menu issues it by hand.
6. **Orders.** The release check decides whether goods may leave: an account on hold or closed, no; the invoice paid, yes; a cash customer who has not paid, no; a customer on terms, yes while nothing is overdue and everything they owe, this order included, is inside their credit limit. "Release for collection / delivery" runs it and puts the stock aside.
7. **Deliveries.** Print the picking list from the order's menu: what to take, how many, where it was last put away, and which need a batch written down. Then issue the delivery or collection note (DN) for the quantities going out, or "Issue note for everything left". Issuing it takes the stock off the books, there and then. "Sign for it" records who took the goods, with their signature from a finger on a phone or a mouse.
8. **Orders.** When every line has been delivered and the invoice is paid or inside its terms, close the order. An order can be cancelled at any point before goods have left.
9. **Quotes.** At the counter, "Quick sale" does all of this in one press for goods paid and taken now: the quote, the order, the invoice, the payment, the release and the signed collection note, each with its own number. A price that needs approval cannot be a counter sale; if any step is refused it stops there and says which.

The rules it keeps:

- There is no standalone invoice: every invoice traces back to an order, and every order to an accepted quote.
- An issued document (quote, order, invoice, note) is never edited. A mistake is corrected by a new document: a new quote, a credit note.
- Two people for a price below the floor: the person who asks and the person who approves are never the same.

## Getting paid: the bank statement, matching and credit notes

Money is matched to invoices from the bank statement, and the system never guesses in silence: a payment either carries an invoice number, or a person confirms where it belongs.

1. **Payments.** Import the bank statement as a CSV. Choose the bank layout (or build one once: pick the header row, say which column is the date, the description, the reference and the amount, and it is saved under the bank's name). Only money in becomes a payment; the same file imported twice adds nothing.
2. **Payments.** Matching runs on import. A payment whose reference holds exactly one open invoice number is matched (or part-paid if it is short). A payment with no number but exactly one open invoice of that amount is suggested, and waits under "To match" for "Confirm suggested match". Anything else is unmatched and says why.
3. **Payments.** An unmatched payment is matched by hand under "Match a payment by hand", with a note saying why. Hand matches are listed apart in the Integrity report. Cash or card taken at the counter is recorded under its own fold and matched at once.
4. **Invoices.** As money arrives the invoice moves from issued to part paid to paid. At 02:00 the nightly run marks anything past its due date overdue, which blocks that customer's next release until it is settled.
5. **Invoices.** A correction is a credit note. Press "Ask for a credit note" against the invoice with the amount and the reason; a different person approves it under "Credit notes waiting for approval". Only on approval does it take its CRN number and PDF, so the series stays gapless, and the invoice's outstanding amount drops.
6. **Invoices.** The Chase fold at the top of Invoices shows everything owed by how late, then every customer with something past its due date, the most overdue money first, with when they were last chased. "Send a reminder" or "Send a statement" from there.
7. **Customers.** Each customer's page shows what they owe and how late, in five columns from not yet due to over 90 days. "Send a statement" or, when something is late, "Send a reminder" emails the statement PDF with the words from your template. "Monthly statement by email" switches on a statement on the same day every month while they owe anything; the owner sets the day on the Chase fold.
8. **Your account.** A customer with a login sees their open invoices, downloads any invoice or credit note, and their statement, from their account.

The rules it keeps:

- A match always records who, how and when. Automatic matches are by reference only.
- A credit note is asked for by one person and approved by another; the request never changes the invoice by itself.

## Stock: a ledger of movements, corrections with two names, stocktakes

Nobody types a stock level. On hand is the sum of every movement recorded; put aside is what released orders hold; available is the difference. Receiving adds, delivery notes subtract, and anything else needs two people.

1. **Stock.** Read the levels: on hand, put aside and available per product, with the reorder point. Every figure can be opened to the movements behind it.
2. **Stock.** Correct stock, write some off, or record a return: press "Correct stock" (the fold "Correct stock or write it off"). That makes a request with your name and the reason. Nothing moves yet.
3. **Stock.** Someone else holding the approval right decides under "Stock changes waiting for your approval". On approval the movement is written with both names on it.
4. **Stock.** A stocktake: "Start a stocktake", save the counts, and "Hand over for checking". A different person presses "Check and post the count"; the differences are posted as movements carrying the counter's and the checker's names.
5. **Stock.** When available plus on order falls to a product's reorder point, a reorder alert is raised and stays open until a purchase order covers it.
6. **Integrity.** Every adjustment, write-off and count difference appears in the month's Integrity report, by person.

The rules it keeps:

- Stock is never edited, only moved.
- An adjustment, a write-off and a count difference always carry two names.

## Purchasing: ordering from suppliers and receiving

A purchase order tells the supplier what you want and tells the cashflow what you have committed to; receiving it is what puts the stock on the books.

1. **Purchasing.** See what to reorder (the open reorder alerts) and what is on its way.
2. **Purchasing.** Raise a purchase order: the supplier, the products and quantities, the expected date. It is numbered PO, and it resolves the reorder alerts it covers.
3. **Purchasing.** "Mark sent" once it has gone to the supplier; the PDF is made then. The open order is counted as a committed payment in the cashflow, on the supplier's terms.
4. **Purchasing.** Receive it when it arrives under "Receive stock", line by line, with what actually came, where it was put away, and for a product tracked by batch the batch number and its expiry, typed as printed on the goods. Each receipt is a stock movement that adds to on hand. A short delivery leaves the order open for the rest.
5. **Purchasing.** Cancel an order the supplier will not fill; the cashflow drops it that night.

The rules it keeps:

- Received quantities are typed once, at the door; the stock level is never typed.

## Products and pricing: categories, tiers, rules, datasheets

A category groups products and carries the lowest margin and the shared specification rows. A price tier is a discount off list that a customer sits in. A rule is one customer's own price for one product or one category. The list price is what everyone else pays.

1. **Products.** Add a product: its category, cost, list price, lowest margin (else the category's, else the policy), reorder point and price dates. Type one in or upload a file of them. On the product's page add a picture (JPG, PNG or WebP): it shows on the lists, on quote lines and on the datasheet.
2. **Products.** Set up categories and price tiers under their own folds. One tier is the default for new customers.
3. **Customers.** Put each customer in a tier. Add a rule for a customer who has negotiated their own price for a product or a whole category; a second person approves the rule before it is used.
4. **Quotes.** When a line is quoted, the price comes from the most specific thing that applies: the customer's product rule, then their category rule (nearest category first), then their tier, then list. The source is shown and frozen on the line.
5. **Documents.** A product's datasheet is a row of data: a one-line summary, description, applications and handling notes, which join the product's specification rows and render to a PDF with your letterhead whenever anyone asks for one. Type the rows, or upload a CSV to change a whole range at once. Where the supplier's own sheet is better, upload the file instead, or give the address of the online datasheet; the row says which one the customer gets.

The rules it keeps:

- A category never sets a selling price.
- A rule below the floor is refused until approved, like a quote line.

## Customers and their portal

A customer record carries the terms, the credit limit, the price tier and the account status that the release check reads. Each customer can be given a login that shows them only their own account.

1. **Customers.** Add a customer with their payment terms (0 days is a cash customer), credit limit, price tier and status. Add their contacts, and tick who receives invoices and who receives datasheets: those are the people ticked when you send. Archive, never delete.
2. **Customers.** Click a customer's name anywhere to open their page: what they owe and how late, their terms, limit and tier, then their open invoices, quotes, orders, payments, contacts, the timeline, their own prices and their documents.
3. **Customers.** Under "Contacts and portal logins", a contact's menu has "Give a portal login (sends the set-password email now)". The email goes out only when that is pressed, never by itself. The same menu can send it again, or turn the login off.
4. **Your account.** The customer signs in and sees their open quotes (to accept or decline), unpaid invoices with due dates, orders, deliveries, their statement and the datasheets for what they buy.
5. **Your account.** Under Order, the customer sees their products at their own price with the stock in words (in stock, low, to order), searches the rest of the range, and presses "Put these in my basket" on any past order to order it again. "Ask for a quote for these" turns the basket into a draft quote for the rep; it is never sent or accepted by itself. A price that would need approval shows as "Price on request".
6. **Your account.** "Ask for this change" under their details sends a request that staff approve under "Contact changes customers asked for"; nothing is written to the customer record directly.
7. **Marketing.** Every portal sign-in, call and visit is a touchpoint on the customer's timeline.

The rules it keeps:

- A portal login belongs to exactly one contact and through it to one customer; everything is scoped to that customer and fails closed.
- Customer-facing email is never automatic.

## Knowing: demand, cashflow and the Integrity report

Every night at 02:00 the system works out, from what actually happened, what is likely to happen next: who will order, when money will move, and whether anyone has had to put their name to something unusual.

1. **Today.** Today, for people who see the money: this month so far against the same days last year (sales before VAT and cash received), and the next four weeks of cash from last night's forecast, week by week.
2. **Marketing.** Record a contact (a call, a visit, a complaint) against a customer. Read who is likely to order soon, who has gone quiet, and each customer's stage: lead, quoted, first order, repeat, at risk, lapsed.
3. **The system, at night.** Nightly: per customer and product, the rhythm of ordering and the predicted next order with a confidence; per product, which months are busy; each customer's stage, updated only when it changes.
4. **Cashflow.** Thirteen weeks ahead, week by week: invoices due (shifted by how late each customer actually pays), likely orders, open purchase orders on the supplier's terms, and payroll. Each night's forecast is kept so it can be checked later.
5. **Integrity.** On the first of the month, last month's Integrity report: stock adjustments and write-offs, count differences, credit notes with the payment they reverse, hand-matched payments, prices approved below the floor, and the audit-trail check, each by the person who asked and the person who approved.
6. **Notifications.** What the system noticed (a reorder alert, an overdue invoice, a chain check) is a notification in the workspace. Email is off for every group until the owner switches it on under Settings.

The rules it keeps:

- Forecasts are worked out from records, never typed.
- The audit trail is a hash chain that is verified every night; a break notifies the owner.

## Team: timesheets, leave, KPIs and reviews

People record their own time and leave; someone other than them approves it. Hours and leave days are worked out, never typed.

1. **Staff.** My timesheet: start, end and break for the day; the hours are computed. Submit it. The approver, never the person, approves or queries it.
2. **Staff.** Ask for leave between two dates. The days are working days (weekends and South African public holidays excluded); the balance is entitlement accrued less approved days, computed each time. Someone else approves or declines.
3. **Staff.** KPIs are measured monthly from the engines' own records where the measure allows (quotes sent, acceptance rate, matched payments) and typed only where it does not.
4. **Staff.** A review runs scheduled, self review, manager review, discussed, signed by both.

The rules it keeps:

- Nobody approves their own timesheet or leave.
- Leave rules default to the Basic Conditions of Employment Act and are editable per company under Leave types.

## Payroll: a monthly run with two people on it

The system calculates and records pay and produces the payslips and the figures; it never pays anyone and never submits anything to SARS.

1. **Payroll.** A payroll profile per person paid: salaried or hourly, the rate, tax number and bank account (stored encrypted), date of birth for the rebates, medical scheme members, retirement contribution. It needs the encryption key in wp-config.php.
2. **Payroll.** "Start a pay run" for the month and "Calculate draft". Hourly pay comes from approved timesheets only (ordinary hours to the weekly limit, overtime above it, public holidays at their rate); approved unpaid leave and start or end dates in the month reduce a salary pro rata. Recalculate after any change.
3. **Payroll.** A different person presses "I have checked this pay run". Anything edited after that sends it back to draft.
4. **Payroll.** Finalise. The payslips are written once and never change; their PDFs are made; each person opens their own. The EMP201 figures (PAYE, UIF, SDL) are shown for you to capture, and the bank file is yours to upload.
5. **Payroll.** A correction is an adjustment line in the next run, never an edit to a finalised payslip. Tax tables live per tax year under Payroll settings and are never guessed: a pay date with no tax year is refused.

The rules it keeps:

- Draft, checked by a different person, finalised. A finalised payslip cannot be changed.
- Bank details and tax numbers are encrypted at rest.

## Setting up: the company, who can do what, and the IT part

An owner sets the company up once; everything after that is day-to-day work. The checklist under System Settings shows what is still to do.

1. **System Settings.** Company details: name, registration and VAT numbers, address, logo, colours (checked for contrast), bank details and the footer line that go on every document.
2. **Settings.** Tax rate, numbering, when to invoice (on acceptance or when goods leave), the lowest margin and quote validity, which alert groups may email, and the demo switch.
3. **Settings.** Who can do what: tick the screens each person works in. A person's menu shows only those, and a screen they cannot open says who can give it to them.
4. **Products.** Load the master tables: categories, price tiers, products, customers, contacts, suppliers, staff, leave types, KPIs. Type them in on each screen, or download the template, fill it, "Check file" and then "Validate and import". A file with one problem imports nothing and lists the problems by row.
5. **Settings.** Email templates: the words each "Send by email" starts with, for quotes, invoices, credit notes, statements, reminders and datasheets. The details fill themselves in. The site must be able to send email (an SMTP plugin is the reliable way).
6. **Customers.** Archive, never delete: an archived customer or product leaves the lists, and "Show archived" under the list brings them into view with "Restore" on each.
7. **Technical.** For IT, once, on the Technical screen: create the business tables, and check the PDF engine, the encryption key, the private folder, email and the nightly jobs. The owner never needs this screen; WordPress administrators have it. A real server cron for wp-cron makes the 02:00 run (overdue sweep, quote expiry, reorder alerts, demand and cashflow, chain check, the Integrity report on the first) is reliable.

The rules it keeps:

- Imports are all or nothing and ledgered as one entry.
- Settings are configuration, kept apart from business data, and every change is recorded.

## The documents and the audit trail

Every numbered document is a PDF made once, at the moment it is issued; every change anywhere is a line in a chain that cannot be edited.

1. **Documents.** Quote, invoice, credit note, delivery note and purchase order PDFs carry the letterhead from System Settings, the lines, the totals with VAT, the bank details and the footer. "Download PDF" is on each document's menu; the portal hands the customer theirs through short-lived links.
2. **The system, at night.** Every write calls the ledger: who, what, before and after, hashed onto the entry before it. Changing or removing a row breaks every hash after it, and the nightly check finds the first break.
3. **Integrity.** Nothing is deleted: records are archived (and demo rows voided), so the trail is always whole. The audit-trail check is the last row of the Integrity report.

The rules it keeps:

- A PDF is made once and kept; an issued document is immutable.
- The ledger is a plugin table nothing in the interface can edit.

## The demo

The demo is a shared sample company with a year of trading behind it, played through the real engines, so every figure has a trail. The demo visitor can press anything; at 02:00 the data is wiped and the year is replayed.

1. **Settings.** Nobody has to give their details to try the demo. A "Keep me posted" box on the front page and the sign-in page lets anyone who wants news of releases and special offers leave an email and tick to agree; the owner downloads that list, with each person's unsubscribe link, under Settings.
2. **Settings.** The owner loads the demo data and opens the demo login under Settings, where the demo's login name and password are shown and the password can be changed. The front page then shows "Try the demo" and the login details, and the sign-in page shows them beside an "Enter the demo" button. A visitor can press the button or type the details into the ordinary form.
3. **Today.** Today shows what the demo visitor can clear: prices to approve, payments to confirm, a stock correction, a stocktake to check, timesheets and leave, a draft pay run.
4. **Quotes.** Walk the sale: write a quote, send it, accept it, watch the order and invoice appear, match the money, release and deliver.
5. **The system, at night.** Nightly: everything the demo wrote is removed (numbered documents are voided so the series stay gapless), and a fresh year is generated up to today.

The rules it keeps:

- The demo visitor cannot reach Settings, System Settings or WordPress admin.
- Rows a real person made are never touched by the demo wipe.

