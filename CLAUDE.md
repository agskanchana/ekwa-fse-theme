# Ekwa theme — working rules

## Backward compatibility is the first requirement of every change

This theme auto-updates on live client sites from GitHub releases
(`functions.php` → plugin-update-checker → `agskanchana/ekwa-fse-theme`). **The code
is replaced; the site's data is not.** After an update a site still carries:

- options written by older versions (`ekwa_locations`, `ekwa_social`, the media
  manifest, every `ekwa_*` setting) — with the keys the old version wrote, and
  none of the ones added since
- block markup saved in posts, patterns and templates, with the attributes that
  existed when it was inserted
- theme mods (site logo, colors), menus and their item meta
- child-theme CSS and JS written against the classes the theme rendered *then*

So the test for any edit is: **a site running the previous version, with its
existing data, must render and behave exactly as before after the update.** New
behavior is opt-in — reached only by a setting someone deliberately turns on.

### Rules that follow from it

- **Reading data:** never assume a key exists. `$row['new_field'] ?? default`,
  `! empty( $row['new_flag'] )`. A missing key must land on the old behavior.
- **Never rename, remove or repurpose** an existing option key, array key, block
  attribute, shortcode attribute, function name or CSS class. Add alongside it
  and keep the old one working. Renaming a block attribute invalidates every
  block already saved in a client's database.
- **New block attributes** need a `default` in `block.json` that reproduces the
  old rendering; same for new shortcode attributes in `shortcode_atts()`.
- **Rendered markup:** existing classes and element order stay. A new variant
  gets a new modifier class (e.g. `…__row--note`) — it must not change the
  markup of the rows that already existed, because child CSS targets them.
- **Sanitize callbacks** must tolerate rows saved by older versions (fewer keys,
  different shapes — e.g. the geocode importer writes `day` + `closed` only).
- **Pluggable helpers** (`if ( ! function_exists( … ) )`) are a child-theme
  extension point: keep their names and signatures.
- **Never write to site state as a side effect.** Theme mods, options, menus and
  media may only be written from an explicit user action, and never over
  something the site already has — report it instead and let the user decide.
- **Migrations, if truly unavoidable,** run once behind a version-stamped option,
  are idempotent, and never delete the old data in the same release.

### How to verify it (do this, don't just assert it)

For anything that renders from saved data, diff the output of the old code
against the new code using *old-shaped* data:

```bash
# 1. extract the pre-change file(s)
git show HEAD:inc/ekwa-shortcodes.php > /tmp/old/inc/ekwa-shortcodes.php
# 2. render the same legacy fixtures through both (see the harness pattern:
#    stub esc_*/__/shortcode_atts/get_option, require the file, call the function)
# 3. compare — normalize line endings first: the working tree is CRLF and
#    `git show` emits LF, which otherwise reads as a false difference
```

Fixtures must include data as the *old* version wrote it — the new keys absent
entirely, not set to `0`. State the result in the summary: which fixtures, which
attribute combinations, identical or not.

### What to flag to the user

If a change alters behavior for an existing site in any way that isn't opt-in —
including one that only fires on an explicit action — say so plainly in the
summary rather than letting it ship silently.

## Releasing

`live` is the branch the updater tracks; sites update only when `style.css`
`Version:` is **higher** than what they run. Bump it with every push to `live`
and never reuse or go below a number that was ever on `live` (3.4.0 was on
`live` twice, so sites that took the first one never got the second).

## Page audit — PageSpeed, accessibility and SEO with AI fixes

Every page/post editor has a "Page Speed & Accessibility" sidebar; the admin
menu has a **Page Speed** dashboard for all pages and posts. Google PageSpeed
Insights tests the published page; the site's AI provider (Gemini key or the
MCP connector) reads the report plus the page, its template parts and the site
CSS, and proposes fixes. The user applies them one by one; every change is
checked, verified and undoable.

**This feature is meant to keep growing.** When the user reports a fix the AI
could only give as "manual", or a fix that was applied but didn't work, the job
is to teach the AI a real method for it (recipe below) and log it at the end of
this section.

### Where the code is

| File | What it does |
|---|---|
| `inc/ekwa-page-audit.php` | PSI fetch + compact report, PASS/FAIL, REST routes, **the AI prompt** (`ekwa_page_audit_system_prompt()`, incl. THEME KNOWLEDGE), fix validation (`ekwa_page_audit_validate_fixes()`), settings registry, change logs, undo, editor localization |
| `inc/ekwa-page-audit-css.php` | CSS fix engine: locate a rule/declaration, apply in place, exact revert; targets (site stylesheet, page-block Section CSS, template-part Section CSS) |
| `inc/ekwa-page-audit-cascade.php` | Page snapshot (HTML + every `<style>` in order), specificity, "does this declaration win?", selector strengthening, `OTHER_CSS` for the prompt |
| `inc/ekwa-page-audit-color.php` | Contrast maths (alpha-composited), Lighthouse contrast data parsed from each failing element, **verified colour options** for colour fixes, `CONTRAST` hints for the prompt |
| `inc/ekwa-page-audit-parts.php` | Template parts a page uses, their block outline for the AI, surgical attribute changes (one block delimiter rewritten), undo |
| `inc/ekwa-page-audit-compare.php` + `assets/js/ekwa-page-audit-compare.js` | Side-by-side compare (without / with a stylesheet fix) via a signed, uncached front-end preview |
| `inc/ekwa-page-audit-dashboard.php` + `assets/js/ekwa-page-audit-dashboard.js` | Site-wide dashboard, bulk re-tests from the browser |
| `assets/js/ekwa-page-audit-editor.js` | The sidebar: scores, PASS/FAIL, grouped fix cards, apply/undo, automatic verification |
| `inc/ekwa-site-logo-priority.php` | Example of a capability added just so the AI can fix something (see log) |

### Fix kinds (what the AI can do)

- **setting** — one whitelisted option from `ekwa_page_audit_settings_registry()` (site-wide, admins).
- **block** — attributes of one block in the page body, applied in the editor.
- **part** — attributes of one block inside a template part (header, footer, mobile header…), applied on the server. Only attributes stored in the block delimiter (no `source` in the attribute definition) and only parts saved in the database.
- **css** — one declaration in the site stylesheet (old value kept in an `ekwa-fix:<id>` comment, may add ONE new colour token), or in a block's Section CSS (`css.in` → page block or template part).
- **manual** — steps for a person. Every manual fix is a candidate for a new method.

### Invariants — keep these when changing anything here

- **No side effects before the AI call** in `/page-audit/analyze`: the MCP relay re-sends the request. Reading (including fetching the page) is fine; writing is not.
- **The client only sends a fix id.** Apply routes read the validated fix from the stored analysis, never from the request.
- **Every write is logged and exactly undoable**, and undo refuses when the target was edited since (never overwrite a newer edit).
- **Never put `/* */` comments in block attributes** (Section CSS / scopedCss): saving them through the editor's REST request trips ModSecurity CRS 942440 → 403. Section CSS fixes use `--ekwa-fix-<id>:"was …"` custom properties instead. Stylesheet comments are fine (the settings form is WAF-safe packed).
- **The cascade:** the site stylesheet prints in `<head>`; block CSS (`ekwa-inline-blocks-*-style`) and Section CSS (`ekwa-inline-<hash>`) print later in the body and win ties. Every css fix goes through `ekwa_page_audit_cascade_check()`; a losing one is strengthened or made manual.
- **A colour is only offered if it passes against the background Lighthouse measured** for the elements the fix covers (never the model's assumed "against"). Options: passing existing tokens (no border/scrim/background-named tokens), a shade of the current colour, the model's pick only if it passes; the person chooses, Apply/Compare read the option from the stored fix. Semi-transparent colours are composited over the background before measuring.
- **Never redefine an existing colour token** (it recolours the whole site). New tokens go only into the site stylesheet; in Section CSS the value is written directly.
- **PASS/FAIL must equal the team's sitemap tool** (`E:\work\ekwa-tools\new\performance-eval`, `psi/client.py` `evaluate()`): Performance ≥ 90, Accessibility 100, SEO 100, FCP ≤ 1.8 s, LCP ≤ 2.5 s, CLS ≤ 0.1; Best Practices and TBT recorded, not enforced; a missing score fails, a missing metric is skipped. Change `ekwa_page_audit_thresholds()` only together with that tool.
- **Old stored data:** reports saved before v2 have no `seo`/`best-practices` scores and no metric `value`; analyses before v2 only have setting/block/manual fixes. Readers must tolerate both.
- **Front end:** the audit adds nothing to pages except the signed compare preview and whatever opt-in capability a fix switches on.

### Recipe — teaching the AI a new fix

1. **Find where the fix really lives.** Check the live page's HTML and CSS (`curl` the public URL; `ekwa_page_audit_snapshot_from_html()` + `ekwa_page_audit_cascade_check()` explain which rule wins). Is it a block attribute, a template part, the stylesheet, a block's Section CSS, a theme setting, or something the theme can't do yet?
2. **Pick the mechanism, in this order:**
   1. An existing attribute/setting already does it → only a THEME KNOWLEDGE line is needed.
   2. **New block attribute**, opt-in, default reproduces the old rendering. Ekwa blocks: `block.json` + render callback. Core blocks: `register_block_type_args` (server) **and** a `blocks.registerBlockType` filter in editor JS (so editors keep it on save) + an inspector toggle for humans. Store it in the delimiter (no `source`) so `part` fixes can set it. The AI sees it automatically — schemas come from the block registry.
   3. **New site-wide option** → add it to `ekwa_page_audit_settings_registry()` (bool/enum, default = the theme's `get_option()` default, `help`, `risk`).
   4. **CSS** is already covered; if a CSS fix didn't work, the cause is in the cascade check or the validator — fix it there, generally, not for one site.
3. **Tell the AI:** add a THEME KNOWLEDGE line in `ekwa_page_audit_system_prompt()` mapping the Lighthouse audit to the fix (which attribute/setting, on which block, in which part — e.g. mobile header on mobile).
4. **Verify end to end** with WP-CLI harnesses (`wp eval-file`): a synthetic AI fix through `ekwa_page_audit_validate_fixes()` must come out one-click; apply via REST → check the result → undo → byte-identical. Diff old vs new rendering for saved data without the new attribute (rules at the top of this file).
5. **Log it** below, bump the version, push to `live`.

### Testing notes

- PageSpeed cannot reach the local site (`ekwa-fse.test`); use synthetic reports (`ekwa_page_audit_put_meta()` with a v2 report) or a public test site.
- `wp eval-file` + `rest_do_request()` drives every route without a browser. Python Playwright (with Chromium) is installed for headless UI checks.
- Clean up everything a test writes: test pages, temp users, options (snapshot + restore), template parts.

### Methods added (append here)

| Version | Problem the user reported | Method the AI now has |
|---|---|---|
| 3.3.9 | Contrast/colour issues needed CSS | `css` fixes in the site stylesheet using colour tokens, `ekwa-fix:` comments, compare view |
| 3.3.9 | Issues in header/footer were manual | `part` fixes on template-part blocks |
| 3.4.1 | Breadcrumb underline applied but never showed (block CSS won the cascade) | Cascade check against the real page; losing selectors strengthened; `OTHER_CSS` + `PREVIOUS` in the prompt; live-page check + automatic PageSpeed re-test after applying |
| 3.4.1 | CSS also lives in `ekwa/div` Section CSS | `css.in` targets for page-block and template-part Section CSS (comment-free markers) |
| 3.4.1 | LCP was the site logo in the mobile header (manual) | `core/site-logo` attribute `ekwaFetchPriority` ("Load first (LCP image)"): fetchpriority=high, no lazy, `<head>` preload; AI sets it as a `part` fix |
| 3.4.2 | Colour fixes went round in circles (AI picked a colour that failed against the real background) | Verified colour options: each passes against Lighthouse's measured background; the user picks one in the fix card; `CONTRAST` hints (tokens that already pass) in the prompt |
