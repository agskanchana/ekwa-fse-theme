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
