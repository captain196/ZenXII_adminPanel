# Website Design Handoff Document

**Project:** [Brand] — School Management ERP Platform
**Deliverable:** Production-Grade Marketing Website
**Target quality lineage:** Stripe · Linear · Notion · Vercel · Framer (visual rigour) + Fedena · Teachmint · PowerSchool (ERP-sector structure)
**Document version:** 1.0
**Owner:** Product / Design / Frontend Engineering

---

## How to Read This Document

This is a single, self-contained execution brief. It is not a marketing or strategy artefact — it is the spec your frontend team builds from.

**Every value in this document is a default decision. Override only with reason.**

- Pixel values, hex codes, durations, and easing curves are exact. Use them as-is.
- Component names map directly to file names in the codebase.
- Tailwind config snippets are copy-paste ready.
- Branding placeholder: `[Brand]` — replace with finalised product name throughout.

---

## Table of Contents

1. Website Objective
2. Website Style Direction
3. Color System
4. Typography System
5. Website Structure
6. Homepage UX Blueprint
7. Product Page Templates
8. Component System
9. Responsive Design Rules
10. Motion & Interaction System
11. Frontend Engineering Recommendations
12. Design References
13. Final Website Build Order

---

# 1. Website Objective

## 1.1 What This Website Does

The [Brand] website is a **commercial lead-generation surface** for a multi-tenant school management ERP platform. Its job is to convert institutional visitors (principals, school owners, accountants, trustees) into booked demos — and to give existing users (teachers, parents) easy access to product downloads and support.

The website is **not** a documentation site, not an application surface, not a community forum. It is a focused marketing and trust-building surface that exists to make one action easy: **book a live demo**.

## 1.2 Target Audiences (Ranked by Buying Power)

| Audience | Role on Site | Primary Action |
|---|---|---|
| **School owners, trustees, chairmen** | Make the buying decision | Book demo, request pricing |
| **Principals** | Evaluate operational fit | Book demo, read customer stories |
| **Accountants / Operations heads** | Validate financial and ops capabilities | Read module pages (Fees, Accounting), book demo |
| **Coaching institute directors** | Evaluate for non-K-12 institutions | Book demo, review pricing |
| **Educational group head offices** | Multi-branch operators | Talk to enterprise sales |
| **Teachers** | App download + reassurance | Download Teacher App from Google Play |
| **Parents** | App download + onboarding | Download Parent App from Google Play |

**Design tension to resolve:** The same site serves enterprise buyers (procurement-driven, evidence-heavy) AND emotional end-users (parents who want warmth, teachers who want respect). The site solves this through page-level tone shifts — homepage and product pages are enterprise; Parent App page is warm; Teacher App page is empowerment-focused.

## 1.3 Primary Conversion Goals

In priority order:

1. **Demo booking** (highest-value action — drives every page CTA)
2. **Pricing page visit** (high-intent signal, supports demo conversion)
3. **App downloads** (Parent + Teacher Play Store)
4. **Contact form submission** (medium-intent, sales-qualifiable)
5. **Newsletter signup** (low-intent, lead nurture)
6. **Customer story read-through** (trust building, future conversion)

Every page must surface CTA #1 within one viewport scroll. CTAs #2 and #3 must be reachable from every page in two clicks or fewer.

## 1.4 Emotional Positioning

The site must communicate **five emotional signals simultaneously**:

- **Confidence** — "We've thought this through. We're serious operators."
- **Sophistication** — "This product is built by people who understand modern software."
- **Empathy** — "We've watched how schools actually work."
- **Trustworthiness** — "Your data and your institution are safe with us."
- **Premium-without-corporate** — "We respect your time and intelligence. We don't shout."

Visual treatments that signal these:
- Generous whitespace (confidence)
- Type-led layouts with restrained colour (sophistication)
- Real product imagery, not stock photography (empathy)
- Visible security and audit posture (trustworthiness)
- Editorial pacing and animation discipline (premium-without-corporate)

---

# 2. Website Style Direction

## 2.1 Overall Visual Identity

**Reference position:** Imagine if **Linear's clarity**, **Stripe's editorial confidence**, and **Notion's playful flexibility** had a school-software child. That's the visual identity.

What this is NOT:
- ❌ Bright purple-pink gradient SaaS (looks Indian-startup, not enterprise)
- ❌ Corporate blue + grey conservative (looks legacy-ERP, not modern)
- ❌ Cartoon-illustrated children-and-pencils (we sell to operators, not parents browsing toys)
- ❌ Dense feature-comparison-matrix-first (looks like a procurement vendor, not a product)

What this IS:
- ✅ Type-led with generous whitespace
- ✅ Real product screenshots, not stylised illustrations
- ✅ Alternating light + ink (dark) sections for visual rhythm
- ✅ One brand colour (teal) used disciplined, never as a wash
- ✅ Subtle motion, not flashy

## 2.2 UI Philosophy

| Principle | Application |
|---|---|
| **Clarity beats density** | If a section requires squinting, redesign it. Default to fewer elements with more whitespace. |
| **Type does most of the work** | Headlines carry the brand. Avoid weak typography compensated by heavy colour. |
| **Show the product** | Real screenshots over illustrations. The product is the proof. |
| **One brand colour, used sparingly** | Brand teal appears on primary CTAs and key accents only — never as a section background. |
| **Visual rhythm via section alternation** | Alternate light → dark → light to break scroll fatigue. |
| **Performance is design** | A 4-second page is an ugly page. Lighthouse 95+ across all four metrics is non-negotiable. |

## 2.3 Spacing Philosophy

**4px base unit. Use the scale exclusively — no arbitrary values.**

Scale: `4 / 8 / 12 / 16 / 24 / 32 / 48 / 64 / 96 / 128`

### Section vertical padding

| Section type | Desktop | Mobile |
|---|---|---|
| Hero | 140px top, 100px bottom | 88px top, 64px bottom |
| Standard content | 120px top + bottom | 64px top + bottom |
| Compressed strip (trust bar, app strip) | 60px top + bottom | 40px top + bottom |
| Final CTA strip | 120px top + bottom | 80px top + bottom |

### Container widths

| Container | Max-width |
|---|---|
| Marketing page wrapper | 1280px |
| Reading-width body block (blog, legal) | 720px |
| Wide feature grid | 1200px |
| Hero | 1280px |
| Footer | 1280px |

**Side padding:** 24px mobile / 48px tablet / 80px desktop.

### Component-level spacing

| Element | Padding |
|---|---|
| Card (standard) | 32px |
| Card (compact, tile) | 24px |
| Button (primary) | 16px vertical / 28px horizontal |
| Button (secondary) | 14px vertical / 24px horizontal |
| Input field | 14px vertical / 16px horizontal |
| Badge / pill | 4px vertical / 12px horizontal |

## 2.4 Typography Direction

See full system in Section 4. Summary: **Inter throughout**, with weight-driven hierarchy and tabular numerals for stats.

## 2.5 Card Style — Three Variants

### Variant A — Elevated Card
**Use:** Feature tiles, module cards, customer story cards.
- Background: `#FFFFFF`
- Border: `1px solid #E2E8F0`
- Radius: `16px`
- Shadow (default): `0 1px 2px rgba(0,0,0,0.04)`
- Shadow (hover): `0 8px 24px rgba(0,0,0,0.08)`
- Hover: `translateY(-4px)`, 250ms ease-out-expo

### Variant B — Flat Card
**Use:** FAQ accordion rows, list items, dense grids.
- Background: `#F8FAFC`
- Border: none
- Radius: `12px`
- Hover: background `#F1F5F9`

### Variant C — Bento Card (Asymmetric)
**Use:** Bento grid sections, dashboard previews.
- Background: alternating `#FFFFFF` and `#0F172A` (ink) for rhythm
- Border: `1px solid #E2E8F0` on light cards, none on ink cards
- Radius: `24px`
- Internal padding: `40px`

## 2.6 Dashboard Preview Style

The website includes stylised product previews (admin dashboard, analytics charts, mobile screens). These must look like **real product UI**, not concept art.

Standards:
- Use actual product UI where it exists. Take real screenshots, clean them up.
- Where the product doesn't yet show what you want, build a faithful mockup in the same design system as the product.
- **Always** show real-looking data (real school names, real-looking numbers). Lorem ipsum and "Student 1, Student 2" kills the premium feel instantly.
- Animate dashboards subtly — chart numerals tick gently every 4 seconds with tiny random deltas to feel "live."
- Dashboards on the marketing site use the **same design system** (colours, type) as the actual product, so visitors aren't surprised by the product they see in demo.

## 2.7 Animation Philosophy

**First principles:**
- Every animation answers "what did this just communicate?" If you can't answer, remove it.
- Speed beats smoothness. A 200ms instant-feeling animation beats a 600ms graceful one.
- Easing matters more than duration. Linear easing is wrong by default.
- Animations on scroll are *revealed*, not autoplayed.
- Always respect `prefers-reduced-motion`.

See Section 10 for full motion spec.

## 2.8 Icon Style

**Choice:** [Lucide React](https://lucide.dev) — monoline, consistent, ~1,400 icons, open-source.

Rules:
- Single weight throughout. Never mix monoline with filled icons in the same section.
- Default stroke: `1.5px`
- Default size: `20px` for inline, `24px` for cards, `32px` for feature tiles, `48px` for hero accents
- Colour: inherits `currentColor` — never hardcoded
- No icon backgrounds (avoid coloured-circle-with-icon clichés). Place icons directly on the surface.

## 2.9 Illustration Direction

Illustrations are **accents, never replacements** for product imagery.

Where illustrations are appropriate:
- Empty states ("No data yet")
- 404 page
- Decorative hero ornaments (subtle, abstract)
- Section transitions (small framing graphics)

Where they are NOT:
- ❌ Feature explanations (use real product screenshots)
- ❌ Customer testimonial portraits (use real photography)
- ❌ Cartoon characters representing students or teachers

**Style:** Geometric, minimal, single-line where possible. Use the brand colour palette only. No skeuomorphism, no shadows on illustrations, no isometric 3D.

**Reference style:** Linear's empty-state illustrations, Stripe's abstract section ornaments.

## 2.10 Screenshot / Mockup Style

| Asset Type | Treatment |
|---|---|
| **Web product screenshots** | Real browser frame (or a clean window chrome we own). 4–8px radius corners. Subtle drop shadow `0 24px 48px rgba(15,23,42,0.12)`. Slight perspective tilt (`-2deg` to `-5deg` rotate-Y) where it adds depth. |
| **Phone mockups** | Pixel-perfect device frames (modern Android, no iPhone — until iOS app ships). Drop shadow as above. Used in fanned compositions (2–3 phones at varied angles) in app-spotlight sections. |
| **Annotated screenshots** | Small numbered hotspot circles (1–5) with hairline connector lines to off-image labels. Hotspots use brand teal. |
| **Multi-device composites** | Laptop + phone + phone overlapping, showing the same data flowing across them. Used in hero only. |

**Photography for screenshots:**
- Backgrounds: simple gradient or neutral surface. Never busy.
- File format: WebP primary, PNG fallback. Use `next/image` for automatic format negotiation.
- Resolution: 2x for sharpness on high-DPI displays.

---

# 3. Color System

All values below are exact. Use as Tailwind tokens; do not introduce arbitrary colours.

## 3.1 Primary Brand Color

```
--brand-primary:        #0F766E    /* Deep teal — confidence + education trust */
--brand-primary-hover:  #0D6B63
--brand-primary-pressed:#0A5853
--brand-on-primary:     #FFFFFF    /* Text on brand */
```

Used on:
- Primary CTAs (filled state on light backgrounds)
- Logo
- Brand mark accents
- Link hover underlines
- Focus rings
- Eyebrow tags (selectively)

**Not used as a section background.** Brand colour is a touch, not a wash.

## 3.2 Accent Color

```
--accent:        #F59E0B    /* Warm amber — high-conversion moments */
--accent-hover:  #D97706
```

Used on:
- Hero CTAs (when primary teal feels too quiet)
- Final CTA strip background gradient end-point
- "Most Popular" pricing tier badge
- Highlight badges ("New", "Free Pilot")
- Announcement bar accents

**Reserved for highest-conversion moments only.** Overuse dilutes signal.

## 3.3 Product Sub-Accents

Each product surface gets a subtle colour identity used in eyebrows, hover borders, and feature-tile corners:

```
--admin-accent:    #4338CA    /* Indigo — Admin Panel */
--teacher-accent:  #0F766E    /* Teal — Teacher App (matches brand) */
--parent-accent:   #B45309    /* Warm amber — Parent App */
```

Used to differentiate the three product surfaces visually while keeping them part of one family.

## 3.4 Neutrals (Slate-Based 12-Step Scale)

```
--neutral-0:    #FFFFFF
--neutral-50:   #F8FAFC    /* Subtle page background tints */
--neutral-100:  #F1F5F9    /* Card background variant B */
--neutral-200:  #E2E8F0    /* Hairline borders */
--neutral-300:  #CBD5E1    /* Disabled borders */
--neutral-400:  #94A3B8    /* Muted text on light */
--neutral-500:  #64748B    /* Body text muted */
--neutral-600:  #475569    /* Body text default */
--neutral-700:  #334155    /* Headings on light */
--neutral-800:  #1E293B    /* Strong headings */
--neutral-900:  #0F172A    /* Hero headlines on light */
--neutral-950:  #0A0F1C    /* Deepest ink (footer, dark sections) */
```

## 3.5 Background & Surface Colors

| Surface | Color | Use |
|---|---|---|
| Page default | `#FFFFFF` | Standard sections |
| Soft surface | `#F8FAFC` (neutral-50) | "Off-white" sections to break visual rhythm |
| Tinted surface | `#F1F5F9` (neutral-100) | Trust / testimonial / proof sections |
| Ink surface 1 | `#0A0F1C` (neutral-950) | Hero, Real-Time Sync section, Final CTA |
| Ink surface 2 | `#111827` | Footer |
| Ink surface 3 | `#1E293B` | Card-within-ink-section background |

**Section alternation rhythm (homepage):**
`Ink → Light → Off-white → Light → Ink → Light → Light → Light → Ink → Light → Light → Light → Light → Light → Off-white → Light → Light → Ink → Ink (footer)`

## 3.6 Text Hierarchy

On light backgrounds:
```
--text-hero:      #0F172A    /* H1 hero — maximum weight */
--text-heading:   #1E293B    /* H2/H3 — strong */
--text-body:      #334155    /* Body paragraphs */
--text-muted:     #64748B    /* Captions, metadata */
--text-disabled:  #94A3B8    /* Disabled states */
```

On ink backgrounds:
```
--text-hero-dark:    #FFFFFF
--text-heading-dark: #F1F5F9
--text-body-dark:    #CBD5E1
--text-muted-dark:   #94A3B8
```

**Contrast minimums (WCAG AA):**
- Body text: 4.5:1 against background — always.
- Large headings (≥24px bold): 3:1 minimum.
- Verify with axe-core or browser DevTools contrast checker.

## 3.7 CTA Color Specifications

| CTA Tier | Light Background | Ink Background |
|---|---|---|
| **Primary (filled)** | Background `#F59E0B` accent, text `#FFFFFF`, no border | Background `#FFFFFF`, text `#0F172A`, no border |
| **Brand (filled)** | Background `#0F766E`, text `#FFFFFF`, no border | Background `#0F766E`, text `#FFFFFF`, no border |
| **Outlined** | Border `1.5px solid #0F172A`, transparent fill, text `#0F172A` | Border `1.5px solid #FFFFFF`, transparent fill, text `#FFFFFF` |
| **Ghost** | Transparent, text `#0F172A`, hover background `#F1F5F9` | Transparent, text `#FFFFFF`, hover background `rgba(255,255,255,0.1)` |
| **Text link** | Text `#0F766E` with right-arrow, hover underline | Text `#F59E0B` (accent), hover underline |

## 3.8 Gradients

Use gradients sparingly — only as section accents.

```
/* Final CTA gradient — deep teal to indigo */
--gradient-final-cta: linear-gradient(135deg, #0F766E 0%, #1E3A8A 100%);

/* Hero subtle overlay — top-to-bottom */
--gradient-hero-overlay: linear-gradient(180deg, transparent 0%, rgba(10,15,28,0.4) 100%);

/* Soft warm background (parent app sections) */
--gradient-warm-tint: linear-gradient(180deg, #FEF3C7 0%, #FFFFFF 100%);
```

**Avoid:** Multi-stop rainbow gradients, mesh gradients with > 3 colours, gradients on body text.

## 3.9 Semantic Colors

```
--success: #16A34A
--warning: #D97706
--error:   #DC2626
--info:    #0284C7
```

Used in:
- Form validation
- Status badges (in dashboard previews)
- Toast notifications
- Pricing tier "best value" indicators

## 3.10 Dark / Light Mode Recommendation

**Launch in light mode only.** The site uses dark "ink" sections for visual rhythm, but does not need user-toggleable dark mode at launch.

Reasons:
- Marketing sites primarily live on light mode (Stripe, Linear, Notion all do)
- Doubling design surface for dark mode delays launch
- Real product (admin panel) does have dark mode; marketing site doesn't need it

**Defer to V2:** if/when analytics show > 15% of visitors using system-level dark mode, implement.

---

# 4. Typography System

## 4.1 Font Pairing

**Primary recommendation:** **Inter** throughout — for headlines, body, and UI. Weight-driven hierarchy.

| Use | Font | Weights Required |
|---|---|---|
| Display + Headings | Inter | 500, 600, 700 |
| Body + UI | Inter | 400, 500 |
| Stats + Numerals + Code | JetBrains Mono | 400, 500 |

**Why Inter:**
- Free (Google Fonts + open-source)
- Used by Linear, Vercel, Substack, and most premium SaaS sites
- Excellent Indic language coverage (for V2 multilingual)
- Tabular numerals built in (`font-variant-numeric: tabular-nums`)
- 18 weights available

**Alternative (premium upgrade if budget allows):**
- Display: **Söhne** (Stripe's font) or **GT Walsheim** (Notion's font) — commercial license required
- Body: keep Inter

**Editorial alternative (warm direction):**
- Display: **Fraunces** (free, variable serif) for editorial-feel headlines
- Body: Inter

For the launch handoff: **Inter throughout** is the recommended default. It's premium, free, and used by the best in the category.

## 4.2 Font Loading

Self-host via `next/font/google`:

```ts
// app/fonts.ts
import { Inter, JetBrains_Mono } from 'next/font/google'

export const inter = Inter({
  subsets: ['latin'],
  display: 'swap',
  variable: '--font-sans',
})

export const jetbrainsMono = JetBrains_Mono({
  subsets: ['latin'],
  display: 'swap',
  variable: '--font-mono',
})
```

Apply via `<html className={\`${inter.variable} ${jetbrainsMono.variable}\`}>` and reference via `font-sans` / `font-mono` in Tailwind.

## 4.3 Type Scale (Modular Scale 1.25 — Major Third)

Built on 8pt baseline. Desktop / Mobile values shown side-by-side.

| Token | Desktop | Mobile | Weight | Line-height | Tracking | Use |
|---|---|---|---|---|---|---|
| `text-hero` | 72px | 44px | 600 | 1.05 | -0.02em | Page H1 / hero only |
| `text-display` | 56px | 36px | 600 | 1.1 | -0.02em | Section H1 |
| `text-h1` | 48px | 32px | 600 | 1.15 | -0.02em | Major section heading |
| `text-h2` | 36px | 28px | 600 | 1.2 | -0.01em | Sub-section heading |
| `text-h3` | 28px | 22px | 600 | 1.3 | 0 | Card heading |
| `text-h4` | 22px | 18px | 600 | 1.4 | 0 | Block title |
| `text-lead` | 20px | 18px | 400 | 1.55 | 0 | Body lead paragraphs |
| `text-body` | 17px | 16px | 400 | 1.7 | 0 | Default body |
| `text-base` | 16px | 16px | 400 | 1.6 | 0 | UI text |
| `text-sm` | 14px | 14px | 400 | 1.5 | 0 | Captions, labels |
| `text-xs` | 12px | 12px | 500 | 1.4 | 0 | Metadata |
| `text-eyebrow` | 12px | 12px | 600 | 1.0 | +0.08em | Section labels (UPPERCASE) |
| `text-stat` | 48px | 36px | 600 | 1.0 | -0.02em | Stat numerals (tabular) |
| `text-stat-lg` | 64px | 48px | 600 | 1.0 | -0.02em | Hero stats |

## 4.4 Spacing Ratios (Vertical Rhythm)

Heading-to-body and inter-section spacing follows multiples of 8px:

| Pair | Spacing |
|---|---|
| Eyebrow → Headline | 16px |
| Headline → Sub-headline | 24px |
| Sub-headline → Body | 32px |
| Body paragraph → Body paragraph | 24px |
| Body → CTA group | 40px |
| Heading group → Content block | 48px |
| Section heading group → Component grid | 64px |

## 4.5 Tabular Numerals

All stat strips, pricing numbers, comparison tables, and counter animations require:

```css
font-variant-numeric: tabular-nums;
font-feature-settings: 'tnum';
```

Tailwind class: `tabular-nums`.

Prevents layout jitter when numbers tick.

## 4.6 Premium SaaS Typography References

| Site | What They Do Well | Apply to [Brand] |
|---|---|---|
| **Stripe** | Editorial restraint, Söhne pairing, generous line-height on body | Use Inter at line-height 1.7 for body |
| **Linear** | Hero typography weight discipline, no decorative type | Use weight 600 for headings, 400 for body — never mix |
| **Vercel** | Mono numerals everywhere for "engineering" credibility | Use JetBrains Mono on every numerical stat |
| **Notion** | Variable scale based on viewport (smooth fluid type) | Optional V2: implement `clamp()` for fluid typography |
| **Anthropic** | Editorial centered heroes with serif gravitas | Optional: Fraunces for hero-only on About / Mission pages |
| **Framer** | Tight tracking on display sizes | Use -0.02em tracking on ≥40px headings |

---

# 5. Website Structure

## 5.1 Full Sitemap

```
/                                       Homepage

/platform                               Platform overview (umbrella)
/admin-panel                            Admin ERP product page
/teacher-app                            Teacher App product page
/parent-app                             Parent App product page

/modules                                Modules index
/modules/admissions
/modules/student-lifecycle
/modules/academic-management
/modules/timetable
/modules/attendance
/modules/examinations
/modules/fees
/modules/hr-payroll
/modules/communication
/modules/transport
/modules/library
/modules/analytics
/modules/reports

/solutions                              Industries index
/solutions/k12-schools
/solutions/colleges
/solutions/coaching-institutes
/solutions/educational-groups

/pricing
/customers                              Customer stories index
/customers/[story-slug]

/resources                              Resources index
/resources/blog
/resources/blog/[post-slug]
/resources/help-center
/resources/webinars
/resources/playbooks
/resources/changelog
/resources/migration-guide

/about
/why-us
/security
/careers
/careers/[role-slug]
/press
/partners
/contact

/faq
/demo                                   Direct demo booking page
/login                                  Login chooser modal/page
/signup                                 Self-serve waitlist (V2)

/legal/terms
/legal/privacy
/legal/cookies
/legal/refund-policy
/legal/dpa
/legal/subprocessors
/legal/accessibility
```

URL conventions:
- Kebab-case slugs
- No trailing slash (use `next.config.js trailingSlash: false`)
- No file extensions
- Locale prefix reserved for V2 (`/hi/`, `/ta/`)

## 5.2 Main Navbar Structure (Desktop)

Sticky top bar, **64px height**, translucent over hero (`backdrop-filter: blur(12px)`), solid `#FFFFFF` after 80px scroll.

```
┌────────────────────────────────────────────────────────────────────────────────┐
│ [Logo]   Platform▾  Modules▾  Solutions▾  Pricing  Customers  Resources▾       │
│                                                              Sign In  [Book Demo]│
└────────────────────────────────────────────────────────────────────────────────┘
```

Hover delays:
- Dropdown open: 100ms (prevents accidental triggers)
- Dropdown close: 80ms

### Dropdown — Platform

Two-column panel, ~520px wide.

```
PRODUCTS                          FEATURED
──────                            ────────
Admin Panel                       The ecosystem at a glance →
Web command centre                See how 3 apps share 1 backbone

Teacher App                       Real-time sync engine →
Built for the classroom           The infrastructure behind the speed

Parent App                        Security & infrastructure →
Live in your pocket               Read our trust posture
```

### Dropdown — Modules

Three-column panel, ~720px wide.

```
ACADEMIC                OPERATIONS           FINANCE & HR
────────                ──────────           ────────────
Admissions              Attendance           Fees Management
Student Lifecycle       Communication        HR & Payroll
Academic Management     Transport            Analytics
Timetable               Library              Reports
Examinations
```

### Dropdown — Solutions

Two-column panel.

```
BY INSTITUTION TYPE              FEATURED
──────────────────               ────────
K-12 Schools                     Customer Story →
Colleges & Higher Ed             [Demo School] cut fees reconciliation by 80%
Coaching Institutes
Educational Groups               Talk to our solutions team →
```

### Dropdown — Resources

Single-column panel, ~240px wide.

```
Help Center
Webinars
Operational Playbooks
Blog
Changelog
Migration Guide
```

## 5.3 Footer Structure

Background `#0A0F1C` (ink-1). Light text. Bottom-bar one shade lighter `#111827`.

### Top strip — Newsletter

Full-width, centred.
```
Stay informed.
Quarterly product updates, education-sector insights, and operational playbooks. No spam.

[email input ____________________] [Subscribe]
```

### Above-grid — App Strip

```
Available on Google Play. iOS coming late 2026.    [Google Play Badge] [App Store Badge — coming soon]
```

### Main Grid — 5 Columns

| Brand | Product | Solutions | Resources | Company |
|---|---|---|---|---|
| Logo | Platform Overview | For K-12 Schools | Help Center | About |
| Tagline | Admin Panel | For Colleges | Video Tutorials | Mission & Values |
| HQ Address | Teacher App | For Coaching | Webinars | Team |
| Phone / Email | Parent App | For Educational Groups | Customer Stories | Careers |
| WhatsApp | Modules | Comparison vs Other ERPs | Blog | Press |
| Social icons (LinkedIn / X / YouTube / Instagram) | Pricing | Migration Services | Operational Playbooks | Partners |
| | Security | International Schools (V2) | API Docs (2027) | Contact |
| | Changelog | | System Status | |
| | | | Brand Assets | |

### Bottom Bar — Legal

Single line, 11px text:
```
© 2026 [Brand] Technologies Private Limited.  ·  GSTIN: [#]  ·  CIN: [#]  ·  Registered Office: [Address]
Terms · Privacy · Cookies · Refund Policy · DPA · Sub-Processors · Responsible Disclosure · Accessibility
[Country selector ▾]
```

## 5.4 Mobile Navigation

Slide-in drawer (full-screen overlay) triggered by hamburger icon top-left.

### Mobile top bar (60px height)
```
[☰]  [Logo]                                     [Book Demo]
```

### Drawer contents (when open)
```
┌──────────────────────────────┐
│ [Logo]                  [×]  │
├──────────────────────────────┤
│ Platform                  ▾  │
│   ↳ Admin Panel              │
│   ↳ Teacher App              │
│   ↳ Parent App               │
├──────────────────────────────┤
│ Modules                   ▾  │
│   ↳ Academic                 │
│   ↳ Operations               │
│   ↳ Finance & HR             │
├──────────────────────────────┤
│ Solutions                 ▾  │
│ Pricing                      │
│ Customers                    │
│ Resources                 ▾  │
│ About                        │
│ Contact                      │
├──────────────────────────────┤
│ [   Book a Demo   ]          │   ← sticky bottom of drawer
│ Sign In →                    │
│ [WhatsApp icon] [Phone icon] │
└──────────────────────────────┘
```

Behaviour:
- Full viewport height (`100dvh`)
- Solid background (no transparency — avoid behind-content bleed)
- Body scroll locked when open
- Hamburger morphs to `×` (Framer Motion crossfade, 200ms)
- Close on route change
- Section accordions: single-expand
- Sticky bottom CTA inside drawer

### Mobile sticky bottom bar (persistent across all pages)
```
┌────────────────────────────────────────────────┐
│ [Book Demo Button — flexgrow]   [WhatsApp] [📞] │
└────────────────────────────────────────────────┘
```

- Always visible on mobile.
- Compresses to icons-only on scroll-down (saves vertical space).
- Expands on scroll-up (CTA re-emerges).

---

# 6. Homepage UX Blueprint

The homepage is 19 sections. Each is specified below with purpose, layout, alignment, visual references, animation, mobile behaviour, and CTA placement.

## Section 1 — Announcement Strip

| Property | Spec |
|---|---|
| **Purpose** | Surface time-sensitive announcements (beta, webinar, certification) |
| **Layout** | Thin 36px bar, full-width, centred single line + right-aligned `×` |
| **Alignment** | Centre |
| **Visual ref** | Stripe's product update strip; Vercel's launch strip |
| **Animation** | Fade-rotation between 3–5 messages every 6s (pause on hover) |
| **Mobile** | 32px height, smaller text (12px), no rotation (display first message only) |
| **CTA** | Inline arrow link |

## Section 2 — Hero

| Property | Spec |
|---|---|
| **Purpose** | Position the product in 5 seconds; convert via primary CTA |
| **Layout** | Two-column 50/50 split. Left: eyebrow, H1 (`text-hero`), sub (`text-lead`), body (`text-body`), CTA pair. Right: layered device composite (laptop + 2 phones) showing the same student's data across surfaces |
| **Alignment** | Left-aligned text, right-aligned visual |
| **Visual ref** | Stripe's homepage hero; Linear's homepage hero; Notion's homepage hero |
| **Animation** | Text fades in with stagger (200ms between H1, sub, body, CTAs). Device composite eases in from right with subtle scale-up. Continuous gentle floating animation on phones (translateY ±4px, 6s loop). Animated dot-flow between devices (continuous loop, signals real-time sync). On scroll: parallax at 0.1× rate on device composite |
| **Mobile** | Stack vertical — text top, device composite bottom. Reduce composite to single phone. No parallax. |
| **CTA** | Primary "Book a Live Demo" (filled accent) + Secondary "Explore the Platform" (outlined). Below CTAs: tertiary text link "Trusted by 500+ schools →" |
| **Background** | Dark ink (`#0A0F1C`) with subtle radial gradient |

## Section 3 — Trust Bar

| Property | Spec |
|---|---|
| **Purpose** | Institutional credibility in <2 seconds |
| **Layout** | Logo wall (8 logos, single row, equal spacing) + 4-stat strip below |
| **Alignment** | Centred |
| **Visual ref** | Vercel's trust bar; Linear's customer logo strip |
| **Animation** | Logos stagger-fade in (80ms each). Stats counter-tick on viewport entry (400ms ease-out-expo) |
| **Mobile** | Logos → horizontal scrollable carousel. Stats → 2×2 grid |
| **CTA** | Subtle text link below stats: "See customer stories →" |
| **Background** | `#FFFFFF` (white — visual relief after dark hero) |

## Section 4 — The Problem We Solve

| Property | Spec |
|---|---|
| **Purpose** | Activate empathy; create reader recognition |
| **Layout** | Centred single column, max-width 880px. Small before/after icon strip above headline. Body with deliberate paragraph rhythm |
| **Alignment** | Centred headline, left-aligned body |
| **Visual ref** | Linear's "Built for high-performance teams" section; Notion's "Why Notion" section |
| **Animation** | Paragraphs fade in on scroll-into-view (120ms stagger). Before/after icon strip has subtle morph animation |
| **Mobile** | Same vertical layout, reduced padding |
| **CTA** | Tertiary text link at bottom: "See how the ecosystem fits together →" |
| **Background** | `#F8FAFC` (off-white — subtle break from white above) |

## Section 5 — The Ecosystem (3-App Overview)

| Property | Spec |
|---|---|
| **Purpose** | Educate on solution structure |
| **Layout** | Centred headline/sub/body intro. Below: 3-column card grid. Each card: form-factor icon + product name + sub-accent top border + 3-line description + CTA link |
| **Alignment** | Centred |
| **Visual ref** | Stripe's product cards on /products; Vercel's "Build" / "Deploy" / "Scale" cards |
| **Animation** | Cards lift on hover (-4px translateY, shadow expansion). Cards stagger-fade on entry (150ms stagger) |
| **Mobile** | Cards stack vertically. Full-width. |
| **CTA** | One per card → Explore Admin Panel / Teacher App / Parent App |
| **Background** | `#FFFFFF` |

## Section 6 — Real-Time Sync Engine

| Property | Spec |
|---|---|
| **Purpose** | Establish core technical differentiator |
| **Layout** | Eyebrow + centred headline/sub. Below: 4 horizontal workflow rows (Fee Payment, Homework, Attendance, Red Flag). Each row: source-device icon → arrow with animated dots → destination-device icon, with 1-line outcome and latency claim |
| **Alignment** | Centred section header, left-aligned workflow rows |
| **Visual ref** | Stripe Connect's money-flow diagram; Segment's data-flow visualisation |
| **Animation** | Animated dots flow along arrows continuously (3-second cycle). Workflow rows reveal one at a time on scroll (400ms stagger). Latency badge counts down "2 sec... 1 sec..." then pulses |
| **Mobile** | Workflow rows stack vertically. Reduce animation complexity (slower dot flow, fewer simultaneous animations) |
| **CTA** | Primary button below diagrams: "See the engine in action" |
| **Background** | Dark ink (`#0A0F1C`) — dots and motion pop visually |

## Section 7 — Core Modules Grid

| Property | Spec |
|---|---|
| **Purpose** | Convey breadth ("everything is covered") |
| **Layout** | 5×4 grid of module tiles (20 modules). Each tile: 32px icon top-left, module name, 2-line capability. Hover reveals a soft accent-color bottom border |
| **Alignment** | Grid centred in container |
| **Visual ref** | Notion's "What you can do" bento; Linear's features grid |
| **Animation** | Tiles stagger-fade in (60ms each). Hover: tile lifts + accent bottom border slides in |
| **Mobile** | Reduce to 2 columns. Tile aspect-ratio adjusts |
| **CTA** | Below grid: "Browse all modules →" |
| **Background** | `#F8FAFC` |

## Section 8 — Admin Panel Spotlight

| Property | Spec |
|---|---|
| **Purpose** | Sell admin product to buyers |
| **Layout** | Asymmetric: left 40% (eyebrow, headline, sub, body, capability list with 3 sub-columns: Operational / Financial / Governance), right 60% (annotated screenshot of admin dashboard with 4-5 numbered hotspots) |
| **Alignment** | Left-aligned text, right-aligned visual |
| **Visual ref** | Linear's product features section; Stripe Atlas product page |
| **Animation** | Hotspots reveal sequentially (400ms stagger) on viewport entry. Tooltip connectors fade in. Hover on hotspot expands tooltip |
| **Mobile** | Stack: text top, full-width screenshot below. Hide hotspots; show feature bullets instead |
| **CTA** | Primary "Tour the Admin Panel" + Secondary "Download Brochure" |
| **Background** | `#FFFFFF` |

## Section 9 — Teacher App Spotlight

| Property | Spec |
|---|---|
| **Purpose** | Sell teacher product to schools |
| **Layout** | Mirror of Section 8 (visual on left, text on right). Visual = phone-mockup-fan with 3 Teacher App screens (Attendance grid, Homework composition, Marks entry) |
| **Alignment** | Right-aligned text, left-aligned visual |
| **Visual ref** | Notion's mobile app section; Linear's iOS app feature page |
| **Animation** | Phones stagger-reveal from off-screen (slide-up + fade, 200ms stagger). Continuous floating animation on phones (translateY ±4px, 6s loop) |
| **Mobile** | Stack: visual top, text below |
| **CTA** | Primary "Explore Teacher App" + Secondary "Watch the Tour" |
| **Background** | `#F1F5F9` (tinted — differentiates from Section 8) |

## Section 10 — Parent App Spotlight

| Property | Spec |
|---|---|
| **Purpose** | Sell parent product to schools (drives parent satisfaction → school retention) |
| **Layout** | Same as Section 9 but text-left, visual-right. Phones show Parent Home screen, Fees with Pay button, Live transport tracking |
| **Alignment** | Left-aligned text, right-aligned visual |
| **Visual ref** | Stripe Checkout's mobile section; Calm app's product page |
| **Animation** | Same as Section 9 |
| **Mobile** | Stack |
| **CTA** | Primary "Explore Parent App" + Secondary "Watch the Tour" |
| **Background** | `#FFFFFF` |

## Section 11 — Multi-Role Dashboards

| Property | Spec |
|---|---|
| **Purpose** | Establish role-aware sophistication |
| **Layout** | 5-column grid (Principal / Accountant / Class Teacher / Warden / Parent). Each column: role icon, role name, 4 dashboard items as bullets, "View dashboard preview →" link |
| **Alignment** | Centred |
| **Visual ref** | Vercel's "By role" section; Linear's persona-based features |
| **Animation** | Columns stagger-reveal (100ms each) |
| **Mobile** | Columns → 2×3 grid or vertical carousel |
| **CTA** | Below grid: "Explore role-based access controls →" |
| **Background** | `#FFFFFF` |

## Section 12 — Analytics & Intelligence

| Property | Spec |
|---|---|
| **Purpose** | Signal data sophistication |
| **Layout** | Two-column: left 50% text, right 50% stylised dashboard preview with live chart + heatmap |
| **Alignment** | Left text, right visual |
| **Visual ref** | Linear's analytics page; Vercel's monitoring page |
| **Animation** | Chart line strokes draw in on viewport entry (1.2s). Numerals tick up subtly (continuous 4s pulse with small random delta to feel live) |
| **Mobile** | Stack: text top, dashboard preview below (full-width, scrollable horizontally if needed) |
| **CTA** | Primary "See the analytics layer" |
| **Background** | Dark ink (`#0A0F1C`) — charts pop |

## Section 13 — Security & Infrastructure

| Property | Spec |
|---|---|
| **Purpose** | Address trust/risk objections |
| **Layout** | 2×2 grid of 4 security tiles (Encryption / Tenant Isolation / Audit Trail / Backups). Below grid: horizontal trust-badge row |
| **Alignment** | Centred |
| **Visual ref** | Vercel's security page; Linear's trust page |
| **Animation** | Tiles fade-in stagger. Trust badges fade in below |
| **Mobile** | 2×2 grid stays; trust badge row scrollable |
| **CTA** | "Read our security posture →" |
| **Background** | `#FFFFFF` with faint hexagonal mesh pattern (subtle) |

## Section 14 — Industries We Serve

| Property | Spec |
|---|---|
| **Purpose** | Audience self-selection |
| **Layout** | 4-column grid (K-12 / Colleges / Coaching / Educational Groups). Each: small illustration top, name, 1-line tagline, 3-line description, CTA link |
| **Alignment** | Centred |
| **Visual ref** | Vercel's "Built for" segments; Notion's "For teams" page |
| **Animation** | Cards stagger-fade in. Hover: illustration animates subtly |
| **Mobile** | 4 columns → 2×2 grid |
| **CTA** | One per card → Learn how [audience] uses [Brand] |
| **Background** | `#F8FAFC` |

## Section 15 — Why [Brand]

| Property | Spec |
|---|---|
| **Purpose** | Differentiation summary |
| **Layout** | 3×2 grid of differentiator tiles. Each tile: large outlined numeral (1–6) + headline + 2-3 line description |
| **Alignment** | Centred |
| **Visual ref** | Linear's "Built for the way teams actually work"; Stripe's "Why Stripe" |
| **Animation** | Numerals SVG-stroke draw-in on scroll-into-view (600ms each, staggered) |
| **Mobile** | 3×2 → 1×6 vertical stack |
| **CTA** | Primary "Compare [Brand] to your current system" |
| **Background** | `#FFFFFF` with subtle dot pattern |

## Section 16 — Customer Outcomes

| Property | Spec |
|---|---|
| **Purpose** | Outcome proof |
| **Layout** | Top: Before/After comparison table (4 rows). Below: featured testimonial card with portrait |
| **Alignment** | Centred section header, table left/right contrast, testimonial centred |
| **Visual ref** | Linear's customer outcomes; Webflow's case study highlights |
| **Animation** | Before/After numerals slide in with stagger. Testimonial fades in |
| **Mobile** | Table → stacked rows. Testimonial full-width |
| **CTA** | Primary "Read customer stories" + Secondary "Schedule a discovery call" |
| **Background** | `#F8FAFC` |

## Section 17 — Customer Stories Carousel

| Property | Spec |
|---|---|
| **Purpose** | Peer validation through narrative |
| **Layout** | 3-card horizontal carousel of customer story cards. Pagination dots below |
| **Alignment** | Centred |
| **Visual ref** | Shopify's case study carousel; Stripe's customer stories |
| **Animation** | Card transitions with fade-slide (400ms ease-out-expo). Auto-advance every 8s (pause on hover). Manual navigation via dots or arrow keys |
| **Mobile** | Single card, swipe to advance |
| **CTA** | "Read all customer stories →" |
| **Background** | `#FFFFFF` |

## Section 18 — FAQ

| Property | Spec |
|---|---|
| **Purpose** | Objection handling |
| **Layout** | Two-column: left 30% (eyebrow, headline, sub, decorative), right 70% (accordion of 8 questions) |
| **Alignment** | Left-aligned content |
| **Visual ref** | Stripe's FAQ pattern; Notion's pricing FAQ |
| **Animation** | Accordion expand/collapse 250ms ease-out-quart. Plus → minus icon rotation |
| **Mobile** | Stack: eyebrow/headline top, accordion below |
| **CTA** | Below accordion: "See all FAQs →" |
| **Background** | `#FFFFFF` |

## Section 19 — Final CTA Strip

| Property | Spec |
|---|---|
| **Purpose** | Last-chance conversion |
| **Layout** | Full-width band with brand gradient. Centred content max-width 800px. Small badge above headline ("FREE 3-MONTH PILOT"). Headline + sub + primary + secondary CTAs |
| **Alignment** | Centred |
| **Visual ref** | Notion's "Get started" strip; Linear's "Ready to build?" strip |
| **Animation** | Subtle animated dot pattern in background |
| **Mobile** | Same layout, reduced padding |
| **CTA** | Primary "Book a Live Demo" (largest button on the page) + Secondary "Get Pricing" |
| **Background** | Brand gradient (`#0F766E → #1E3A8A`) |

## Section 20 — Footer

See Section 5.3.

## 6.X Scroll Pacing Summary

Total target height: **5,800–6,200px desktop** after dark-section compression.

```
Hero                           ~640px (full viewport)
Trust bar                      ~360px
Problem narrative              ~720px
Ecosystem 3-app                ~640px
Real-time sync                 ~880px ← tallest content section
Modules grid                   ~640px
Admin spotlight                ~640px
Teacher spotlight              ~640px
Parent spotlight               ~640px
Multi-role dashboards          ~480px
Analytics                      ~600px
Security                       ~600px
Industries                     ~480px
Why [Brand]                    ~600px
Outcomes                       ~640px
Customer stories carousel      ~520px
FAQ                            ~600px
Final CTA strip                ~520px
Footer                         ~440px
```

Rhythm: alternating light → dark sections every 4–5 sections to break scroll fatigue.

---

# 7. Product Page Templates

Each template is a sequence of component invocations. Components are defined in Section 8.

## 7.1 Admin Panel Page (`/admin-panel`)

```
1.  nav-bar
2.  hero-split            (eyebrow "ADMIN PANEL — WEB", H1 "The control center for school operations.", dark background)
3.  trust-bar             (logos + 4-stat strip)
4.  feature-row-alternating × 6
    - Operational Control (with annotated dashboard screenshot)
    - Financial Integrity
    - Governance & Audit
    - Multi-Tenant Architecture
    - Bulk Operations
    - Reports Library
5.  bento-grid            (showcase of admin sub-features in asymmetric tiles)
6.  workflow-flow-diagram (Admin → Firestore → Teacher + Parent apps — illustrates source-of-truth)
7.  feature-grid-4        (security tiles relevant to admin: encryption, audit trail, period locks, role-based access)
8.  stat-strip            (operational outcome stats — "5 days → 1 day reconciliation", etc.)
9.  customer-story-card   (one related story — multi-branch group)
10. faq-accordion         (6 admin-specific FAQs)
11. cta-strip-full-width  ("See the Admin Panel in your demo environment")
12. footer
```

## 7.2 Parent App Page (`/parent-app`)

```
1.  nav-bar
2.  hero-split            (warm background, emotional headline, single hero phone mockup)
3.  trust-bar             (4.7★ Play Store + active parents + fees processed + uptime)
4.  problem-narrative     ("The hardest part of being a parent? Not always being there.")
5.  feature-row-alternating × 8
    - Daily Visibility ("One glance. The whole day.")
    - Real-Time Notifications
    - Attendance
    - Homework
    - Fees (with Razorpay branding)
    - Exams & Performance
    - Communication
    - School Updates / Events / Gallery
6.  feature-row-alternating × 2 (Transport, PTM Booking)
7.  cta-inline-banner     ("More than one child? Switch with one tap.")
8.  feature-grid-4        (Privacy & data trust tiles)
9.  featured-testimonial + testimonial-grid
10. app-showcase-block    (Google Play badge, QR code, phone mockup composite, Play Store listing copy)
11. faq-accordion         (parent-specific FAQs, 12 questions)
12. cta-strip-full-width  ("Stay close. Even when you can't be there.")
13. footer (with parent-specific quick-link strip above main footer)
```

## 7.3 Teacher App Page (`/teacher-app`)

```
1.  nav-bar
2.  hero-split            (professional background, "Give your day back to teaching.")
3.  trust-bar             (4.6★ + active teachers + minutes reclaimed/day + uptime)
4.  problem-narrative     ("The teacher's day is interrupted by everything except teaching.")
5.  time-back-table       (before/after comparison — attendance, homework, marks, communication times)
6.  feature-row-alternating × 9
    - 90-second attendance
    - Homework management
    - Marks & performance
    - Timetable (live)
    - Communication (without sharing personal number)
    - Substitute & schedule updates
    - Lesson plans
    - Self-service HR (payslips, leave, appraisals)
    - Offline-first
7.  cta-inline-banner     ("A school platform that doesn't follow you home.")
8.  featured-testimonial + testimonial-grid
9.  app-showcase-block    (Play Store badge + listing copy)
10. faq-accordion         (teacher-specific FAQs)
11. cta-strip-full-width  ("Give your day back to teaching.")
12. footer (with teacher-specific quick-link strip above main footer)
```

## 7.4 Module Pages (`/modules/[slug]`)

Reusable template; module-specific accent colour drives section accents.

```
1.  nav-bar
2.  hero-split            (module eyebrow, module H1, dual CTAs, annotated screenshot or icon-led visual)
3.  pain-point-scroller   (horizontal-scroll cards: 5-7 specific pain points)
4.  feature-row-alternating × 2 (capability detail blocks with annotated screenshots)
5.  workflow-flow-diagram (cross-system flow specific to this module)
6.  feature-grid-3        (benefits tiles)
7.  stat-strip            (outcome stats specific to this module)
8.  customer-story-card   (one related customer story)
9.  related-modules-strip (3 horizontal cards — modules logically adjacent to this one)
10. cta-strip-full-width
11. footer
```

## 7.5 Industry Pages (`/solutions/[slug]`)

```
1.  nav-bar
2.  hero-split            (industry-specific framing — eg. "Built for the operational complexity of K-12.")
3.  trust-bar             (industry-relevant logos and stats)
4.  problem-narrative     (industry-specific pain framing)
5.  feature-row-alternating × 3 (3 most relevant capabilities for this industry, ordered by relevance)
6.  customer-story-card × 2 (2 industry-matched stories)
7.  module-grid-relevant  (only modules relevant to this industry, 8-12 tiles)
8.  pricing-tier-recommendation (suggests specific tiers for this audience)
9.  faq-accordion         (industry-specific FAQs)
10. cta-strip-full-width
11. footer
```

---

# 8. Component System

Build these as reusable components in `components/marketing/`. Every section of every page composes from this library.

## 8.1 Hero Components

### `<HeroSplit>`
**Props:** eyebrow, headline, subheadline, body, primaryCta, secondaryCta, tertiaryLink, visual, theme ('light' | 'dark' | 'warm')
**Layout:** Two-column 50/50 on desktop, stacks vertical on mobile.
**Variants:**
- Theme `dark` — homepage hero
- Theme `light` — module hero
- Theme `warm` — Parent App hero (warm gradient background)

### `<HeroCentered>`
**Props:** eyebrow, headline, subheadline, body, ctas
**Layout:** Single column, max-width 720px, centred.
**Use:** About, Why Us, Pricing, FAQ heroes.

### `<HeroFullProduct>`
**Props:** headline, subheadline, productScreenshot
**Layout:** Short headline on top, full-bleed product screenshot below.
**Use:** Admin Panel page hero — the screenshot is the proof.

## 8.2 Bento Grid

### `<BentoGrid>`
**Props:** items (array of bento-tile configs with size variants)
**Layout:** 3 or 4 column CSS Grid with `grid-auto-flow: dense`. Tiles vary in size: 1×1, 2×1, 1×2, 2×2.
**Tile types:**
- Stat tile (large number + label)
- Quote tile (testimonial snippet)
- Feature tile (icon + headline + body)
- Product preview tile (screenshot)
- Dark "highlight" tile (inverted colours)
**Visual ref:** Notion homepage bento; Vercel's product features bento.

## 8.3 Dashboard Preview

### `<DashboardPreview>`
**Props:** variant ('admin' | 'analytics' | 'fees' | 'attendance'), animate (boolean)
**Layout:** Mock browser frame (or window chrome) with realistic admin dashboard UI inside.
**Animations (when `animate=true`):**
- Numerals tick up subtly every 4s (random delta ±2 to ±5)
- Chart line redraws on first viewport entry (1.2s stroke animation)
- Subtle pulse on the "live" indicator (green dot, 2s pulse cycle)
- Hover on chart areas reveals tooltips

### `<PhoneMockupFan>`
**Props:** screens (array of 2-3 screen image paths), tilt
**Layout:** 2-3 phone mockups in fanned arrangement, varied tilt angles.
**Hover:** Slight tilt change on individual phones.

### `<PhoneMockupHero>`
**Props:** screen, tilt
**Layout:** Single tilted phone, hero-sized.

### `<AnnotatedScreenshot>`
**Props:** screenshot, hotspots (array of {x, y, label, description})
**Layout:** Product screenshot with numbered hotspots overlaid. Tooltips reveal on hover/tap.
**Mobile:** Hide hotspots; surface as a bulleted feature list below image.

## 8.4 Stats Strip

### `<StatStrip>`
**Props:** stats (array of 4 {value, label}), theme
**Layout:** 4-column horizontal. Large tabular numeral + small uppercase label.
**Animation:** Counter ticks from 0 to value on viewport entry (400ms ease-out-expo).
**Mobile:** 2×2 grid.

## 8.5 Feature Cards & Grids

### `<FeatureGrid4>`
**Props:** items (array of 4 {icon, headline, body})
**Layout:** 2×2 grid on desktop, 1×4 stack on mobile.
**Card style:** Variant A (elevated).

### `<FeatureGrid3>`
**Props:** items (array of 3)
**Layout:** 3-column grid.

### `<FeatureRowAlternating>`
**Props:** rows (array of feature rows with {text, visual, layout: 'left' | 'right'})
**Layout:** Each row alternates layout left/right. 60/40 or 50/50 split inside each row.
**Section-level:** Multiple rows render with consistent vertical padding between them.

### `<ModuleGrid20>`
**Props:** modules (array of 20 {icon, name, capability, slug})
**Layout:** 5×4 grid on desktop, 2-column on mobile.
**Card style:** Variant B (flat) with hover-reveal accent bottom border.

## 8.6 Testimonial Cards

### `<FeaturedTestimonial>`
**Props:** quote, portrait, name, role, institution, ratingStars (optional)
**Layout:** Centred card with large quote mark in brand colour, body quote, portrait + attribution below.
**Card style:** Variant A (elevated) with `padding: 48px`.

### `<TestimonialGrid>`
**Props:** testimonials (array of 3 short quotes with attribution)
**Layout:** 3-column on desktop, 1-column on mobile.

### `<TestimonialCarousel>`
**Props:** testimonials (array of full testimonials)
**Layout:** Horizontal carousel with pagination dots below.
**Auto-advance:** 8s (pause on hover/focus).
**Manual:** Swipe (mobile) + arrow keys + dot navigation.

## 8.7 FAQ Accordion

### `<FAQAccordion>`
**Props:** items (array of {question, answer}), single-expand (boolean, default true)
**Built on:** Radix UI Accordion (a11y-compliant).
**Animation:** Smooth height transition 250ms ease-out-quart. Plus → minus icon rotates 90° over 250ms.

### `<FAQAccordionGrouped>`
**Props:** groups (array of {category, items})
**Layout:** Sticky category headers, accordion items below each.
**Use:** Master FAQ page.

## 8.8 CTA Strips

### `<CTAStripFullWidth>`
**Props:** badge (optional), headline, subheadline, primaryCta, secondaryCta, background ('brand-gradient' | 'dark' | 'accent')
**Layout:** Full-width band, centred content max-width 800px.
**Animation:** Subtle animated dot pattern in background (continuous slow drift).
**Use:** Final section of every page.

### `<CTAInlineBanner>`
**Props:** headline, cta
**Layout:** Compact horizontal banner inside a content section.
**Use:** Breaking up long content sections for re-engagement.

### `<CTANewsletter>`
**Props:** none
**Layout:** Single-line email input + Subscribe button.
**Use:** Footer top strip.

## 8.9 Pricing Cards

### `<PricingTierCard>`
**Props:** tier, price, billing, bestFor, features (array), cta, featured (boolean — adds "Most Popular" badge)
**Layout:** Vertical card, name top, price hero, "best for" descriptor, feature list with checkmarks, CTA at bottom.
**Featured variant:** Brand-amber border, "Most Popular" pill at top.

### `<PricingComparisonTable>`
**Props:** tiers (array), categories (array of {category, features})
**Layout:** Long comparison table with sticky header row on scroll. Mobile: collapses to tier-tab interface.

## 8.10 Workflow / Timeline

### `<WorkflowFlowDiagram>`
**Props:** steps (array of {icon, label, description}), latency (string)
**Layout:** Horizontal flow with animated dot trails between steps.
**Animation:** Dot trails loop continuously. Steps reveal on scroll-into-view.
**Mobile:** Stack vertically with downward arrows.

### `<WorkflowTimeline>`
**Props:** steps (array of {week, title, description})
**Layout:** Horizontal numbered timeline on desktop, vertical on mobile.
**Use:** Onboarding 5-week rollout, migration journey.

### `<CrossSystemFlow>`
**Props:** scenario ('fee-payment' | 'homework' | 'attendance' | 'red-flag')
**Layout:** Three-device horizontal — Admin web | Teacher phone | Parent phone — with animated data flowing between.
**Animation:** Data propagation animation triggered on viewport entry (loops).

## 8.11 App Showcase Block

### `<AppShowcaseBlock>`
**Props:** appName, platform ('google-play' | 'app-store'), screens (array of phone screen images), qrCode, listingCopy
**Layout:** Full-width band. Left: long-form Play Store listing copy. Right: phone mockup composite + Google Play badge + QR code.
**Background:** Premium gradient.
**Use:** Bottom of Parent App and Teacher App pages.

## 8.12 Customer Story Components

### `<CustomerStoryCard>`
**Props:** institutionName, location, outcome, quote, image, slug
**Layout:** Mid-density card with hero image, institution name, outcome stat callout, short quote, "Read story →" link.
**Card style:** Variant A.

### `<CustomerStoryTemplate>`
**Layout for individual customer story pages:** hero image + facts strip + narrative + outcome stats + featured quote + CTAs.

## 8.13 Navigation

### `<NavBar>`
**Behaviour:** Sticky top, translucent over hero (`backdrop-blur(12px)`), solid white after 80px scroll. 64px height.

### `<NavDropdown>`
**Built on:** Radix UI NavigationMenu.
**Hover delay:** 100ms open, 80ms close.

### `<MobileNavDrawer>`
**Behaviour:** Slide-in from left, full viewport height, body-scroll-locked.

### `<StickyMobileBottomBar>`
**Behaviour:** Always visible on mobile. Compresses to icons-only on scroll-down, expands on scroll-up.

## 8.14 Utility Components

### `<EyebrowTag>` — Small uppercase section label
### `<BadgePill>` — Status / category pill (variants: new, beta, popular, free)
### `<Section>` — Section wrapper applying consistent vertical padding + max-width container
### `<Container>` — Max-width wrapper (1280px default; reading=720px)
### `<Tooltip>` — Built on Radix UI Tooltip
### `<Modal>` — Built on Radix UI Dialog
### `<Toast>` — Bottom-right notification
### `<LogoWall>` — 8-10 logos, mono with hover-restore-color

---

# 9. Responsive Design Rules

## 9.1 Mobile-First Strategy

**Build mobile styles first. Progressively enhance for tablet and desktop.**

This is enforced at the code level — Tailwind base classes target mobile; `md:` / `lg:` / `xl:` prefixes enhance.

```
Default (no prefix)  → Mobile (0 - 767px)
md: prefix           → Tablet (768 - 1023px)
lg: prefix           → Laptop (1024 - 1279px) ← primary desktop breakpoint
xl: prefix           → Desktop (1280 - 1535px)
2xl: prefix          → Large desktop (1536+px)
```

## 9.2 Tablet Layout Behaviour

Tablet is a transitional viewport — neither fully mobile nor fully desktop.

| Element | Mobile | Tablet (md) | Desktop (lg) |
|---|---|---|---|
| Hero | Stack vertical | Stack vertical (text top) | Split 50/50 |
| Feature rows | Stack | 60/40 split | 50/50 split |
| Card grids (4 cards) | 1 column | 2×2 | 2×2 (until xl: 4×1) |
| Module grid (20 tiles) | 2 columns | 3 columns | 5 columns |
| Footer | Accordion | 2 columns | 5 columns |
| Sticky bottom CTA | Visible | Hidden (sticky nav handles it) | Hidden |
| Nav | Hamburger drawer | Hamburger drawer | Full horizontal nav |
| Side padding | 24px | 48px | 80px |

## 9.3 Desktop Layout Scaling

Beyond 1280px, content stops growing — outer padding expands.

| Viewport | Container width | Side padding |
|---|---|---|
| 1280px | 1200px (calc) | 40px each side |
| 1440px | 1280px | 80px each side |
| 1920px | 1280px | 320px each side |
| 2560px+ | 1280px | (centred, ample margins) |

This prevents text lines from becoming uncomfortable to read on ultra-wide displays.

## 9.4 Section Spacing Rules

| Viewport | Section vertical padding | Component spacing within section |
|---|---|---|
| Mobile | 64px top + bottom | 24-32px between elements |
| Tablet | 80px top + bottom | 32-40px between elements |
| Desktop | 120px top + bottom | 48-64px between elements |
| Hero (special) | 88/64 mobile, 140/100 desktop | — |

## 9.5 Responsive Typography Logic

Two approaches; pick one and apply consistently:

### Approach A — Discrete breakpoints (recommended for launch)

Use the desktop/mobile values from the type scale (Section 4.3). Switch at `md` breakpoint.

```css
/* Tailwind utility approach */
class="text-4xl md:text-6xl lg:text-7xl"
```

### Approach B — Fluid typography (V2 enhancement)

Use `clamp()` for smooth scaling between breakpoints.

```css
font-size: clamp(36px, 5vw, 72px);
```

**Recommendation:** Launch with Approach A. Migrate to Approach B post-launch if testing shows benefit.

## 9.6 Image Responsive Rules

- All images via `next/image` for automatic responsive serving.
- Specify `sizes` attribute correctly:
  - Hero: `sizes="(max-width: 768px) 100vw, 50vw"`
  - Feature row: `sizes="(max-width: 768px) 100vw, 60vw"`
  - Card grid: `sizes="(max-width: 768px) 100vw, 33vw"`
- Always specify `width` and `height` to prevent CLS.
- Use `priority` only for above-fold images (hero).
- All other images use `loading="lazy"` (default in `next/image`).

## 9.7 Touch Target Minimums

All interactive elements must have a minimum touch target of **44×44px** on mobile (WCAG 2.5.5).

This is critical for:
- Button heights (always min 44px on mobile)
- Card hit areas (use `<a>` wrapping the entire card)
- Icon-only buttons (wrap in 44×44 invisible touch area)
- Nav drawer links (each link minimum 48px tall with padding)

---

# 10. Motion & Interaction System

## 10.1 First Principles

1. Every animation answers "what did this communicate?"
2. Speed beats smoothness. 200ms instant > 600ms graceful.
3. Easing matters more than duration.
4. Scroll animations are revealed, not autoplayed.
5. Respect `prefers-reduced-motion` always.

## 10.2 Standard Durations

```
--motion-fast:        80ms   /* Micro-state transitions: button colour, link underline */
--motion-normal:      150ms  /* Hover lifts, small UI transitions */
--motion-medium:      250ms  /* Modal open, accordion expand */
--motion-slow:        400ms  /* Scroll reveals, large element transitions */
--motion-hero:        600ms  /* Page transitions, hero reveals on load */
```

## 10.3 Standard Easing Curves

```
--ease-out-quart:  cubic-bezier(0.25, 1, 0.5, 1);      /* Default for entries */
--ease-in-quart:   cubic-bezier(0.5, 0, 0.75, 0);      /* Default for exits */
--ease-out-back:   cubic-bezier(0.34, 1.56, 0.64, 1);  /* Small overshoot for delight */
--ease-out-expo:   cubic-bezier(0.16, 1, 0.3, 1);      /* Premium feel — primary easing */
```

**The premium-SaaS feel comes from `--ease-out-expo` on scroll reveals.** Use it as the default.

## 10.4 Hover States

| Element | Behaviour |
|---|---|
| Button (primary) | Background lightens 6% over 80ms. No transform. |
| Button (secondary) | Border colour darkens 8%, fill becomes 4% tint over 80ms. |
| Card (elevated) | Translate-Y `-4px`, shadow expands from `0 1px 2px` to `0 8px 24px`, 250ms ease-out-expo. |
| Link | Right-arrow translates `+2px` over 150ms. Underline animates from 0% to 100% width (left-anchored). |
| Module tile | Translate-Y `-4px`, accent-color bottom border slides in from left (300ms ease-out-expo). |
| Phone mockup | Subtle tilt-change (rotate from -5deg to 0deg), 400ms ease-out-expo. |
| Logo (in logo wall) | Greyscale to colour, 150ms. |
| Nav dropdown trigger | 100ms delay before opening (prevents accidental triggers). |

## 10.5 Scroll Reveals

**Trigger:** IntersectionObserver, `threshold: 0.2` (element 20% in viewport).
**Properties:** `opacity 0 → 1`, `transform translateY(24px) → translateY(0)`.
**Duration:** 400ms.
**Easing:** ease-out-expo.
**Stagger:** When animating children, stagger by 80–120ms.
**Run once:** Never re-trigger on scroll-back.

Implementation pattern (Framer Motion):
```ts
const variants = {
  hidden: { opacity: 0, y: 24 },
  visible: (i: number) => ({
    opacity: 1,
    y: 0,
    transition: { duration: 0.4, ease: [0.16, 1, 0.3, 1], delay: i * 0.08 }
  })
}
```

## 10.6 Counter Animations

Stat numerals tick from 0 to target on viewport entry.
- Duration: 400ms.
- Easing: ease-out-expo.
- Tabular numerals required (prevents layout shift).
- Run once per page session.

## 10.7 Navbar Behaviour

```
State 1 (over hero):
  background: transparent
  backdrop-filter: blur(12px)
  border-bottom: 1px solid rgba(255,255,255,0.1)
  text colour: white

State 2 (after 80px scroll):
  background: rgba(255,255,255,0.96)
  backdrop-filter: blur(12px)
  border-bottom: 1px solid #E2E8F0
  text colour: dark
  
Transition: 200ms ease-out-quart for all properties
```

## 10.8 Sticky CTA Behaviour

### Desktop sticky banner
- Triggers at 30% scroll depth
- Slides down from top (250ms ease-out-expo)
- Contains "Book a Demo" + dismiss `×`
- Hides at 95% scroll (before footer)
- Dismissal persists in `sessionStorage`

### Mobile sticky bottom bar
- Always visible
- On scroll-down (page scrolling up = user reading): full pill view ("Book Demo" + icons)
- On scroll-up (page scrolling down = user navigating): collapses to icons-only
- Transition: 200ms ease-out-quart
- z-index: above page content, below modals

## 10.9 Dashboard "Live" Transitions

Dashboard previews on marketing pages should feel alive without being distracting.

- Numerals pulse-update every 4 seconds with tiny random delta (-2 to +5).
- Chart line strokes animate in on first viewport entry (1.2s draw).
- "Live" indicator (green dot): 2s pulse cycle (scale 1.0 → 1.1 → 1.0).
- Pause all animations when not in viewport (performance optimisation).

## 10.10 Microinteractions

| Element | Animation |
|---|---|
| Tooltip appear | Fade + scale-up from 0.95, 150ms ease-out-quart |
| Toggle/switch | 200ms ease-out-back (small overshoot delight) |
| Input focus | Border colour transition, ring scales from 0 to 4px, 150ms |
| Accordion expand | Height auto-animate, 250ms ease-out-quart. Icon rotates 90° simultaneously |
| Dropdown menu | Fade + slide-down from -8px, 200ms ease-out-quart |
| Modal open | Backdrop fades in (150ms), modal scales from 0.96 to 1.0 with fade (250ms ease-out-expo) |
| Toast appear | Slide up from bottom-right, fade in, 250ms |
| Form submit success | Button text replaces with checkmark + green pulse, 600ms |

## 10.11 Anti-Patterns (Forbidden)

- ❌ Parallax that moves >0.2× scroll rate (motion sickness)
- ❌ Auto-playing carousels without user control (a11y violation)
- ❌ Animations longer than 600ms outside of dashboard "live" elements
- ❌ Spring animations with high bounce (gimmicky)
- ❌ Animated GIF backgrounds
- ❌ Cursor-following decorative elements
- ❌ Scroll-jacking / scroll-locking sections
- ❌ Hover-only critical information (mobile won't see it)

## 10.12 Reduced Motion

When user has `prefers-reduced-motion: reduce`:
- Disable all entrance animations (set duration to 0)
- Disable parallax
- Disable auto-advance carousels (require manual interaction)
- Keep functional animations (modal open, accordion expand) but reduce duration to 100ms
- Keep dashboard data updates (these are informational, not decorative)

---

# 11. Frontend Engineering Recommendations

## 11.1 Stack

| Layer | Choice | Rationale |
|---|---|---|
| Framework | **Next.js 14+ (App Router)** | RSC, file routing, ISR, image optimisation, Vercel-hostable. Industry standard for marketing SaaS. |
| Language | **TypeScript (strict)** | Non-negotiable for a team product. `strict: true`, `noUncheckedIndexedAccess: true` |
| Styling | **Tailwind CSS v3.4+** | Co-located styling, design-system enforceable via `tailwind.config.ts`. |
| UI primitives | **Radix UI** | Headless, accessible primitives for accordion, dialog, tooltip, dropdown, navigation menu. |
| Animation | **Framer Motion** | Best React animation library; scroll reveals, page transitions. |
| Icons | **Lucide React** | Open-source, monoline, ~1,400 icons. |
| Forms | **React Hook Form + Zod** | Best-in-class form handling + schema validation. |
| CMS (V2) | **Sanity** or **Contentful** | For blog, customer stories, changelog. Headless. |
| Analytics | **Plausible** (privacy-respecting, lightweight) + **PostHog** (deeper funnel analytics) | Plausible for marketing, PostHog for product. |
| A/B testing | **PostHog Experiments** | Hero copy, CTA testing. |
| Hosting | **Vercel** | Best for Next.js. ISR + edge functions + analytics. |
| DNS/CDN | **Cloudflare** | DDoS protection + edge caching. |
| Error monitoring | **Sentry** | Standard. |
| Performance CI | **Lighthouse CI** | Fails build below score thresholds. |
| Demo booking | **Calendly embed** | V2: migrate to **Cal.com** for branding control. |

## 11.2 Next.js App Router Folder Structure

```
app/
├── layout.tsx                       Root layout — global nav, footer, theme
├── page.tsx                         Homepage
├── globals.css                      Tailwind directives + CSS variables
│
├── (marketing)/                     Route group
│   ├── platform/page.tsx
│   ├── admin-panel/page.tsx
│   ├── teacher-app/page.tsx
│   ├── parent-app/page.tsx
│   │
│   ├── modules/
│   │   ├── page.tsx                Index
│   │   ├── _data.ts                Static module config
│   │   └── [slug]/page.tsx         Dynamic module page
│   │
│   ├── solutions/
│   │   ├── page.tsx
│   │   ├── _data.ts
│   │   └── [slug]/page.tsx
│   │
│   ├── pricing/page.tsx
│   ├── customers/
│   │   ├── page.tsx
│   │   └── [slug]/page.tsx
│   │
│   ├── resources/
│   │   ├── page.tsx
│   │   ├── blog/
│   │   │   ├── page.tsx
│   │   │   └── [slug]/page.tsx
│   │   ├── webinars/page.tsx
│   │   └── changelog/page.tsx
│   │
│   ├── about/page.tsx
│   ├── why-us/page.tsx
│   ├── security/page.tsx
│   ├── faq/page.tsx
│   ├── contact/page.tsx
│   ├── demo/page.tsx
│   ├── careers/
│   │   ├── page.tsx
│   │   └── [slug]/page.tsx
│   └── press/page.tsx
│
├── (legal)/                         Route group with simpler layout
│   ├── layout.tsx
│   ├── terms/page.tsx
│   ├── privacy/page.tsx
│   ├── cookies/page.tsx
│   ├── refund-policy/page.tsx
│   ├── dpa/page.tsx
│   ├── subprocessors/page.tsx
│   └── accessibility/page.tsx
│
├── api/
│   ├── contact/route.ts             Contact form
│   ├── newsletter/route.ts          Newsletter signup
│   ├── lead/route.ts                Lead capture (sales pipeline)
│   └── webhook/calendly/route.ts    Calendly webhook → CRM
│
├── sitemap.ts                       Dynamic sitemap
├── robots.ts                        robots.txt
├── manifest.ts                      PWA manifest
└── opengraph-image.tsx              Default OG image template
```

## 11.3 Components Folder Structure

```
components/
├── ui/                              Primitive components (button, input, accordion)
│   ├── Button.tsx
│   ├── Card.tsx
│   ├── Accordion.tsx
│   ├── Tooltip.tsx
│   ├── Modal.tsx
│   ├── Badge.tsx
│   └── ...
│
├── marketing/                       Marketing-specific composed blocks
│   ├── heroes/
│   │   ├── HeroSplit.tsx
│   │   ├── HeroCentered.tsx
│   │   └── HeroFullProduct.tsx
│   ├── feature/
│   │   ├── FeatureGrid4.tsx
│   │   ├── FeatureGrid3.tsx
│   │   ├── FeatureRowAlternating.tsx
│   │   ├── BentoGrid.tsx
│   │   └── ModuleGrid20.tsx
│   ├── proof/
│   │   ├── LogoWall.tsx
│   │   ├── StatStrip.tsx
│   │   ├── FeaturedTestimonial.tsx
│   │   ├── TestimonialGrid.tsx
│   │   └── TestimonialCarousel.tsx
│   ├── workflows/
│   │   ├── WorkflowFlowDiagram.tsx
│   │   ├── WorkflowTimeline.tsx
│   │   └── CrossSystemFlow.tsx
│   ├── pricing/
│   │   ├── PricingTierCard.tsx
│   │   └── PricingComparisonTable.tsx
│   ├── cta/
│   │   ├── CTAStripFullWidth.tsx
│   │   ├── CTAInlineBanner.tsx
│   │   └── CTANewsletter.tsx
│   ├── faq/
│   │   ├── FAQAccordion.tsx
│   │   └── FAQAccordionGrouped.tsx
│   ├── dashboards/
│   │   ├── DashboardPreview.tsx
│   │   ├── PhoneMockupFan.tsx
│   │   ├── PhoneMockupHero.tsx
│   │   └── AnnotatedScreenshot.tsx
│   ├── customer/
│   │   ├── CustomerStoryCard.tsx
│   │   └── CustomerStoryTemplate.tsx
│   └── app-showcase/
│       └── AppShowcaseBlock.tsx
│
├── navigation/
│   ├── NavBar.tsx
│   ├── NavDropdown.tsx
│   ├── MobileNavDrawer.tsx
│   ├── Footer.tsx
│   └── StickyMobileBottomBar.tsx
│
├── forms/
│   ├── ContactForm.tsx
│   ├── NewsletterInline.tsx
│   └── DemoBookingEmbed.tsx
│
└── shared/
    ├── EyebrowTag.tsx
    ├── BadgePill.tsx
    ├── Section.tsx                  Section wrapper with consistent padding
    ├── Container.tsx                Max-width wrapper
    └── ReducedMotionWrapper.tsx     Respects user motion preferences
```

## 11.4 Tailwind Configuration

Copy-paste ready:

```ts
// tailwind.config.ts
import type { Config } from 'tailwindcss'

const config: Config = {
  content: ['./app/**/*.{ts,tsx}', './components/**/*.{ts,tsx}'],
  theme: {
    extend: {
      colors: {
        brand: {
          DEFAULT: '#0F766E',
          hover: '#0D6B63',
          pressed: '#0A5853',
        },
        accent: {
          DEFAULT: '#F59E0B',
          hover: '#D97706',
        },
        admin: '#4338CA',
        teacher: '#0F766E',
        parent: '#B45309',
        ink: {
          1: '#0A0F1C',
          2: '#111827',
          3: '#1E293B',
        },
      },
      fontFamily: {
        sans: ['var(--font-sans)', 'system-ui', 'sans-serif'],
        mono: ['var(--font-mono)', 'monospace'],
      },
      fontSize: {
        'hero':     ['72px', { lineHeight: '1.05', letterSpacing: '-0.02em', fontWeight: '600' }],
        'display':  ['56px', { lineHeight: '1.1',  letterSpacing: '-0.02em', fontWeight: '600' }],
        'h1':       ['48px', { lineHeight: '1.15', letterSpacing: '-0.02em', fontWeight: '600' }],
        'h2':       ['36px', { lineHeight: '1.2',  letterSpacing: '-0.01em', fontWeight: '600' }],
        'h3':       ['28px', { lineHeight: '1.3',  letterSpacing: '0',       fontWeight: '600' }],
        'h4':       ['22px', { lineHeight: '1.4',  letterSpacing: '0',       fontWeight: '600' }],
        'lead':     ['20px', { lineHeight: '1.55', letterSpacing: '0',       fontWeight: '400' }],
        'body':     ['17px', { lineHeight: '1.7',  letterSpacing: '0',       fontWeight: '400' }],
        'stat':     ['48px', { lineHeight: '1.0',  letterSpacing: '-0.02em', fontWeight: '600' }],
        'stat-lg':  ['64px', { lineHeight: '1.0',  letterSpacing: '-0.02em', fontWeight: '600' }],
        'eyebrow':  ['12px', { lineHeight: '1.0',  letterSpacing: '0.08em',  fontWeight: '600' }],
      },
      maxWidth: {
        container: '1280px',
        reading: '720px',
      },
      transitionTimingFunction: {
        'out-expo': 'cubic-bezier(0.16, 1, 0.3, 1)',
        'out-back': 'cubic-bezier(0.34, 1.56, 0.64, 1)',
        'out-quart': 'cubic-bezier(0.25, 1, 0.5, 1)',
      },
      transitionDuration: {
        '80': '80ms',
        '250': '250ms',
        '400': '400ms',
        '600': '600ms',
      },
      backgroundImage: {
        'gradient-cta': 'linear-gradient(135deg, #0F766E 0%, #1E3A8A 100%)',
        'gradient-warm': 'linear-gradient(180deg, #FEF3C7 0%, #FFFFFF 100%)',
      },
    },
  },
  plugins: [
    require('@tailwindcss/typography'),
    require('@tailwindcss/forms'),
  ],
}

export default config
```

## 11.5 SEO Implementation

- Build a typed `<Metadata>` helper for consistent per-page metadata.
- Implement `app/sitemap.ts` to dynamically generate sitemap from page registry.
- Implement `app/robots.ts` for environment-aware robots.
- Schema.org JSON-LD in page heads (Organisation, FAQPage, MobileApplication, Product, Review).
- Per-page meta titles ≤60 chars, descriptions ≤155 chars.
- All images have meaningful alt text (lint rule enforced).
- Single `<h1>` per page, hierarchy consistent.
- Canonical URLs via `<link rel="canonical">`.
- OG image generation via `@vercel/og` for dynamic OG images per page.

## 11.6 Performance Strategy

| Metric | Target | Strategy |
|---|---|---|
| LCP | < 1.8s | Static generation + image optimisation + critical CSS inline + preload hero font |
| INP | < 100ms | Minimal client JS; RSC by default; islands for interactivity |
| CLS | < 0.05 | Reserve dimensions for images, fonts, embeds |
| FCP | < 1.2s | Preload hero font + hero image |
| TBT | < 200ms | Code-split aggressively; lazy-load below-fold |
| Lighthouse | ≥ 95 all 4 categories | CI gate via Lighthouse CI |

Specific techniques:
- Static site generation for all marketing pages (ISR with `revalidate: 3600` for stats).
- `next/image` for every image; `priority` on hero only.
- `next/font` self-hosted (Inter + JetBrains Mono).
- React Server Components by default; `"use client"` only for interactive components.
- Code-split heavy components via `next/dynamic`.
- Preload hero assets in `<head>`.
- Inline critical CSS for above-fold.
- Defer non-critical scripts (analytics, Calendly, pixels) until `onLoad`.
- Image format: AVIF first, WebP fallback.
- Edge caching via Vercel (HTML 1h, static immutable).
- Bundle analysis in CI; fail if main bundle exceeds 200KB gzipped.

## 11.7 Image Optimisation

- All images via `next/image`.
- Specify `width`, `height`, and `sizes` always.
- `priority` only for above-fold hero images.
- AVIF + WebP via `next/image` (automatic).
- Lazy-load below-fold (default).
- Use `placeholder="blur"` for hero images with `blurDataURL`.
- Image source: 2× resolution for retina (Next.js handles sizing).
- For product screenshots: maintain a `/public/screenshots/` directory with consistent naming (`admin-dashboard-1.webp`, `parent-fees-payment.webp`).

## 11.8 Animation Libraries

- **Framer Motion** as primary animation library (scroll reveals, page transitions, complex interactions).
- **CSS transitions** for simple state changes (hover, focus).
- **Lottie** ONLY for sophisticated illustrations (logo intros, complex multi-frame animations). Avoid Lottie for simple animations.
- **No GIF backgrounds** ever.

Framer Motion patterns:
- `motion.div` + `whileInView` for scroll reveals
- `motion.div` + `whileHover` for hover lifts
- `AnimatePresence` for modal/drawer enter/exit
- `LayoutGroup` for shared-layout transitions
- Use `viewport={{ once: true }}` to avoid re-triggering scroll animations

## 11.9 Form Implementation

- React Hook Form for state.
- Zod for schema validation.
- Server actions (Next.js 14 App Router) for form submission.
- Honeypot field for spam (`name="website"`, hidden, must be empty).
- reCAPTCHA v3 (invisible) on contact forms.
- Toast notifications for submission feedback.
- Email delivery via Resend or SendGrid.
- CRM webhook on success (Pipedrive / HubSpot / Salesforce).

## 11.10 Accessibility (a11y)

Target: **WCAG 2.1 AA**, non-negotiable.

- Semantic HTML5 (`<nav>`, `<main>`, `<article>`, `<section>`).
- All interactive elements keyboard-accessible (Tab navigation works site-wide).
- Focus-visible rings on all focusable elements (`2px` ring offset, brand-primary at 30% opacity).
- ARIA labels on icon-only buttons.
- Colour contrast ≥4.5:1 for body text, ≥3:1 for large headings.
- `prefers-reduced-motion` respected (Section 10.12).
- All images have meaningful `alt` text; decorative get `alt=""`.
- Form errors announced via ARIA live regions.
- Skip-to-content link at top of every page.
- Test with axe-core in CI; manual screen-reader testing pre-launch (NVDA, VoiceOver).

---

# 12. Design References

For every major section pattern, the SaaS sites pioneering or perfecting it — and why the pattern converts.

## 12.1 Hero Patterns

| Site | Pattern | Why It Works | Our Application |
|---|---|---|---|
| **Stripe** | Split hero with product composite right | Visitor scans text 1.2s then validates with visual. Eye-path matches reading direction. | Homepage hero (Section 2) |
| **Linear** | Tight typographic hero, minimal visual | Type-led heroes convey confidence; less is more for sophisticated buyers. | Module page heroes |
| **Notion** | Centred editorial hero with serif | Centred = "this is important." Serif = "we take ourselves seriously." | Optional V2 — About / Mission page |
| **Apple product pages** | Full-bleed product screenshot below short headline | When the product is the proof, lead with the product. | Admin Panel page (Section 7.1) |
| **Vercel** | Animated grid/dots background with simple text | Subtle motion signals "modern, alive" without distracting. | Hero background pattern |

## 12.2 Trust Bars

| Site | Pattern | Why It Works |
|---|---|---|
| **Stripe, Vercel, Segment** | Greyscale logo wall, no colour | Greyscale signals "we don't shout." Colour logos signal "early-stage app showing off." For enterprise, greyscale converts better. |
| **Linear, Vercel** | Counter-tick stats on scroll | Numbers ticking up create a 400ms moment of attention. Signals "live and measured." |
| **Cloudflare** | Combined logo + numerical credibility above-fold | Layered trust signals compound. |

**Our application:** Section 3 (Trust Bar) uses both — greyscale logos + ticking stats.

## 12.3 Feature Sections

| Site | Pattern | Why It Works |
|---|---|---|
| **Notion** | Alternating feature rows with product imagery | Forces eye to re-engage each section. Identical-layout sections become hypnotic. |
| **Linear** | Asymmetric feature grid (bento) | Varied tile sizes give visual rhythm. Forces visitor to slow down. |
| **Stripe** | Long-form product page with section anchors | Heavy detail builds credibility for enterprise evaluators. |
| **Figma** | Tabbed product showcase | Compresses multiple product faces into one screen. |
| **Webflow** | Animated step-by-step workflow | Animation makes abstract claims tangible. |

**Our application:**
- Alternating feature rows: All product pages
- Bento grid: Homepage Section 7 variant, Multi-Role Dashboards
- Step workflow: Real-Time Sync Engine (Section 6) — highest-stakes section

## 12.4 Dashboard Previews

| Site | Pattern | Why It Works |
|---|---|---|
| **Linear** | Real product UI in marketing — pixel-perfect, animated subtly | Visitors see exactly what they'll get. Builds trust. |
| **Vercel** | Dashboard preview with live-feel counters | "Live" feel signals modern infrastructure. |
| **Notion** | Templates and demos shown as actual product | Removes ambiguity about what the product looks like. |
| **Stripe** | Code snippets that look like real code (not stylised) | Authenticity over polish. |

**Our application:** Sections 8 (Admin Spotlight) and 12 (Analytics) use real product UI screenshots with annotated hotspots and live-feel animations.

## 12.5 Pricing

| Site | Pattern | Why It Works |
|---|---|---|
| **Linear** | 3-tier card grid with "Most Popular" middle | Forces Goldilocks decision-making. Amber badge on middle tier increases its selection 15–20%. |
| **Notion** | Long detailed comparison table below tier cards | Enterprise evaluators (procurement, IT) need detail; cards alone feel evasive. |
| **Vercel** | "Get pricing" CTA for Enterprise (no public Enterprise price) | Enterprise prices vary; hiding them is fine and signals "we customise." |

**Our application:** Pricing page has both — 3-tier featured cards + long comparison table + "Get Custom Pricing" for Enterprise tier.

## 12.6 Testimonials

| Site | Pattern | Why It Works |
|---|---|---|
| **Linear, Stripe, Webflow** | Featured large quote + grid of smaller quotes | One quote anchors weight; the grid signals breadth. Together feels substantial. |
| **Shopify** | Customer story card carousel | Implies "many more stories than fit on screen." Drives click-through. |
| **Notion** | Inline testimonials within feature sections | Contextual social proof — quote relates to the feature you just read about. |

**Our application:** Section 16 (featured + grid), Section 17 (carousel), and inline testimonials on product pages.

## 12.7 Final CTAs

| Site | Pattern | Why It Works |
|---|---|---|
| **Notion** | Full-width coloured band with strong gradient | Background change re-engages visitor after long scroll. |
| **Linear** | Two-CTA stack (primary + secondary) | Single CTA forces binary; two allows softer-yes (download, watch). |
| **Stripe** | "Start building" + "Talk to sales" pair | Self-serve + sales-led parallel paths. |

**Our application:** Final CTA strip (Section 19) on every page.

## 12.8 FAQ Patterns

| Site | Pattern | Why It Works |
|---|---|---|
| **Stripe** | Accordion with single-expand | Single-expand keeps section compact, prevents list fatigue. |
| **Notion** | Categorised accordion on long FAQ pages | Helps visitor self-serve when there are many questions. |

**Our application:** Section 18 (homepage FAQ) and `/faq` page.

---

# 13. Final Website Build Order

## 13.1 Tiered Page Priority

### Tier 1 — Launch Critical (Weeks 1–6)

**Goal:** Functional, conversion-ready public website. 14 pages.

| Priority | Page | Reasoning |
|---|---|---|
| 1 | Homepage | Highest-traffic page; primary conversion source |
| 2 | Pricing | Most direct conversion driver |
| 3 | Contact + Demo booking | Conversion completion |
| 4 | Admin Panel | Primary B2B buyer page |
| 5 | Parent App | Public adoption + trust signal |
| 6 | Teacher App | Staff buy-in + trust signal |
| 7 | About | Trust foundation |
| 8 | Why Us | Differentiation; paid-ad landing destination |
| 9 | Security | Enterprise procurement requirement |
| 10 | FAQ | Sales support + SEO |
| 11–13 | Top 3 module pages (Fees, Attendance, Admissions) | Highest-search-volume modules; SEO drivers |
| 14 | Legal pages (Terms, Privacy, Cookies, DPA) | Compliance |

### Tier 2 — Post-Launch (Weeks 7–14)

**Goal:** Complete content depth, more SEO coverage. ~15 pages.

- Remaining 10 module pages (long-tail SEO)
- 4 industry / solutions pages (vertical paid-ad destinations)
- Customer Stories index + 2–3 individual stories
- Platform overview (`/platform`)
- Resources index + initial blog
- Careers + Press

### Tier 3 — Growth (Months 4–6)

- Blog content engine — 2 posts/week
- 5–10 more customer stories
- Webinar landing pages
- Help Center / Knowledge Base
- Comparison pages vs specific competitors
- API documentation (after API launch)
- Localisation (Hindi first)

## 13.2 MVP Launch Timeline (6 Weeks)

### Week 1–2: Foundation
- Next.js + Tailwind + Radix scaffolding
- Design system implementation (tokens, base components)
- Nav + footer + sticky CTAs
- Component library (heroes, cards, CTAs, stat strips, feature grids)
- Sitemap, robots, base SEO infrastructure
- Vercel deployment + Cloudflare DNS + analytics setup

### Week 3–4: Hero Pages
- Homepage (all 19 sections)
- Pricing page
- Contact page + form backend
- Demo booking integration (Calendly)

### Week 5: Product Pages
- Admin Panel
- Parent App
- Teacher App

### Week 6: Trust + Coverage Pages + Launch
- About, Why Us, Security, FAQ
- Top 3 module pages (Fees, Attendance, Admissions)
- Legal pages
- QA, performance audit, a11y audit, Lighthouse CI gates
- **LAUNCH**

## 13.3 Critical Sections (Build Order Within Each Page)

For every page, build sections in this order to enable parallel work and incremental review:

1. **Hero** (most-seen, sets quality bar — build first)
2. **Final CTA strip** (page-ending consistency)
3. **Trust signals** (stats, logos, testimonials)
4. **Main content sections** (alternating feature rows)
5. **Supporting sections** (FAQ, related links)
6. **Polish pass** (animations, micro-interactions)

## 13.4 What to Delay to V2

Resist scope creep. Defer:

| Feature | Defer Because |
|---|---|
| Multi-language (Hindi, Tamil, Telugu) | Launch in English; add Hindi after product-market fit signals |
| Self-serve signup flow | Pilot via sales-led; self-serve at scale (Year 2) |
| Blog content engine | Need 2 posts/week to be credible; don't launch empty |
| Help Center / Knowledge Base | Launch from existing-customer demand, not assumption |
| API documentation | API doesn't exist yet (2027 roadmap) |
| Comparison pages vs specific competitors | Need legal review per vendor |
| Webinar registration system | Use third-party (Zoom + Mailchimp) until volume justifies |
| Live chat widget | Defer; rely on demo + WhatsApp + email until support volume justifies |
| Interactive product demos (scrollytelling) | Phase 3 production once base is performing |
| Trust Center dedicated page | Use Security page until enterprise sales pipeline justifies |
| Dark mode | Marketing sites live in light mode; defer unless analytics demand it |
| Investor / fundraising page | Only if raising capital — keep private otherwise |
| Self-serve onboarding | Sales-led for now |

## 13.5 Launch Quality Gates (Non-Negotiable)

Do not launch until all of these pass:

- ✅ Lighthouse score ≥95 on all four categories, all Tier-1 pages
- ✅ Mobile usability tested on 5+ real devices (range: low-end Android to flagship)
- ✅ Cross-browser tested: Chrome, Safari, Firefox, Edge
- ✅ Accessibility: axe-core clean + manual screen-reader pass on Homepage, Pricing, Contact
- ✅ All forms tested end-to-end with real email delivery
- ✅ All CTAs route correctly (no dead clicks)
- ✅ 404 page designed and tested
- ✅ 500 error page designed and tested
- ✅ Page-load time < 2s on simulated 4G
- ✅ Sitemap submitted to Google Search Console
- ✅ Schema.org validated via Google's Rich Results Test
- ✅ OG tags validated via Facebook Sharing Debugger and Twitter Card Validator
- ✅ Analytics tracking confirmed firing on all key events
- ✅ Privacy policy, Terms, DPA reviewed by legal counsel
- ✅ Cookie consent functional and DPDP-compliant
- ✅ Demo booking integration tested end-to-end
- ✅ Three senior stakeholders signed off

## 13.6 First 30 Days Post-Launch

| Week | Focus |
|---|---|
| **Week 1** | Daily Lighthouse, error monitoring, hotfix any critical bugs. Watch funnel analytics. |
| **Week 2** | Begin A/B test on hero headline (control vs variant). Watch CTA click rates. |
| **Week 3** | Ship first customer story. Begin blog content cadence. |
| **Week 4** | Review funnel data with sales team. Identify highest-drop-off page; iterate. |

---

# Closing Note

This document specifies enough for a senior frontend team to begin Tier 1 build immediately. The remaining work — Figma design files, brand identity finalisation, photography commissioning, copywriter polish on Tier-2 pages, content engine standup — runs in parallel with engineering.

**Strategic stance:**

Build Tier 1 in six weeks. Launch. Iterate against real conversion data, not assumptions. Tier 2 ships as a continuous stream over the next eight weeks. **Treat the website as a product, not a project.** The first version is the worst version.

---

## Engineering Quick-Start Checklist

For the team to begin work immediately:

1. ☐ Create GitHub repo and Vercel project
2. ☐ Initialise Next.js 14 with TypeScript strict mode
3. ☐ Install: Tailwind, Radix UI, Framer Motion, Lucide React, React Hook Form, Zod
4. ☐ Set up `tailwind.config.ts` with tokens from Section 11.4
5. ☐ Set up `next/font` for Inter + JetBrains Mono
6. ☐ Build the base layout: `<NavBar>`, `<Footer>`, `<StickyMobileBottomBar>`
7. ☐ Build base UI primitives: `<Button>`, `<Card>`, `<Container>`, `<Section>`
8. ☐ Build hero components: `<HeroSplit>`, `<HeroCentered>`
9. ☐ Build the homepage (Section 6 of this document)
10. ☐ Set up Lighthouse CI with score thresholds
11. ☐ Set up axe-core in CI
12. ☐ Set up Plausible analytics
13. ☐ Set up Sentry error monitoring
14. ☐ Configure Calendly demo booking integration

The remainder of the build flows naturally from here.

---

**End of handoff document.**
