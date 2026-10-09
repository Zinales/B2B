# B2BGro: UX, design and workflow review (9 October 2026)

A sweep of wb-core 1.4.0 as a person would use it, screen by screen and flow by flow, read
against what buyers of this kind of system already know from the market: Cin7 Core (DEAR),
Unleashed, Zoho Inventory and Books, Odoo Sales/Inventory, Sage 200 and Sage Business Cloud,
Xero, Katana, inFlow, and for the customer portal OroCommerce and Shopify B2B; for quoting,
Quotient and PandaDoc. The question asked of each finding: would a first-time user from one of
those systems find B2BGro easier, as easy, or harder, and what would it take to make it easier.

Scope: the workspace and portal as built (the PHP renders every screen; there is no JavaScript
beyond the menu, the kebab menus and the confirm dialog), the flows in `docs/FLOWS.md`, the
demo data. Not covered: performance under load, security (reviewed separately on 4 October).

## 1. What is already better than the market, and must be kept

These are the things a buyer will not get elsewhere at this price. Every recommendation below
is made so as not to lose them.

- **The two price checks and the words that explain them.** No competitor in this bracket
  explains a refused price in a sentence ("Below the lowest allowed price: R 85.00 is under
  R 93.60 (cost R 72.00 + 30% margin)"). Keep the sentence everywhere a price is shown.
- **Nothing retyped, nothing edited after issue, nothing deleted.** Cin7 and Zoho let a user
  edit an issued invoice; Xero voids and reissues. B2BGro's immutability plus credit notes is
  the auditor's preferred model, and the hash chain is unique in this bracket.
- **Two people for the risky things.** Stock write-offs, below-floor prices, credit notes,
  pay runs. Most competitors have this only in their enterprise tier.
- **Payment matching that never guesses.** Suggested matches wait for a person. Xero's bank
  rules can mis-post; ours cannot.
- **Today, the needs list and the menu count all read one list.** They can never disagree.
- **Plain words.** No "SKU", "UOM", "AR", "GL" on the screens.
- **One screen anatomy**, Kaycie's: head, next band, folds by weight, kebab menus, empty-state
  cards. The accessibility gate (contrast, names, targets, no sideways scroll) runs on every
  build. None of the systems above pass WCAG AA on their list screens.

## 2. The biggest gaps, in the order a first client will hit them

### P1. Writing a quote is the weakest screen, and it is the one sales lives in

Today a quote's lines are added by typing `ABC-100, 20` into a textarea, pressing Add, and
reading the result in a table. A manual price is set on a separate form by choosing the line
from a drop-down. The front page says "watch both price checks run as you type", which is not
true: the checks run on the server when Add is pressed.

Every competitor has a line editor: type to search the product (code or name), the customer's
price and the stock available appear on the line, change the quantity or price in place, the
total updates, and a warning appears on the line the moment a price breaks a rule.

Recommendation, in this order:
1. **A product search field** on the quote (and purchase order, and stocktake) that finds by
   code or name as you type and fills the line. One small script against a REST route that
   returns the twenty best matches with the customer's price, the floor and the available stock.
   This one change removes the single biggest "harder than what I had" moment.
2. **Lines editable in place**: quantity and price as inputs on the row, the line total and the
   quote total recomputed on the server on Save (not live maths in the browser: the server stays
   the only place prices are worked out). Remove the separate "Set price" form.
3. **The rule warning on the line as soon as it is saved**, in the sentence we already have,
   with "Ask for approval" on that line. Already partly there; make it the only path.
4. Then make the front-page words true, or change them to "every line is checked the moment it
   is added".

### P1. There is no search, no filter, no sort, no paging on any list

Every list is "the newest 200 (or 500)" in one order. A client with 800 customers or 3 000
invoices cannot find one without the browser's find. Every competitor has a search box on every
list, column sorting, a status filter and paging. This is the second "harder than what I had".

Recommendation: one list control, built once in `WB_Render::render_table` and used everywhere:
- a search box (one field; matches number, name, code, reference),
- the status chips as filters (click "overdue" to see only overdue),
- sortable column heads (server-side, by query string, so the address is shareable),
- paging in fifties with "Showing 51 to 100 of 812",
- the empty state saying "Nothing matches 'karoo' among open invoices. Clear the search."
All of it by query string and plain links, no script needed, so it works on a phone and in a
bookmarked address.

### P1. A customer has no page of their own

Customers is a list with Edit and Archive. To see what Karoo Agri owes, what they bought, the
quotes out, their contacts and the last call, a person opens five screens. Every competitor
opens a customer page: balance and overdue at the top, then tabs or folds for quotes, orders,
invoices and payments, contacts and logins, notes and timeline, documents, price rules.

Recommendation: `?customer=ID` on the Customers screen, built like the order view already is
(a lead fold with the facts, then folds), with the head action "Write a quote for Karoo Agri".
The data is all there; this is assembly. The same pattern then gives a product page (stock
movements, where it is quoted, its datasheet, its supplier and price rules) and a supplier page.

### P1. Documents cannot be sent from the system

A quote, invoice, statement or datasheet is downloaded and then emailed by hand from Outlook.
That was a deliberate rule ("customer-facing email is never automatic") and the rule is right;
but "a person presses Send" is also allowed by the rule, and every competitor has it: Send
opens a short form (to, from the customer's contacts who receive invoices; subject and body
pre-filled from a template; the PDF attached; a copy to yourself), the send is recorded as a
touchpoint, and the quote's status moves to sent in the same press.

Recommendation: a Send panel on quotes, invoices, credit notes, statements and datasheets, one
template per kind under Settings, through `wp_mail` (the host's mail, or an SMTP plugin the
client already runs). The acceptance link goes in the quote email. "Mark sent" stays for the
cases where the document went out another way.

### P1. Statements and reminders

Overdue invoices are counted and listed, and statements exist in the register, but there is no
monthly statement run and no reminder flow. Every accounting-grade competitor sends a statement
on a day of the month and a reminder at a set number of days overdue, with a person switching it
on per customer. This is the feature that most directly affects a wholesaler's cash.

Recommendation: a "Chase" fold on Invoices: the overdue list grouped by customer with the days
overdue and the amount, one "Send statement" and one "Send reminder" per customer (through the
Send panel above, with the two templates), recorded as touchpoints, and a setting for an
automatic monthly statement per customer that the owner switches on. An ageing summary (current,
30, 60, 90+) at the top of Invoices and on the customer page.

### P2. Payments: the counter sale and the receipt

A cash customer at the counter today needs a quote, acceptance, an invoice, a receipt recorded
under "Record cash or card taken at the counter", a release and a collection note: six presses
for a R 300 sale. inFlow, Zoho and Cin7 all have a "sales receipt" or "quick sale".

Recommendation: a **Quick sale** action on Quotes for cash customers: pick the customer and
lines, press "Sold and paid": the quote is made, accepted, invoiced, receipted and released in
one transaction, the collection note is issued, and the PDFs are made. The chain stays whole;
only the presses go.

### P2. Orders, deliveries and picking

The release gate and delivery notes are right. What is missing on the floor: a **picking list**
(what to take from where, by order, printable), **partial delivery by line quantity on one
screen** (today the note form is a separate fold), and a **collected-by / signed-by capture on
a phone** (name typed and a finger signature saved as an image on the note) which Cin7 and
Unleashed both have in their mobile apps. The Deliveries screen at 375 px already works; the
signature pad is one small script.

### P2. Stock: images, locations, batches on the screen

Products have no image. A picture on the product row, the quote line and the datasheet is
expected everywhere now, and it reduces picking errors. Batch tracking exists in the schema
(`batch_tracked`, wb_batches) but has no screen. Multi-location is in the schema (`location`)
with no screen either. Recommend: an image field on the product (filed through the document
register, shown as a 40 px thumbnail), a batch column on receiving and delivery lines when the
product is batch tracked, and locations later only if a client asks.

### P2. The portal is a reader; it should let a customer order

The portal shows quotes, invoices, datasheets and a request-a-quote form. OroCommerce and
Shopify B2B let the customer see their own prices and stock, build an order from their last
orders ("order again") and submit it, with the rep approving. Our request form already becomes a
draft quote for the rep, which is the right control.

Recommendation: on the portal home, "Order again" from any past order (makes the draft quote
with those lines at today's prices), a product list with the customer's own price and "in
stock / low / to order" words, and a cart that becomes the request. The rep still sends the
quote; nothing is accepted without a person. Add "Pay now" later when a gateway is chosen.

### P2. Today should show the week, not only the moment

Today has stat tiles, the needs list and quick actions. Competitors show a sales line for the
month against last month, cash in and out this week, and the top five customers or products.
Our Cashflow and Marketing screens hold the numbers; Today shows none of them.

Recommendation: two small cards on Today for owners: "This month" (sales invoiced, cash
received, both against the same month last year, as numbers and one bar each), and "Cash in
the next four weeks" (the first four weeks of the thirteen-week forecast). No chart library:
bars are divs, as the Getting started meter is.

### P3. Cross-cutting polish

- **Confirmations.** Every destructive or issuing action confirms with a browser dialog.
  Competitors use an in-page sheet with the consequence in words and the button named for the
  action ("Issue the invoice" not "OK"). One sheet component, reused.
- **Undo for archive.** Archive is reversible in the data but there is no "Restore" on the
  screen. Add "Show archived" to lists and a Restore action.
- **Inline help.** The How-to screen and the fold at the foot are good; add a one-line hint
  under each form field that has a rule (terms, credit limit, floor), already supported by
  `WB_Render::field` notes, used on about half the fields.
- **Keyboard.** `/` to focus the search box, `n` for the head action, Esc to close the kebab.
  Nobody in this bracket does it; it costs an afternoon and power users notice.
- **Dates and money.** Dates show as 2026-10-08 in tables; show 8 Oct 2026 everywhere a person
  reads (keep ISO in files). Money shows R 12 345.00 on documents and 12345.00 in some tables;
  one format.
- **Table on a phone.** Rows become cards at 640 px; the kebab is reachable, but a card shows
  every column. Mark two or three columns per table as the card's face and hide the rest behind
  "More".
- **Notifications.** The bar on every screen is useful; the Notifications screen is a table. A
  grouped feed by day, with the thing it is about as a link, reads better.
- **Settings.** "Who can do what" is a grid of ticks per person. Add the three role presets as
  one-click starting points above the grid ("Make Thandi a Sales login"), which the roles
  already define.
- **Demo.** The demo visitor lands on Today with 24 things waiting, which is honest but
  overwhelming. Land the first visit on the How-to screen's first walkthrough, with Today one
  click away, or seed fewer loose ends for the visitor.

## 3. Workflow improvements, counted in presses

| Task | Today | After P1 and P2 | What changes |
|---|---|---|---|
| Quote a known customer, 3 lines, send by email | 9 presses, 2 screens, then Outlook | 5 presses, 1 screen | line search, in-place lines, Send panel |
| Find an overdue invoice for one customer and chase it | find in browser, download, Outlook | 3 presses | search, customer page, Send reminder |
| Counter sale, cash | 6 presses, 4 screens | 2 presses, 1 screen | Quick sale |
| Receive a purchase order with one short line | 3 presses | 3 presses | unchanged; already right |
| Approve a below-floor price | 2 presses | 2 presses | unchanged; the words are the win |
| Load 200 products with datasheets | template, fill, check, import, twice | same | unchanged; better than most |
| Customer reorders last month's order | phone call, rep types it | 2 presses by the customer | portal "Order again" |

## 4. What not to copy from the market

- Editable issued documents. Keep immutability and credit notes.
- Automatic email on every event. Keep "a person presses Send", and make Send easy.
- Bank rules that post without a person. Keep suggested matches.
- Seventeen-column tables with a column chooser. Keep few columns, a detail page and search.
- A dashboard of a dozen charts. Two cards that answer "how is the month" and "is cash fine".
- Per-feature pricing tiers. Everything is in; permissions decide who sees it.

## 5. A roadmap

**1.5 (before the first live client):** line search and in-place lines on quotes; search, filter,
sort and paging on every list; the customer page; the Send panel for quotes and invoices with
templates; truthful front-page words. Estimated at ten working days of build plus tests.

**1.6:** statements and reminders with ageing; Quick sale; product images; Today's two cards;
dates and money in one format; Restore from archive.

**1.7:** portal ordering ("Order again", the price list, the cart); picking list and signature
capture; batch column; keyboard shortcuts; the confirm sheet; the first-visit landing in the demo.

Each release keeps the five rules, the gates and the regression tests; each new screen gets a
walkthrough in the How-to and a line in FLOWS.md through `WB_Guide`.
