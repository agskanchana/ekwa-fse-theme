# Ekwa FSE Theme — Developer Reference

WordPress Full Site Editing block theme for small business websites (dental, medical, legal).  
Requires WordPress 6.4+ and PHP 8.0+.

## Architecture

```
ekwa/
├── assets/css/           # Stylesheets (ekwa-blocks.css, ekwa-block-styles.css, ekwa-editor.css, ekwa-mobile.css)
├── assets/js/            # Block editor scripts + frontend JS (no build step — vanilla JS)
├── assets/fontawesome/   # Font Awesome 6.5.1 (self-hosted)
├── assets/fonts/         # Inter + Playfair Display web fonts
├── assets/mmenu-light/   # Mobile off-canvas menu library
├── blocks/               # Custom block definitions (block.json per block)
├── inc/                  # PHP: block registration, settings, shortcodes, block styles
├── parts/                # Template parts (header, footer, mobile variants)
├── patterns/             # Block patterns (hero, services, cta)
├── templates/            # FSE page templates
├── functions.php         # Theme setup, enqueueing, includes
├── theme.json            # Design tokens (colors, fonts, spacing, layout)
└── style.css             # Theme header + responsive visibility
```

All custom blocks are **server-side rendered** (PHP render callbacks, `save` returns `null`).  
Editor scripts use `wp.element.createElement` — no JSX, no build tooling.

---

## Custom Blocks (28)

### Raw Blocks (3) — Free-Form Mockup Conversion

These blocks output **exactly** the HTML you specify — no WordPress wrapper classes, no layout styles, no inner wrappers. Use with the `tools/mockup-converter.php` CLI tool for free-form mockup workflows where CSS copies 1:1 from mockup to child theme.

| Block | Output | Purpose |
|-------|--------|---------|
| `ekwa/div` | `<tagName class="your-classes">children</tagName>` | Clean wrapper element. Supports `div`, `section`, `header`, `footer`, `nav`, `main`, `aside`, `article` via `tagName` attribute. No forced classes, no inner wrappers. |
| `ekwa/image` | `<img class="your-classes" src="..." alt="...">` | Clean image element. No `<figure>` wrapper, no `wp-block-image` class. |
| `ekwa/link` | `<a href="..." class="your-classes">text</a>` | Clean anchor element. No `ekwa-btn` classes, no variant/size logic. |

### Layout Blocks (7) — Structured Mockup Conversion

These blocks output clean HTML that maps 1:1 to mockup markup. Use these instead of `core/group`, `core/columns`, `core/cover`, `core/buttons` for new content. Note: these blocks add `ekwa-*` classes and WordPress wrapper attributes via `get_block_wrapper_attributes()`.

| Block | Output Tag | Purpose |
|-------|-----------|---------|
| `ekwa/section` | `<section>` (or `<header>`, `<footer>`, `<main>`, `<aside>`, `<article>` via `tagName`) | Top-level page section with optional background image, overlay, and inner container width |
| `ekwa/container` | `<div>` with `max-width` + `margin:0 auto` | Centered content wrapper (presets: 700px, 900px, 1000px, 1100px, 1280px) |
| `ekwa/flex` | `<div>` (or `<nav>`, `<header>`, `<footer>` via `tagName`) with `display:flex` | Flexbox layout (direction, justify, align, wrap controls) |
| `ekwa/grid` | `<div>` with CSS Grid | Responsive grid (desktop/tablet/mobile column counts, custom widths) |
| `ekwa/button` | `<a>` or `<button>` | Clean button with variants (filled/outline/ghost), sizes (sm/default/lg), optional FA icon |
| `ekwa/button-group` | `<div>` with `display:flex` | Flex wrapper for grouping buttons (only allows `ekwa/button` children) |
| `ekwa/text` | `<span>` (or `<small>`, `<strong>`, `<em>`, `<mark>`, `<time>`, `<label>`, `<sup>`, `<sub>` via `tagName`) | Inline text element for badges, labels, stats, icon companions |

### Data & UI Blocks (18)

| Block | Purpose |
|-------|---------|
| `ekwa/icon` | Standalone Font Awesome icon (optionally linkable via `url` attribute) |
| `ekwa/card-link` | Linked card wrapper — renders `<a>` with InnerBlocks (for clickable service cards, feature cards, etc.) |
| `ekwa/phone` | Clickable phone number (multi-location, ad-tracking) |
| `ekwa/phone-dropdown` | Multi-location phone dropdown |
| `ekwa/address` | Address with directions link |
| `ekwa/address-dropdown` | Multi-location address dropdown |
| `ekwa/hours` | Business hours display |
| `ekwa/map` | Google Maps embed |
| `ekwa/social` | Social media icons |
| `ekwa/search` | Full-screen search overlay |
| `ekwa/hamburger-menu` | Mobile off-canvas menu trigger |
| `ekwa/scroll-top` | Scroll-to-top FAB button |
| `ekwa/mobile-dock` | Floating mobile bottom dock |
| `ekwa/conditional` | Visibility wrapper (page, device, user role, schedule, ad-tracking) |
| `ekwa/inner-banner` | Inner page banner (featured image + breadcrumbs) |
| `ekwa/page-title` | Conditional page title (shows when menu name differs from page title) |
| `ekwa/copyright` | Auto-generated copyright year |
| `ekwa/sitemap` | Dynamic sitemap with collapsible tree |

### Core Block Extension

`core/button` has a **phone-number extension** (`ekwa-button-phone.js`): adds `ekwaPhoneButton`, `ekwaPhoneType`, `ekwaPhoneLocation` attributes. At render time, injects `tel:` link via `render_block` filter.

---

## Block Style Variations

Registered in `inc/ekwa-block-styles.php`, styled in `assets/css/ekwa-block-styles.css`.

### core/button

| Style class | Description |
|-------------|-------------|
| `is-style-outline` | WP built-in. Transparent bg + `currentColor` border. Theme adds hover transition. |
| `is-style-ghost` | Transparent bg + white semi-transparent border. For dark background sections. |
| `is-style-size-sm` | Compact padding (6px 16px) + small font size. |
| `is-style-size-lg` | Generous padding (16px 48px) + medium font size. |

### core/group

| Style class | Description |
|-------------|-------------|
| `is-style-service-card` | Hover lift (-4px) + shadow transition. |
| `is-style-parallax-bg` | `background-attachment: fixed` (iOS fallback to scroll). |
| `is-style-has-overlay` | Dark `::before` overlay (50% black). Inner content stays above. |

### core/column

| Style class | Description |
|-------------|-------------|
| `is-style-card` | Hover lift + shadow transition. |

---

## Color Palette (theme.json)

| Slug | Hex | Usage |
|------|-----|-------|
| `primary` | #1a6ef5 | Brand blue — buttons, links, accents |
| `primary-dark` | #0f4fc2 | Hover states |
| `accent` | #f5a623 | Orange — CTAs, highlights |
| `accent-dark` | #d48c0e | Accent hover |
| `foreground` | #1a1a2e | Body text |
| `foreground-light` | #4a4a68 | Secondary text |
| `background` | #ffffff | White background |
| `background-alt` | #f4f6fa | Alternate section bg |
| `surface` | #e8ecf4 | Borders, dividers |
| `gray-100` | #f3f4f6 | Lightest gray |
| `gray-200` | #e5e7eb | Light gray |
| `gray-300` | #d1d5db | Medium-light gray |
| `gray-500` | #6b7280 | Mid gray |
| `gray-700` | #374151 | Dark gray |
| `gray-900` | #111827 | Near-black |
| `success` | #10b981 | Green — success states |
| `danger` | #ef4444 | Red — error/danger |
| `warning` | #f59e0b | Amber — warnings |
| `info` | #3b82f6 | Blue — informational |
| `white` | #ffffff | Pure white |
| `black` | #000000 | Pure black |

Use color slugs in block markup: `"textColor":"primary"` or `"backgroundColor":"background-alt"`.

---

## Spacing Presets

| Slug | Size |
|------|------|
| `xs` | 4px |
| `sm` | 8px |
| `sm-md` | 12px |
| `md` | 16px |
| `md-lg` | 24px |
| `lg` | 32px |
| `lg-xl` | 48px |
| `xl` | 64px |
| `2-xl` | 128px |

Use in block markup: `"padding":{"top":"var:preset|spacing|lg"}`.

---

## Layout / Container Sizes

- **Default content width:** 1280px (`contentSize`)
- **Wide width:** 1600px (`wideSize`)

### Custom container widths

Use the `contentSize` attribute on any group block to set a custom container width:

```html
<!-- wp:group {"layout":{"type":"constrained","contentSize":"900px"}} -->
<div class="wp-block-group">...</div>
<!-- /wp:group -->
```

Common sizes used in mockups: 700px, 900px, 1000px, 1100px, 1280px.

---

## Conversion Rules (Mockup HTML to WordPress Blocks)

### Free-Form Mode (recommended for new sites)

Use `tools/mockup-converter.php` to convert any HTML mockup automatically. See `mockup-instructions.md` for full details.

| Mockup HTML | WordPress Block | Output |
|---|---|---|
| `<section>`, `<header>`, `<footer>`, `<nav>`, `<main>`, `<aside>`, `<article>` | `ekwa/div` with `tagName` | `<tagName class="your-classes">children</tagName>` |
| `<div>` | `ekwa/div` | `<div class="your-classes">children</div>` |
| `<div style="display:flex">` | `ekwa/flex` | Flex container with layout attributes |
| `<div style="display:grid">` | `ekwa/grid` | Grid container with column attributes |
| `<div style="max-width:...;margin:auto">` | `ekwa/container` | Centered container |
| `<img>` | `ekwa/image` | `<img class="..." src="..." alt="...">` (no figure) |
| `<a>` | `ekwa/link` | `<a href="..." class="...">text</a>` (no btn styles) |
| `<h1>` – `<h6>` | `core/heading` | Standard heading |
| `<p>` | `core/paragraph` | Standard paragraph |
| `<i class="fa-…">` | `ekwa/icon` | Font Awesome icon |
| `<span>`, `<small>`, etc. (text-only) | `ekwa/text` | Inline text element |
| `<ul>`, `<ol>` | `core/list` | Standard list |
| Mixed content (text + elements) | `core/html` | Raw HTML preserved |

### Structured Mode (original — uses ekwa-* class conventions)

| Mockup HTML | WordPress Block |
|-------------|----------------|
| `<section>` | `ekwa/section` |
| `<div>` (container) | `ekwa/container` (if centered) or `ekwa/flex` / `ekwa/grid` (if layout) |
| `<div>` (flex row) | `ekwa/flex` |
| `<div>` (grid) | `ekwa/grid` |
| `<a class="btn">` | `ekwa/button` |
| `<button>` | `ekwa/button` with `htmlTag: "button"` |
| `<span>`, `<small>`, `<strong>`, `<em>` | `ekwa/text` |
| `<a>` (card wrapper) | `ekwa/card-link` |
| `<i class="fa-…">` | `ekwa/icon` |
| `<h1>` – `<h6>` | `core/heading` |
| `<p>` | `core/paragraph` |
| `<img>` | `core/image` |
| `<ul>`, `<ol>` | `core/list` |
| `<hr>` | `core/separator` |

### Section (with background image + overlay)

```html
<!-- wp:ekwa/section {"tagName":"section","bgImageUrl":"hero.jpg","bgSize":"cover","bgPosition":"50% 50%","bgFixed":false,"overlayColor":"#1a1a2e","overlayOpacity":80,"style":{"dimensions":{"minHeight":"85vh"},"spacing":{"padding":{"top":"var:preset|spacing|2-xl","bottom":"var:preset|spacing|2-xl"}}}} -->
  <!-- inner blocks here -->
<!-- /wp:ekwa/section -->
```

**With inner container:**
```html
<!-- wp:ekwa/section {"containerWidth":"900px","backgroundColor":"background-alt","style":{"spacing":{"padding":{"top":"var:preset|spacing|2-xl","bottom":"var:preset|spacing|2-xl"}}}} -->
  <!-- content constrained to 900px -->
<!-- /wp:ekwa/section -->
```

### Container

```html
<!-- wp:ekwa/container {"maxWidth":"1280px","style":{"spacing":{"padding":{"left":"var:preset|spacing|md","right":"var:preset|spacing|md"}}}} -->
  <!-- centered content -->
<!-- /wp:ekwa/container -->
```

### Flex Layout

```html
<!-- wp:ekwa/flex {"direction":"row","justifyContent":"space-between","alignItems":"center","style":{"spacing":{"blockGap":"var:preset|spacing|md"}}} -->
  <!-- child blocks placed side-by-side -->
<!-- /wp:ekwa/flex -->
```

### Grid Layout

```html
<!-- wp:ekwa/grid {"columns":3,"tabletColumns":2,"mobileColumns":1,"style":{"spacing":{"blockGap":"var:preset|spacing|lg"}}} -->
  <!-- child blocks placed directly in grid cells — no column wrappers -->
<!-- /wp:ekwa/grid -->
```

**Custom column widths:**
```html
<!-- wp:ekwa/grid {"columnWidths":"1fr 2fr 1fr","tabletColumns":1,"mobileColumns":1} -->
```

### Buttons

**Filled button (default):**
```html
<!-- wp:ekwa/button {"text":"Book Appointment","url":"/book/","variant":"filled","backgroundColor":"primary","textColor":"white"} /-->
```

**Outline button:**
```html
<!-- wp:ekwa/button {"text":"Our Services","url":"/services/","variant":"outline","textColor":"primary"} /-->
```

**Ghost button (on dark sections):**
```html
<!-- wp:ekwa/button {"text":"Learn More","url":"/about/","variant":"ghost"} /-->
```

**Button with icon:**
```html
<!-- wp:ekwa/button {"text":"Call Us","url":"tel:+15551234567","iconClass":"fa-solid fa-phone","iconPosition":"left","variant":"filled","backgroundColor":"primary","textColor":"white"} /-->
```

**Button sizes:** Use `"size":"sm"` or `"size":"lg"`. Custom padding via style attributes also works.

### Button Group

```html
<!-- wp:ekwa/button-group {"justifyContent":"center","style":{"spacing":{"blockGap":"var:preset|spacing|md"}}} -->
  <!-- wp:ekwa/button {"text":"Book Appointment","url":"/book/","variant":"filled","size":"lg","backgroundColor":"primary","textColor":"white"} /-->
  <!-- wp:ekwa/button {"text":"Our Services","url":"/services/","variant":"outline","size":"lg","textColor":"white"} /-->
<!-- /wp:ekwa/button-group -->
```

### Inline Text

**Badge/label:**
```html
<!-- wp:ekwa/text {"tagName":"span","text":"New Patient Special","backgroundColor":"accent","textColor":"white","style":{"spacing":{"padding":{"top":"var:preset|spacing|xs","bottom":"var:preset|spacing|xs","left":"var:preset|spacing|sm","right":"var:preset|spacing|sm"}},"border":{"radius":"99px"},"typography":{"fontSize":"sm","fontWeight":"600","textTransform":"uppercase"}}} /-->
```

**Icon + text pair:**
```html
<!-- wp:ekwa/flex {"alignItems":"center","style":{"spacing":{"blockGap":"var:preset|spacing|sm"}}} -->
  <!-- wp:ekwa/icon {"iconClass":"fas fa-calendar"} /-->
  <!-- wp:ekwa/text {"text":"Since 1987"} /-->
<!-- /wp:ekwa/flex -->
```

### Linked Cards (service cards, feature cards)

Use `ekwa/card-link` wrapper inside `ekwa/grid`:
```html
<!-- wp:ekwa/card-link {"url":"/services/crowns/","style":{"spacing":{"padding":{"top":"var:preset|spacing|lg","bottom":"var:preset|spacing|lg","left":"var:preset|spacing|lg","right":"var:preset|spacing|lg"}},"border":{"radius":"6px"}},"backgroundColor":"background","shadow":"var:preset|shadow|md"} -->
  <!-- wp:ekwa/icon {"iconClass":"fa-solid fa-tooth","size":40,"color":"var(--wp--preset--color--primary)"} /-->
  <!-- wp:heading {"level":4,"fontSize":"lg"} -->
  <h4 class="wp-block-heading has-lg-font-size">Same-Day Crowns</h4>
  <!-- /wp:heading -->
  <!-- wp:paragraph {"textColor":"foreground-light","fontSize":"sm"} -->
  <p class="has-foreground-light-color has-text-color has-sm-font-size">Description text here.</p>
  <!-- /wp:paragraph -->
<!-- /wp:ekwa/card-link -->
```

### Icons

**Standalone icon:**
```html
<!-- wp:ekwa/icon {"iconClass":"fa-solid fa-tooth","size":40,"color":"#1a6ef5"} /-->
```

**Linked icon:**
```html
<!-- wp:ekwa/icon {"iconClass":"fa-solid fa-tooth","size":40,"url":"/services/"} /-->
```

### Full Page Section Example (Hero)

```html
<!-- wp:ekwa/section {"bgImageUrl":"hero.jpg","bgSize":"cover","overlayColor":"#1a1a2e","overlayOpacity":80,"style":{"dimensions":{"minHeight":"85vh"}}} -->
  <!-- wp:ekwa/container {"maxWidth":"900px","style":{"spacing":{"padding":{"top":"var:preset|spacing|2-xl","bottom":"var:preset|spacing|2-xl","left":"var:preset|spacing|md","right":"var:preset|spacing|md"}}}} -->
    <!-- wp:heading {"textAlign":"center","level":1,"textColor":"white","fontSize":"hero"} -->
    <h1 class="wp-block-heading has-text-align-center has-white-color has-text-color has-hero-font-size">Your Smile Deserves the Best</h1>
    <!-- /wp:heading -->
    <!-- wp:paragraph {"align":"center","textColor":"surface","fontSize":"md"} -->
    <p class="has-text-align-center has-surface-color has-text-color has-md-font-size">Experience comprehensive dental care.</p>
    <!-- /wp:paragraph -->
    <!-- wp:ekwa/button-group {"justifyContent":"center","style":{"spacing":{"blockGap":"var:preset|spacing|md","margin":{"top":"var:preset|spacing|lg"}}}} -->
      <!-- wp:ekwa/button {"text":"Book Appointment","url":"/book/","variant":"filled","size":"lg","backgroundColor":"primary","textColor":"white"} /-->
      <!-- wp:ekwa/button {"text":"Our Services","url":"/services/","variant":"outline","size":"lg","textColor":"white"} /-->
    <!-- /wp:ekwa/button-group -->
  <!-- /wp:ekwa/container -->
<!-- /wp:ekwa/section -->
```

---

## Typography

| Family | Slug | Usage |
|--------|------|-------|
| Playfair Display | `heading` | Headings (H1-H6) |
| Inter | `body` | Body text, buttons, UI |
| System Monospace | `monospace` | Code blocks |

**Font sizes:** `sm`, `base`, `md`, `lg`, `xl`, `2-xl`, `hero` (all fluid via `clamp()`).

---

## Shadows

| Slug | Description |
|------|-------------|
| `sm` | Subtle (1px offset) |
| `md` | Standard card shadow |
| `lg` | Elevated element |
| `xl` | Prominent floating element |

Use: `"shadow":"var:preset|shadow|md"`.

---

## Gradients

| Slug | Description |
|------|-------------|
| `primary-to-accent` | Blue to orange (135deg) |
| `primary-dark-overlay` | Blue overlay with opacity |
| `dark-overlay` | Black gradient overlay (0.3 to 0.7 opacity) |
| `light-overlay` | White gradient overlay (0.1 to 0.3 opacity) |

---

## Mobile Breakpoint

- **1200px** — Primary breakpoint separating desktop/mobile
- Desktop header hidden below 1200px; mobile header hidden above 1200px
- Mobile dock visible only below 1200px

---

## Key Files for Modifications

| What | File |
|------|------|
| Design tokens | `theme.json` |
| Block registration + render callbacks | `inc/ekwa-blocks.php` |
| Block style variations (PHP) | `inc/ekwa-block-styles.php` |
| Block style CSS | `assets/css/ekwa-block-styles.css` |
| Custom block CSS | `assets/css/ekwa-blocks.css` |
| Editor-only CSS | `assets/css/ekwa-editor.css` |
| Theme setup + enqueueing | `functions.php` |
| Block definitions | `blocks/{block-name}/block.json` |
| Editor scripts | `assets/js/{block-name}-editor.js` |
| Frontend JS | `assets/js/ekwa-blocks.js` |
| Theme settings admin | `inc/ekwa-settings.php` |
| HTML-to-block converter | `tools/mockup-converter.php` |
| Mockup instructions | `mockup-instructions.md` |
