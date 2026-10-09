<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Guide — the flows the system runs, and how to walk them (1.3.0, Zina: "an explanation of
 * the flows you've built, and a how-to section with walkthroughs").
 *
 * One source of words, three places it shows:
 *   - the How-to screen (the 'howto' screen, shortcode [wb_howto]): every flow in plain words,
 *     then the walkthroughs, each step naming the screen it happens on (a link the person can open);
 *   - a read-only "How this screen fits in" fold at the foot of every work screen, showing the
 *     flow that screen is part of with its own step marked;
 *   - docs/FLOWS.md and docs/HOW-TO.md, written by tools/guide-md.php from the same constants,
 *     so the documents can never say something different from the program.
 *
 * Nothing here is business data and nothing is stored: it is the system describing itself, so
 * the demo visitor reads exactly what a customer's staff will read.
 */
class WB_Guide {

	/**
	 * The flows. key => [ title, the one idea, [ [ screen slug, step ], ... ], [ rules it keeps ] ].
	 * A step's screen is where that step is done ('' for something the system does by itself at night).
	 */
	const FLOWS = [
		'sell' => [ 'Selling: from a quote to the goods going out',
			'Everything in the sale is typed once, on the quote. The order, the invoice and the delivery note are built from it, never retyped, and each gets its number in sequence the moment it is issued.',
			[
				[ 'quotes', 'Write a quote: choose the customer, then find each product by typing part of its code or name; the list shows this customer\'s price, where it comes from and what is in stock. As each line is added, check one finds the price this customer pays (their own rule, else their category rule, else their price tier, else the list price) and check two makes sure the product allows it (not below the floor of cost plus the lowest margin, not above the list price, and inside the product\'s price dates). Quantities and prices can be changed on the lines and saved in one press; every changed line is checked again.' ],
				[ 'quotes', 'A line that breaks check two cannot go out. Press "Ask for price approval" and a second person decides under "Prices waiting for your approval"; the Why column says in words what is wrong. A typed price is a manual price and goes through the same check.' ],
				[ 'quotes', 'Press "Send by email": the customer\'s contact is ticked, the message is filled from your template, the PDF is attached, and the acceptance link goes in the message. Sending freezes the quote. The link lets the customer accept without a login for seven days; customers with a portal login also see it under their account. If it went out another way, "Mark sent and get the acceptance link" does the same without the email. If the customer tells you by phone, press "Record acceptance".' ],
				[ 'orders', 'Acceptance builds the order (ORD) from the quote\'s frozen lines, prices and price sources. A declined quote is marked declined; a quote past its valid-until date is marked expired by the nightly run.' ],
				[ 'invoices', 'The invoice (INV) is issued from the order, with the VAT rate of that moment frozen on it. By default this happens on acceptance. Under Settings, "Invoice when" can be set to "the goods leave" instead; a cash customer is always invoiced on acceptance so they can pay before collecting. "Issue invoice" on the order\'s menu issues it by hand.' ],
				[ 'orders', 'The release check decides whether goods may leave: an account on hold or closed, no; the invoice paid, yes; a cash customer who has not paid, no; a customer on terms, yes while nothing is overdue and everything they owe, this order included, is inside their credit limit. "Release for collection / delivery" runs it and puts the stock aside.' ],
				[ 'deliveries', 'Print the picking list from the order\'s menu: what to take, how many, where it was last put away, and which need a batch written down. Then issue the delivery or collection note (DN) for the quantities going out, or "Issue note for everything left". Issuing it takes the stock off the books, there and then. "Sign for it" records who took the goods, with their signature from a finger on a phone or a mouse.' ],
				[ 'orders', 'When every line has been delivered and the invoice is paid or inside its terms, close the order. An order can be cancelled at any point before goods have left.' ],
				[ 'quotes', 'At the counter, "Quick sale" does all of this in one press for goods paid and taken now: the quote, the order, the invoice, the payment, the release and the signed collection note, each with its own number. A price that needs approval cannot be a counter sale; if any step is refused it stops there and says which.' ],
			],
			[ 'There is no standalone invoice: every invoice traces back to an order, and every order to an accepted quote.', 'An issued document (quote, order, invoice, note) is never edited. A mistake is corrected by a new document: a new quote, a credit note.', 'Two people for a price below the floor: the person who asks and the person who approves are never the same.' ],
		],
		'money' => [ 'Getting paid: the bank statement, matching and credit notes',
			'Money is matched to invoices from the bank statement, and the system never guesses in silence: a payment either carries an invoice number, or a person confirms where it belongs.',
			[
				[ 'payments', 'Import the bank statement as a CSV. Choose the bank layout (or build one once: pick the header row, say which column is the date, the description, the reference and the amount, and it is saved under the bank\'s name). Only money in becomes a payment; the same file imported twice adds nothing.' ],
				[ 'payments', 'Matching runs on import. A payment whose reference holds exactly one open invoice number is matched (or part-paid if it is short). A payment with no number but exactly one open invoice of that amount is suggested, and waits under "To match" for "Confirm suggested match". Anything else is unmatched and says why.' ],
				[ 'payments', 'An unmatched payment is matched by hand under "Match a payment by hand", with a note saying why. Hand matches are listed apart in the Integrity report. Cash or card taken at the counter is recorded under its own fold and matched at once.' ],
				[ 'invoices', 'As money arrives the invoice moves from issued to part paid to paid. At 02:00 the nightly run marks anything past its due date overdue, which blocks that customer\'s next release until it is settled.' ],
				[ 'invoices', 'A correction is a credit note. Press "Ask for a credit note" against the invoice with the amount and the reason; a different person approves it under "Credit notes waiting for approval". Only on approval does it take its CRN number and PDF, so the series stays gapless, and the invoice\'s outstanding amount drops.' ],
				[ 'invoices', 'The Chase fold at the top of Invoices shows everything owed by how late, then every customer with something past its due date, the most overdue money first, with when they were last chased. "Send a reminder" or "Send a statement" from there.' ],
				[ 'customers', 'Each customer\'s page shows what they owe and how late, in five columns from not yet due to over 90 days. "Send a statement" or, when something is late, "Send a reminder" emails the statement PDF with the words from your template. "Monthly statement by email" switches on a statement on the same day every month while they owe anything; the owner sets the day on the Chase fold.' ],
				[ 'portal', 'A customer with a login sees their open invoices, downloads any invoice or credit note, and their statement, from their account.' ],
			],
			[ 'A match always records who, how and when. Automatic matches are by reference only.', 'A credit note is asked for by one person and approved by another; the request never changes the invoice by itself.' ],
		],
		'stock' => [ 'Stock: a ledger of movements, corrections with two names, stocktakes',
			'Nobody types a stock level. On hand is the sum of every movement recorded; put aside is what released orders hold; available is the difference. Receiving adds, delivery notes subtract, and anything else needs two people.',
			[
				[ 'stock', 'Read the levels: on hand, put aside and available per product, with the reorder point. Every figure can be opened to the movements behind it.' ],
				[ 'stock', 'Correct stock, write some off, or record a return: press "Correct stock" (the fold "Correct stock or write it off"). That makes a request with your name and the reason. Nothing moves yet.' ],
				[ 'stock', 'Someone else holding the approval right decides under "Stock changes waiting for your approval". On approval the movement is written with both names on it.' ],
				[ 'stock', 'A stocktake: "Start a stocktake", save the counts, and "Hand over for checking". A different person presses "Check and post the count"; the differences are posted as movements carrying the counter\'s and the checker\'s names.' ],
				[ 'stock', 'When available plus on order falls to a product\'s reorder point, a reorder alert is raised and stays open until a purchase order covers it.' ],
				[ 'integrity', 'Every adjustment, write-off and count difference appears in the month\'s Integrity report, by person.' ],
			],
			[ 'Stock is never edited, only moved.', 'An adjustment, a write-off and a count difference always carry two names.' ],
		],
		'buy' => [ 'Purchasing: ordering from suppliers and receiving',
			'A purchase order tells the supplier what you want and tells the cashflow what you have committed to; receiving it is what puts the stock on the books.',
			[
				[ 'purchasing', 'See what to reorder (the open reorder alerts) and what is on its way.' ],
				[ 'purchasing', 'Raise a purchase order: the supplier, the products and quantities, the expected date. It is numbered PO, and it resolves the reorder alerts it covers.' ],
				[ 'purchasing', '"Mark sent" once it has gone to the supplier; the PDF is made then. The open order is counted as a committed payment in the cashflow, on the supplier\'s terms.' ],
				[ 'purchasing', 'Receive it when it arrives under "Receive stock", line by line, with what actually came, where it was put away, and for a product tracked by batch the batch number and its expiry, typed as printed on the goods. Each receipt is a stock movement that adds to on hand. A short delivery leaves the order open for the rest.' ],
				[ 'purchasing', 'Cancel an order the supplier will not fill; the cashflow drops it that night.' ],
			],
			[ 'Received quantities are typed once, at the door; the stock level is never typed.' ],
		],
		'price' => [ 'Products and pricing: categories, tiers, rules, datasheets',
			'A category groups products and carries the lowest margin and the shared specification rows. A price tier is a discount off list that a customer sits in. A rule is one customer\'s own price for one product or one category. The list price is what everyone else pays.',
			[
				[ 'products', 'Add a product: its category, cost, list price, lowest margin (else the category\'s, else the policy), reorder point and price dates. Type one in or upload a file of them. On the product\'s page add a picture (JPG, PNG or WebP): it shows on the lists, on quote lines and on the datasheet.' ],
				[ 'products', 'Set up categories and price tiers under their own folds. One tier is the default for new customers.' ],
				[ 'customers', 'Put each customer in a tier. Add a rule for a customer who has negotiated their own price for a product or a whole category; a second person approves the rule before it is used.' ],
				[ 'quotes', 'When a line is quoted, the price comes from the most specific thing that applies: the customer\'s product rule, then their category rule (nearest category first), then their tier, then list. The source is shown and frozen on the line.' ],
				[ 'documents', 'A product\'s datasheet is a row of data: a one-line summary, description, applications and handling notes, which join the product\'s specification rows and render to a PDF with your letterhead whenever anyone asks for one. Type the rows, or upload a CSV to change a whole range at once. Where the supplier\'s own sheet is better, upload the file instead, or give the address of the online datasheet; the row says which one the customer gets.' ],
			],
			[ 'A category never sets a selling price.', 'A rule below the floor is refused until approved, like a quote line.' ],
		],
		'customer' => [ 'Customers and their portal',
			'A customer record carries the terms, the credit limit, the price tier and the account status that the release check reads. Each customer can be given a login that shows them only their own account.',
			[
				[ 'customers', 'Add a customer with their payment terms (0 days is a cash customer), credit limit, price tier and status. Add their contacts, and tick who receives invoices and who receives datasheets: those are the people ticked when you send. Archive, never delete.' ],
				[ 'customers', 'Click a customer\'s name anywhere to open their page: what they owe and how late, their terms, limit and tier, then their open invoices, quotes, orders, payments, contacts, the timeline, their own prices and their documents.' ],
				[ 'customers', 'Under "Contacts and portal logins", a contact\'s menu has "Give a portal login (sends the set-password email now)". The email goes out only when that is pressed, never by itself. The same menu can send it again, or turn the login off.' ],
				[ 'portal', 'The customer signs in and sees their open quotes (to accept or decline), unpaid invoices with due dates, orders, deliveries, their statement and the datasheets for what they buy.' ],
				[ 'portal', 'Under Order, the customer sees their products at their own price with the stock in words (in stock, low, to order), searches the rest of the range, and presses "Put these in my basket" on any past order to order it again. "Ask for a quote for these" turns the basket into a draft quote for the rep; it is never sent or accepted by itself. A price that would need approval shows as "Price on request".' ],
				[ 'portal', '"Ask for this change" under their details sends a request that staff approve under "Contact changes customers asked for"; nothing is written to the customer record directly.' ],
				[ 'marketing', 'Every portal sign-in, call and visit is a touchpoint on the customer\'s timeline.' ],
			],
			[ 'A portal login belongs to exactly one contact and through it to one customer; everything is scoped to that customer and fails closed.', 'Customer-facing email is never automatic.' ],
		],
		'know' => [ 'Knowing: demand, cashflow and the Integrity report',
			'Every night at 02:00 the system works out, from what actually happened, what is likely to happen next: who will order, when money will move, and whether anyone has had to put their name to something unusual.',
			[
				[ 'home', 'Today, for people who see the money: this month so far against the same days last year (sales before VAT and cash received), and the next four weeks of cash from last night\'s forecast, week by week.' ],
				[ 'marketing', 'Record a contact (a call, a visit, a complaint) against a customer. Read who is likely to order soon, who has gone quiet, and each customer\'s stage: lead, quoted, first order, repeat, at risk, lapsed.' ],
				[ '', 'Nightly: per customer and product, the rhythm of ordering and the predicted next order with a confidence; per product, which months are busy; each customer\'s stage, updated only when it changes.' ],
				[ 'cashflow', 'Thirteen weeks ahead, week by week: invoices due (shifted by how late each customer actually pays), likely orders, open purchase orders on the supplier\'s terms, and payroll. Each night\'s forecast is kept so it can be checked later.' ],
				[ 'integrity', 'On the first of the month, last month\'s Integrity report: stock adjustments and write-offs, count differences, credit notes with the payment they reverse, hand-matched payments, prices approved below the floor, and the audit-trail check, each by the person who asked and the person who approved.' ],
				[ 'notifications', 'What the system noticed (a reorder alert, an overdue invoice, a chain check) is a notification in the workspace. Email is off for every group until the owner switches it on under Settings.' ],
			],
			[ 'Forecasts are worked out from records, never typed.', 'The audit trail is a hash chain that is verified every night; a break notifies the owner.' ],
		],
		'team' => [ 'Team: timesheets, leave, KPIs and reviews',
			'People record their own time and leave; someone other than them approves it. Hours and leave days are worked out, never typed.',
			[
				[ 'staff', 'My timesheet: start, end and break for the day; the hours are computed. Submit it. The approver, never the person, approves or queries it.' ],
				[ 'staff', 'Ask for leave between two dates. The days are working days (weekends and South African public holidays excluded); the balance is entitlement accrued less approved days, computed each time. Someone else approves or declines.' ],
				[ 'staff', 'KPIs are measured monthly from the engines\' own records where the measure allows (quotes sent, acceptance rate, matched payments) and typed only where it does not.' ],
				[ 'staff', 'A review runs scheduled, self review, manager review, discussed, signed by both.' ],
			],
			[ 'Nobody approves their own timesheet or leave.', 'Leave rules default to the Basic Conditions of Employment Act and are editable per company under Leave types.' ],
		],
		'pay' => [ 'Payroll: a monthly run with two people on it',
			'The system calculates and records pay and produces the payslips and the figures; it never pays anyone and never submits anything to SARS.',
			[
				[ 'payroll', 'A payroll profile per person paid: salaried or hourly, the rate, tax number and bank account (stored encrypted), date of birth for the rebates, medical scheme members, retirement contribution. It needs the encryption key in wp-config.php.' ],
				[ 'payroll', '"Start a pay run" for the month and "Calculate draft". Hourly pay comes from approved timesheets only (ordinary hours to the weekly limit, overtime above it, public holidays at their rate); approved unpaid leave and start or end dates in the month reduce a salary pro rata. Recalculate after any change.' ],
				[ 'payroll', 'A different person presses "I have checked this pay run". Anything edited after that sends it back to draft.' ],
				[ 'payroll', 'Finalise. The payslips are written once and never change; their PDFs are made; each person opens their own. The EMP201 figures (PAYE, UIF, SDL) are shown for you to capture, and the bank file is yours to upload.' ],
				[ 'payroll', 'A correction is an adjustment line in the next run, never an edit to a finalised payslip. Tax tables live per tax year under Payroll settings and are never guessed: a pay date with no tax year is refused.' ],
			],
			[ 'Draft, checked by a different person, finalised. A finalised payslip cannot be changed.', 'Bank details and tax numbers are encrypted at rest.' ],
		],
		'setup' => [ 'Setting up: the company, the tables, who can do what',
			'An owner sets the company up once; everything after that is day-to-day work. The checklist under System Settings shows what is still to do.',
			[
				[ 'setup', 'Company details: name, registration and VAT numbers, address, logo, colours (checked for contrast), bank details and the footer line that go on every document.' ],
				[ 'settings', 'Tax rate, numbering, when to invoice (on acceptance or when goods leave), the lowest margin and quote validity, which alert groups may email, and the demo switch.' ],
				[ 'settings', 'Who can do what: tick the screens each person works in. A person\'s menu shows only those, and a screen they cannot open says who can give it to them.' ],
				[ 'products', 'Load the master tables: categories, price tiers, products, customers, contacts, suppliers, staff, leave types, KPIs. Type them in on each screen, or download the template, fill it, "Check file" and then "Validate and import". A file with one problem imports nothing and lists the problems by row.' ],
				[ 'settings', 'Email templates: the words each "Send by email" starts with, for quotes, invoices, credit notes, statements, reminders and datasheets. The details fill themselves in. The site must be able to send email (an SMTP plugin is the reliable way).' ],
				[ 'customers', 'Archive, never delete: an archived customer or product leaves the lists, and "Show archived" under the list brings them into view with "Restore" on each.' ],
				[ 'technical', 'For IT, once, on the Technical screen: create the business tables, and check the PDF engine, the encryption key, the private folder, email and the nightly jobs. The owner never needs this screen; WordPress administrators have it. A real server cron for wp-cron makes the 02:00 run (overdue sweep, quote expiry, reorder alerts, demand and cashflow, chain check, the Integrity report on the first) is reliable.' ],
			],
			[ 'Imports are all or nothing and ledgered as one entry.', 'Settings are configuration, kept apart from business data, and every change is recorded.' ],
		],
		'trail' => [ 'The documents and the audit trail',
			'Every numbered document is a PDF made once, at the moment it is issued; every change anywhere is a line in a chain that cannot be edited.',
			[
				[ 'documents', 'Quote, invoice, credit note, delivery note and purchase order PDFs carry the letterhead from System Settings, the lines, the totals with VAT, the bank details and the footer. "Download PDF" is on each document\'s menu; the portal hands the customer theirs through short-lived links.' ],
				[ '', 'Every write calls the ledger: who, what, before and after, hashed onto the entry before it. Changing or removing a row breaks every hash after it, and the nightly check finds the first break.' ],
				[ 'integrity', 'Nothing is deleted: records are archived (and demo rows voided), so the trail is always whole. The audit-trail check is the last row of the Integrity report.' ],
			],
			[ 'A PDF is made once and kept; an issued document is immutable.', 'The ledger is a plugin table nothing in the interface can edit.' ],
		],
		'demo' => [ 'The demo',
			'The demo is a shared sample company with a year of trading behind it, played through the real engines, so every figure has a trail. The demo visitor can press anything; at 02:00 the data is wiped and the year is replayed.',
			[
				[ 'settings', 'Nobody has to give their details to try the demo. A "Keep me posted" box on the front page and the sign-in page lets anyone who wants news of releases and special offers leave an email and tick to agree; the owner downloads that list, with each person\'s unsubscribe link, under Settings.' ],
				[ 'settings', 'The owner loads the demo data and opens the demo login under Settings, where the demo\'s login name and password are shown and the password can be changed. The front page then shows "Try the demo" and the login details, and the sign-in page shows them beside an "Enter the demo" button. A visitor can press the button or type the details into the ordinary form.' ],
				[ 'home', 'Today shows what the demo visitor can clear: prices to approve, payments to confirm, a stock correction, a stocktake to check, timesheets and leave, a draft pay run.' ],
				[ 'quotes', 'Walk the sale: write a quote, send it, accept it, watch the order and invoice appear, match the money, release and deliver.' ],
				[ '', 'Nightly: everything the demo wrote is removed (numbered documents are voided so the series stay gapless), and a fresh year is generated up to today.' ],
			],
			[ 'The demo visitor cannot reach Settings, System Settings or WordPress admin.', 'Rows a real person made are never touched by the demo wipe.' ],
		],
	];

	/** Which flow(s) each screen is part of, for the fold at its foot. */
	const SCREEN_FLOW = [
		'home' => [ 'demo' ], 'notifications' => [ 'know' ], 'customers' => [ 'customer', 'price' ], 'quotes' => [ 'sell', 'price' ],
		'orders' => [ 'sell' ], 'invoices' => [ 'sell', 'money' ], 'payments' => [ 'money' ], 'deliveries' => [ 'sell' ],
		'products' => [ 'price', 'setup' ], 'stock' => [ 'stock' ], 'purchasing' => [ 'buy' ], 'documents' => [ 'price', 'trail' ],
		'marketing' => [ 'know', 'customer' ], 'cashflow' => [ 'know' ], 'integrity' => [ 'know', 'stock', 'trail' ],
		'staff' => [ 'team' ], 'payroll' => [ 'pay' ], 'setup' => [ 'setup' ], 'technical' => [ 'setup' ], 'settings' => [ 'setup', 'demo' ],
	];

	/**
	 * The walkthroughs. key => [ title, who it is for, [ [ screen slug, what to do ], ... ], flow key ].
	 * Written for a first visit to the demo; each step is one thing to press or read.
	 */
	const HOWTO = [
		'sale' => [ 'Sell something from quote to delivery', 'Sales and the owner', [
			[ 'quotes', 'Press "New quote". Choose a customer (Karoo Agri is on 30-day terms; Bayside Hardware is a cash customer). In Product, type "epoxy": the list shows each match with Karoo Agri\'s price and the stock. Choose one, give a quantity, and press "Start the quote".' ],
			[ 'quotes', 'Add a second product the same way. Then type a price well below cost in its Price each box and press "Save changes": the line turns red and says, in a sentence, which rule it breaks.' ],
			[ 'quotes', 'On that line press "Ask for price approval" with a reason. Sign in as someone else (or, in the demo, read it under "Prices waiting for your approval") and approve or decline it.' ],
			[ 'quotes', 'Press "Send by email" on the quote\'s menu. Read who is ticked and the message, then press Send. In the demo nothing leaves; it is recorded on the customer\'s timeline as if it had gone. Then press "Record acceptance" to stand in for the customer.' ],
			[ 'orders', 'Find the order. It carries the quote\'s lines and prices. Open the invoice from its menu: issued, numbered, with VAT frozen.' ],
			[ 'orders', 'Press "Release for collection / delivery". A terms customer inside their limit is released and the stock is put aside; a cash customer is refused until the invoice is paid.' ],
			[ 'deliveries', 'Issue the note for the quantities going out and record who signed. Watch the product\'s on hand drop on its page.' ],
			[ 'orders', 'Close the order once everything has gone and the invoice is paid or inside its terms.' ],
		], 'sell' ],
		'match' => [ 'Match a bank statement', 'Accounts', [
			[ 'payments', 'Press "Import a bank statement", choose the layout and the CSV, and upload. If the layout is new, pick the header row and name the columns; it is saved for next time.' ],
			[ 'payments', 'Read the result: matched by reference, suggested by amount, unmatched with the reason.' ],
			[ 'payments', 'Under "To match", each suggested payment names the invoice it looks like; press "Confirm suggested match" if it is right.' ],
			[ 'payments', 'For an unmatched payment, use "Match a payment by hand": choose the invoice and give a note. It will be listed in the Integrity report as a hand match.' ],
			[ 'invoices', 'Open Invoices: the matched invoices now read paid or part paid.' ],
		], 'money' ],
		'find' => [ 'Find anything', 'Everyone', [
			[ 'invoices', 'Type part of a number or a customer\'s name in the search box above any list and press Search. The words stay in the box; Clear puts the whole list back.' ],
			[ 'invoices', 'Press a status chip (Overdue, Part paid) to see only those. Press it again, or All, to see everything. Search and chip work together: "karoo" and Overdue shows Karoo Agri\'s late invoices.' ],
			[ 'invoices', 'Press a column heading to sort by it; press it again to turn it round. Long lists come in pages of fifty with Previous and Next. Every one of these is in the address, so a filtered list can be bookmarked or sent to a colleague.' ],
			[ 'customers', 'Click a customer\'s name in any list to open their page; click a product code to open the product\'s.' ],
		], 'know' ],
		'counter' => [ 'Make a counter sale', 'Whoever serves at the counter', [
			[ 'quotes', 'Open "Quick sale at the counter" on Quotes. Choose the customer (Bayside Hardware pays cash), find the product, give the quantity.' ],
			[ 'quotes', 'Choose how it was paid, type the slip number and the name of the person taking the goods, and press "Sold and paid".' ],
			[ 'orders', 'The order opens with its invoice paid and its collection note signed. Each has its own number and PDF, exactly as if every button had been pressed.' ],
		], 'sell' ],
		'chase' => [ 'Chase a customer who is late', 'Accounts', [
			[ 'invoices', 'Open the Chase fold at the top of Invoices: everything owed by how late, then the customers to chase, the most overdue money first. Click a customer\'s name.' ],
			[ 'customers', 'Their page shows what they owe and how late, and every open invoice with what is still owing on it.' ],
			[ 'customers', 'Press "Send a reminder". Their accounts contact is ticked, the message is filled in, and the statement PDF is attached. Send it.' ],
			[ 'customers', 'The reminder is on their timeline with the date and who it went to. When their payment comes in on the bank statement it matches the invoice by its number.' ],
		], 'money' ],
		'credit' => [ 'Issue a credit note', 'Accounts and a second approver', [
			[ 'invoices', 'Open "Ask for a credit note". Choose the invoice, the amount and the reason, and press "Ask for the credit note".' ],
			[ 'invoices', 'A different person opens "Credit notes waiting for approval" and presses "Approve credit note" (or Decline).' ],
			[ 'invoices', 'The credit note now has its CRN number and a PDF; the invoice\'s outstanding amount has dropped. Both names are on it in the Integrity report.' ],
		], 'money' ],
		'correct' => [ 'Correct stock or write some off', 'Stores and a second approver', [
			[ 'stock', 'Press "Correct stock". Choose the product, what happened (a correction up or down, a write-off, a return), the quantity and the reason.' ],
			[ 'stock', 'Someone else opens "Stock changes waiting for your approval" and approves or declines.' ],
			[ 'stock', 'Open the product\'s movements: the new movement carries who asked and who approved.' ],
		], 'stock' ],
		'count' => [ 'Do a stocktake', 'Stores and a checker', [
			[ 'stock', 'Under "Stocktakes" press "Start a stocktake". A sheet of products is made; the book figure is not shown to the counter.' ],
			[ 'stock', 'Type the counted quantities and "Save count". Then press "Hand over for checking" on the stocktake\'s menu.' ],
			[ 'stock', 'A different person presses "Check and post the count". The differences are posted as movements with both names, and show in the Integrity report.' ],
		], 'stock' ],
		'reorder' => [ 'Reorder from a supplier and receive it', 'Purchasing and stores', [
			[ 'purchasing', 'Read "To reorder": products at or below their reorder point.' ],
			[ 'purchasing', 'Open "New purchase order": supplier, lines, expected date. Save, then "Mark sent" on its menu and open the PDF. The alert it covers is resolved.' ],
			[ 'cashflow', 'Open Cashflow tomorrow: the order sits in the week its payment falls due.' ],
			[ 'purchasing', 'When it arrives, "Receive stock" with the quantities that came. On hand rises on the Stock screen.' ],
		], 'buy' ],
		'login' => [ 'Give a customer a portal login', 'Sales', [
			[ 'customers', 'Open the customer. Under "Contacts and portal logins", on the contact\'s menu press "Give a portal login (sends the set-password email now)".' ],
			[ 'customers', 'The set-password email is sent to that contact, once, now. "Send the set-password email again" is on the same menu if it did not arrive; so is "Turn the portal login off".' ],
			[ 'portal', 'The customer signs in at Sign in and lands on their account: quotes to accept, invoices, statement, datasheets, and the two request forms.' ],
		], 'customer' ],
		'load' => [ 'Load your products and customers from a spreadsheet', 'The owner, once', [
			[ 'products', 'Open "Upload categories from a file" (the fold under Categories) and download the template. Fill it, save as CSV, choose it and press "Check file (no import)". Fix what it lists, then "Validate and import". Then do the same for Price tiers, then Products.' ],
			[ 'customers', 'Customers next, then Contacts (a contact names its customer by the exact name or the _ID).' ],
			[ 'purchasing', 'Suppliers.' ],
			[ 'documents', 'Datasheets: one row per product with the summary, description, applications and handling notes, uploaded under "Upload datasheets from a file". The specification rows came in with the products.' ],
			[ 'staff', 'Staff, Leave types and KPIs.' ],
			[ 'products', 'Later changes: download the export, edit it, import it back. Rows with an _ID are updated, rows without one are added.' ],
		], 'setup' ],
		'payrun' => [ 'Run the month\'s payroll', 'The owner and a checker', [
			[ 'payroll', 'Make sure every person paid has a payroll profile, and that the month\'s timesheets and leave are approved.' ],
			[ 'payroll', 'Under "Start a pay run" choose the month and press "Calculate draft". Read each payslip; press "Recalculate" after any change to profiles, timesheets or leave.' ],
			[ 'payroll', 'A different person presses "I have checked this pay run". Then press "Finalise".' ],
			[ 'payroll', 'Download the payslip PDFs (each person can open their own), capture the EMP201 figures shown, and pay through your bank.' ],
		], 'pay' ],
		'time' => [ 'Record and approve time and leave', 'Everyone, and their approver', [
			[ 'staff', 'My timesheet: today\'s start, end and break. Save, then Submit.' ],
			[ 'staff', 'Ask for leave: the type and the dates; the working days and the balance are shown.' ],
			[ 'staff', 'The approver opens "Timesheets to approve" and "Leave to approve" and approves, queries or declines each one. The person\'s own rows are never theirs to approve.' ],
		], 'team' ],
		'integrity' => [ 'Read the Integrity report', 'The owner, monthly', [
			[ 'integrity', 'Choose the month. Read each section by person: adjustments and write-offs, count differences, credit notes with the payment each reverses, hand-matched payments, prices approved below the floor.' ],
			[ 'integrity', 'Read the last section, the audit-trail check: when the chain verifies, no record has been changed or removed since it was written. Under Settings, "Check the audit trail now" runs it on demand.' ],
		], 'know' ],
		'start' => [ 'Set up a new company', 'The owner, once', [
			[ 'setup', 'Company details, logo and colours, bank details and the document footer. Save and read the checklist.' ],
			[ 'settings', 'Tax rate, numbering prefixes, when to invoice, the lowest margin and quote validity. Leave the alert emails off until you want them.' ],
			[ 'settings', 'Who can do what: tick the screens each person works in.' ],
			[ 'products', 'Load the master tables (see "Load your products and customers from a spreadsheet").' ],
			[ 'technical', 'IT adds the encryption key to wp-config.php before payroll and a real cron for the 02:00 run, and checks both on the Technical screen.' ],
		], 'setup' ],
	];

	public static function init(): void {
		add_shortcode( 'wb_howto', [ __CLASS__, 'render' ] );
	}

	/** A screen's words as a link the reader can open ('' when the step is the system's own). */
	private static function screen_link( string $slug, bool $current = false ): string {
		if ( '' === $slug ) return '<span class="wb-guide-where wb-guide-where--sys">The system, at night</span>';
		$s = WB_Workspace::screen( $slug );
		if ( ! $s ) return '';
		$words = $current ? 'This screen' : $s[0];
		return '<a class="wb-guide-where' . ( $current ? ' is-current' : '' ) . '" href="' . esc_url( WB_Workspace::url( $slug ) ) . '">' . esc_html( $words ) . '</a>';
	}

	/** The steps of one flow as an ordered list; $here marks the steps done on the screen being read. */
	private static function steps( array $steps, string $here = '' ): string {
		$h = '<ol class="wb-guide-steps">';
		foreach ( $steps as [ $slug, $words ] ) {
			$on = '' !== $here && $slug === $here;
			$h .= '<li' . ( $on ? ' class="is-here"' : '' ) . '>' . self::screen_link( $slug, $on ) . '<span>' . esc_html( $words ) . '</span></li>';
		}
		return $h . '</ol>';
	}

	private static function rules( array $rules ): string {
		if ( ! $rules ) return '';
		$h = '<ul class="wb-guide-rules">';
		foreach ( $rules as $r ) $h .= '<li>' . esc_html( $r ) . '</li>';
		return $h . '</ul>';
	}

	/** [wb_howto] — the How-to screen: the flows, then the walkthroughs. */
	public static function render(): string {
		$h = '<p class="wb-guide-start">New here? Start with <a href="#wb-howto-sale">Sell something from quote to delivery</a>, then look at <a href="' . esc_url( WB_Workspace::url( 'home' ) ) . '">Today</a>. Keys: <kbd>/</kbd> goes to the search box, <kbd>n</kbd> to the screen\'s main action, <kbd>Esc</kbd> closes a menu.</p>';
		$h .= '<nav class="wb-guide-toc" aria-label="On this page"><span class="wb-kicker">The flows</span><ul>';
		foreach ( self::FLOWS as $k => $f ) $h .= '<li><a href="#wb-flow-' . esc_attr( $k ) . '">' . esc_html( $f[0] ) . '</a></li>';
		$h .= '</ul><span class="wb-kicker">Walkthroughs</span><ul>';
		foreach ( self::HOWTO as $k => $w ) $h .= '<li><a href="#wb-howto-' . esc_attr( $k ) . '">' . esc_html( $w[0] ) . '</a></li>';
		$h .= '</ul></nav>';

		$h .= '<section class="wb-guide" aria-labelledby="wb-guide-flows"><h2 id="wb-guide-flows">How the work flows</h2><p class="wb-muted">Each flow in the order it happens. The name on the left of a step is the screen it is done on.</p>';
		foreach ( self::FLOWS as $k => [ $title, $idea, $steps, $rules ] ) {
			$h .= '<article class="wb-guide-flow" id="wb-flow-' . esc_attr( $k ) . '"><h3>' . esc_html( $title ) . '</h3><p class="wb-guide-idea">' . esc_html( $idea ) . '</p>' . self::steps( $steps )
				. ( $rules ? '<p class="wb-guide-rules-h">The rules it keeps</p>' . self::rules( $rules ) : '' ) . '</article>';
		}
		$h .= '</section>';

		$h .= '<section class="wb-guide" aria-labelledby="wb-guide-howto"><h2 id="wb-guide-howto">Walkthroughs</h2><p class="wb-muted">One thing to press or read per step. Written for the demo, where every customer and product named is on file.</p>';
		foreach ( self::HOWTO as $k => [ $title, $who, $steps, $flow ] ) {
			$h .= '<article class="wb-guide-flow" id="wb-howto-' . esc_attr( $k ) . '"><h3>' . esc_html( $title ) . '</h3><p class="wb-guide-idea">For ' . esc_html( lcfirst( $who ) ) . '. Part of <a href="#wb-flow-' . esc_attr( $flow ) . '">' . esc_html( self::FLOWS[ $flow ][0] ) . '</a>.</p>' . self::steps( $steps ) . '</article>';
		}
		return $h . '</section>';
	}

	/** The read-only fold at the foot of a work screen: the flow(s) it is part of, its own steps marked, and the way to the How-to screen. */
	public static function fold( string $slug ): string {
		$keys = self::SCREEN_FLOW[ $slug ] ?? [];
		if ( ! $keys ) return '';
		$body = '';
		foreach ( $keys as $k ) {
			[ $title, $idea, $steps ] = self::FLOWS[ $k ];
			$body .= '<h4 class="wb-guide-h">' . esc_html( $title ) . '</h4><p class="wb-guide-idea">' . esc_html( $idea ) . '</p>' . self::steps( $steps, $slug );
		}
		$body .= '<p><a href="' . esc_url( WB_Workspace::url( 'howto' ) ) . '">All the flows and the walkthroughs</a></p>';
		return WB_Render::fold( 'How this screen fits in', $body, [ 'kind' => 'reference', 'id' => 'wb-guide' ] );
	}

	/* ================================================================== the documents */

	/** docs/FLOWS.md from FLOWS. Screen names in bold; the system's own steps labelled. */
	public static function flows_markdown(): string {
		$m = "# B2BGro: how the work flows\n\n_Written by tools/guide-md.php from WB_Guide::FLOWS; edit the class, not this file. The same words are on the How-to screen in the workspace and in the fold at the foot of every screen._\n\n";
		foreach ( self::FLOWS as $k => [ $title, $idea, $steps, $rules ] ) {
			$m .= "## {$title}\n\n{$idea}\n\n";
			$n = 0;
			foreach ( $steps as [ $slug, $words ] ) {
				$where = '' === $slug ? 'The system, at night' : (string) WB_Workspace::screen( $slug )[0];
				$m .= ( ++$n ) . ". **{$where}.** {$words}\n";
			}
			if ( $rules ) {
				$m .= "\nThe rules it keeps:\n\n";
				foreach ( $rules as $r ) $m .= "- {$r}\n";
			}
			$m .= "\n";
		}
		return $m;
	}

	/** docs/HOW-TO.md from HOWTO. */
	public static function howto_markdown(): string {
		$m = "# B2BGro: how to\n\n_Written by tools/guide-md.php from WB_Guide::HOWTO; edit the class, not this file. One thing to press or read per step, written for the demo company. The flows these belong to are in FLOWS.md._\n\n";
		foreach ( self::HOWTO as $k => [ $title, $who, $steps, $flow ] ) {
			$m .= "## {$title}\n\nFor " . lcfirst( $who ) . '. Part of _' . self::FLOWS[ $flow ][0] . "_.\n\n";
			$n = 0;
			foreach ( $steps as [ $slug, $words ] ) $m .= ( ++$n ) . '. **' . (string) WB_Workspace::screen( $slug )[0] . ".** {$words}\n";
			$m .= "\n";
		}
		return $m;
	}
}
