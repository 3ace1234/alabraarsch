# AL-FALAAH COLOR SYSTEM — IMPLEMENTATION PROMPT

Redesign the existing Al-Falaah Nursery, Primary & Secondary School website using the following **controlled color system**.

The goal is to create a website that feels **custom-designed, premium, modern, warm, trustworthy, and distinctly Islamic**, while still looking like a serious contemporary educational institution.

Do **not** make the website look like a generic “Islamic green and gold” template. The colors must be used with restraint and hierarchy.

## 1. CORE COLOR PALETTE

Define these as global CSS custom properties:

```css
--color-primary: #0B4535;        /* Al-Falaah Forest */
--color-primary-dark: #06352A;   /* Al-Falaah Deep */
--color-accent: #C6A24A;         /* Olive Gold */
--color-accent-light: #D8B65A;   /* Warm Gold */

--color-background: #F7F4EA;     /* Warm Ivory */
--color-surface: #FFFFFF;        /* White */

--color-text: #18231F;           /* Ink Charcoal */
--color-text-muted: #5D6B64;     /* Slate Green */

--color-soft-green: #E7EEE9;     /* Soft Sage */
--color-soft-warm: #EEE7D5;      /* Sand */
--color-border: #DDE3DE;         /* Border Mist */
```

Use these colors consistently throughout the entire website.

Do not introduce random additional colors unless required for accessibility, system states, or genuine UI feedback such as success, warning, or error.

---

## 2. COLOR HIERARCHY

Follow approximately this visual distribution:

**60% — Neutral backgrounds**

* Warm Ivory
* White

**30% — Green family**

* Forest Green
* Deep Green
* Soft Sage

**10% — Gold accents**

* Olive Gold
* Warm Gold

The gold must remain an **accent**, not a dominant color.

---

## 3. HOW EACH COLOR SHOULD BE USED

### Forest Green — `#0B4535`

Use as the primary Al-Falaah brand color for:

* primary buttons
* important headings
* active navigation
* links
* icons
* section accents
* highlighted UI elements

### Deep Green — `#06352A`

Use for:

* footer
* dark CTA sections
* selected dark backgrounds
* portal sidebars
* hero overlays
* strong brand sections

### Olive Gold — `#C6A24A`

Use sparingly for:

* small decorative details
* section accent lines
* important highlights
* icons
* numbers
* selected indicators
* primary CTA backgrounds where appropriate

### Warm Gold — `#D8B65A`

Use mainly for:

* hover states
* subtle highlights
* interactive accents
* decorative details

Do not use gold for large areas simply because the website is Islamic.

### Warm Ivory — `#F7F4EA`

Use as the main soft background for:

* major content sections
* About sections
* Academic sections
* page backgrounds
* alternating sections

### White — `#FFFFFF`

Use for:

* navigation
* cards
* forms
* content surfaces
* dashboard panels
* clean visual areas

### Ink Charcoal — `#18231F`

Use primarily for:

* body text
* navigation text
* headings where appropriate
* labels
* readable content

Avoid using pure black `#000000` as the default text color.

### Slate Green — `#5D6B64`

Use for:

* secondary text
* descriptions
* metadata
* dates
* breadcrumbs
* supporting information

### Soft Sage — `#E7EEE9`

Use for:

* subtle backgrounds
* feature blocks
* educational pillar sections
* cards
* information panels

### Sand — `#EEE7D5`

Use occasionally to introduce a warm secondary tone into selected sections.

### Border Mist — `#DDE3DE`

Use for:

* card borders
* dividers
* form borders
* table borders
* subtle UI separation

---

## 4. IMPORTANT DESIGN RULES

Do NOT:

* make every section green
* make every section white
* use gold everywhere
* use green and gold together on every component
* use gradients unnecessarily
* introduce random colors for individual sections
* use pure black everywhere
* create rainbow-colored cards
* give every educational pillar a completely different color
* use color simply to make a section “interesting”

The website should feel **calm, intentional and professionally art-directed**.

---

## 5. SECTION COLOR RHYTHM

Create visual rhythm by alternating neutral and branded sections.

Example:

```text
Hero
→ Dark Green / Photography

Feature Strip
→ White

About
→ Warm Ivory

Educational Pillars
→ White + Soft Sage accents

Academics
→ Warm Ivory

Digital Literacy
→ Soft Sage + Green

Student Life
→ White

Admissions CTA
→ Deep Green

Footer
→ Deep Green
```

Do not use this exact sequence mechanically on every page. Adapt the rhythm to each page's content.

---

## 6. BUTTON COLOR SYSTEM

### Primary button

```text
Background: #0B4535
Text: #FFFFFF
Hover: #06352A
```

### Gold CTA

Use selectively for high-priority actions:

```text
Background: #C6A24A
Text: #06352A
Hover: #D8B65A
```

### Secondary button

```text
Background: transparent
Border: #0B4535
Text: #0B4535
Hover: #0B4535
Hover text: #FFFFFF
```

Buttons must have clear hover and keyboard-focus states.

---

## 7. DARK SECTIONS

For sections with a Deep Green background:

```text
Background: #06352A
Heading: #FFFFFF
Body: #E7EEE9
Accent: #D8B65A
Primary CTA: #C6A24A
```

Use these sections strategically for:

* major calls to action
* admissions
* footer
* important school messages

---

## 8. BRAND FEEL

The final color treatment should communicate:

**Faith**
→ Deep Green

**Academic excellence**
→ Structured Green + Ivory

**Warmth**
→ Ivory + Sand

**Modernity**
→ Clean White + restrained Green

**Future & opportunity**
→ Gold accents

**Trust**
→ Deep Green + Charcoal

The result should feel like a **professionally designed Nigerian Islamic school brand**, not a pre-built template.

---

## 9. FINAL INSTRUCTION

Treat the color palette as a **design system**, not a list of colors.

Every color must have a purpose.

Prioritize **hierarchy, contrast, whitespace and composition** over excessive decoration.

When redesigning existing pages, preserve the existing content and functionality while systematically replacing inconsistent colors with this Al-Falaah palette.

Do not redesign every component simply by changing its color. Use the palette together with spacing, typography, imagery, cards and layout to create a coherent visual identity.

**Target feeling:**

> Elegant. Academic. Warm. Islamic. Modern. Trustworthy. Distinctly Al-Falaah.
