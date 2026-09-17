# AL-FALAAH TYPOGRAPHY SYSTEM — IMPLEMENTATION PROMPT

Redesign the existing Al-Falaah website typography using a **refined editorial + modern educational** typographic system.

The typography should feel **professional, elegant, warm, readable, and distinctive** — not like a generic AI-generated website.

## 1. FONT SYSTEM

Use a maximum of **three font families**:

### Primary Heading Font

**DM Serif Display**

Use for:

* Hero headings
* Main page headings
* Major section headings
* Important editorial statements

It should give the website a refined, established academic character.

### Primary Body Font

**Manrope**

Use for:

* Body text
* Navigation
* Buttons
* Forms
* Cards
* Metadata
* Dashboard interfaces
* Tables
* Labels

It should provide a clean, modern and highly readable interface.

### Arabic Font

**Noto Naskh Arabic**

Use only where Arabic text appears, particularly for:

* Qur'anic/Islamic educational content
* Arabic terminology
* Arabic headings
* Islamic studies content

Do not mix Arabic and Latin fonts carelessly.

---

## 2. TYPOGRAPHIC HIERARCHY

Create a clear hierarchy rather than making every heading large.

### Hero Heading

Large and expressive:

```css
font-size: clamp(2.8rem, 6vw, 5.5rem);
font-family: var(--font-heading);
font-weight: 400;
line-height: 1.05;
```

### Page H1

```text
clamp(2.4rem, 5vw, 4.5rem)
```

### Section H2

```text
clamp(2rem, 4vw, 3.2rem)
```

### Card H3

Approximately:

```text
1.15rem – 1.4rem
```

### Body Text

Approximately:

```text
1rem – 1.1rem
line-height: 1.7
```

### Small Text

Approximately:

```text
0.8rem – 0.95rem
```

Use `clamp()` where appropriate so typography scales naturally between screen sizes.

---

## 3. HEADING STYLE

Major headings should feel **editorial rather than corporate**.

Use:

* short, meaningful headlines
* strong line breaks where visually appropriate
* generous line-height control
* restrained font weights
* dark green or charcoal from the Al-Falaah color system

Do not make every heading uppercase.

Use uppercase mainly for small **eyebrow labels**, such as:

```text
ACADEMICS
ADMISSIONS
STUDENT LIFE
ABOUT AL-FALAAH
```

---

## 4. SECTION HEADING PATTERN

Use this hierarchy consistently:

```text
SMALL EYEBROW
Large Editorial Heading
Short Supporting Description
```

Example:

```text
ACADEMICS

Learning with purpose.

Our approach combines strong academics,
Islamic values and future-ready skills.
```

Do not repeat the same heading formula mechanically on every section.

---

## 5. BODY TEXT

Body text must prioritize readability.

Use:

* comfortable line height
* moderate paragraph width
* clear spacing between paragraphs
* strong contrast
* simple sentence structure

Avoid excessively wide text blocks.

Target approximately:

```text
60–75 characters per line
```

for long-form reading where practical.

---

## 6. NAVIGATION TYPOGRAPHY

Navigation should use **Manrope**.

Characteristics:

* medium weight
* compact
* clean
* easy to scan

Avoid overly bold navigation.

Active navigation should use the Al-Falaah Forest color and a subtle visual indicator.

---

## 7. BUTTON TYPOGRAPHY

Buttons should use **Manrope**.

Use:

* medium/semi-bold weight
* clear wording
* consistent letter spacing
* comfortable horizontal padding

Examples:

```text
Apply Now
Explore Academics
Meet Our Staff
Check Result
Contact Admissions
```

Avoid unnecessary all-caps buttons throughout the website.

---

## 8. NUMBERS & STATISTICS

For statistics and important numbers, use **Manrope with strong weight**.

Example:

```text
15+
YEARS OF LEARNING

500+
STUDENTS

20+
PROGRAMS
```

Only use real school statistics.

Numbers should be visually prominent without becoming oversized.

---

## 9. CARD TYPOGRAPHY

Cards should have a clear hierarchy:

```text
CATEGORY / LABEL
Card Title
Short Description
Optional Action
```

Do not make every card title large.

Card text should remain compact and scannable.

---

## 10. PORTAL TYPOGRAPHY

Student, Parent and Staff portals should prioritize functionality.

Use **Manrope throughout the dashboard UI**.

Use:

* clear headings
* compact labels
* readable tables
* consistent numbers
* strong status indicators
* clear form labels

The serif heading font may be used sparingly for major dashboard greetings, but functional UI should remain sans-serif.

---

## 11. RESPONSIVE TYPOGRAPHY

Typography must scale smoothly across:

* mobile
* tablet
* laptop
* desktop
* large screens

Do not simply shrink desktop typography at mobile breakpoints.

Use responsive values such as:

```css
clamp()
```

where appropriate.

Hero headings must never cause:

* horizontal overflow
* awkward wrapping
* text overlapping images
* buttons being pushed off-screen

---

## 12. TYPOGRAPHIC CONSISTENCY

Define typography through global CSS variables.

Example:

```css
:root {
    --font-heading: "DM Serif Display", serif;
    --font-body: "Manrope", sans-serif;
    --font-arabic: "Noto Naskh Arabic", serif;
}
```

Also define reusable typography tokens for:

* display
* H1
* H2
* H3
* body
* small text
* eyebrow
* buttons
* labels

Do not scatter arbitrary font sizes throughout the CSS.

---

## 13. IMPORTANT — AVOID GENERIC AI TYPOGRAPHY

Do NOT:

* use oversized headings everywhere
* use bold text for everything
* use multiple decorative fonts
* use uppercase everywhere
* use excessive letter spacing
* use gradients inside text
* use random font combinations
* make every section headline identical
* sacrifice readability for style

Typography should create **personality through hierarchy and composition**, not through excessive decoration.

---

## FINAL TYPOGRAPHY DIRECTION

The overall typographic feeling should be:

**Editorial + Academic + Modern + Warm + Islamic + Highly Readable**

The combination of:

**DM Serif Display**
+
**Manrope**
+
**Noto Naskh Arabic**

should become part of Al-Falaah's visual identity.

Do not change fonts from section to section simply for variety.

**Consistency should create the brand; hierarchy should create the visual interest.**
