# Translations

The theme text domain is `ekwa`. PHP strings are loaded from this folder via
`load_theme_textdomain( 'ekwa', … )` in `functions.php`; block metadata
(`block.json` titles/descriptions) is loaded automatically by WordPress.

## Regenerating the template (.pot)

Use WP-CLI from the theme root:

```
wp i18n make-pot . languages/ekwa.pot --domain=ekwa
```

Then create per-locale files, e.g. `ekwa-fr_FR.po` / `ekwa-fr_FR.mo`
(`wp i18n make-mo languages/`). Drop the `.mo` files in this folder and they
load automatically.
