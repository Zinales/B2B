# wb-core changelog

## 1.5.0 — 9 October 2026
The five gaps from the review of 9 October (docs/review-2026-10-09/UX-REVIEW.md), closed.
- **Search, filter, sort and paging on every list** (WB_List): a search box that looks in the
  numbers, names and codes and in the customer's or supplier's name; the status chips as filters;
  sortable column heads (press again to turn round); pages of fifty with "Showing 51 to 100 of 812".
  All by plain links in the address, so a filtered list can be bookmarked or sent, and works on a
  phone without the script. A search that finds nothing says so and offers the way back. On
  Customers, Products, Quotes, Orders, Invoices, delivery notes, purchase orders and the contact log.
- **A page for each customer**: what they owe and how late (not yet due, 1–30, 31–60, 61–90, over
  90 days), credit left, terms, tier, stage and last order; then their open invoices, quotes,
  orders, payments, contacts, the timeline, their own prices and their documents; "Write a quote"
  and "Send a reminder" at the top. Every customer name in every list opens it. **A page for each
  product**: price, cost and the lowest price allowed, available, put aside and on order, the
  specification, the datasheet, its stock movements, where it is quoted, and the customers with
  their own price.
- **Writing a quote** (WB_Quote_Editor): find a product by typing part of its code, name or
  barcode; each match shows this customer's price, where it comes from, the stock, and a warning
  when that price would need approval. A draft's quantities and prices are edited on the lines and
  saved in one press; each changed line goes through both checks again (a typed price is manual; a
  manual price stays manual when only the quantity changes). The broken rule is said on the line.
  Pasting "code, quantity" lines stays, folded away. The same product search fills purchase orders.
- **Send by email** (WB_Send) on quotes, invoices, credit notes, statements, reminders and
  datasheets: the customer's right contacts ticked (accounts for invoices, the buyer for quotes,
  whoever receives datasheets), another address can be typed, a copy to yourself, the subject and
  message from the company's template with the details filled in, the PDF attached. A draft quote
  is marked sent and its acceptance link goes in the message. Recorded on the timeline and in the
  audit trail. Nothing is sent by itself; in the demo nothing leaves the building. Templates under
  Settings › Email templates.
- **Statements** (WB_Statements): an open-item statement PDF with days late, the ageing and the bank
  details, made fresh whenever it is asked for.
- The front page and quick actions now say what is true ("find products as you type").
- Fixed: a quantity of 10 could show as 1 where trailing zeros were trimmed (WB_Render::num()).
- Tests: regress-list.php (62), regress-quote-editor.php (37), regress-send.php (58). The
  accessibility gate renders the real quote editor and customer page (it caught the line table
  scrolling sideways on a phone; lines are now cards there).

## 1.4.0 — 8 October 2026
Datasheets as data (Zina, 8 October: "datasheet data stored in tables, converted to PDFs, easier to
update in bulk … or upload a datasheet, or link it to the online datasheet database").
- **A new JetEngine table, wb_datasheets**: one row per product saying where its datasheet comes
  from. "From the data": the row's one-line summary, description, applications and storage/handling
  notes join the product's specification rows and render to a PDF with the company letterhead
  whenever anyone asks (staff from the Products table or the row's menu, a customer from the portal).
  Nothing is stored: the sheet is living data and renders in well under a second. "An uploaded
  file": the supplier's own PDF, filed as before. "A link": the address of the online datasheet.
  A product with no row falls back to an uploaded file if one is on record. An empty sheet is never
  handed out.
- **Typed, uploaded in bulk, exported**: the datasheet rows are a master table on the Documents
  screen, so the form, the CSV template, "Check file" and "Validate and import", and the export all
  come from the same policy as customers and products. A whole range is updated in one file.
- **The 7-day link for a customer** renders the sheet as it is that day and files that copy in the
  document register, so what the customer was sent stays on record; an uploaded sheet links as
  before, and an online sheet's own address is the link.
- **Products**: the Datasheet column now reads "PDF · Rev 2", "Open · v3" or "Online", and the ⋯
  menu has "Datasheet PDF". The portal lists all three kinds.
- **Documents** opens on the datasheet rows; uploaded product documents and the system's issued
  documents are folds below.
- The demo gives every product a datasheet (one points at an online sheet instead). Creating the
  table: System Settings › Business tables › "Create the missing tables" adds wb_datasheets.
- Tests: regress-datasheets.php (resolution, the sheet's HTML, real PDF bytes, the master-table
  policy).

## 1.3.2 — 8 October 2026
Documents, by product (Zina, 8 October: "clicking Datasheets on Products took me to a documents
list; I would like to view the documents we have uploaded").
- **The Products table has a Datasheet column**: the current version as a link that opens it, or
  "none". The ⋯ menu on a product has "Datasheets and documents", which opens the Documents screen
  narrowed to that product: its name on top, its documents, the datasheet link and the filing form
  already pointed at it, and "All documents" to widen again.
- **The Documents screen is in three parts**: the product documents people file (datasheets,
  certificates of analysis, safety sheets, certificates) lead; the documents the system issued
  (quote, invoice, credit note and delivery note PDFs, signed copies, contracts) are a fold of their
  own, so they no longer bury the datasheets; staff documents another.
- **Open, not Download**: a PDF or an image opens in the browser (the file was already sent inline;
  the menu now says so); anything else downloads.
- Tests: regress-documents.php.

## 1.3.1 — 8 October 2026
The set-up checklist restyled (Zina, 8 October: "this doesn't look very professional at all").
- **Getting started** on System Settings is now a progress card in the same anatomy as "Needs
  attention": a title with "3 of 8 done" and a thin meter, then one row per step with a tick or an
  empty circle, the step's name, one line saying what it means, and "Do it" on the steps still to
  do. Two columns on a desk, one on a phone. Labels shortened ("Company details"; the detail is the
  line under it).
- **The notification bar** on every screen uses the same rows (chip, message, Open) instead of a
  bulleted list.
- Links inside lists and table cells are no longer underlined (prose links still are); they
  underline on hover. The waiting count on the top bar never wraps; on a phone it shows the number
  alone.
- The accessibility gate renders System Settings too.

## 1.3.0 — 8 October 2026
The flows explained, and walkthroughs (Zina, 8 October: "an explanation of the flows you've built,
and a how-to section with walk throughs").
- **The How-to screen** (`How to`, in the menu for every staff login, the demo visitor included):
  twelve flows in plain words, each in the order it happens, every step naming the screen it is done
  on (a link) or saying it is the system's own night work, and the rules each flow keeps. Then
  thirteen walkthroughs for a first visit (sell something from quote to delivery, match a bank
  statement, issue a credit note, correct stock, do a stocktake, reorder and receive, give a
  customer a portal login, load your tables from a spreadsheet, run payroll, record and approve time
  and leave, read the Integrity report, set up a new company), one thing to press or read per step,
  using the exact words on the buttons and folds.
- **"How this screen fits in"** — a read-only fold at the foot of every work screen showing the
  flow(s) that screen is part of, with its own steps marked "This screen", and the way to the How-to
  screen.
- **The documents are written from the program.** `docs/FLOWS.md` and `docs/HOW-TO.md` are
  generated by `tools/guide-md.php` from `WB_Guide::FLOWS` / `HOWTO`; build gate 2c refuses a build
  when they differ, so the documents can never say something the screens do not.
- The invoice question answered in the words themselves: there is no standalone invoice. An
  invoice is issued from an order (on acceptance by default, or when the goods leave, or by hand
  from the order's menu), and an order only ever comes from an accepted quote.
- Fixed: the import fold on Products read "Upload categorys from a file" (`WB_Import::plural()`).
- Tests: regress-guide.php (441): every step names a real screen, every screen is in a flow, every
  link is a `WB_Workspace::url()` address, the fold marks the right steps, the Markdown carries
  everything, the plural. regress-workspace: the How-to screen in every menu. The accessibility
  gate renders the How-to screen too.

## 1.2.0 — 8 October 2026
A year of trading in the demo, and a broken price rule explained in words (Zina, 8 October).
- **The demo now holds a year.** `WB_Demo_Seed` writes a deterministic script (the same year on
  every site) and plays it through the real engines with the business clock set to each event's
  date, so every document is numbered in sequence, priced by both checks, released by the gate,
  ledgered and PDF'd exactly as a real one would be. Demo Technical Supplies: 16 products in four
  categories with specifications, 3 price tiers, 4 suppliers, 8 customers (two pay early, two
  pay late, one is on hold, two pay cash at the counter), 12 contacts, 6 staff with pay set up;
  stock arriving by purchase order; around 80 quotes over twelve months with coatings peaking in
  spring and adhesives dipping over the holidays; orders, invoices, delivery and collection notes;
  a year's bank statement imported and matched (most by reference, some suggested, one odd);
  overdue invoices; two weeks of timesheets; leave; a review; last month's pay run.
- **Loose ends for a first visit:** a price below the floor to decide, a write-off to decide, a
  credit note to approve, a stocktake to check, timesheets to approve, a leave request, a pay run
  to check, quotes still out, a purchase order on its way, and three notes on the timeline.
- **The business clock.** Every engine now stamps dates from `wb_now()` (filterable) instead of
  `current_time()` directly; live, it is the site's time. Numbering follows the clock's year. The
  audit trail keeps its own real time.
- **"Why it needs approval"** on every quote line that broke a rule, and on the approve queue, in
  a sentence: "Below the lowest allowed price: R 85.00 is under R 93.60 (cost R 72.00 + 30%
  margin)." / "The product's price is out of date…" / "Above the list price…" / "The product has
  no cost price on file…" (`WB_Pricing::explain()`). The person who typed the price and the person
  who decides read the same words; the requester's own note and the decider's note stay as they
  were.
- The demo wipe now knows every kind of row the deeper seed makes (contacts, KPIs, credit notes,
  payments, adjustments and counts, stocktakes, timesheets, leave, reviews, pay runs, payslips,
  profiles, scores, PDFs and their files).
- Tests: regress-demo.php (23): the script is deterministic, a year long, in date order, never on a
  weekend, seasonal, stock always arrives before it is sold, the loose ends are there;
  regress-money.php +6 for the explanations.

## 1.1.0 — 8 October 2026
Documents as PDF (Zina, 8 October: "please convert to pdf").
- **Quotes, invoices, credit notes, delivery notes and purchase orders are made as PDF** on this
  server by the Dompdf engine now bundled in `lib/dompdf` (pure PHP, nothing to install; DejaVu
  Sans so any name renders; remote fetches off; file access confined to the plugin and the private
  folder). One letterhead from System Settings: name, registration and VAT numbers, address, logo,
  and two new fields there, **Bank details on invoices** (the "Pay to" box, with the invoice number
  as the reference) and a **footer line**. Lines with code, words, quantity, each and amount; VAT
  shown once at the frozen rate; the total in rand. A delivery note has quantities and a line to
  sign, no money. A purchase order goes to the supplier for the attention of its contact.
- A PDF is made when the document is issued (quote sent, invoice issued, credit note approved,
  delivery note issued, purchase order sent), after the transaction has committed, filed in
  `wb_documents` so the portal, the tokened download and the audit trail treat it like any file,
  and pointed at by the row's `pdf_key`. **Download PDF** on the ⋯ menu makes it on demand for
  anything issued before 1.1.0. An issued document is immutable, so its PDF is made once.
- **Payslips are PDF** from this release when a run is finalised. If the engine ever fails, the
  HTML version stands and nothing is lost.
- The portal's "Download invoice" serves the PDF.
- Tests: regress-pdf.php (21): the words and numbers on each kind, typed text never runs as
  markup, and the engine itself renders an invoice and Unicode names to real PDF bytes, so a
  broken `lib/` fails the build here and not on a client's first invoice.

## 1.0.1 — 8 October 2026
- **The product is B2BGro** (Zina, 8 October). The plugin, the documents and the front page footer
  ("built on B2BGro") say so. The code prefix stays `wb_` — an internal name, like Kaycie's `kc_`;
  renaming 35 tables on a site with data buys nothing a person can see.
- **Categories, price tiers and customer pricing explained** where they are set: a read-only fold
  on Products, "How a price is worked out", says what each is for and how check one and check two
  use them. The same words are in the plugin README.

## 1.0.0 — 8 October 2026
Version one (Zina, 8 October: "ready to go to market with this as v1 for customisation by
clients"). The working name, code prefix `wb_` and table names stay as they are until the real
name is chosen; the README says what that costs later.
- **The system's own sign-in page** at `/workspace/sign-in/`. Every "Sign in" on the front page,
  the top bar and every signed-out redirect lands here, and so does the address WordPress hands
  out (except for wp-admin's own needs). Two cards: **Your login**, WordPress's own form posted to
  wp-login.php so passwords, lockouts and resets stay WordPress's, with a failed attempt said in
  words and a way to reset; and, while the demo is open, **The demo**, the demo visitor already
  filled in, one button to enter, and the words that say what happens to what they save: kept for
  the day and cleared every night, so please no real names or numbers. Someone already signed in
  sees where they can go instead. A redirect off the site is dropped.
- Tests: regress-frame.php +12 (signed out, redirects kept and dropped, a failed attempt, the demo
  card when open, signed-in owner and customer, the login address rule). The browser check now
  covers the sign-in page too: 983 text elements, 0 problems.

## 0.3.7 — 8 October 2026
The front page gets a proper introduction, and a way into a demo (Zina, 8 October).
- **The front page in the Brandzgro page rhythm:** a navy hero with display type and the one
  hand-drawn underline (drawn in once, off under reduced motion), six feature cards on cream with
  ghost numerals, "how it keeps you safe" on white, the one statement band, a five-step first
  visit on the tint, the footer. One primary button per screen: **Try the demo** when the demo is
  open, else Sign in. On navy the primary is rose accent with navy text; deep rose is never a fill
  on navy.
- **A shared demo login** (Kaycie's demo site, Zina's rule: click anything, break nothing).
  Under Settings an administrator opens or closes the demo. Opening it creates a `demo` login
  (a manager: everything but settings, staff files and pay; a random password nobody knows; its
  own staff record so it can approve) and "Try the demo" on the front page signs the visitor in
  and lands on Today. A ribbon on every screen says it is the shared demo and asks for no real
  names or numbers. The login never reaches wp-admin, and the panels that would change the
  tenant for everyone (System Settings, who can do what, business tables, uploads) bounce with
  a notice; everything else works. Every night while the demo is open the data goes back to the
  seed. Opening, closing, every entry and every reset are in the audit trail.
- Tests: regress-frame.php +8 (demo closed and open, signed out and as the owner; the underline
  once; the page rhythm; the open/closed rule). The browser check covers the dark bands: 923
  text elements, 0 problems.

## 0.3.6 — 8 October 2026
"Upload all data points", half two: every master table can be uploaded from a CSV, and exported
back out in the same layout (Kaycie's import, with wb-core's own reader).
- **Under every master table** (customers, contacts, products, categories, price tiers,
  suppliers, staff, leave types, KPIs) an "Upload … from a file" fold: download a blank template
  (the typed columns, `_ID` first, one example row), export what is there now (same layout, so a
  round trip is a plain edit), choose a file, then **Check file (no import)** or **Validate and
  import**. The columns and the ones that must be filled are listed on the fold.
- **Every row is checked before anything is written.** Headers may be the column names or the
  words on the form, any case and order; an unknown column refuses the file and lists the columns;
  a missing required column is named. Values are cleaned exactly as the form cleans them (numbers
  with spaces or commas, choices, dates in words, emails, encrypted columns typed in plain). A
  reference (customer, price tier, category, supplier, manager, login) may be its `_ID` or its
  exact name. The natural key (code, name, email, employee number) may not repeat in the file or
  on file: a known name without its `_ID` is refused with the `_ID` to use, so a re-upload can
  never make a twin. Every problem says its row and the fix; 25 are shown, then "and N more".
  One bad row refuses the whole file and nothing changes.
- **All or nothing.** Rows are written in one transaction through `WB_CCT`, so each is ledgered
  with the audit trail deferred until COMMIT; a failure rolls everything back and says which row.
  One audit entry per upload says how many were created and updated and by whom; every export
  is ledgered too. The file is never kept. Generated cells are formula-guarded. Up to 5 MB and
  5,000 rows (split larger files).
- Engine-owned columns are never in a file; what can be typed can be uploaded and nothing else
  (the import reads the same policy as the forms). Stock opening balances are not a table upload:
  they are stock movements, which stay append-only and two-named.
- Tests: regress-import.php (39): the layout, header words, the formula guard, CSV out, a good
  file (comma, semicolon, BOM, blank lines, references by name and by number), every refusal with
  its row and fix, the result words.

## 0.3.5 — 8 October 2026
"Upload all data points" (Zina, 8 October), half one: every master record can now be added and
edited, every field. Before this the add forms captured a fraction of each table (customers 10 of
17 fields, staff 2 of 15, price tiers without the discount, suppliers 4 of 9) and nothing could be
edited at all; staff, suppliers, categories, tiers, leave types and KPIs had no form.
- **One form per table, drawn from the schema.** New `WB_Records` reads `schema/wb-ccts.json`
  (field types, choices, defaults) plus a short policy per table: which columns a person types,
  in what order, with what words, which are required, which point at another table, and the
  natural key that may not repeat. Nine tables: customers, contacts, products, categories, price
  tiers, suppliers, staff, leave types, KPIs. Columns the engines own (journey stage, portal login,
  the date marketing consent was given, the current datasheet) are never typed.
- **Edit, not only add.** Every master table's ⋯ menu has Edit beside Archive; it opens the same
  fold prefilled, titled "Edit Karoo Agri", and a line says the change is recorded with your name.
- **Typed values are checked with words:** a number with spaces or a comma is read, an "R" in
  front is refused with the fix in the sentence; a choice off the list names the choices; a date
  typed in words is read; a bad email is refused; a duplicate code, name, email or employee number
  names the record already on file. Encrypted columns (ID numbers, emergency contacts, supplier
  bank details) are typed in plain, stored through `wb_enc()`, shown as "On file — type to
  replace" and never printed back; blank on edit keeps what is on file.
- Specification rows are typed one per line as "label | value | unit" and stored as the JSON the
  datasheets read. A product's usual supplier, lead time, barcode and shelf life are on the form.
  Setting a price tier as the one new customers start on clears it from the others. Switching a
  contact's marketing consent on records the date once.
- Screens: Categories and Price tiers are "Also here" folds on Products; Suppliers on Purchasing;
  Staff (with the form), Leave types and KPI definitions on Staff; Contacts on Customers.
- The 0.3.6 CSV import will read the same policy, so what can be typed can be uploaded and
  nothing else.
- Tests: regress-records.php (47): every typed field is a real column, engine-owned columns never
  leak into a form, type resolution, cleaning (numbers, choices, dates, emails, encryption, keep on
  edit, defaults on add only), the form's markup, anchors.

## 0.3.4 — 8 October 2026
The screens get their look and their anatomy (Zina, 8 October: "the screens need a lot of visual
styling"; benchmark Kaycie, look from the Brandzgro design system v2). Nothing that stores data
changed; this is how screens are drawn.
- **Two sources, one sheet.** Brandzgro decides how things look: cream page, white cards, deep rose
  for anything pressed or read as a link, navy headings and the one dark surface (the side menu),
  slate body text, a 1px hairline instead of shadows, 4px corners on everything that holds content
  and full-round only on chips, Poppins, hover is a colour change and nothing moves, the 3px rose
  focus ring on everything you can reach by keyboard. Every colour is still a token from System
  Settings, so a client's own colours replace the Brandzgro defaults without touching the sheet.
  Kaycie decides how screens are built (below).
- **One type scale.** Eight sizes (`--wb-fs-xs` to `--wb-fs-3xl`); every size on a screen is one of
  them. Buttons follow Brandzgro's three levels (primary, outline, text) and sizes; one primary per
  screen, and it sits in the page head.
- **Every screen has the same head:** an eyebrow naming the group (Sell, Stock, Know, Team, Admin),
  the title, one line, and the screen's one primary action as a button that lands on the fold that
  does it (Add a customer, New quote, Import a bank statement…). The "Next" band sits under it.
- **Folds have a hierarchy by width before colour:** the first fold on a page carries the rule
  (lead), "Also here" folds are inset, read-only proof is inset further and marked. A fold can carry
  a count on its summary and a note at the top of its body. `WB_Render::fold()` draws every fold;
  the portal, payroll, Setup and Integrity screens now use it too.
- **Stat tiles read label → number → note,** and the one number that must not be missed (overdue
  invoices) carries the rule. Empty lists show a state card that says what would be here, never a
  bare line. Chips are the only pill. Notices are the only component with a left rule.
- **The side menu** has a line icon per screen (Lucide shapes, 1.6 stroke, never filled), the
  system name with "Workspace" under it, a count of what is waiting on the person against Today,
  and the signed-in person with Sign out at the foot. The top bar has a breadcrumb and the same
  count as a bubble that links to Needs attention. Tap targets are 44px on touch screens.
- **Today is now Kaycie's Today:** the date as the eyebrow, "Good morning, Thandi." as the title,
  "3 things are waiting on you, and 2 are still open." under it; the week in numbers; a **Needs
  attention** card listing every line this person can clear with a verb on each (Decide, Match,
  Approve, Check, Chase, Reorder…), waiting before open; **Quick actions** beside it; then the
  notifications. New `WB_Needs` is the one list behind the card, the menu count and the top bar,
  so they cannot disagree; every count fails to zero on a missing table, never a blank page.
- Placeholders are examples, never instructions (BUILD-PATTERNS §2.9) on the customer, product and
  price-rule forms. Required fields carry a mark. A bounded `[wb_list]` says its bound in words.
  Stored values get their screen words from one map, `WB_Render::WORDS` (§2.3); the two remaining
  hand-made labels now read it.
- Tests: regress-frame.php +164 (the head on every screen for every login, breadcrumb, waiting
  count, icons, the foot of the menu), regress-workspace.php +24 (fold kinds, state, stat, bound
  line, the word map, the needs list: sort order, the lede, greetings, every line lands on a real
  screen behind a real capability and fails to zero without a database). The browser check passes
  on the new chrome at 1280px and 375px (761 text elements, 0 problems).

## 0.3.3 — 8 October 2026
Buttons you could not read (Zina, 8 October: "the button text doesn't render").
- **Link-buttons painted their words in the button's own colour.** The workspace sheet colours every
  link, and that rule outranked `.wb-btn`, so "Sign in", "Open the workspace", "Your account", "Go to
  System Settings" and every other link styled as a button showed rose text on a rose button (1:1).
  Buttons that were real `<button>`s were fine, which is why the forms worked and the welcome page
  did not. Link-buttons now keep the button's text colour.
- The "Sign in" and "Sign out" links on the top bar and the welcome footer were 17px tall; they now
  meet the 24px tap-target minimum.
- **A browser now measures the pages before a build.** `tools/a11y-pages.php` renders the welcome
  page, the frame for several logins, a refusal, the portal shell and the 404 with the real
  stylesheets; `tools/a11y-check.js` opens each in Chromium at 1280px and 375px and checks every
  text element's painted contrast (WCAG AA), accessible names on buttons and links, labels on
  fields, tap-target size and sideways scroll. It is build gate 7 and runs wherever Node and
  Playwright are installed (skipped with a note elsewhere). This is the regression test for the
  bug above: the check found it at 1:1 on nine pages before the fix and nothing after.

## 0.3.2 — 8 October 2026
The first of the method changes from `docs/BUILD-PATTERNS.md` (§2.1, Kaycie's registry pattern):
one list drives every address.
- **Every workspace address comes from one function.** `WB_Workspace::url( $slug, $args )` is now
  the only place that knows the shape of `/workspace/<screen>/`; the 40 typed addresses in the
  engines, the home tiles, the Setup checklist, the notification links and the login redirects
  all ask it by screen slug. View state (`?quote=12`, `?month=`) goes through it too. The portal
  has `WB_Workspace::portal_url()`. An unknown slug lands on Today rather than a dead address.
  `wb_return_url()` takes a slug for its fallback and its docblock carries the strip-list rule
  (§2.8: a new one-shot message parameter must be added to the list or it re-fires on reload).
- Tests: regress-workspace.php +12. Every slug used in code is a screen; no file types
  `/workspace/` or `/portal/` outside the one function; every capability a dashboard tick grants
  is checked somewhere in the code; every screen's gate is granted by some tick.
- Found by the new test, left as is and recorded here: the **Export** tick grants
  `wb_export_data`, which nothing checks yet. The tick does nothing until an export exists.
  Staff, Performance reviews and Payroll open no screen of their own by design: their screens
  are open to every login and the folds inside are gated.

## 0.3.1 — 8 October 2026
The user-ready pass, after the first look at 0.3.0 on staging (Zina: "a lot of styling help and UX
support", a home page, no Brandzgro, simple words).
- **A welcome page at the site's front door.** What the system does in plain words (Sell, Get paid,
  Stock, Know, Team, Your customers), how it keeps you safe, and the way in: Sign in, or straight to
  the workspace or the customer's account for someone already signed in. The owner also sees three
  getting-started steps. It is the system's own page, in the system's own look, and holds no business
  information, so it may be found by search. Setup can hand the front page back to the website.
- **A short system name.** The menu was showing the whole site title. Setup's "Product or display
  name" is now "System name"; until it is set, the site title is cut at the first dash, bar or colon.
- **No Brandzgro anywhere a person reads.** The colours are the same; they are "the ones the system
  came with". The plugin's author line reads GroB2B.
- **Plainer words.** "Business tables" (not "in JetEngine"), "Bank statement layout" (not "CSV
  mapping"), "The system shows its own screens" (not "the plugin"), and a demo message that points at
  the Setup screen rather than a file. On a first visit, Customers and Products open their "Add"
  form by themselves when the list is empty.
- The top bar of the portal and the welcome page links the name back to the front page; a signed-out
  visitor sees "Sign in" there. Forms line up from the top so a long note no longer pushes its row.
- The Setup screen is titled **System Settings** (Zina, 8 October). Its address stays `/workspace/setup/`.
- Tests: regress-frame.php +14 (welcome page for signed-out, owner, sales and customer logins; the
  screens stay unindexed), regress-workspace.php +7 (the short name).

## 0.3.0 — 7 October 2026
The plugin now shows itself. On a clean site nothing appeared, because every screen was a shortcode
waiting for a page someone had to make by hand, and the layout came from the Kaycie stylesheet the
site did not have (Zina, 6–7 October). Built on what Kaycie and FindGro taught us.
- **The workspace is served by the plugin.** `/workspace/` (Today), `/workspace/<screen>/` for every
  staff screen, and `/portal/` for customers. No WordPress pages, no Custom HTML to paste, nothing for
  an editor to break by deleting a shortcode. Kaycie and FindGro kept their dashboards as JetEngine
  Profile Builder tabs and needed repair tools when a slug, a role name or a template drifted
  (Kaycie's 0.7.0 migration renamed "pages" and found none; FindGro's pb_fix). Here the list of
  screens is one constant in code. A WordPress page at one of these addresses is never shown; Setup
  says so and suggests moving it to the Bin.
- **Its own look.** A side menu, top bar and page header drawn by the plugin and styled by the new
  `assets/wb-workspace.css` from the colours on the Setup screen (Brandzgro by default). The theme's
  stylesheets are left off these addresses, so GeneratePress or any other theme cannot change the
  screens. On phones the menu is a drawer. Classes the screens used with no style at all now have one.
- **One gate, shown properly.** The menu lists only the screens a person can open. Not signed in goes
  to the login page and straight back. Signed in without access shows what the screen is, that it is
  not part of their work, who can tick it for them and the way back (Kaycie's gates audit: never a
  blank page). The shortcodes and engines still check as before.
- **The business tables are created for you.** Setup has a "Business tables" section with a button
  (administrators only) that creates every missing JetEngine content type from `schema/wb-ccts.json`
  through JetEngine's own API. Existing types are never changed; missing fields are listed. Money,
  quantities, hours and percentages keep two decimals (a JetEngine number field without a step is a
  whole-number column); references and counts stay whole numbers. No REST access or single pages are
  switched on. The setup checklist starts with this step.
- `/workspace/notifications/`, which the notification bar already linked to, now exists.
- The `dashboards/*.html` files stay as the reference for anyone who still wants shortcodes on pages.
- Tests: regress-workspace.php (42) and regress-frame.php (1,105): every screen rendered for every
  kind of login with WordPress stood in for, failing on any PHP notice, blank refusal, or a menu or
  "Next" link the login cannot open (BUILD-PATTERNS.md §2.2; a one-off version of it caught an
  mbstring fatal before this release).

## 0.2.2 — 5 October 2026
Two decisions from Zina after the review.
- **Company-wide documents in the portal are the organisation's choice.** Setup now has
  "Company-wide documents in the customer portal": hidden (the default) or shown to every
  customer with a portal login. It covers certificates, safety data sheets and datasheets that
  belong to no product, such as an ISO certificate. Each document must also be marked as one
  customers may see. Product documents still follow what the customer bought or was quoted.
  Shown documents appear under "Company documents" on the portal's datasheets page.
- **The owners are told when someone can no longer open their payslips.** When a person with
  finalised payslips loses workspace access (their role or dashboard ticks change, or their login
  is removed), the owners and administrators get one in-app notice saying so, and that they can
  download a payslip from Payroll and send it themselves. It is never emailed. If access comes
  back, the notice can fire again later. A nightly check catches changes made outside the system.
- Closing an order whose invoice was fully credited stays allowed (Zina confirmed).
- Tests: regress-portal-leaver.php, 24 new assertions.

## 0.2.1 — 5 October 2026
The fixes from the review sweep of 4 October (76 findings, all listed with IDs in
`../../docs/review-2026-10-04/FINDINGS.md`). Every fix has a regression test that stays.
- **The audit trail can no longer miss a change.** An entry that cannot be chained straight
  away waits in a queue and is chained at the next write or at night; the owner is told while
  anything waits. A record of the latest entry catches entries removed from the end. With
  WB_ENCRYPTION_KEY set, new entries are signed so they cannot be quietly rewritten. Events
  fire only after a numbered document is committed. (S1, S3, S6, S7)
- **Double clicks are safe.** Accepting a quote twice, issuing a delivery note twice, recording
  a receipt twice, approving a stock request, price or credit note twice: each now happens
  once. (M1, M6, M7, M8, M12)
- **Stock and money.** A delivery note ships only its own reservation plus free stock. A
  credited invoice no longer releases goods. Credit-note returns must match what was invoiced
  and delivered. Money owed back after a credit note is flagged. Split payments keep every
  allocation. Prices above list need approval, and the person who priced a line cannot approve
  it. Bank lines whose description starts with "Total" or "Balance" are no longer dropped, and
  a Unicode minus sign is read correctly. Hand matches by anyone without a staff record are
  refused, so the Integrity report sees every one. (M2–M5, M9–M11, M13–M23)
- **Payroll.** Editing a profile keeps the stored date of birth, medical members and pension.
  Joiners and leavers are paid for the days they were employed. Overtime in a week that
  crosses a month end is paid. Hourly staff get public-holiday pay, and work on a holiday is
  paid at double (a setting). Anyone who changed a draft run cannot check it. A run with a
  finalised payslip cannot be reopened. Leavers can still see their own payslips. The bonus
  counts towards the retirement limit. Declared extra public holidays can be added in
  settings. (P1–P5, P8–P15)
- **Portal and documents.** Customers see datasheets only for products they bought or were
  quoted, and nothing once their account is closed. JetEngine "true"/"false" switches are read
  correctly everywhere. (P7, S4)
- **Files and links.** Uploaded files keep only safe extensions; the bank import takes CSV,
  TXT or TSV only. Storage paths cannot climb out of the private folder. An expired link shows
  a readable page. Acceptance and datasheet links are shown so they can be copied. (S2, S8–S10)
- **Demo clean-up never touches real records** such as the owner's staff file or the leave
  types. (S5) Notifications reach everyone given access by dashboard ticks. (S11)
- **Integrity** names each kind of audit-trail break in words, shows entries still waiting,
  groups write-offs by product and no longer lets gains and losses cancel out.
- New fields: `wb_pay_runs.editors_json`, `wb_quote_lines.priced_by_user_id`,
  `wb_payments.allocations_json`. Ledger table gains `hash_version` (database version 2).
- Tests: selftest 288, regress-ledger 93, regress-money 62, regress-payroll 71 — all pass.
- Guardrails: `tools/build.py` builds the zip only when every file lints, every test passes,
  nothing that existed has disappeared, versions agree and the work is committed.

## 0.2.0 — 4 October 2026
- **Setup screen** (`[wb_setup]`, needs *Manage settings*). Each client sets its display name,
  company details, VAT number, logo, colours and fonts. Brandzgro's colours are the default.
  Every text-and-background pair is checked for contrast and a pair under 4.5:1 is refused with
  a plain message. "Reset to default" puts the Brandzgro look back. A first-run checklist shows
  what is done and what is still to do.
- **Bank statements by CSV, with mapping.** Works with a full statement or a filtered export
  saved as CSV. Comma, semicolon and tab files are recognised, the header row can be anywhere
  in the first lines, and amounts can be one signed column or separate debit and credit
  columns. Mappings are saved by name ("FNB cheque", "Filtered export") and reused. Only money
  in becomes a payment. Importing the same file twice adds nothing.
- **Customer portal** (role *Customer*). A person at the customer sees only their own company:
  open quotes they can accept or decline, invoices and statements, datasheets for products they
  have bought or been quoted, a quote request, and a request to change their details, which
  staff approve. A login is created only when staff press the button.
- **Payroll** (`[wb_payroll]`). Monthly and hourly staff, PAYE from the 2027 SARS tables, medical
  credits, retirement contributions within the limit, UIF and SDL, overtime from approved
  timesheets, and unpaid leave. A pay run is prepared, checked by a different person, then
  finalised and locked. Each run produces payslips with the fields the Basic Conditions of
  Employment Act requires, the EMP201 totals to capture on SARS eFiling, and a net-pay bank
  file. The system never submits anything to SARS or pays anyone. Payroll cost now feeds the
  cashflow forecast. Tax figures are stored per tax year, and a pay date with no tax year is
  refused.
- Self-test: 288 assertions, including the payroll cases worked by hand.

## 0.1.0 — 2 October 2026
- First build. The engine for the B2B Wholesale System, following DATA-ARCHITECTURE.md: the
  hash-chained audit trail (checked every night; a break tells the owner), gapless document
  numbers (quotes, orders, invoices, credit notes, delivery notes, purchase orders, statements),
  access by dashboard with approval ticks, and every change recorded.
- Prices: each quote line is checked twice — what this customer pays (their own price, their
  category price, their tier, or list) and what the product allows (never below cost plus the
  lowest margin without someone else's approval; out-of-date prices flagged). Both are kept on the
  line so a later cost change never rewrites an old quote.
- Orders: accepted quotes become orders with their prices fixed; invoices on acceptance or on
  dispatch; goods leave only when the customer has paid, or is within terms and credit limit.
- Stock is the sum of recorded movements. Corrections and write-offs need a second person;
  stocktakes have a counter and a different checker; reorder alerts appear in the app.
- Bank statements: import the CSV; a payment quoting an open invoice number matches itself,
  a payment that only matches by amount is suggested for a person to confirm, anything unclear
  waits. Every match records who, how and when.
- Nightly: who is likely to order next and when, busy and quiet months, each customer's stage,
  and a 13-week cashflow forecast.
- Staff: timesheets, leave with South African defaults (15 annual, 30 sick per 3 years,
  3 family responsibility) and public holidays, KPIs measured from the records, reviews.
- Monthly Integrity report: adjustments, write-offs, credit notes, hand-matched payments and
  below-floor prices, by person.
- Nothing is ever emailed automatically.
