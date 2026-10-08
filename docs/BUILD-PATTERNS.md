# Build patterns from Kaycie, FindGro and Brandzgro — what applies to wb-core

*7 October 2026. Written after Zina's correction: look at the other projects for **how** to build,
not for **what** to build. Nothing here adds a function. Each item says where the lesson came from,
what wb-core does today, and the change in method (if any). Sources are the three projects'
own docs and code as read on 7 October; nothing is assumed.*

Read with `System Integrity Framework/DATA-ARCHITECTURE.md` (the five rules) and
`README.md` → *How we change this safely* (the build gates). Much of Kaycie's method is already in
wb-core because its conventions were the starting point. This document is mainly about the gaps.

---

## 1. Already in wb-core (no action, listed so nobody re-invents them)

| Pattern | Kaycie source | Where it lives in wb-core |
|---|---|---|
| Capabilities, never role names | DEVELOPER-MANUAL §4.1 | `WB_Roles`, every engine entry point |
| Column-safe, fail-closed data access | §4.2, §4.7 | `WB_CCT` |
| Ledger every state change | §4.3 | `wb_ledger_write()`, `WB_CCT` |
| Nonce-POST + redirect back to where you were, never `wp_get_referer()` | §4.5, §4.18 | `wb_return_field()`, `wb_return_url()` |
| Row actions over ID-entry forms; the handler is the law, visibility is convenience | `KC_RowActions` header | `WB_RowActions` |
| Primitives, never hand-written markup; extend the primitive, never fork it | §4.17, STANDARDISATION-AUDIT | `WB_Render` (1 inline style and 2 hand-built tables in the whole plugin) |
| ⋯ menu is the last column; tables never scroll sideways; cards under 640px | §4.11 | `WB_Render::render_table`, `wb-dashboard.css` |
| One delegated double-confirm handler | §4.13 | `WB_Render::footer_script` |
| Browser-facing REST errors are pages, never JSON | "Browser-facing REST errors are pages" | `WB_Rest::human_page()` on every link route |
| Setup checklist is a live check, never a stored tick | `KC_Setup` header | `WB_Setup::checklist()` |
| Contrast is refused at save, not patched in the browser | FindGro's `fg-contrast.js` does it after render; wb-core's way is stronger | `WB_Setup::check_pairs()` |
| Version in header must equal the constant; `php -l` after any scripted bump | §8.10, build.py lint gate | `tools/build.py` gates 1 and 4 |
| Source files are the master; never edit in the block editor | Brandzgro `DEPLOY.md` | Plugin-served screens (0.3.0) |

---

## 2. Method changes that apply to functions we already have

### 2.1 One list drives the menu, the home tiles and the gates
**Source.** `KC_Registry` (Kaycie 0.7.2). Before it, the same knowledge lived in five hand-kept
lists and "sidebar and home must match" came back every release. Now one list of destinations;
everything else derives.

**wb-core today.** Three lists must agree by hand: `WB_Workspace::SCREENS` (address, gate,
words), `WB_Roles::catalog()` (the dashboard ticks and the caps each grants) and the stat tiles in
`WB_Screens::home()` (each with its own `current_user_can` and `home_url('/workspace/…')`).

**Change.** Not a new class. Two things:
1. The home tiles point at screens by **slug** through `WB_Workspace::url()`, never a typed
   `/workspace/…` string. Same for the 38 `home_url( '/workspace/…' )` calls in the engines.
2. A test that walks `WB_Roles::catalog()` and asserts that every dashboard tick opens at least one
   screen in `SCREENS` whose gate is among the tick's caps. Today nothing stops a tick granting caps
   for a screen that no longer exists.

### 2.2 A boot-and-render proof, not only pure-function tests
**Source.** Kaycie's `tools/harness/harness-lib.php`: stub enough of WordPress to **include the
plugin and run its `plugins_loaded` and `init` closures**, then render every dashboard for several
capability sets and fail on any PHP warning or empty page. "Any fatal here is the fatal that killed
the site." It was written after 0.7.0 fataled on demo (a page rename at `plugins_loaded`).

**wb-core today.** The tests are pure functions only (the README says so). `tools/inventory.php`
already boots the plugin with stubs, but only to list what it registers. The one-off render stub I
ran on 7 October caught a real fatal (`mb_strtoupper` on a build without mbstring) that 42 passing
assertions had not.

**Change.** Make that stub a permanent gate: `tests/render-*.php` boots wb-core the way
`inventory.php` does, then renders every entry in `WB_Workspace::SCREENS` and the portal for
owner / sales / warehouse / accounts / plain staff / customer / signed-out, with every `WB_CCT`
table reported missing (a fresh site) and again present but empty. Fail on any notice, warning,
empty `<main>`, or a menu item whose gate the scenario cannot pass. Add it to `build.py` gate 2.

### 2.3 Screen words come from one map; stored values are never renamed
**Source.** `KC_Words` and `DATA-VOCABULARY.md`: *rename what is regenerated from code, never
what is stored, bridge the two in one place.* The restructure renamed every screen word and
carried **zero** table, column or status-value renames.

**wb-core today.** `WB_Render::LABELS` maps column keys to words and `WB_Render::chip()` tones
status values, but five places still make a label with `ucfirst( str_replace( '_', ' ', … ) )`,
and there is no written map of stored value → screen word.

**Change.** Route the five through `WB_Render::label()` / a `WB_Render::word( $column, $value )`
that reads one map, and add a short *Values inside rows* table to `DATA-ARCHITECTURE.md` (status
values, `record_status`, `match_method`, `price_source`) so the real name, the word and the reason
sit together. This is what lets us pick the product name later without touching data (README:
"Pick the real name before the first live client").

### 2.4 Bounded output states its bound, everywhere
**Source.** Kaycie, "the silent 50-row cap": a list that stops at its limit with no sign reads as
"everything is here"; a person searches for a record that "isn't in the system" and creates a
duplicate; an accountant files a CSV that silently lost rows.

**wb-core today.** `[wb_list]` says "Showing the newest N" when its cap bites. The other 78
bounded fetches in the screens (`limit => 300`, `500`, `2000`…) say nothing.

**Change.** One helper in `WB_Render` that takes rows and the limit and appends the note when
`count === limit`, used by every screen fetch; and the last line of any CSV the plugin writes
(bank file, exports) says `EXPORT CAPPED AT N ROWS` when the fetch filled its cap. Mechanical, no
new function.

### 2.5 Gates fail with words, at panel level too
**Source.** Kaycie's `GATES-AND-ERRORS.md`: page gates served a blank page (fixed in 0.3.0 here);
**36 panel gates** returned `''`, which is right when the whole dashboard is out of reach and wrong
when the person can open the page but not a fold on it — the page then looks broken.

**wb-core today.** Screens use the same `if ( ! current_user_can(…) ) return ''` inside folds.

**Change.** Keep silent absence where the fold is an *extra* (an approve queue for approvers).
Where a fold is the *point* of the screen for that person (e.g. Payroll for someone with only
`wb_view_payroll` opening the run panel), show the one-line refusal that names the tick, through
`wb_notice()`. A pass over `WB_Screens` with that rule; no new words beyond the existing notice.

### 2.6 A data fingerprint around every upload
**Source.** Kaycie's `compare-fingerprint.py` with a snippet that prints tables, columns, row
counts, roles and caps before and after a migration; the comparison applies the rename map first,
so a wrong map fails loudly instead of data failing quietly. Used before every upload of 0.7.x.

**wb-core today.** `tools/inventory-baseline.json` guards what the **code** registers. Nothing
checks what the **site** holds across an upload.

**Change.** A read-only snippet (or a `wp eval-file`) that prints the fingerprint of the staging
site, kept in `tools/`, and a `compare` script. Run it before and after each zip. The expected
difference for 0.3.0 is exactly: 35 new `jet_cct_wb_*` tables, nothing else. This belongs with
README → *Staging before live*.

### 2.7 Migrations that touch pages or posts run at `init`, behind a done flag
**Source.** Kaycie 0.7.0: `wp_update_post` at `plugins_loaded` fatals (`get_page_permastruct()`
on null); 0.7.1 moved it to `init` priority 5 behind an option. Also the `wb_activate()` re-run on
version change, which wb-core already has, must stay cheap and idempotent for the same reason.

**wb-core today.** Already applied to the rewrite flush (`wp_loaded`). Nothing else touches posts.

**Change.** A line in README → *How we change this safely*: anything that creates, renames or
reads pages runs at `init` or later, once, behind an option; the render proof (2.2) runs the boot
with `$GLOBALS['__booted'] = false` before `init` so this cannot regress.

### 2.8 One-shot notice parameters are stripped on return; view-state ones never are
**Source.** Kaycie §UI conventions: every new PRG message code must be added to the strip list
in `kc_return_url()`, or the notice re-fires on every reload; filters and selections are never
stripped.

**wb-core today.** `wb_return_url()` strips `wbmsg` and `wbra`. `?month=` on Integrity is
view-state and correctly kept.

**Change.** None in code. A one-line rule in the `wb_return_url()` docblock so the next message
parameter goes in the list.

### 2.9 Placeholders are realistic examples, never instructions
**Source.** Kaycie, 2026-08-17: the label says *what*, the hint shows *what good input looks
like* (`Thandi Mokoena`, `INV-1042`, `1500.00`). Kaycie's second reason (its demo engine types the
placeholder) does not apply here; the first does.

**wb-core today.** Two placeholders in the whole plugin.

**Change.** Add examples to the text and number fields on the add-customer, add-product,
price-rule and bank-mapping forms. Never on `type="date"`. Small, do it when those screens are next
touched.

### 2.10 Off-site anchor for the audit ledger
**Source.** FindGro's audit module: append-only, hash-chained, and **anchored off the server once
a day** (latest entry number and fingerprint written to private R2). Someone with the database
password can rebuild the chain; they cannot make it match the copies already off-site.

**wb-core today.** The ledger is chained, keyed when `WB_ENCRYPTION_KEY` is set, and keeps a tail
record (0.2.1, S3). Nothing leaves the server.

**Change.** When a storage driver other than `local` exists (`wb_storage_driver`, already
planned), the nightly verify writes the tail record there too and the Integrity report says when it
last did. Same function, one more place the tail is kept.

---

## 3. Patterns we deliberately do not take

| Pattern | Why not here |
|---|---|
| Dashboards as JetEngine Profile Builder sub-pages (Kaycie, FindGro) | Both needed repair tools when slugs, roles or templates drifted (`KC_Jet_Profile`, FindGro `pb_fix`). 0.3.0 serves the screens from one constant in code instead. |
| Module loader with `function_exists` guards (FindGro) | That exists so the plugin can coexist with live FluentSnippets during migration. wb-core has no snippets to coexist with; one plugin, one loader. |
| Scripted patch files per version (`tools/patch-0-7-11-a.py` … 40+ of them) | Kaycie edits on Windows without a PHP binary and patches by script. wb-core has git branches and `php -l`; a patch-script trail would be noise. |
| Admin tools over the REST API (FindGro `tools-api.php`) | Useful for FindGro because Claude operates the live site. For wb-core this would be a new surface; raise it separately if staging work makes it worth it. |
| Self-hosted auto-updater and R2 manifest (Kaycie `KC_Updater`, `UPDATE-DISTRIBUTION.md`) | Right answer the day there is a second site. Not before. |
| Per-tenant raw CSS box | Kaycie removed it ("tech-not-savvy users, asking for errors"). wb-core's Setup already gives colour pickers with a contrast check and nothing else. |

---

## 4. Gotchas worth carrying over (from Kaycie §8 and FindGro's start-here)

- FluentSnippets bodies carry no `<?php`; scan for `*/` inside doc comments.
- JetEngine auto-updates are **off** on Kaycie's live site (Zina's call, 26 Sep). Do the same on
  any site running wb-core: the CCT creator and `WB_CCT` are written against JetEngine 3.8.
- Page caching and nonces: any public page with a POST (the quote-accept page) must be excluded
  from page cache, or the nonce is stale.
- A "yes cookie" consent plugin broke login redirects and snippet nonces on Kaycie. Keep it off.
- WP REST cookie auth needs `X-WP-Nonce`; plain links and `<img>` cannot send headers, hence the
  two-hop token on `/download` (wb-core already does this).
- CRLF files: Kaycie's `pricing.html` is CRLF; check line endings before a scripted edit.
- JetEngine switchers store `"true"`/`"false"`: always `wb_truthy()`, never `empty()` (wb-core S4).

---

## 5. Order of work

Smallest first, each one a branch and a regression test:

1. **2.2 render proof** — it already found one fatal; it guards everything below.
2. **2.1 slugs not strings** + the catalogue-vs-screens test.
3. **2.4 bounded output** helper, applied across `WB_Screens`.
4. **2.3 one word map** + the vocabulary table in `DATA-ARCHITECTURE.md`.
5. **2.6 data fingerprint** before the 0.3.0 upload to staging.
6. 2.5, 2.8, 2.9 as the screens are next touched. 2.7 is a README line. 2.10 waits for the
   storage driver.
