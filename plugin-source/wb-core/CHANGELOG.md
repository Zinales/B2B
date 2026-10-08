# wb-core changelog

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
