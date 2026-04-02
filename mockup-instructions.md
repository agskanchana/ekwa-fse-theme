# HTML Mockup Generation Instructions

This document defines the exact HTML patterns to use when generating HTML mockups for this WordPress theme. Every HTML element in the mockup maps 1:1 to a WordPress block, enabling pixel-perfect conversion.

**Rules:**
1. Use ONLY the HTML patterns documented here — do not invent custom wrappers or extra `<div>` elements
2. Use CSS custom properties (variables) for all colors, spacing, and shadows — never hardcode hex values in inline styles
3. The `ekwa-*` class names are the exact classes WordPress outputs — do not rename, remove, or add to them
4. The HTML markup for data blocks (phone, address, hours, etc.) must be used exactly as shown — you may style them with CSS but never change the HTML structure
5. For blocks handled by WordPress JS (phone dropdown, address dropdown, search overlay) — render them in their **closed/default state** in the mockup. Their JS behavior comes from WordPress
6. Any mockup-specific JS interactions can go in a separate JS file

---

## 1. CSS Boilerplate

Include this in the mockup's `<head>`. Fonts will be specified per project.

```html
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mockup</title>

  <!-- Font Awesome 6 -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

  <!-- Fonts: specify per project -->
  <!-- Example: <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Playfair+Display:wght@400;500;600;700;800;900&display=swap" rel="stylesheet"> -->

  <style>
    /* ============================================
       Design Tokens (CSS Custom Properties)
       ============================================ */
    :root {
      /* Colors */
      --wp--preset--color--primary: #1a6ef5;
      --wp--preset--color--primary-dark: #0f4fc2;
      --wp--preset--color--accent: #f5a623;
      --wp--preset--color--accent-dark: #d48c0e;
      --wp--preset--color--foreground: #1a1a2e;
      --wp--preset--color--foreground-light: #4a4a68;
      --wp--preset--color--background: #ffffff;
      --wp--preset--color--background-alt: #f4f6fa;
      --wp--preset--color--surface: #e8ecf4;
      --wp--preset--color--gray-100: #f3f4f6;
      --wp--preset--color--gray-200: #e5e7eb;
      --wp--preset--color--gray-300: #d1d5db;
      --wp--preset--color--gray-500: #6b7280;
      --wp--preset--color--gray-700: #374151;
      --wp--preset--color--gray-900: #111827;
      --wp--preset--color--success: #10b981;
      --wp--preset--color--danger: #ef4444;
      --wp--preset--color--warning: #f59e0b;
      --wp--preset--color--info: #3b82f6;
      --wp--preset--color--white: #ffffff;
      --wp--preset--color--black: #000000;

      /* Spacing */
      --wp--preset--spacing--xs: 4px;
      --wp--preset--spacing--sm: 8px;
      --wp--preset--spacing--sm-md: 12px;
      --wp--preset--spacing--md: 16px;
      --wp--preset--spacing--md-lg: 24px;
      --wp--preset--spacing--lg: 32px;
      --wp--preset--spacing--lg-xl: 48px;
      --wp--preset--spacing--xl: 64px;
      --wp--preset--spacing--2-xl: 128px;

      /* Font Sizes (fluid) */
      --wp--preset--font-size--sm: clamp(0.75rem, 0.7rem + 0.25vw, 0.875rem);
      --wp--preset--font-size--base: clamp(0.875rem, 0.8rem + 0.375vw, 1rem);
      --wp--preset--font-size--md: clamp(1rem, 0.9rem + 0.5vw, 1.25rem);
      --wp--preset--font-size--lg: clamp(1.25rem, 1.1rem + 0.75vw, 1.75rem);
      --wp--preset--font-size--xl: clamp(1.5rem, 1.2rem + 1.5vw, 2.5rem);
      --wp--preset--font-size--2-xl: clamp(2rem, 1.5rem + 2.5vw, 3.5rem);
      --wp--preset--font-size--hero: clamp(2.5rem, 1.75rem + 3.75vw, 5rem);

      /* Shadows */
      --wp--preset--shadow--sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
      --wp--preset--shadow--md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
      --wp--preset--shadow--lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.1);
      --wp--preset--shadow--xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);

      /* Font Families — set per project */
      --wp--preset--font-family--heading: 'Playfair Display', serif;
      --wp--preset--font-family--body: 'Inter', sans-serif;
    }

    /* ============================================
       Base Styles
       ============================================ */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: var(--wp--preset--font-family--body);
      font-size: var(--wp--preset--font-size--base);
      color: var(--wp--preset--color--foreground);
      background: var(--wp--preset--color--background);
      line-height: 1.6;
      -webkit-font-smoothing: antialiased;
    }
    img { max-width: 100%; height: auto; display: block; }
    a { color: var(--wp--preset--color--primary); text-decoration: none; }
    a:hover { color: var(--wp--preset--color--primary-dark); }

    h1, h2, h3, h4, h5, h6 {
      font-family: var(--wp--preset--font-family--heading);
      font-weight: 700;
      line-height: 1.2;
      color: var(--wp--preset--color--foreground);
    }
    h1 { font-size: var(--wp--preset--font-size--hero); }
    h2 { font-size: var(--wp--preset--font-size--2-xl); }
    h3 { font-size: var(--wp--preset--font-size--xl); }
    h4 { font-size: var(--wp--preset--font-size--lg); }
    h5 { font-size: var(--wp--preset--font-size--md); }
    h6 { font-size: var(--wp--preset--font-size--sm); }

    /* ============================================
       Layout Block Styles
       ============================================ */

    /* Section */
    .ekwa-section { position: relative; }
    .ekwa-section__overlay {
      position: absolute; inset: 0;
      pointer-events: none; z-index: 0;
    }
    .ekwa-section__container {
      position: relative; z-index: 1;
      margin-left: auto; margin-right: auto;
    }
    .ekwa-section__inner { position: relative; z-index: 1; }
    @supports (-webkit-touch-callout: none) {
      .ekwa-section { background-attachment: scroll !important; }
    }

    /* Grid — responsive */
    .ekwa-grid { display: grid; }
    .ekwa-grid > * { min-width: 0; }
    @media (max-width: 1199px) {
      .ekwa-grid[data-tablet-cols="1"] { grid-template-columns: 1fr !important; }
      .ekwa-grid[data-tablet-cols="2"] { grid-template-columns: repeat(2, 1fr) !important; }
      .ekwa-grid[data-tablet-cols="3"] { grid-template-columns: repeat(3, 1fr) !important; }
    }
    @media (max-width: 599px) {
      .ekwa-grid[data-mobile-cols="1"] { grid-template-columns: 1fr !important; }
      .ekwa-grid[data-mobile-cols="2"] { grid-template-columns: repeat(2, 1fr) !important; }
    }

    /* Button */
    .ekwa-btn {
      display: inline-flex; align-items: center; justify-content: center; gap: 6px;
      text-decoration: none; cursor: pointer; font-family: inherit; line-height: 1.4;
      border: none; transition: opacity 0.2s ease, transform 0.15s ease, box-shadow 0.2s ease;
    }
    .ekwa-btn:hover { opacity: 0.88; }
    .ekwa-btn--outline { background: transparent !important; border: 2px solid currentColor; }
    .ekwa-btn--ghost { background: transparent !important; border: 1px solid rgba(255, 255, 255, 0.35); color: #fff; }
    .ekwa-btn--sm { padding: 6px 16px; font-size: var(--wp--preset--font-size--sm); }
    .ekwa-btn--lg { padding: 16px 48px; font-size: var(--wp--preset--font-size--md); }
    .ekwa-btn i { flex-shrink: 0; }

    /* Card Link */
    .ekwa-card-link {
      display: block; text-decoration: none; color: inherit;
      transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .ekwa-card-link:hover { transform: translateY(-2px); }
    .ekwa-card-link h1, .ekwa-card-link h2, .ekwa-card-link h3,
    .ekwa-card-link h4, .ekwa-card-link h5, .ekwa-card-link h6,
    .ekwa-card-link p, .ekwa-card-link span { color: inherit; }
  </style>
</head>
```

---

## 2. Layout Block HTML Patterns

### Section (`<section>`)

**Basic section:**
```html
<section class="ekwa-section" style="padding: var(--wp--preset--spacing--2-xl) var(--wp--preset--spacing--md); background-color: var(--wp--preset--color--background-alt);">
  <div class="ekwa-section__inner">
    <!-- content -->
  </div>
</section>
```

**Section with background image + overlay:**
```html
<section class="ekwa-section" style="min-height: 85vh; background-image: url(hero.jpg); background-size: cover; background-position: 50% 50%; padding: var(--wp--preset--spacing--2-xl) var(--wp--preset--spacing--md);">
  <div class="ekwa-section__overlay" style="background: var(--wp--preset--color--foreground); opacity: 0.8;" aria-hidden="true"></div>
  <div class="ekwa-section__inner">
    <!-- content -->
  </div>
</section>
```

**Section with inner container (constrained width):**
```html
<section class="ekwa-section" style="padding: var(--wp--preset--spacing--2-xl) var(--wp--preset--spacing--md); background-color: var(--wp--preset--color--primary); color: var(--wp--preset--color--white);">
  <div class="ekwa-section__container" style="max-width: 900px;">
    <!-- content constrained to 900px -->
  </div>
</section>
```

**Section with background image + overlay + container:**
```html
<section class="ekwa-section" style="min-height: 85vh; background-image: url(hero.jpg); background-size: cover; background-position: 50% 50%;">
  <div class="ekwa-section__overlay" style="background: var(--wp--preset--color--foreground); opacity: 0.8;" aria-hidden="true"></div>
  <div class="ekwa-section__container" style="max-width: 900px; padding: var(--wp--preset--spacing--2-xl) var(--wp--preset--spacing--md);">
    <!-- content -->
  </div>
</section>
```

**Other tags:** Replace `<section>` with `<header>`, `<footer>`, `<main>`, `<aside>`, `<article>` as needed. Same class structure.

### Container (`<div>`)

```html
<div class="ekwa-container" style="max-width: 1280px; margin-left: auto; margin-right: auto; padding: 0 var(--wp--preset--spacing--md);">
  <!-- centered content -->
</div>
```

Common widths: `700px`, `900px`, `1000px`, `1100px`, `1280px`.

### Flex (`<div>`)

**Horizontal row:**
```html
<div class="ekwa-flex" style="display: flex; flex-direction: row; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: var(--wp--preset--spacing--md);">
  <!-- children side by side -->
</div>
```

**Vertical stack:**
```html
<div class="ekwa-flex" style="display: flex; flex-direction: column; align-items: flex-start; gap: var(--wp--preset--spacing--sm);">
  <!-- children stacked -->
</div>
```

**As `<nav>`:**
```html
<nav class="ekwa-flex" style="display: flex; align-items: center; gap: var(--wp--preset--spacing--lg);">
  <!-- nav items -->
</nav>
```

### Grid (`<div>`)

**3-column grid:**
```html
<div class="ekwa-grid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--wp--preset--spacing--lg);" data-tablet-cols="2" data-mobile-cols="1">
  <!-- children placed directly — NO column wrappers -->
  <div>Card 1</div>
  <div>Card 2</div>
  <div>Card 3</div>
</div>
```

**Custom column widths:**
```html
<div class="ekwa-grid" style="display: grid; grid-template-columns: 1fr 2fr 1fr; gap: var(--wp--preset--spacing--lg);" data-tablet-cols="1" data-mobile-cols="1">
  <div>Sidebar</div>
  <div>Main Content</div>
  <div>Sidebar</div>
</div>
```

**2-column grid:**
```html
<div class="ekwa-grid" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--wp--preset--spacing--lg);" data-tablet-cols="1" data-mobile-cols="1">
  <div>Left</div>
  <div>Right</div>
</div>
```

### Button (`<a>`)

**Filled (default):**
```html
<a class="ekwa-btn ekwa-btn--filled" href="/book/" style="background-color: var(--wp--preset--color--primary); color: var(--wp--preset--color--white); padding: 12px 32px; border-radius: 6px; font-weight: 600;">Book Appointment</a>
```

**Outline:**
```html
<a class="ekwa-btn ekwa-btn--outline" href="/services/" style="color: var(--wp--preset--color--primary); padding: 12px 32px; border-radius: 6px; font-weight: 600;">Our Services</a>
```

**Ghost (for dark backgrounds):**
```html
<a class="ekwa-btn ekwa-btn--ghost" href="/about/" style="padding: 12px 32px; border-radius: 6px; font-weight: 600;">Learn More</a>
```

**Small:**
```html
<a class="ekwa-btn ekwa-btn--filled ekwa-btn--sm" href="/book/" style="background-color: var(--wp--preset--color--primary); color: var(--wp--preset--color--white); border-radius: 6px;">Book Now</a>
```

**Large:**
```html
<a class="ekwa-btn ekwa-btn--filled ekwa-btn--lg" href="/book/" style="background-color: var(--wp--preset--color--primary); color: var(--wp--preset--color--white); border-radius: 6px; font-weight: 700;">Book Appointment</a>
```

**With icon (left):**
```html
<a class="ekwa-btn ekwa-btn--filled" href="tel:+15551234567" style="background-color: var(--wp--preset--color--primary); color: var(--wp--preset--color--white); padding: 12px 32px; border-radius: 6px;"><i class="fa-solid fa-phone" aria-hidden="true"></i>Call Us</a>
```

**With icon (right):**
```html
<a class="ekwa-btn ekwa-btn--filled" href="/services/" style="background-color: var(--wp--preset--color--accent); color: var(--wp--preset--color--foreground); padding: 12px 32px; border-radius: 6px;">View Services<i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
```

**As `<button>`:**
```html
<button class="ekwa-btn ekwa-btn--filled" type="button" style="background-color: var(--wp--preset--color--primary); color: var(--wp--preset--color--white); padding: 12px 32px; border-radius: 6px;">Submit</button>
```

### Button Group

```html
<div class="ekwa-button-group" style="display: flex; flex-wrap: wrap; justify-content: center; gap: var(--wp--preset--spacing--md);">
  <a class="ekwa-btn ekwa-btn--filled ekwa-btn--lg" href="/book/" style="background-color: var(--wp--preset--color--primary); color: var(--wp--preset--color--white); border-radius: 6px; font-weight: 700;">Book Appointment</a>
  <a class="ekwa-btn ekwa-btn--outline ekwa-btn--lg" href="/services/" style="color: var(--wp--preset--color--white); border-radius: 6px;">Our Services</a>
</div>
```

### Text (`<span>` and inline elements)

**Basic span:**
```html
<span class="ekwa-text">Since 1987</span>
```

**Badge/label:**
```html
<span class="ekwa-text" style="display: inline-block; background-color: var(--wp--preset--color--accent); color: var(--wp--preset--color--white); padding: var(--wp--preset--spacing--xs) var(--wp--preset--spacing--sm); border-radius: 99px; font-size: var(--wp--preset--font-size--sm); font-weight: 600; text-transform: uppercase;">New Patient Special</span>
```

**Stat number:**
```html
<span class="ekwa-text" style="font-size: var(--wp--preset--font-size--2-xl); font-weight: 700; color: var(--wp--preset--color--primary);">15,000+</span>
```

**Other tags:** Replace `<span>` with `<small>`, `<strong>`, `<em>`, `<mark>`, `<time>`, `<sup>`, `<sub>` as needed.

### Card Link (`<a>` wrapper)

```html
<a href="/services/crowns/" class="ekwa-card-link" style="padding: var(--wp--preset--spacing--lg); border-radius: 6px; background-color: var(--wp--preset--color--background); box-shadow: var(--wp--preset--shadow--md);">
  <div class="ekwa-icon" style="margin-bottom: var(--wp--preset--spacing--md);"><i class="fa-solid fa-tooth" aria-hidden="true" style="font-size: 40px; color: var(--wp--preset--color--primary);"></i></div>
  <h4 style="font-size: var(--wp--preset--font-size--lg); margin-bottom: var(--wp--preset--spacing--sm);">Same-Day Crowns</h4>
  <p style="color: var(--wp--preset--color--foreground-light); font-size: var(--wp--preset--font-size--sm);">Description text here.</p>
</a>
```

### Icon

**Standalone:**
```html
<div class="ekwa-icon"><i class="fa-solid fa-tooth" aria-hidden="true" style="font-size: 40px; color: var(--wp--preset--color--primary);"></i></div>
```

**Linked:**
```html
<a href="/services/" class="ekwa-icon"><i class="fa-solid fa-tooth" aria-hidden="true" style="font-size: 40px; color: var(--wp--preset--color--primary);"></i></a>
```

---

## 3. Data Block HTML Patterns

These blocks display business data. Use the exact HTML markup shown. You can style them with CSS but **never change the HTML structure or class names**.

### Ekwa Phone Number

Renders a clickable phone link. Variations depend on text prefix and icon.

**New Patients:**
```html
<span class="ekwa-phone-number"><a href="tel:+15128838450" class="ekwa-phone-number__link" aria-label="Call (512) 883-8450"><i class="ekwa-phone-number__icon fa-solid fa-phone" aria-hidden="true"></i><span class="ekwa-phone-number__text"><span class="ekwa-phone-number__prefix">New Patients: </span><span class="ekwa-phone-number__number">(512) 883-8450</span></span></a></span>
```

**Existing Patients:**
```html
<span class="ekwa-phone-number"><a href="tel:+15123378560" class="ekwa-phone-number__link" aria-label="Call (512) 337-8560"><i class="ekwa-phone-number__icon fa-solid fa-phone" aria-hidden="true"></i><span class="ekwa-phone-number__text"><span class="ekwa-phone-number__prefix">Existing Patients: </span><span class="ekwa-phone-number__number">(512) 337-8560</span></span></a></span>
```

**Notes:**
- The `tel:` href uses `+1` country code prefix followed by digits only
- The icon class (`fa-solid fa-phone`) can vary per design
- The prefix text ("New Patients: ", "Existing Patients: ") can vary

### Ekwa Phone Dropdown

**Single location:**
```html
<div class="ekwa-phone-dd" id="ekwa-phone-dd-1">
  <button class="ekwa-phone-dd__trigger" type="button" aria-expanded="false" aria-controls="ekwa-phone-dd-1-panel">
    <i class="fa-solid fa-phone" aria-hidden="true"></i> <span>Call Us</span>
    <svg class="ekwa-phone-dd__chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
  </button>
  <div class="ekwa-phone-dd__panel" id="ekwa-phone-dd-1-panel">
    <div class="ekwa-phone-dd__location">
      <a href="tel:+15128838450" class="ekwa-phone-dd__link" aria-label="Call New Patients (512) 883-8450">
        <i class="fa-solid fa-phone" aria-hidden="true"></i>
        <span class="ekwa-phone-dd__info">
          <span class="ekwa-phone-dd__label">New Patients</span>
          <span class="ekwa-phone-dd__num">(512) 883-8450</span>
        </span>
      </a>
      <a href="tel:+15123378560" class="ekwa-phone-dd__link" aria-label="Call Existing Patients (512) 337-8560">
        <i class="fa-solid fa-user-check" aria-hidden="true"></i>
        <span class="ekwa-phone-dd__info">
          <span class="ekwa-phone-dd__label">Existing Patients</span>
          <span class="ekwa-phone-dd__num">(512) 337-8560</span>
        </span>
      </a>
    </div>
  </div>
</div>
```

**Multi-location:**
```html
<div class="ekwa-phone-dd" id="ekwa-phone-dd-1">
  <button class="ekwa-phone-dd__trigger" type="button" aria-expanded="false" aria-controls="ekwa-phone-dd-1-panel">
    <i class="fa-solid fa-phone" aria-hidden="true"></i> <span>Call Us</span>
    <svg class="ekwa-phone-dd__chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
  </button>
  <div class="ekwa-phone-dd__panel" id="ekwa-phone-dd-1-panel">
    <div class="ekwa-phone-dd__location">
      <div class="ekwa-phone-dd__city"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> Location Name</div>
      <a href="tel:+10001112222" class="ekwa-phone-dd__link" aria-label="Call New Patients (000) 111-2222">
        <i class="fa-solid fa-phone" aria-hidden="true"></i>
        <span class="ekwa-phone-dd__info">
          <span class="ekwa-phone-dd__label">New Patients</span>
          <span class="ekwa-phone-dd__num">(000) 111-2222</span>
        </span>
      </a>
      <a href="tel:+10003334444" class="ekwa-phone-dd__link" aria-label="Call Existing Patients (000) 333-4444">
        <i class="fa-solid fa-user-check" aria-hidden="true"></i>
        <span class="ekwa-phone-dd__info">
          <span class="ekwa-phone-dd__label">Existing Patients</span>
          <span class="ekwa-phone-dd__num">(000) 333-4444</span>
        </span>
      </a>
    </div>
    <!-- Repeat .ekwa-phone-dd__location for each location -->
  </div>
</div>
```

**Notes:**
- Trigger text ("Call Us") can vary
- Render in closed state (`aria-expanded="false"`, panel hidden)
- JS toggle behavior is handled by WordPress

### Ekwa Address

**Full address:**
```html
<a href="https://maps.app.goo.gl/XXXXX" class="ekwa-address ekwa-address--full" aria-label="Get directions to 9800 N Lake Creek Pkwy #150, Austin, TX 78717" target="_blank" rel="noreferrer nofollow"><i class="ekwa-address__icon fa-solid fa-location-dot" aria-hidden="true" style="margin-right:0.4em;"></i><span class="ekwa-address__text">9800 N Lake Creek Pkwy #150, Austin, TX 78717</span></a>
```

**City, State only:**
```html
<a href="https://maps.app.goo.gl/XXXXX" class="ekwa-address ekwa-address--address" aria-label="Get directions to 9800 N Lake Creek Pkwy #150, Austin, TX 78717" target="_blank" rel="noreferrer nofollow"><i class="ekwa-address__icon fa-solid fa-location-dot" aria-hidden="true" style="margin-right:0.4em;"></i><span class="ekwa-address__text">Austin, TX</span></a>
```

**Directions label:**
```html
<a href="https://maps.app.goo.gl/XXXXX" class="ekwa-address ekwa-address--text" aria-label="Get directions to 9800 N Lake Creek Pkwy #150, Austin, TX 78717" target="_blank" rel="noreferrer nofollow"><i class="ekwa-address__icon fa-solid fa-location-dot" aria-hidden="true" style="margin-right:0.4em;"></i><span class="ekwa-address__text">Directions</span></a>
```

**Notes:**
- The icon class can vary per design
- The `href` links to Google Maps
- Display mode modifier class: `--full`, `--address`, `--text`

### Ekwa Address Dropdown

```html
<div class="ekwa-addr-dd" id="ekwa-addr-dd-1">
  <button class="ekwa-addr-dd__trigger" type="button" aria-expanded="false" aria-controls="ekwa-addr-dd-1-panel">
    <i class="fa-solid fa-location-dot" aria-hidden="true"></i> <span>Directions</span>
    <svg class="ekwa-addr-dd__chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
  </button>
  <div class="ekwa-addr-dd__panel" id="ekwa-addr-dd-1-panel">
    <div class="ekwa-addr-dd__location">
      <div class="ekwa-addr-dd__city"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> Location Name</div>
      <a href="https://maps.google.com/..." class="ekwa-addr-dd__link" target="_blank" rel="noopener noreferrer" aria-label="Get directions to Full Address">
        <i class="fa-solid fa-diamond-turn-right" aria-hidden="true"></i> Full Address Here
      </a>
    </div>
    <!-- Repeat .ekwa-addr-dd__location for each location -->
  </div>
</div>
```

**Notes:**
- Trigger text ("Directions") can vary
- Render in closed state
- JS toggle behavior is handled by WordPress

### Ekwa Working Hours

**No grouping:**
```html
<div class="ekwa-working-hours">
  <div class="ekwa-working-hours__list">
    <div class="ekwa-working-hours__row">
      <span class="ekwa-working-hours__day">Monday</span>
      <span class="ekwa-working-hours__time">9:00 AM – 5:00 PM</span>
    </div>
    <div class="ekwa-working-hours__row">
      <span class="ekwa-working-hours__day">Tuesday</span>
      <span class="ekwa-working-hours__time">9:00 AM – 5:00 PM</span>
    </div>
    <!-- ...remaining days -->
  </div>
</div>
```

**Consecutive day grouping:**
```html
<div class="ekwa-working-hours">
  <div class="ekwa-working-hours__list">
    <div class="ekwa-working-hours__row">
      <span class="ekwa-working-hours__day">Monday – Friday</span>
      <span class="ekwa-working-hours__time">9:00 AM – 5:00 PM</span>
    </div>
  </div>
</div>
```

**Abbreviated day names:**
```html
<div class="ekwa-working-hours">
  <div class="ekwa-working-hours__list">
    <div class="ekwa-working-hours__row">
      <span class="ekwa-working-hours__day">Mon</span>
      <span class="ekwa-working-hours__time">9:00 AM – 5:00 PM</span>
    </div>
    <!-- ...remaining days as Mon, Tue, Wed, Thu, Fri, Sat, Sun -->
  </div>
</div>
```

**Abbreviated + grouped:**
```html
<div class="ekwa-working-hours">
  <div class="ekwa-working-hours__list">
    <div class="ekwa-working-hours__row">
      <span class="ekwa-working-hours__day">Mon – Fri</span>
      <span class="ekwa-working-hours__time">9:00 AM – 5:00 PM</span>
    </div>
  </div>
</div>
```

### Ekwa Copyright

```html
<div class="ekwa-copyright">
  © 2026 Practice Name. All Rights Reserved.
  Powered by <a href="https://www.ekwa.com" target="_blank" rel="noreferrer nofollow">www.ekwa.com</a>
</div>
```

### Ekwa Social Icons

```html
<div class="ekwa-social-icons">
  <div class="social-media">
    <a class="sm-icons" aria-label="Facebook" rel="noopener noreferrer" target="_blank" href="https://facebook.com/practice">
      <i class="fa-brands fa-facebook" style="font-size:28px;"></i>
    </a>
    <a class="sm-icons" aria-label="Instagram" rel="noopener noreferrer" target="_blank" href="https://instagram.com/practice">
      <i class="fa-brands fa-instagram" style="font-size:28px;"></i>
    </a>
    <a class="sm-icons" aria-label="Youtube" rel="noopener noreferrer" target="_blank" href="https://youtube.com/practice">
      <i class="fa-brands fa-youtube" style="font-size:28px;"></i>
    </a>
    <!-- Add/remove icons as needed. Share button is handled by WordPress JS. -->
  </div>
</div>
```

**Notes:**
- Icon size (`font-size`) and color can be styled
- Available icon classes: `fa-brands fa-facebook`, `fa-brands fa-instagram`, `fa-brands fa-youtube`, `fa-brands fa-x-twitter`, `fa-brands fa-linkedin`, `fa-brands fa-tiktok`, `fa-brands fa-google`, `fa-brands fa-yelp`, `fa-brands fa-pinterest`
- The share button with its JS toggle and popover is handled by WordPress — omit from mockup unless specifically needed

### Ekwa Google Map

```html
<div class="ekwa-map-wrapper" style="width:100%;overflow:hidden;">
  <iframe src="https://www.google.com/maps/embed?pb=YOUR_EMBED_URL" width="100%" height="400" style="border:0;display:block;width:100%;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="Google Map"></iframe>
</div>
```

### Ekwa Search

Render in closed (default) state. The overlay is handled by WordPress JS.

```html
<div class="ekwa-search-block">
  <button class="ekwa-search-trigger" type="button" aria-label="Open Search" aria-expanded="false" style="color: var(--wp--preset--color--foreground);">
    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" fill="currentColor"><path d="M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"></path></svg>
  </button>
</div>
```

### Core Image (WordPress)

```html
<figure class="wp-block-image size-full">
  <img src="image.jpg" alt="Descriptive alt text" width="735" height="980" loading="lazy" decoding="async">
</figure>
```

**With border radius:**
```html
<figure class="wp-block-image size-full" style="border-radius: 6px; overflow: hidden;">
  <img src="image.jpg" alt="Descriptive alt text" width="735" height="980" loading="lazy" decoding="async">
</figure>
```

---

## 4. Page Structure Template

A typical page follows this structure:

```html
<!DOCTYPE html>
<html lang="en">
<head>
  <!-- CSS boilerplate from Section 1 -->
</head>
<body>

  <!-- HEADER -->
  <header class="ekwa-section" style="...">
    <div class="ekwa-section__container" style="max-width: 1280px;">
      <div class="ekwa-flex" style="display: flex; justify-content: space-between; align-items: center;">
        <!-- Logo -->
        <figure class="wp-block-image"><img src="logo.png" alt="Practice Name" width="200" height="60"></figure>
        <!-- Nav -->
        <nav><!-- navigation links --></nav>
        <!-- Phone / CTA -->
      </div>
    </div>
  </header>

  <!-- MAIN CONTENT -->
  <main>

    <!-- Hero Section -->
    <section class="ekwa-section" style="background-image: url(hero.jpg); background-size: cover; min-height: 85vh;">
      <div class="ekwa-section__overlay" style="background: var(--wp--preset--color--foreground); opacity: 0.8;" aria-hidden="true"></div>
      <div class="ekwa-section__container" style="max-width: 900px; padding: var(--wp--preset--spacing--2-xl) var(--wp--preset--spacing--md); text-align: center;">
        <h1 style="color: var(--wp--preset--color--white); font-size: var(--wp--preset--font-size--hero);">Hero Headline</h1>
        <p style="color: var(--wp--preset--color--surface); font-size: var(--wp--preset--font-size--md);">Subtext here.</p>
        <div class="ekwa-button-group" style="display: flex; flex-wrap: wrap; justify-content: center; gap: var(--wp--preset--spacing--md); margin-top: var(--wp--preset--spacing--lg);">
          <a class="ekwa-btn ekwa-btn--filled ekwa-btn--lg" href="/book/" style="background-color: var(--wp--preset--color--primary); color: var(--wp--preset--color--white); border-radius: 6px;">Book Appointment</a>
          <a class="ekwa-btn ekwa-btn--outline ekwa-btn--lg" href="/services/" style="color: var(--wp--preset--color--white); border-radius: 6px;">Our Services</a>
        </div>
      </div>
    </section>

    <!-- Content Section -->
    <section class="ekwa-section" style="padding: var(--wp--preset--spacing--2-xl) var(--wp--preset--spacing--md); background-color: var(--wp--preset--color--background-alt);">
      <div class="ekwa-section__container" style="max-width: 1280px;">
        <!-- Section heading + content -->
        <div class="ekwa-grid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--wp--preset--spacing--lg);" data-tablet-cols="2" data-mobile-cols="1">
          <!-- Grid children -->
        </div>
      </div>
    </section>

    <!-- More sections... -->

  </main>

  <!-- FOOTER -->
  <footer class="ekwa-section" style="background-color: var(--wp--preset--color--foreground); color: var(--wp--preset--color--white); padding: var(--wp--preset--spacing--xl) var(--wp--preset--spacing--md);">
    <div class="ekwa-section__container" style="max-width: 1280px;">
      <div class="ekwa-grid" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: var(--wp--preset--spacing--lg);" data-tablet-cols="2" data-mobile-cols="1">
        <!-- Footer columns with hours, address, social, nav -->
      </div>
      <div class="ekwa-copyright" style="margin-top: var(--wp--preset--spacing--lg); text-align: center; font-size: var(--wp--preset--font-size--sm);">
        © 2026 Practice Name. All Rights Reserved.
        Powered by <a href="https://www.ekwa.com" target="_blank" rel="noreferrer nofollow">www.ekwa.com</a>
      </div>
    </div>
  </footer>

</body>
</html>
```

---

## 5. Conversion Guidelines

### Spacing Reference

| Token | Size | Use for |
|-------|------|---------|
| `--wp--preset--spacing--xs` | 4px | Tiny gaps, badge padding |
| `--wp--preset--spacing--sm` | 8px | Small gaps, inline spacing |
| `--wp--preset--spacing--sm-md` | 12px | Between tight elements |
| `--wp--preset--spacing--md` | 16px | Standard padding, gaps |
| `--wp--preset--spacing--md-lg` | 24px | Between content blocks |
| `--wp--preset--spacing--lg` | 32px | Section inner gaps, grid gaps |
| `--wp--preset--spacing--lg-xl` | 48px | Large section padding |
| `--wp--preset--spacing--xl` | 64px | Section vertical padding |
| `--wp--preset--spacing--2-xl` | 128px | Hero section padding |

### Color Reference

| Token | Hex | Use for |
|-------|-----|---------|
| `--wp--preset--color--primary` | #1a6ef5 | Buttons, links, accents |
| `--wp--preset--color--primary-dark` | #0f4fc2 | Hover states |
| `--wp--preset--color--accent` | #f5a623 | CTAs, highlights |
| `--wp--preset--color--foreground` | #1a1a2e | Body text, dark overlays |
| `--wp--preset--color--foreground-light` | #4a4a68 | Secondary text |
| `--wp--preset--color--background` | #ffffff | White background |
| `--wp--preset--color--background-alt` | #f4f6fa | Alternate section bg |
| `--wp--preset--color--surface` | #e8ecf4 | Borders, light text on dark |
| `--wp--preset--color--white` | #ffffff | White text/bg |
| `--wp--preset--color--black` | #000000 | Black |

### Font Size Reference

| Token | Range | Use for |
|-------|-------|---------|
| `--wp--preset--font-size--sm` | 12px – 14px | Small labels, captions |
| `--wp--preset--font-size--base` | 14px – 16px | Body text |
| `--wp--preset--font-size--md` | 16px – 20px | Lead text, subtitles |
| `--wp--preset--font-size--lg` | 20px – 28px | H4, card titles |
| `--wp--preset--font-size--xl` | 24px – 40px | H3, section titles |
| `--wp--preset--font-size--2-xl` | 32px – 56px | H2, major headings |
| `--wp--preset--font-size--hero` | 40px – 80px | H1, hero headlines |

### Key Principles

1. **Every `<section>` in the mockup = one `ekwa/section` block** in WordPress
2. **Every centered `<div>` = one `ekwa/container`** block
3. **Every flex row/column = one `ekwa/flex`** block
4. **Every grid = one `ekwa/grid`** block — children go directly in, no column wrappers
5. **Every button/link = one `ekwa/button`** — never `<div><div><a>` nesting
6. **Every `<span>`/`<small>`/`<strong>` = one `ekwa/text`** block
7. **Headings and paragraphs** use standard `<h1>`–`<h6>` and `<p>` tags
8. **Phone numbers, addresses, hours** use the exact data block markup — these are WordPress blocks whose data comes from theme settings
9. **Mobile breakpoint** is 1200px — the grid `data-tablet-cols` and `data-mobile-cols` attributes handle responsive columns automatically
