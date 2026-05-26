# Phase 5 — Website System Architecture Blueprint

**Project:** [Brand] — The Operating System for Modern Schools
**Document Type:** Strategic Frontend Blueprint — Build-Ready
**Audience:** Frontend engineering team, design system team, conversion ops, leadership
**Intent:** Translate the content phases (1–4) into a production-grade website architecture, in the quality lineage of Stripe, Linear, Notion, Vercel, and Framer.

**This document does not generate marketing prose, HTML, or React.** It is the architectural specification a senior frontend team uses to build the production website.

---

## Document Map

1. Full Sitemap & Navigation Architecture
2. Landing Page Hierarchy & Conversion Flow
3. Product Architecture
4. Design System Direction
5. UI Component Inventory
6. Homepage Wireframe (Section-by-Section)
7. Product Page Templates
8. Modern SaaS Visual References (with rationale)
9. Motion & Animation Direction
10. Frontend Engineering Recommendations
11. Conversion Optimisation Layer
12. Final Build Recommendation & Launch Strategy

---

# 1. Full Sitemap & Navigation Architecture

## 1.1 URL Architecture (Canonical)

The site is structured around five top-level concerns: **product, modules, solutions, resources, company**. Pricing and Contact sit outside the dropdown hierarchy and are first-class top-level links.

```
/                                       Homepage
├── /platform                           Platform overview (umbrella)
├── /admin-panel                        Admin Panel product page
├── /teacher-app                        Teacher App product page
├── /parent-app                         Parent App product page
│
├── /modules                            Modules index
│   ├── /modules/admissions
│   ├── /modules/student-lifecycle
│   ├── /modules/academic-management
│   ├── /modules/timetable
│   ├── /modules/attendance
│   ├── /modules/examinations
│   ├── /modules/fees
│   ├── /modules/hr-payroll
│   ├── /modules/communication
│   ├── /modules/transport
│   ├── /modules/library
│   ├── /modules/analytics
│   └── /modules/reports
│
├── /solutions                          Solutions index
│   ├── /solutions/k12-schools
│   ├── /solutions/colleges
│   ├── /solutions/coaching-institutes
│   ├── /solutions/educational-groups
│   └── /solutions/international-schools     (V2)
│
├── /pricing                            Pricing tiers + comparison
├── /customers                          Customer stories index
│   └── /customers/[story-slug]
│
├── /resources                          Resources index
│   ├── /resources/help-center
│   ├── /resources/webinars
│   ├── /resources/playbooks
│   ├── /resources/blog
│   ├── /resources/blog/[post-slug]
│   ├── /resources/changelog
│   └── /resources/migration-guide
│
├── /company                            Company umbrella
│   ├── /about
│   ├── /why-us
│   ├── /security
│   ├── /careers
│   │   └── /careers/[role-slug]
│   ├── /press
│   ├── /partners
│   └── /contact
│
├── /faq
├── /demo                               Direct demo booking page
├── /login                              Existing customer login redirect
├── /signup                             Self-serve waitlist (V2)
│
├── /legal/terms
├── /legal/privacy
├── /legal/cookies
├── /legal/refund-policy
├── /legal/dpa                          Data Processing Agreement
├── /legal/subprocessors
└── /legal/accessibility
```

**Routing conventions:**
- Kebab-case slugs.
- No file extensions in URLs.
- Trailing slash policy: **no trailing slashes** (cleaner, matches Stripe / Linear / Vercel).
- Locale prefix reserved for V2 (`/hi/`, `/ta/`, `/te/`) — English-only for V1.

## 1.2 Primary Navigation (Desktop)

The desktop nav is a single fixed bar, height 64px, with a single dropdown level. Avoid mega-menus at launch; modules and solutions get rich dropdowns; everything else is a direct link.

**Layout structure (left → right):**

```
[Logo]    [Platform ▾] [Modules ▾] [Solutions ▾] [Pricing] [Customers] [Resources ▾]    [Sign In] [Book a Demo →]
```

**Dropdown contents:**

### Platform ▾
A two-column dropdown panel.

| Left column (Products) | Right column (Featured) |
|---|---|
| **Admin Panel** — Web command centre for administration | **The ecosystem at a glance** — short product overview |
| **Teacher App** — Built for the classroom | **Real-time sync** — Engine overview |
| **Parent App** — Your child's school day, live | **Security & infrastructure** — Trust posture |

### Modules ▾
A three-column dropdown panel, ~13 module links grouped by domain.

| Academic | Operations | Finance & HR |
|---|---|---|
| Admissions | Attendance | Fees Management |
| Student Lifecycle | Communication | Accounting |
| Academic Management | Transport | HR & Payroll |
| Timetable | Library | Analytics |
| Examinations | Reports | |

### Solutions ▾
A two-column dropdown.

| Left (Audience) | Right (CTA) |
|---|---|
| K-12 Schools | Featured customer story — link |
| Colleges & Higher Ed | "Talk to our solutions team" CTA |
| Coaching Institutes | |
| Educational Groups | |

### Resources ▾
Single-column dropdown.

- Help Center
- Webinars
- Operational Playbooks
- Blog
- Changelog
- Migration Guide

## 1.3 Secondary / Top-Right Navigation

- **Sign In** — Text link in nav, opens a login chooser modal (Admin / Teacher / Parent → routes to respective surface).
- **Book a Demo** — Primary CTA button, always visible, accent-colour fill.

## 1.4 Mobile Navigation

Mobile nav is a slide-in drawer (full-screen overlay) triggered by a hamburger icon at top-left. Drawer contents:

```
[Brand logo]                                       [×]
─────────────────────────────────────────
Platform
  Admin Panel
  Teacher App
  Parent App
─────────────────────────────────────────
Modules (collapsible accordion)
  Academic — Admissions, Student Lifecycle, …
  Operations — Attendance, Communication, …
  Finance & HR — Fees, Accounting, HR, …
─────────────────────────────────────────
Solutions
  K-12 Schools
  Colleges
  Coaching
  Educational Groups
─────────────────────────────────────────
Pricing
Customers
Resources
About
Contact
─────────────────────────────────────────
[Book a Demo] (sticky bottom of drawer)
[Sign In →]
[WhatsApp icon]
```

**Mobile nav rules:**
- Drawer height: 100vh.
- Background: solid (no transparency — avoid behind-content bleed).
- Scroll-locked when open (prevents background scroll).
- Close on route change.
- Hamburger icon transforms to `×` when open (Framer Motion micro-animation).

## 1.5 Footer Navigation

Already specified in Phase 4 (Part 8). Structural summary:

- **5-column grid** desktop: Brand / Product / Solutions / Resources / Company
- **Newsletter strip** above the grid
- **App download strip** above the grid: Google Play badge + iOS coming-soon
- **Legal bar** below: copyright, GSTIN, terms/privacy/cookies/refund/DPA/subprocessors
- **Region/Language selector** on the legal bar (V2)

## 1.6 Sticky Elements

| Element | Trigger | Behaviour |
|---|---|---|
| **Top nav bar** | Always sticky on desktop & mobile | Translucent on hero (`backdrop-filter: blur(12px)`); solid white at scroll > 80px |
| **Sticky CTA bar** (desktop) | Scroll depth > 30% on /demo-eligible pages | Slides down from top, contains "Book a Demo" button + dismiss `×`; hides at footer |
| **Mobile sticky bottom bar** | Always visible on mobile | Persistent "Book a Demo" + WhatsApp icon |
| **Cookie consent** | First-visit only | Bottom-left toast, dismissible, persistent dismissal |
| **Demo widget** (modal) | "Book a Demo" CTAs everywhere | Full-screen modal with Calendly embed; query param `?demo=1` opens it directly |

---

# 2. Landing Page Hierarchy & Conversion Flow

## 2.1 The Five-Phase Visitor Journey

Every page must be architected against this journey. Each section answers a specific phase question.

| Phase | Visitor Question | Page Strategy |
|---|---|---|
| **1. Attract** | "What is this and is it for me?" | Hero — clear positioning, instant context |
| **2. Educate** | "How does it actually work?" | Problem framing → solution narrative → mechanism |
| **3. Convince** | "Will it work for *my* institution?" | Workflow demos, capability proof, role-relevant detail |
| **4. Trust** | "Can I rely on these people?" | Stats, testimonials, security, customer stories |
| **5. Convert** | "What's the next step?" | Clear CTA, low-friction action |

Every page = Attract → Educate → Convince → Trust → Convert.

## 2.2 Homepage Conversion Flow

The homepage is the highest-value surface. Its section order is not a content decision — it is a conversion architecture decision. The order below is finalised:

```
1.  Announcement Strip          (Attract)
2.  Hero                        (Attract — emotional + functional anchor)
3.  Trust Bar / Logos           (Trust — institutional credibility)
4.  The Problem We Solve        (Educate — empathy + relevance)
5.  The Ecosystem (3 Apps)      (Educate — solution overview)
6.  Real-Time Sync Engine       (Educate — differentiator)
7.  Core Modules Grid           (Convince — breadth proof)
8.  Admin Panel Spotlight       (Convince — buyer-facing)
9.  Teacher App Spotlight       (Convince — user-facing)
10. Parent App Spotlight        (Convince — user-facing)
11. Multi-Role Dashboards       (Convince — relevance to each persona)
12. Analytics & Intelligence    (Convince — sophistication signal)
13. Security & Infrastructure   (Trust — risk reduction)
14. Industries We Serve         (Convince — segment confirmation)
15. Why [Brand]                 (Convince — differentiation)
16. Customer Outcomes           (Trust — outcome proof)
17. Customer Stories Carousel   (Trust — peer validation)
18. FAQ                         (Trust — objection handling)
19. Final CTA Strip             (Convert)
20. Footer                      (Navigation)
```

## 2.3 CTA Placement Strategy

Three CTA tiers, used deliberately throughout the site.

| Tier | Type | Visual | Where it appears |
|---|---|---|---|
| **Primary** | Hard conversion — book demo / start trial | Filled accent button, ≥14px height padding, prominent | Hero, every 3 sections, final CTA strip, sticky header (post-scroll) |
| **Secondary** | Soft conversion — watch video / explore platform | Outlined / ghost button, same size as primary | Hero (secondary), feature sections |
| **Tertiary** | Lead nurture — read story / download guide | Text link with right-arrow | Inline within body content |

**Placement rule:** primary CTA never more than 2 viewport heights away. On a 5,000px homepage, that's a primary CTA every ~1,000px scroll.

**Critical CTA positions on homepage:**
- Hero (always — primary + secondary)
- After Section 6 (Real-Time Engine) — "See the engine in action"
- After Section 10 (Parent App Spotlight) — bridging CTA "See it work for your school"
- After Section 13 (Security) — "Talk to our team about your data posture"
- After Section 17 (Customer Stories) — "Read more customer stories"
- Final CTA strip (always — primary + secondary)

## 2.4 Page-Level Conversion Models

Different page types optimise for different actions:

| Page Type | Primary Goal | Primary CTA | Secondary CTA |
|---|---|---|---|
| Homepage | Demo booking | Book a Demo | Explore the Platform |
| Module pages | Module-specific demo | See [Module] in Action | Talk to Sales |
| Product pages (Admin/Teacher/Parent) | Per-product demo / app download | Book Demo / Download | Watch Tour |
| Pricing | Demo booking (Enterprise) / Self-serve (Starter) | Book a Demo | View Detailed Comparison |
| About / Why Us | Trust building → Demo | Schedule a Demo | Read Customer Stories |
| Customer Stories | Demo / Comparable case | Schedule Your Demo | Read More Stories |
| Contact | Form submission | Send Message | Schedule a Demo |
| FAQ | Demo / Contact | Schedule a Demo | Contact Support |
| Security | Trust → Demo | Schedule a Demo | Download Security Brief |

---

# 3. Product Architecture

## 3.1 The Four-Surface Product Model

The site presents four parallel product surfaces. Each gets its own dedicated long-form product page plus appearances on the homepage and module pages.

```
                        ┌─────────────────────────┐
                        │   /platform (umbrella)  │
                        └────────────┬────────────┘
                                     │
        ┌────────────┬───────────────┼──────────────────┐
        ▼            ▼               ▼                  ▼
   /admin-panel  /teacher-app   /parent-app      [V2: /api-platform]
```

The umbrella `/platform` page exists to give the three apps shared positioning (the "operating system" framing) before visitors split into one of three product paths.

## 3.2 Module Architecture

13 module pages, all following one shared template. Modules link inward to:
- Related modules (3-card strip at bottom)
- The product pages that surface this module (Admin Panel, etc.)
- One customer story per module where available
- Pricing tier mapping ("Available in Standard and above")

```
/modules
  /admissions       — links → /modules/student-lifecycle, /modules/fees
  /student-lifecycle — links → /modules/admissions, /modules/academic-management
  /attendance       — links → /modules/timetable, /modules/communication
  /fees             — links → /modules/admissions, /modules/reports
  …
```

Each module page is **SEO-targeted** for high-volume queries (e.g., "school fees software India", "biometric attendance school"). Module pages are the SEO backbone of the site.

## 3.3 Industry / Audience Architecture

Four industry pages serve as vertical landing pages from paid ads and organic search:

```
/solutions/k12-schools         — Tone: trusted partner, board-aligned
/solutions/colleges            — Tone: scale-ready, higher-ed-specific
/solutions/coaching-institutes — Tone: performance-driven, data-led
/solutions/educational-groups  — Tone: enterprise, multi-branch
```

Each industry page:
- Reuses ~60% of homepage components (DRY)
- Replaces messaging in hero, problem framing, and module-relevance ordering
- Features 1-2 customer stories matching that industry
- Has industry-specific pricing context (if applicable)

## 3.4 Cross-Linking Strategy

A directional cross-linking matrix to maximise SEO equity and visitor journey depth:

| From | High-priority links to |
|---|---|
| Homepage | All 3 product pages, /pricing, top 3 modules (Fees / Attendance / Admissions), /why-us |
| Admin Panel page | Top 6 admin-relevant modules, /pricing, /security |
| Teacher App page | /modules/attendance, /modules/homework, /modules/communication, Parent App page |
| Parent App page | Teacher App page, /modules/fees, /modules/transport, /security |
| Each module page | 3 related modules, 1 product page, 1 customer story, /pricing |
| Each industry page | All product pages, 3 industry-relevant modules, 1-2 customer stories |
| Customer story | Module pages mentioned in story, /pricing, /demo |
| Pricing | /demo, /security, /why-us |
| About | /why-us, /careers, /customers |

---

# 4. Design System Direction

## 4.1 Brand & Visual Principles

Five operating principles that govern every design decision:

1. **Clarity beats density.** White space is a feature. If a section requires squinting, redesign it.
2. **Premium-feeling, not corporate-feeling.** Avoid stock-photo cheerful and avoid blue-corporate cold. Aim for the editorial confidence of Stripe + the design rigour of Linear.
3. **Real product, not stylised illustration.** Show actual product UI. Illustrations are accents, never replacements.
4. **Performance is design.** A beautiful page that loads in 4 seconds is an ugly page. Lighthouse 95+ across all metrics is non-negotiable.
5. **Type does most of the work.** Strong typographic hierarchy carries the brand. Avoid leaning on colour or imagery to hide weak type.

## 4.2 Typography System

**Pairing recommendation:** A modern editorial serif for headlines + a precise grotesk for body and UI.

### Headline / Display
- **Primary candidate:** GT Sectra (Grilli Type, commercial)
- **Alternative (open-source):** Fraunces
- **Fallback:** Charter, system serif

### Body / UI
- **Primary candidate:** Inter (open-source, free)
- **Alternative:** Söhne (commercial, used by Stripe)
- **Mono (for stats, code blocks, numerals):** JetBrains Mono or IBM Plex Mono

### Type Scale (8pt baseline, modular scale 1.25 minor third)

| Token | Size (desktop / mobile) | Weight | Line-height | Use |
|---|---|---|---|---|
| `text-hero` | 72px / 44px | 500 | 1.05 | Page H1, hero only |
| `text-display` | 56px / 36px | 500 | 1.1 | Section H1 |
| `text-h1` | 48px / 32px | 500 | 1.15 | Section headings |
| `text-h2` | 36px / 28px | 600 | 1.2 | Sub-section headings |
| `text-h3` | 28px / 22px | 600 | 1.3 | Card / block headings |
| `text-h4` | 22px / 18px | 600 | 1.4 | Small block titles |
| `text-lg` | 18px / 17px | 400 | 1.6 | Body lead paragraphs |
| `text-base` | 16px / 16px | 400 | 1.7 | Body |
| `text-sm` | 14px / 14px | 400 | 1.6 | Captions, UI labels |
| `text-xs` | 12px / 12px | 500 | 1.4 | Eyebrows, tags, metadata |
| `text-eyebrow` | 12px / 12px | 600 | 1.0 | Uppercase tracked, section labels |

**Tracking (letter-spacing):**
- Display sizes (≥40px): -0.02em
- Body sizes (16–22px): 0
- Eyebrow / uppercase: +0.08em

**Tabular numerals required on:**
- All stat strips
- Pricing tier numbers
- Comparison tables
- Counters

## 4.3 Color System

Built around one brand primary, six neutrals, three semantic colors, and three product-accent sub-brands.

### Brand
- `--brand-primary` `#0F766E` (deep teal — confidence + education trust)
- `--brand-primary-hover` `#0D6B63`
- `--brand-primary-pressed` `#0A5853`
- `--brand-on-primary` `#FFFFFF`

### Neutrals (slate-based, 10-step scale)
- `--neutral-0` `#FFFFFF`
- `--neutral-50` `#F8FAFC`
- `--neutral-100` `#F1F5F9`
- `--neutral-200` `#E2E8F0`
- `--neutral-300` `#CBD5E1`
- `--neutral-400` `#94A3B8`
- `--neutral-500` `#64748B`
- `--neutral-600` `#475569`
- `--neutral-700` `#334155`
- `--neutral-800` `#1E293B`
- `--neutral-900` `#0F172A`
- `--neutral-950` `#0A0F1C`

### Accent / Energy
- `--accent` `#F59E0B` (amber — for CTAs that need extra pull, conversion moments)
- `--accent-hover` `#D97706`

### Product Sub-Accents
- `--admin-accent` `#4338CA` (indigo — Admin Panel)
- `--teacher-accent` `#0F766E` (teal — Teacher App, matches brand)
- `--parent-accent` `#B45309` (warm amber — Parent App)

### Semantic
- `--success` `#16A34A`
- `--warning` `#D97706`
- `--error` `#DC2626`
- `--info` `#0284C7`

### Dark Mode Surfaces (for "ink" sections — Real-Time Engine, Analytics, Final CTA)
- `--dark-surface-1` `#0A0F1C`
- `--dark-surface-2` `#111827`
- `--dark-surface-3` `#1E293B`

### Color Usage Rules
- **Hero / "ink" sections:** Dark surface + white text. Used sparingly — 2–3 per page max.
- **Body sections:** White or `--neutral-50` background. Default state.
- **Trust / proof sections:** `--neutral-100` background (slight tint, signals "this is different").
- **Brand color (teal):** Used only on primary CTAs, key accents, and the logo. Not used as a background colour for large sections.
- **Accent amber:** Reserved for highest-priority conversion moments — hero CTA, final CTA strip, pricing "Most Popular" badge.

## 4.4 Spacing Philosophy

**Base unit: 4px. Scale: 4 / 8 / 12 / 16 / 24 / 32 / 48 / 64 / 96 / 128.**

Use the scale exclusively — no arbitrary values.

### Section-Level Spacing

| Context | Vertical Padding (desktop / mobile) |
|---|---|
| Standard content section | 120px / 64px |
| Light/quiet section (between major content) | 80px / 48px |
| Compressed strip (trust bar, stat strip, app strip) | 60px / 40px |
| Hero | 140px top, 100px bottom / 88px, 64px |
| Final CTA strip | 120px / 80px |

### Component-Level Spacing

| Element | Padding |
|---|---|
| Card (standard) | 32px |
| Card (compact, tile) | 24px |
| Button (primary) | 16px vertical, 28px horizontal |
| Button (secondary / ghost) | 14px vertical, 24px horizontal |
| Input field | 14px vertical, 16px horizontal |
| Pill / tag / badge | 4px vertical, 12px horizontal |

### Container Widths

| Container | Max-width |
|---|---|
| Marketing page wrapper | 1280px |
| Reading-width body block | 720px |
| Wide content (feature grid) | 1200px |
| Hero | 1280px (split layout fills) |
| Footer | 1280px |

**Side padding:** 24px on mobile, 48px on tablet, 80px on desktop.

## 4.5 Card Styles (3 Variations)

### Variant 1 — Elevated Card
- Use: Feature tiles, module cards, customer story cards
- Background: `--neutral-0`
- Border: `1px solid --neutral-200`
- Border-radius: `16px`
- Shadow: `0 1px 2px rgba(0,0,0,0.04)` (default), `0 8px 24px rgba(0,0,0,0.08)` (hover)
- Hover: Translate-Y `-4px`, shadow expands

### Variant 2 — Flat Card
- Use: FAQ accordions, list items, dense grids
- Background: `--neutral-50`
- Border: none
- Border-radius: `12px`
- No shadow
- Hover: Background `--neutral-100`

### Variant 3 — Bento Card (Asymmetric)
- Use: Bento grid sections (homepage modules layout, dashboard previews)
- Background: Mixed — some white, some `--neutral-900` for visual rhythm
- Border: `1px solid --neutral-200` (light cards), none (dark cards)
- Border-radius: `24px` (more pronounced than Variant 1)
- Internal padding: 40px

## 4.6 Button Hierarchy (5 Levels)

| Level | Visual | Use Case |
|---|---|---|
| **1. Primary** | Filled accent (amber `--accent`) on light bg, filled brand (teal `--brand-primary`) on dark bg | Hero CTA, final CTA strip, highest-priority action per section |
| **2. Brand** | Filled `--brand-primary` | Secondary primary actions, form submits |
| **3. Outlined** | 1.5px border `--neutral-900`, transparent fill | Secondary CTAs ("Watch the tour") |
| **4. Ghost** | No border, no fill, text only with hover bg `--neutral-100` | Tertiary actions, nav items |
| **5. Link** | Underlined or arrow-suffixed text, inherits text color | Inline body CTAs ("Read more →") |

### Button Sizing

| Size | Height | Padding | Use |
|---|---|---|---|
| Large | 56px | 18px / 32px | Hero CTAs, final CTA strip |
| Medium (default) | 48px | 14px / 28px | Standard section CTAs |
| Small | 40px | 12px / 20px | Form submits, inline actions |
| Compact | 32px | 8px / 14px | Nav buttons, dense areas |

### Button States
- Default
- Hover: Lighten primary by ~6%, scale `1.0` (no movement on hover)
- Active/Pressed: Darken by ~8%, scale `0.98`
- Focus-visible: 2px ring offset (`--brand-primary` at 30% opacity)
- Disabled: 40% opacity, `cursor: not-allowed`
- Loading: Spinner replaces text, button stays clickable-styled but disabled

## 4.7 Enterprise SaaS Visual Language Summary

The visual language is the intersection of:
- **Stripe's editorial confidence** (typography-led, generous whitespace, real product imagery)
- **Linear's structural rigour** (consistent spacing, deliberate dark/light alternation, no decorative noise)
- **Notion's bento-grid flexibility** (asymmetric tile compositions, content fluidity)
- **Vercel's technical credibility** (dark mode for "infrastructure" sections, mono numerals)
- **Apple product pages' vertical storytelling** (long-form scrolling product walkthroughs)

What it is **not**:
- Not bright Indian-SaaS gradient overload
- Not stock-photo "happy classroom" imagery
- Not heavy-illustrated child-friendly cartoony (we are not selling to children)
- Not corporate-grey conservative (we are selling to forward-leaning principals)

---

# 5. UI Component Inventory

A complete, reusable component system. Every section of every page is built from these primitives. Components are named in lowercase-kebab; React/TSX naming uses PascalCase (`<HeroSplit />`).

## 5.1 Heroes

### 5.1.1 `hero-split`
- Two-column 50/50 layout, headline + body left, product visual right.
- Mobile: stacks vertical (text on top).
- Used on: Homepage, all product pages, all industry pages.
- Variations: dark-bg, light-bg, gradient-bg.

### 5.1.2 `hero-centered`
- Single-column, headline + body + CTAs centered.
- Used on: About, Why Us, Pricing, FAQ, Contact (hero only).
- Max-width: 720px for body.

### 5.1.3 `hero-full-product`
- Headline on top, full-bleed product screenshot underneath.
- Used on: Admin Panel page (the screenshot is the hero asset).
- Mobile: scaled product image.

## 5.2 Trust & Social Proof

### 5.2.1 `logo-wall`
- 8–10 logos in monochrome, horizontal strip.
- Hover: restore to brand color of each logo.
- Mobile: horizontal scrollable carousel.

### 5.2.2 `stat-strip`
- 4 stats horizontal, large tabular numerals + small uppercase labels.
- Counter animation on viewport entry.
- Variations: dark-bg (numbers in white + accent), light-bg.

### 5.2.3 `featured-testimonial`
- Large quote card with portrait, name, role, institution.
- Optional star-rating row.
- Hover: subtle border accent.

### 5.2.4 `testimonial-grid`
- 3-column grid of smaller quote cards.
- Each card: short quote + attribution.
- Used after `featured-testimonial`.

## 5.3 Feature Showcases

### 5.3.1 `feature-grid-4`
- 4-column grid of feature cards.
- Each: icon, headline, 2-line description.
- Used on: Why Us, Security tiles.

### 5.3.2 `feature-grid-3`
- 3-column grid for capability blocks.
- Used on: Module pages (Capability section).

### 5.3.3 `feature-row-alternating`
- Alternating left-right rows.
- Each row: 50/50 split with text + product visual.
- Used on: Long-form product pages (Admin Panel, Teacher App, Parent App).

### 5.3.4 `bento-grid`
- Asymmetric grid layout (Notion-style).
- 6–9 tiles of varying widths (1×1, 2×1, 1×2, 2×2).
- Some tiles are dark, some light — visual rhythm.
- Used on: Homepage Module section variant, Dashboard Previews.

## 5.4 Workflow Visualisations

### 5.4.1 `workflow-flow-diagram`
- Horizontal flow: Step 1 → Step 2 → Step 3 → outcome.
- Each step: icon + label + 1-line description.
- Animated arrows with dot-flow.
- Used on: Real-Time Sync section, Homework Flow, Fee Payment Flow.

### 5.4.2 `workflow-timeline`
- Vertical timeline with numbered steps.
- Used on: Onboarding Timeline (5-week rollout), Customer Journey.

### 5.4.3 `cross-system-flow`
- Three-device horizontal — Admin web | Teacher phone | Parent phone.
- Animated transitions showing data propagating between them.
- Hero of "Real-Time Sync Engine" section.

## 5.5 Module / Product Browse

### 5.5.1 `module-grid-20`
- 5×4 grid of module tiles.
- Each tile: icon, name, 2-line capability.
- Click-through to module page.

### 5.5.2 `module-card-featured`
- Larger card with product screenshot, headline, body, CTA.
- Used on module index pages for "Featured Module" callouts.

### 5.5.3 `app-showcase-3-up`
- Three vertical app cards side-by-side: Admin / Teacher / Parent.
- Each: form-factor icon, product name, 3-line description, CTA.

## 5.6 Dashboards & Previews

### 5.6.1 `dashboard-preview-card`
- Stylised analytics dashboard mockup.
- Live-feel: subtle counter animations, gentle chart pulse.
- Used on Analytics section, Multi-Role Dashboards section.

### 5.6.2 `phone-mockup-fan`
- 2–3 phone mockups arranged in a fan layout.
- Each shows a different app screen.
- Used on app spotlight sections.

### 5.6.3 `phone-mockup-hero`
- Single tilted phone mockup, hero-sized.
- Used on app product pages (above-fold).

### 5.6.4 `annotated-screenshot`
- Web product screenshot with numbered hotspots (1–5) calling out features.
- Tooltip on hover reveals feature description.
- Used on Admin Panel sections.

## 5.7 Pricing

### 5.7.1 `pricing-tier-card`
- Single tier card: name, price, "best for" line, feature list, CTA.
- Variations: standard, featured ("Most Popular" with amber border + badge).
- Used on /pricing.

### 5.7.2 `pricing-comparison-table`
- Long table comparing 4–6 tiers across 30+ features.
- Sticky header on scroll.
- Mobile: collapses to tier-tab interface.

### 5.7.3 `pricing-faq-strip`
- Compressed FAQ specific to pricing/billing questions.

## 5.8 Calls to Action

### 5.8.1 `cta-strip-full-width`
- Full-bleed coloured band with centered headline, sub, primary + secondary CTAs.
- Variations: brand-gradient, dark, accent-amber.
- Used as final CTA on every page.

### 5.8.2 `cta-inline-banner`
- Compact horizontal CTA inside a content section.
- Headline + one CTA, single-line layout.
- Used to break up long content sections.

### 5.8.3 `cta-newsletter`
- Email input + Subscribe button.
- Used in footer top strip.

## 5.9 Content & Long-Form

### 5.9.1 `faq-accordion`
- Single-expand accordion of Q&A pairs.
- Built on Radix UI Accordion (a11y-compliant).
- Search field at top (V2).

### 5.9.2 `feature-comparison`
- Side-by-side comparison of "Before [Brand] / With [Brand]" or "Us vs Them".
- Used on: Why Us page, Customer Stories outcome section.

### 5.9.3 `customer-story-card`
- Mid-density card for customer story index.
- Image + institution name + outcome stat + quote teaser.

### 5.9.4 `customer-story-template`
- Full long-form layout: hero photo + facts strip (size, location, year) + narrative body + outcome stats + quote + CTA.

### 5.9.5 `blog-post-template`
- Editorial layout: max-width 720px, generous typography, in-line images, related-posts strip at end.

### 5.9.6 `process-timeline`
- 5-step horizontal or vertical timeline with weekly milestones.
- Used on: Onboarding section, Migration page.

## 5.10 Navigation & Layout

### 5.10.1 `nav-bar`
- Sticky top navigation, 64px height.
- Translucent over hero, solid on scroll.

### 5.10.2 `nav-dropdown-panel`
- Multi-column dropdown on hover (desktop only).
- Rich content: links + featured callouts.

### 5.10.3 `mobile-nav-drawer`
- Full-screen slide-in drawer.
- Hierarchical sections with accordions.

### 5.10.4 `breadcrumb`
- Used on module, customer story, blog, and legal pages.
- Format: `Home / Modules / Fees Management`

### 5.10.5 `sticky-page-toc`
- Right-rail table of contents on long pages (legal, security brief, customer stories).
- Auto-highlights current section on scroll.

## 5.11 Forms

### 5.11.1 `contact-form`
- Standard fields: name, institution, role, email, phone, message, consent.
- Inline validation (Zod + React Hook Form).
- Honeypot anti-spam.

### 5.11.2 `demo-booking-embed`
- Calendly embed in a modal or full page.
- Pre-populated with UTM source.

### 5.11.3 `newsletter-inline`
- Single-line email + button.
- Used in footer.

### 5.11.4 `download-resource-form`
- Email-gated download trigger (used on whitepapers).
- Returns download link in email (not direct).

## 5.12 Utility Components

### 5.12.1 `eyebrow-tag`
- Small uppercase label above section headlines.
- Often colored (`--brand-primary`).

### 5.12.2 `badge-pill`
- Status / category pill.
- Variants: new, beta, coming-soon, enterprise, free.

### 5.12.3 `tooltip`
- Built on Radix UI Tooltip.

### 5.12.4 `modal-dialog`
- Built on Radix UI Dialog.
- Used for: demo widget, video player, exit-intent capture.

### 5.12.5 `toast`
- Bottom-right notification.
- Used for: form-submission confirmations, cookie consent.

### 5.12.6 `loading-skeleton`
- Used during initial page loads where data is fetched.
- Tailwind `animate-pulse` based.

---

# 6. Homepage Wireframe (Section-by-Section UX Brief)

Each section below specifies: purpose, emotional objective, conversion objective, layout, animation, and scroll pacing. This is the spec a frontend team builds from.

## 6.1 Section 1 — Announcement Strip

| Property | Specification |
|---|---|
| **Purpose** | Surface time-sensitive announcements (beta launches, webinars, certifications) |
| **Emotional Objective** | Convey momentum — "this product is moving" |
| **Conversion Objective** | Soft — click-through to feature page or webinar signup |
| **Layout** | Full-width 36px sliver at top, centered single line of text with arrow link, right-aligned dismiss `×` |
| **Animation** | Fade-rotation between 3–5 messages every 6 seconds (paused on hover) |
| **Scroll Pacing** | Stays visible until first scroll, then collapses |

## 6.2 Section 2 — Hero

| Property | Specification |
|---|---|
| **Purpose** | Position the product in 5 seconds; convert via primary CTA |
| **Emotional Objective** | "This is serious. This is for me. This is different." |
| **Conversion Objective** | Hard — book demo (primary), explore platform (secondary) |
| **Layout** | `hero-split` component. Left 50% — eyebrow, H1 (`text-hero`), sub-H1 (`text-lg`), body (`text-base`), CTA pair. Right 50% — layered device composite (laptop + 2 phones) |
| **Animation** | Hero text fades in with stagger (200ms delay between H1, sub, body, CTAs). Device composite eases in from right with slight scale-up. After load, gentle parallax on scroll (device translates upward at 0.1× scroll rate). Animated data-flow dots between devices loop continuously |
| **Scroll Pacing** | Full viewport height (`100vh`) on desktop, `min-height: 90vh` on mobile. First scroll reveals trust bar |
| **Dark/Light** | Dark mode (`--dark-surface-1`), white text |

## 6.3 Section 3 — Trust Bar

| Property | Specification |
|---|---|
| **Purpose** | Institutional credibility in <2 seconds |
| **Emotional Objective** | "Other serious schools trust this" |
| **Conversion Objective** | Soft — link to /customers |
| **Layout** | `logo-wall` component (8 logos) + `stat-strip` (4 stats) below |
| **Animation** | Logos fade in on scroll-into-view (stagger 80ms). Stats counter-tick on viewport entry (400ms ease-out) |
| **Scroll Pacing** | Compact section (~60px vertical padding) |
| **Dark/Light** | Light (white) — visual relief after dark hero |

## 6.4 Section 4 — The Problem We Solve

| Property | Specification |
|---|---|
| **Purpose** | Activate empathy; create reader recognition |
| **Emotional Objective** | "They understand my reality" |
| **Conversion Objective** | None — pure narrative, leads to Section 5 |
| **Layout** | Centered single column max-width 880px. Small "before/after" iconic graphic above H1. Body copy with deliberate line-break cadence |
| **Animation** | Body paragraphs fade in on scroll-into-view (stagger 120ms per paragraph). Before/after graphic has subtle morph animation when in view |
| **Scroll Pacing** | Standard padding (120px) |
| **Dark/Light** | Off-white `--neutral-50` |

## 6.5 Section 5 — The Ecosystem (3-App Overview)

| Property | Specification |
|---|---|
| **Purpose** | Educate on solution structure |
| **Emotional Objective** | "There's a thought-out architecture here" |
| **Conversion Objective** | Tertiary — anchor links to each app spotlight further down |
| **Layout** | H1 + sub + body intro centered. Below: 3-card grid (`app-showcase-3-up`) with one card per app (Admin / Teacher / Parent). Each card top-bordered with its sub-accent color |
| **Animation** | Cards lift on hover (`-4px translate-Y`, shadow expansion, 250ms ease-out). Cards stagger-fade on entry (150ms stagger) |
| **Scroll Pacing** | Standard |
| **Dark/Light** | White |

## 6.6 Section 6 — Real-Time Sync Engine (The Differentiator)

| Property | Specification |
|---|---|
| **Purpose** | Establish the core technical differentiator |
| **Emotional Objective** | Confidence — "this is sophisticated" |
| **Conversion Objective** | Soft — "See it in action" links to video/demo |
| **Layout** | Section eyebrow + headline + sub centered. Below: 4 workflow rows (`workflow-flow-diagram` component), each showing a different cross-system flow (fee payment, homework, attendance, red flag). Optional: embedded 30-second loop video at end |
| **Animation** | Animated dot-flow along the connecting arrows in each workflow row. Each workflow row reveals progressively as user scrolls into view (one at a time, 400ms stagger) |
| **Scroll Pacing** | Tall section — generous vertical space (~140px padding) to let the flows breathe |
| **Dark/Light** | Dark mode (`--dark-surface-1`) — animated dots pop visually |

## 6.7 Section 7 — Core Modules Grid

| Property | Specification |
|---|---|
| **Purpose** | Convey breadth — "everything is covered" |
| **Emotional Objective** | Reassurance |
| **Conversion Objective** | Soft — drill-down to module pages |
| **Layout** | `module-grid-20` — 5×4 grid of module tiles, each icon + name + 2-line capability |
| **Animation** | Tiles stagger-fade in (60ms each). Hover: tile lifts + accent-color border bottom appears |
| **Scroll Pacing** | Standard |
| **Dark/Light** | Light (`--neutral-50`) — soft contrast against the dark Section 6 |

## 6.8 Section 8 — Admin Panel Spotlight

| Property | Specification |
|---|---|
| **Purpose** | Sell the admin product to buyers (principals, accountants) |
| **Emotional Objective** | "This is built for serious operations" |
| **Conversion Objective** | "Explore Admin Panel" CTA to /admin-panel |
| **Layout** | `feature-row-alternating` — left 40% text, right 60% `annotated-screenshot` of admin dashboard. Below the row: 3 sub-columns (Operational / Financial / Governance) |
| **Animation** | Annotated screenshot's numbered hotspots appear sequentially on viewport entry (400ms stagger), connecting tooltips fade in |
| **Scroll Pacing** | Standard |
| **Dark/Light** | White |

## 6.9 Section 9 — Teacher App Spotlight

| Property | Specification |
|---|---|
| **Purpose** | Sell the teacher product to staff and to schools |
| **Emotional Objective** | "Designed for the people who teach" |
| **Conversion Objective** | "Explore Teacher App" CTA |
| **Layout** | `feature-row-alternating` (mirror of Section 8) — text right, `phone-mockup-fan` left showing 3 Teacher App screens |
| **Animation** | Phone mockups stagger-reveal from off-screen (slide-up + fade, 200ms stagger). Subtle continuous floating animation on phones |
| **Scroll Pacing** | Standard |
| **Dark/Light** | Light tinted (`--neutral-100`) — differentiates from white Admin section above |

## 6.10 Section 10 — Parent App Spotlight

| Property | Specification |
|---|---|
| **Purpose** | Sell the parent product to schools (which ultimately drives parent satisfaction) |
| **Emotional Objective** | Warmth — "parents love this" |
| **Conversion Objective** | "Explore Parent App" CTA |
| **Layout** | Mirror Section 9 — text left, `phone-mockup-fan` right with 3 Parent App screens |
| **Animation** | Same as Section 9 |
| **Scroll Pacing** | Standard |
| **Dark/Light** | White |

## 6.11 Section 11 — Multi-Role Dashboards

| Property | Specification |
|---|---|
| **Purpose** | Establish role-aware sophistication |
| **Emotional Objective** | "Everyone gets exactly what they need" |
| **Conversion Objective** | Soft — anchor to /platform |
| **Layout** | 5-column grid, each role (Principal / Accountant / Teacher / Warden / Parent) with role icon, name, 4 dashboard items, "View dashboard preview" link |
| **Animation** | Columns stagger-reveal on scroll-into-view (100ms stagger) |
| **Scroll Pacing** | Standard |
| **Dark/Light** | White |

## 6.12 Section 12 — Analytics & Intelligence

| Property | Specification |
|---|---|
| **Purpose** | Signal data sophistication |
| **Emotional Objective** | "This is intelligent infrastructure" |
| **Conversion Objective** | Soft — "See the analytics layer" |
| **Layout** | Two-column: text 50% left, `dashboard-preview-card` 50% right with live-feel chart |
| **Animation** | Chart numerals tick up subtly on viewport entry. Subtle line-chart drawing animation. Hover on chart elements reveals tooltips |
| **Scroll Pacing** | Standard |
| **Dark/Light** | Dark (`--dark-surface-1`) — charts pop |

## 6.13 Section 13 — Security & Infrastructure

| Property | Specification |
|---|---|
| **Purpose** | Address trust / risk objections preemptively |
| **Emotional Objective** | "My data will be safe" |
| **Conversion Objective** | "Read our security posture" link to /security |
| **Layout** | `feature-grid-4` (2×2) — 4 security tiles (Encryption, Tenant Isolation, Audit Trail, Backups). Below: trust badge row (ISO posture, GDPR, DPDP, Google Cloud Partner) |
| **Animation** | Tiles fade-in with stagger. Trust badges fade in below |
| **Scroll Pacing** | Standard |
| **Dark/Light** | Light with hexagonal mesh background pattern (subtle) |

## 6.14 Section 14 — Industries We Serve

| Property | Specification |
|---|---|
| **Purpose** | Audience confirmation — visitor self-selects relevance |
| **Emotional Objective** | "There's a path for my institution type" |
| **Conversion Objective** | Drill-down to industry landing page |
| **Layout** | `feature-grid-4` — 4 industry cards (K-12 / Colleges / Coaching / Groups), each with illustration + name + tagline + 3-line description + link |
| **Animation** | Cards stagger-reveal. Hover: illustration animates subtly |
| **Scroll Pacing** | Standard |
| **Dark/Light** | White |

## 6.15 Section 15 — Why [Brand]

| Property | Specification |
|---|---|
| **Purpose** | Differentiation summary |
| **Emotional Objective** | "Their thinking is mature" |
| **Conversion Objective** | "Compare to your current system" CTA |
| **Layout** | 3×2 grid of differentiator tiles. Each tile: large outlined numeral (1–6) + headline + 2-3 line description |
| **Animation** | Numerals draw in on scroll-into-view (SVG stroke animation, 600ms each, staggered) |
| **Scroll Pacing** | Standard |
| **Dark/Light** | White with dotted background pattern |

## 6.16 Section 16 — Customer Outcomes

| Property | Specification |
|---|---|
| **Purpose** | Outcome proof — measurable transformation |
| **Emotional Objective** | "Real institutions see real results" |
| **Conversion Objective** | "Read customer stories" + soft demo CTA |
| **Layout** | Top: `feature-comparison` (Before/After 4-row table). Below: `featured-testimonial` card |
| **Animation** | Before/After numerals slide in with stagger. Testimonial fades in |
| **Scroll Pacing** | Standard |
| **Dark/Light** | Off-white `--neutral-50` |

## 6.17 Section 17 — Customer Stories Carousel

| Property | Specification |
|---|---|
| **Purpose** | Peer validation through narrative |
| **Emotional Objective** | "Schools like mine are succeeding here" |
| **Conversion Objective** | Drill-down to /customers |
| **Layout** | 3-card horizontal carousel of `customer-story-card` components. Pagination dots; auto-advance every 8 seconds (paused on hover) |
| **Animation** | Card transitions with fade-slide. Hover pauses auto-advance |
| **Scroll Pacing** | Standard |
| **Dark/Light** | White |

## 6.18 Section 18 — FAQ

| Property | Specification |
|---|---|
| **Purpose** | Objection handling |
| **Emotional Objective** | "My questions are answered" |
| **Conversion Objective** | Soft — link to /faq for full FAQ |
| **Layout** | Two-column: left 30% eyebrow + H1 + sub + decorative; right 70% `faq-accordion` (8 questions visible, link to /faq below) |
| **Animation** | Accordion expand/collapse with smooth 250ms easing. Plus-to-minus icon rotation |
| **Scroll Pacing** | Standard |
| **Dark/Light** | White |

## 6.19 Section 19 — Final CTA Strip

| Property | Specification |
|---|---|
| **Purpose** | Last-chance conversion |
| **Emotional Objective** | "I should book this now" |
| **Conversion Objective** | Hard — demo booking |
| **Layout** | `cta-strip-full-width`. Centered headline + sub + primary + secondary CTAs. Small badge above headline ("FREE 3-MONTH PILOT") |
| **Animation** | Subtle animated background — soft dot pattern, slow movement |
| **Scroll Pacing** | Tall (140px padding) — gives the close weight |
| **Dark/Light** | Brand-gradient (deep teal → indigo) with white text |

## 6.20 Section 20 — Footer

Specified in Phase 4 Part 8.

## 6.21 Scroll Pacing Summary (Cumulative)

The full homepage targets ~5,800–6,200px desktop scroll height. The rhythm alternates dense-content and breathable sections to avoid scroll fatigue:

```
~640px   Hero (full viewport)
~360px   Trust bar
~720px   Problem narrative
~640px   Ecosystem
~880px   Real-Time Sync Engine (tallest content section)
~640px   Modules grid
~640px   Admin Spotlight
~640px   Teacher Spotlight
~640px   Parent Spotlight
~480px   Multi-role dashboards
~600px   Analytics
~600px   Security
~480px   Industries
~600px   Why [Brand]
~640px   Outcomes
~520px   Customer stories carousel
~600px   FAQ
~520px   Final CTA
~440px   Footer
─────────
≈ 11,500px raw, ~5,800–6,200px after dark-section compression
```

---

# 7. Product Page Templates

Reusable layout templates by page type. Each is a sequence of components from Section 5.

## 7.1 Module Page Template (`/modules/[slug]`)

```
1.  nav-bar
2.  hero-split (module-specific eyebrow + H1 + sub + body + dual CTA)
3.  pain-point-scroller (horizontal scroll of 5–7 pain cards)
4.  feature-row-alternating × 2 (capability detail with annotated-screenshot)
5.  workflow-flow-diagram (cross-system flow for this module)
6.  feature-grid-3 (benefits)
7.  stat-strip (outcome stats)
8.  customer-story-card (one related story)
9.  related-modules-strip (3 related module cards)
10. cta-strip-full-width
11. footer
```

## 7.2 App Product Page Template (Parent App, Teacher App)

```
1.  nav-bar
2.  hero-split (warmer for parent, more direct for teacher)
3.  trust-bar (logos + stats)
4.  problem-narrative
5.  feature-row-alternating × 8 (one per feature pillar)
6.  cross-system-flow (3-device sync demonstration)
7.  feature-grid-4 (security/privacy tiles)
8.  testimonial-section (featured + grid)
9.  app-store-section (Play Store badges + QR + listing copy)
10. faq-accordion
11. cta-strip-full-width
12. footer
```

## 7.3 Industry Page Template (`/solutions/[slug]`)

```
1.  nav-bar
2.  hero-split (industry-specific framing)
3.  trust-bar
4.  industry-specific-problem-narrative
5.  feature-row-alternating × 3 (most relevant capabilities to this industry)
6.  customer-story-card × 1–2 (industry-matched)
7.  module-grid-relevant (only modules relevant to this industry)
8.  pricing-tier-card-strip (recommend specific tiers)
9.  faq-accordion (industry-specific Qs)
10. cta-strip-full-width
11. footer
```

## 7.4 About Page Template

```
1.  nav-bar
2.  hero-centered (mission framing)
3.  origin-story (single-column long-form)
4.  mission-statement-block
5.  vision-statement-block
6.  values-grid (2×3)
7.  leadership-grid (4-column portraits)
8.  stat-strip (company numbers)
9.  forward-statement (where we're going)
10. press-logo-wall
11. cta-strip-full-width
12. footer
```

## 7.5 Pricing Page Template

```
1.  nav-bar
2.  hero-centered (pricing framing)
3.  pricing-tier-card-strip (3–4 most popular tiers featured)
4.  pricing-comparison-table (full 9-tier comparison)
5.  feature-grid-3 (what's always included)
6.  stat-strip (cost-comparison vs traditional)
7.  pricing-faq-strip
8.  featured-testimonial
9.  cta-strip-full-width (book demo for custom pricing)
10. footer
```

## 7.6 Customer Story Page Template

```
1.  nav-bar
2.  hero-image-with-facts (institution photo + facts strip: size, location, year, board)
3.  challenge-narrative (single column)
4.  solution-narrative (alternating rows with product screenshots)
5.  outcome-stat-strip (before/after stats — 4 metrics)
6.  featured-quote (large pull-quote with portrait)
7.  related-stories-strip (3 related case studies)
8.  cta-strip-full-width
9.  footer
```

## 7.7 Resource / Blog Post Template

```
1.  nav-bar
2.  breadcrumb
3.  article-hero (title + author + date + read time + featured image)
4.  article-body (max-width 720px, generous typography)
5.  author-bio-card
6.  related-posts-strip (3 related articles)
7.  newsletter-inline
8.  footer
```

## 7.8 FAQ Page Template

```
1.  nav-bar
2.  hero-centered
3.  quick-anchor-strip (jump-links to FAQ categories)
4.  faq-accordion-grouped × 8 (one per category, sticky category headers)
5.  cta-strip-full-width (contact / demo)
6.  footer
```

## 7.9 Contact Page Template

```
1.  nav-bar
2.  hero-centered
3.  department-contact-grid (3×2 cards by department)
4.  contact-form (two-column with visual)
5.  office-cards (multi-column office addresses)
6.  demo-booking-embed (Calendly)
7.  support-tier-table
8.  social-strip
9.  map-embed
10. footer
```

## 7.10 Security Page Template

```
1.  nav-bar
2.  hero-centered (security posture)
3.  feature-grid-4 × 2 (8 security tiles total)
4.  compliance-badge-wall
5.  architecture-diagram (how data flows + isolation)
6.  documents-download-strip (DPA, sub-processor list, SOC reports if any)
7.  responsible-disclosure-section
8.  cta-strip-full-width
9.  footer
```

---

# 8. Modern SaaS Visual References

For every major section pattern, the specific SaaS sites that pioneered or perfected it — and why that pattern converts.

## 8.1 Hero Patterns

### Pattern A — Split Hero with Product Composite
**Used by:** Stripe (stripe.com), Linear (linear.app), Vercel (vercel.com), Notion (notion.so)
**Why it converts:** Left-text-right-product is now the visitor's expected pattern for SaaS. They scan text in 1.2 seconds, then look right for product validation. Reversing this (product left, text right) increases bounce because the eye-path is fought.
**Application to [Brand]:** Section 2 (Homepage hero) uses this. Right-side composite shows all three apps simultaneously — uniquely defensible visual.

### Pattern B — Editorial Centered Hero
**Used by:** Apple product pages, Framer (framer.com), Anthropic (anthropic.com)
**Why it converts:** Centered, typographically-led heroes work for narrative pages (About, mission-led pages). They communicate "this is content worth slowing down for."
**Application to [Brand]:** About page, Mission, Why Us.

### Pattern C — Full-Bleed Product Hero
**Used by:** Notion (workspace hero), Figma (config feature pages)
**Why it converts:** When the product *is* the pitch, putting the product image full-bleed below a short headline drives "show, don't tell." Works for visual products.
**Application to [Brand]:** Admin Panel product page (the dashboard *is* the proof).

## 8.2 Trust Bar Patterns

### Pattern A — Greyscale Logo Wall
**Used by:** Stripe, Vercel, Segment, Cloudflare
**Why it converts:** Greyscale logos signal sophistication ("we don't shout"). Color logos signal "early-stage app showing off." For enterprise B2B, greyscale converts better.
**Application to [Brand]:** Section 3 (Trust bar) and footer "trusted by" rows.

### Pattern B — Animated Stat Counter
**Used by:** Vercel (homepage stats), Linear (changelog stats)
**Why it converts:** Numbers tick up on scroll-into-view, creating a 400ms moment of attention. The animation signals "live and measured" rather than "static brochure."
**Application to [Brand]:** Stat strips throughout, especially below the hero.

## 8.3 Feature / Capability Patterns

### Pattern A — Alternating Feature Rows
**Used by:** Notion (notion.so/product), Linear (linear.app/method), Loom (loom.com)
**Why it converts:** Alternating left-right layouts force the eye to re-engage at each section. Reading three identical-layout sections is hypnotic; alternating forces a re-look.
**Application to [Brand]:** Product pages (Admin / Teacher / Parent) — every feature section alternates.

### Pattern B — Bento Grid
**Used by:** Apple (homepage product strips), Notion (homepage), Vercel (product features)
**Why it converts:** Asymmetric tiles of varying sizes give visual rhythm. Forces the visitor to slow down because there's no scan pattern to follow.
**Application to [Brand]:** Homepage Section 7 (Module grid variant), Multi-Role Dashboards section.

### Pattern C — Tabbed Product Showcase
**Used by:** Figma, Webflow (university page)
**Why it converts:** Compresses 3–5 product faces into one screen real estate via tabs. Works when you need to show multiple variants without scroll fatigue.
**Application to [Brand]:** V2 — could replace Multi-Role Dashboards section.

## 8.4 Workflow / Flow Diagrams

### Pattern A — Animated Cross-System Flow
**Used by:** Stripe (Connect product page — money flow), Segment (data flow visualisations), Twilio (channel-to-channel)
**Why it converts:** Animated arrows + dot-flow make abstract "real-time sync" tangible. Without animation, this kind of section reads as marketing claim; with animation, it reads as engineering reality.
**Application to [Brand]:** Section 6 (Real-Time Sync Engine) — the highest-stakes section to get right.

### Pattern B — Step Timeline
**Used by:** Linear (linear.app/features/cycles), Notion (templates pages)
**Why it converts:** Numbered horizontal steps give the visitor a "I can follow this" feeling. Reduces complexity perception.
**Application to [Brand]:** Onboarding 5-week timeline, customer story workflow narratives.

## 8.5 Pricing Patterns

### Pattern A — 3-Tier Card Grid with "Most Popular"
**Used by:** Linear (linear.app/pricing), Vercel (vercel.com/pricing), Tailwind UI's pricing kit
**Why it converts:** Three options force "Goldilocks" decision-making — most visitors pick the middle tier. The "Most Popular" amber badge on the middle tier increases its selection rate by ~15–20%.
**Application to [Brand]:** Pricing page card strip — feature 3–4 tiers, mark the institutional sweet-spot tier as Popular.

### Pattern B — Long Comparison Table with Sticky Header
**Used by:** Notion (notion.so/pricing), Asana, Monday.com
**Why it converts:** For enterprise evaluators (procurement, IT), a deep comparison table is reassuring — it signals "we have nothing to hide." Sticky header lets them scroll without losing context.
**Application to [Brand]:** Pricing page detailed comparison, below the 3-tier card strip.

## 8.6 Testimonials & Proof

### Pattern A — Featured Quote + Grid
**Used by:** Linear, Stripe, Webflow
**Why it converts:** One large quote anchors emotional weight; the surrounding grid of smaller quotes signals "many people, not just one." Together they feel substantial.
**Application to [Brand]:** Homepage Section 16, Customer Stories index.

### Pattern B — Customer Story Card Carousel
**Used by:** Shopify (case studies), Salesforce (customer stories), Stripe (customer stories)
**Why it converts:** Each card shows institution name + outcome stat + portrait + short teaser, driving click-through. Carousel format implies "there are many more."
**Application to [Brand]:** Section 17 homepage carousel, Customer Stories index.

## 8.7 Final CTAs

### Pattern A — Full-Width Coloured Band
**Used by:** Notion (every page), Linear, Webflow
**Why it converts:** A change in background color signals "this is different — pay attention." After a long-form scroll, the visitor is fatigued; a strong visual break re-engages them.
**Application to [Brand]:** Final CTA strip on every page.

### Pattern B — Two-CTA Stack (Primary + Secondary)
**Used by:** Stripe, Vercel, Linear
**Why it converts:** A single CTA forces a binary decision (yes/no). Two CTAs allow "softer yes" — interested visitors who aren't ready for demo can take a softer action (watch tour, download brochure) instead of leaving entirely.
**Application to [Brand]:** Every hero and every final CTA.

## 8.8 FAQ Patterns

### Pattern A — Accordion with Single-Expand
**Used by:** Stripe (across all pages), Notion, Vercel
**Why it converts:** Single-expand (opening one closes others) keeps the section compact and prevents accordion-list fatigue. The "+" → "−" icon rotation is a satisfying microinteraction.
**Application to [Brand]:** Every page's FAQ section.

---

# 9. Motion & Animation Direction

Motion is part of brand expression. Used right, it conveys quality. Used wrong, it conveys insecurity. Below is a specific motion language.

## 9.1 First Principles

1. **Animation has a job.** Every animation answers "what did this just communicate?" If you can't answer, remove it.
2. **Speed > smoothness.** A 200ms-but-instant animation is better than a 600ms-smooth animation that feels sluggish.
3. **Easing curves matter more than duration.** Linear easing always feels wrong. Use ease-out for entries (the object is "settling in") and ease-in for exits (the object is "leaving").
4. **Animations on scroll are revealed, not autoplayed.** Use IntersectionObserver thresholds; never trigger animations the visitor isn't watching.
5. **Respect `prefers-reduced-motion`.** All animations should have a no-motion fallback.

## 9.2 Standard Durations

| Duration | Use |
|---|---|
| **80ms** | Micro-state transitions (button color on hover, link underline) |
| **150ms** | Hover lifts, small UI transitions |
| **250ms** | Standard transitions (modal open, accordion expand) |
| **400ms** | Scroll-reveal animations, large element transitions |
| **600ms** | Page transitions, hero reveals on load |

## 9.3 Standard Easing Curves

```
ease-out-quart: cubic-bezier(0.25, 1, 0.5, 1)         /* default for entries */
ease-in-quart:  cubic-bezier(0.5, 0, 0.75, 0)         /* default for exits */
ease-out-back:  cubic-bezier(0.34, 1.56, 0.64, 1)     /* small overshoot for delight */
ease-out-expo:  cubic-bezier(0.16, 1, 0.3, 1)         /* fast settle, premium feel */
```

The "premium-SaaS" feel comes primarily from `ease-out-expo` on scroll reveals.

## 9.4 Microinteractions

| Element | Animation |
|---|---|
| **Button hover** | Background lightens 6% over 80ms. No transform. |
| **Button press** | Scale to 0.98 over 80ms, snaps back on release. |
| **Card hover** | Translate-Y `-4px`, shadow expands from `0 1px 2px` to `0 8px 24px`, 250ms ease-out-expo. |
| **Link hover** | Right-arrow translates `+2px` over 150ms. |
| **Tooltip appear** | Fade + scale-up from 0.95, 150ms ease-out-quart. |
| **Toggle / Switch** | 200ms ease-out-back (small overshoot delight). |
| **Input focus** | Border color transitions, ring scales from 0 to 4px, 150ms. |
| **Accordion expand** | Height auto-animation, 250ms ease-out-quart, plus rotates +90° over 250ms. |
| **Dropdown menu** | Fade + slide-down from `-8px`, 200ms ease-out-quart. |
| **Modal open** | Backdrop fades in (150ms), modal scales from 0.96 to 1.0 with fade (250ms ease-out-expo). |

## 9.5 Hover Behaviours

Hover is desktop-only. All hover states must have a non-hover equivalent for mobile/touch.

| Component | Hover Behaviour |
|---|---|
| `feature-card` | Lift `-4px`, shadow expansion, optional accent border-bottom slide-in (300ms) |
| `module-tile` | Lift `-4px`, icon container background shifts to accent color |
| `nav-dropdown` | 100ms delay before opening (prevents accidental triggers), 80ms close delay |
| `phone-mockup` | Slight tilt change on hover (rotate from -5deg to 0deg), 400ms ease-out-expo |
| `cta-strip` | Background gradient subtle shift |
| `logo-wall logo` | Greyscale → color, 150ms |

## 9.6 Scroll-Reveal Animations

The site uses scroll-reveal for: section headlines, stat counters, feature cards, workflow diagrams.

### Specification
- **Trigger:** IntersectionObserver with `threshold: 0.2` (element 20% in viewport).
- **Duration:** 400ms.
- **Easing:** `ease-out-expo`.
- **Properties animated:** `opacity 0 → 1`, `transform translateY(24px) → translateY(0)`.
- **Stagger:** When multiple children animate, stagger by 80–120ms.
- **Run once:** Never re-trigger on scroll-back.

### Counter Animations
- Numerical stats tick from 0 to target value over 400ms.
- Easing: ease-out-expo (so the bulk of the count happens fast, then settles).
- Tabular numerals required to prevent layout jitter.

### Dashboard Preview Animations
- Subtle, continuous (not scroll-triggered).
- Chart line strokes draw in over 1.2s on first viewport entry.
- Chart numerals pulse-update every 4 seconds with tiny random delta (-2 to +3) to feel "live."
- Pause when not in viewport (performance).

## 9.7 Cross-System Sync Animation (Section 6)

The hero animation of the homepage. Must be flawless.

```
Frame 1 (0–200ms):    Source device pulses subtly
Frame 2 (200–600ms):  Animated dot trails from source → destination devices
Frame 3 (600–1000ms): Destination devices receive a subtle "ping" — small expanding ring + screen content flash
Frame 4 (1000ms+):    Loop after 800ms pause
```

Implementation: Framer Motion timeline OR Lottie file. Avoid GIF (poor quality, large file size).

## 9.8 Mobile Animation Considerations

- Reduce animation complexity on mobile (devices vary widely in GPU).
- Disable parallax on mobile entirely (`prefers-reduced-data` + viewport detection).
- Sticky bottom CTA bar should animate in once on first scroll (slide-up + fade, 250ms) — don't re-animate on every scroll back.

## 9.9 Anti-Patterns to Forbid

- ❌ Parallax that moves at >0.2× scroll rate (motion sickness).
- ❌ Auto-playing carousels without user control (accessibility violation).
- ❌ Animations longer than 600ms outside of dashboard "live" elements.
- ❌ Spring animations with high bounce (gimmicky).
- ❌ Animated GIF backgrounds (poor performance, no accessibility control).
- ❌ Cursor-following decorative elements (distracting, no ROI).

---

# 10. Frontend Engineering Recommendations

## 10.1 Stack Recommendation

| Layer | Choice | Rationale |
|---|---|---|
| **Framework** | Next.js 14+ (App Router) | RSC, file-based routing, ISR, image optimisation, vercel-hostable. Industry standard for marketing SaaS. |
| **Language** | TypeScript (strict) | Non-negotiable for a team product. |
| **Styling** | Tailwind CSS v3.4+ | Co-located styling, design-system enforceable via `tailwind.config.ts`. |
| **UI Primitives** | Radix UI | Headless, accessible primitives for accordion, dialog, tooltip, dropdown. |
| **Animation** | Framer Motion | Best React animation library; gestures, scroll reveals, page transitions. |
| **Icons** | Lucide React | Open-source, monoline, consistent. ~1,000 icons. |
| **Forms** | React Hook Form + Zod | Best-in-class form handling + schema validation. |
| **CMS (V2)** | Sanity or Contentful | For blog, customer stories, changelog. Headless. Editor-friendly. |
| **Analytics** | Plausible (light) + PostHog (full) | Plausible for marketing pages, PostHog for product analytics. |
| **A/B Testing** | PostHog Experiments or Vercel Edge Config | For homepage hero variants, CTA copy testing. |
| **Calendly Replacement (V2)** | Cal.com (self-hosted) | If volume justifies, replace Calendly with Cal.com for branding control. |
| **Hosting** | Vercel | Best-in-class for Next.js. ISR + edge functions + analytics. |
| **DNS / CDN** | Cloudflare | DDoS protection + edge caching + free SSL. |
| **Image CDN** | Next.js Image + Vercel | Automatic WebP/AVIF, responsive sizing. |
| **Fonts** | Self-hosted via `next/font` | No Google Fonts CDN (privacy, performance). |
| **Error Monitoring** | Sentry | Standard. |
| **Performance Monitoring** | Vercel Analytics + Lighthouse CI | In CI pipeline. |
| **Search (V2)** | Algolia or Pagefind | For docs, blog, customer stories. |

## 10.2 Next.js App Router Structure

```
app/
├── layout.tsx                 # Root layout — global nav, footer, theme
├── page.tsx                   # Homepage
├── globals.css                # Tailwind directives + CSS variables
│
├── (marketing)/               # Route group for marketing pages
│   ├── platform/page.tsx
│   ├── admin-panel/page.tsx
│   ├── teacher-app/page.tsx
│   ├── parent-app/page.tsx
│   ├── modules/
│   │   ├── page.tsx           # Modules index
│   │   └── [slug]/page.tsx    # Dynamic module page
│   ├── solutions/
│   │   ├── page.tsx
│   │   └── [slug]/page.tsx
│   ├── pricing/page.tsx
│   ├── customers/
│   │   ├── page.tsx
│   │   └── [slug]/page.tsx
│   ├── resources/
│   │   ├── blog/
│   │   │   ├── page.tsx
│   │   │   └── [slug]/page.tsx
│   │   └── ...
│   ├── about/page.tsx
│   ├── why-us/page.tsx
│   ├── security/page.tsx
│   ├── faq/page.tsx
│   ├── contact/page.tsx
│   └── demo/page.tsx
│
├── (legal)/                   # Route group with simpler layout
│   ├── layout.tsx
│   ├── terms/page.tsx
│   ├── privacy/page.tsx
│   └── ...
│
├── api/
│   ├── contact/route.ts       # Contact form endpoint
│   ├── newsletter/route.ts    # Newsletter signup
│   └── webhook/calendly/route.ts
│
├── sitemap.ts                 # Dynamic sitemap generation
├── robots.ts                  # Dynamic robots.txt
├── manifest.ts                # PWA manifest
└── opengraph-image.tsx        # Default OG image
```

## 10.3 Component Organisation

```
components/
├── ui/                        # Primitive components (button, input, accordion)
│   ├── Button.tsx
│   ├── Card.tsx
│   ├── Accordion.tsx
│   └── ...
│
├── marketing/                 # Marketing-specific blocks
│   ├── heroes/
│   │   ├── HeroSplit.tsx
│   │   ├── HeroCentered.tsx
│   │   └── HeroFullProduct.tsx
│   ├── feature/
│   │   ├── FeatureGrid4.tsx
│   │   ├── FeatureRowAlternating.tsx
│   │   └── BentoGrid.tsx
│   ├── proof/
│   │   ├── LogoWall.tsx
│   │   ├── StatStrip.tsx
│   │   ├── FeaturedTestimonial.tsx
│   │   └── TestimonialGrid.tsx
│   ├── workflows/
│   │   ├── WorkflowFlowDiagram.tsx
│   │   └── WorkflowTimeline.tsx
│   ├── cta/
│   │   ├── CTAStripFullWidth.tsx
│   │   └── CTAInlineBanner.tsx
│   ├── faq/
│   │   └── FAQAccordion.tsx
│   └── ...
│
├── navigation/
│   ├── NavBar.tsx
│   ├── NavDropdown.tsx
│   ├── MobileNavDrawer.tsx
│   └── Footer.tsx
│
├── forms/
│   ├── ContactForm.tsx
│   ├── NewsletterInline.tsx
│   └── DemoBookingEmbed.tsx
│
└── shared/
    ├── EyebrowTag.tsx
    ├── BadgePill.tsx
    ├── Section.tsx           # Section wrapper with consistent padding
    └── Container.tsx          # Max-width wrapper
```

**Naming conventions:**
- PascalCase for components.
- Co-locate styles in component (Tailwind classes inline).
- Co-locate component types/interfaces in the same file unless shared.
- One component per file.
- Export default for the main component, named exports for variants.

## 10.4 Tailwind Configuration

```ts
// tailwind.config.ts (abbreviated)
export default {
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
        display: ['var(--font-display)', 'serif'],
        sans: ['var(--font-sans)', 'system-ui', 'sans-serif'],
        mono: ['var(--font-mono)', 'monospace'],
      },
      fontSize: {
        'hero': ['72px', { lineHeight: '1.05', letterSpacing: '-0.02em', fontWeight: '500' }],
        'display': ['56px', { lineHeight: '1.1', letterSpacing: '-0.02em', fontWeight: '500' }],
        // ...full type scale
      },
      spacing: {
        // 8pt-based custom keys if needed beyond Tailwind defaults
      },
      maxWidth: {
        'container': '1280px',
        'reading': '720px',
      },
      transitionTimingFunction: {
        'out-expo': 'cubic-bezier(0.16, 1, 0.3, 1)',
        'out-back': 'cubic-bezier(0.34, 1.56, 0.64, 1)',
      },
    },
  },
  plugins: [
    require('@tailwindcss/typography'),  // for blog posts
    require('@tailwindcss/forms'),
  ],
}
```

## 10.5 Performance Strategy

| Metric | Target | Strategy |
|---|---|---|
| **Largest Contentful Paint (LCP)** | < 1.8s | Static generation + image optimisation + critical CSS inline |
| **First Input Delay (FID) / INP** | < 100ms | Minimal client-side JS; RSC by default; islands for interactivity |
| **Cumulative Layout Shift (CLS)** | < 0.05 | Reserve dimensions for images, fonts (`font-display: swap` with size-adjust), embeds |
| **First Contentful Paint (FCP)** | < 1.2s | Preload hero font + hero image |
| **Total Blocking Time (TBT)** | < 200ms | Code-split aggressively; lazy-load below-fold |
| **Lighthouse score** | ≥ 95 all four categories | CI gate (Lighthouse CI fails build below threshold) |

### Specific Optimisation Techniques

- **Static site generation** for all marketing pages. ISR (`revalidate: 3600`) for stat strips that need live numbers.
- **`next/image`** for every image. Use `priority` only on above-fold images. Use `loading="lazy"` on everything below.
- **`next/font`** for self-hosted fonts. Subset to Latin + Devanagari + Punjabi (Indian market readiness).
- **React Server Components** by default. Mark interactive components `"use client"` only as needed.
- **Code splitting** by route automatically (Next.js does this). Inside heavy components, use `next/dynamic` for non-critical client-side imports.
- **Preload hero assets** in `<head>`: hero font, hero image, brand logo.
- **Inline critical CSS** for above-fold content (Tailwind v4 will help; manual extraction for v3).
- **Defer non-critical scripts:** Analytics, Calendly, marketing pixels — load after `onLoad`.
- **Image format:** AVIF first, WebP fallback, JPEG/PNG as last resort. Next.js Image handles this.
- **Edge caching** via Vercel: HTML cached for 1 hour, static assets immutable.
- **Bundle analysis:** Run `next build` with `@next/bundle-analyzer` in CI; fail if main JS bundle exceeds 200KB gzipped.

## 10.6 Mobile-First Breakpoints

Tailwind defaults are good; specifically:

| Breakpoint | Min-width | Target Devices |
|---|---|---|
| (default) | 0px | Mobile portrait — primary design target |
| `sm:` | 640px | Mobile landscape, small tablet portrait |
| `md:` | 768px | Tablet portrait (iPad mini), large mobile landscape |
| `lg:` | 1024px | Tablet landscape, small laptop — desktop design begins here |
| `xl:` | 1280px | Standard desktop |
| `2xl:` | 1536px | Large desktop — content max-width kicks in |

**Design philosophy:** Mobile-first. Default Tailwind classes target mobile. `md:`/`lg:` progressively enhance.

**Critical breakpoint moments:**
- `< md` — Single column everywhere. Hide non-essential decorative elements.
- `md – lg` — Two-column layouts begin. Sticky nav becomes more permanent.
- `≥ lg` — Full desktop layouts. Hover states enabled.
- `≥ 2xl` — Content remains max-width 1280px; outer padding expands.

## 10.7 SEO Implementation

Already specified in Phase 4 Part 9. Engineering tasks:

- Build a typed `<Metadata>` helper for consistent per-page metadata.
- Implement `app/sitemap.ts` to dynamically generate sitemap from page registry.
- Implement `app/robots.ts` for environment-aware robots.txt.
- Add Schema.org JSON-LD via `<Script type="application/ld+json">` in page heads.
- Ensure all images have `alt` text (lint rule in ESLint).
- Implement canonical URL component.
- Configure OG image generation via `@vercel/og` for dynamic OG images per page.

## 10.8 Accessibility (a11y)

Non-negotiable. Target: WCAG 2.1 AA.

- Semantic HTML5 throughout (`<nav>`, `<main>`, `<article>`, `<section>`).
- All interactive elements keyboard-accessible (Tab navigation works site-wide).
- Focus-visible rings on all focusable elements.
- ARIA labels on icon-only buttons.
- Color contrast: text against backgrounds ≥ 4.5:1 (use brand teal #0F766E on white, not on light tints).
- `prefers-reduced-motion` respected.
- All images have meaningful `alt` text (decorative images get `alt=""`).
- Form errors announced via ARIA live regions.
- Skip-to-content link at top of every page (visually hidden until focused).
- Test with axe-core in CI; manual screen-reader testing before launch.

---

# 11. Conversion Optimisation Layer

## 11.1 Demo CTA Placement Strategy

The primary CTA across the site is **"Book a Demo"**. It must be reachable in every visitor state.

### Placement Pattern (Per Page)

| Position | Element | Behaviour |
|---|---|---|
| Top-right of nav | "Book a Demo" button | Always visible (sticky nav) |
| Hero section | Primary CTA | Below-fold within 1 viewport |
| Every 3 sections | Inline CTA banner or section CTA | Re-engagement every ~1,000px scroll |
| Final section | Full-width CTA strip | Last conversion attempt |
| Mobile sticky | Bottom-bar CTA | Persistent across all pages on mobile |
| Exit-intent (desktop) | Modal | One-time only, lead magnet ("Get the comparison guide") |

### CTA Copy by Position

| Position | Primary Copy | Why |
|---|---|---|
| Nav | "Book a Demo" | Direct |
| Hero | "Book a Live Demo" | "Live" signals real product, not slideware |
| Mid-page (after problem framing) | "See It in Action" | Curiosity-driven |
| Mid-page (after social proof) | "Schedule Your Walkthrough" | Personal-ownership framing |
| Final strip | "Schedule a 30-Minute Demo" | Time-bound = lower commitment perception |
| Mobile sticky | "Book Demo" + WhatsApp icon | Multi-channel for impatient mobile visitors |
| Exit-intent | "Get the Vendor Comparison Guide" | Lead-magnet, not direct demo (already declining) |

## 11.2 Sticky CTA Logic

### Desktop
- **Sticky nav** with persistent "Book a Demo" button — always.
- **Sticky banner CTA** appears at scroll depth 30% (slides down from top, 250ms). Contains "Book a Demo" + dismiss `×`. Hides at scroll depth 95% (before footer).
- **Exit-intent modal** triggers on mouse leaving viewport top. Fires once per session per page. Stores dismissal in `localStorage` for 7 days.

### Mobile
- **Persistent bottom CTA bar** — always visible. Contains "Book Demo" pill + WhatsApp icon + Phone icon. Compresses to icons-only on scroll-down, expands on scroll-up.
- **No exit-intent on mobile** (no reliable signal).
- **WhatsApp deep-link** opens chat with pre-filled message: "Hi, I'm interested in [Brand] for my school."

## 11.3 Social Proof Sequencing

Social proof must compound through the page. Don't dump it all at the top; sequence it.

```
Page top:    Logo wall + headline stats        — institutional credibility
After §3:    No additional proof               — let problem narrative breathe
After §6:    "Trusted by 500+ schools" sub     — reinforce mid-scroll
After §9:    Featured testimonial inline       — narrative humanity
After §12:   Customer story carousel           — peer validation
Pre-CTA:     Outcome stats + featured quote    — final reassurance
Footer:      Press logos + trust badges        — sector recognition
```

**Rule:** Each proof type appears once, in a deliberate position. Repetition dilutes.

## 11.4 Enterprise Trust Signals

For larger institutional buyers (school chains, trusts), additional signals matter:

| Signal | Where to Surface |
|---|---|
| Compliance posture | Hero stat strip + Security page + dedicated badge wall |
| Customer logos (real, named) | Hero trust bar + footer |
| Specific institutional outcomes | Customer stories + Why Us page |
| SLA commitments | Pricing page + Security page |
| Data residency options | Security page + FAQ |
| Audit certifications | Security page + footer compliance row |
| Senior leadership visibility | About page + Press page |
| Years in operation | Footer copyright + About page numbers |
| Funded / profitable status | About page (if positive signal) |
| Customer reference availability | "Talk to a reference customer" CTA on Why Us page |

## 11.5 Scroll Depth Optimisation

Visitors who scroll deeper convert more. Optimise for scroll depth as a leading indicator.

### Tactics

- **First scroll trigger:** First scroll fades in trust bar with subtle animation. Signals "there's more here."
- **Mid-page momentum:** Animated workflow diagrams (Section 6) reward scrolling — visitors stay to watch.
- **Section transition surprise:** Alternate dark/light sections. Each transition creates a "new page" feeling that resets attention.
- **Curiosity breaks:** Place teaser callouts ("See how this works →") that anchor-link to deeper sections. Encourages exploration.
- **Footer is not the end:** Place a final-final CTA at the very bottom of footer ("Still curious? Here's a 60-second tour"). Catches visitors who scroll past the main final CTA.

### Measurement

Track these as conversion correlates:
- % scrolled to 25%, 50%, 75%, 100%
- Time on page (median + 75th percentile)
- CTA visibility time (time the primary CTA was in viewport)
- CTA click rate per section
- Demo bookings per traffic source

A/B test priority targets:
- Hero headline copy
- Hero CTA button text
- "Most Popular" tier on pricing
- Final CTA strip background color (brand vs accent)

---

# 12. Final Build Recommendation & Launch Strategy

## 12.1 Tiered Page Priority (Build Order)

### Tier 1 — Launch Critical (Weeks 1–6)
**Goal:** Functional, conversion-ready public website. ~14 pages.

| Priority | Page | Reasoning |
|---|---|---|
| 1 | Homepage | Highest-traffic page; hero conversion source |
| 2 | Pricing | Most-direct conversion driver |
| 3 | Contact + Demo booking | Conversion completion |
| 4 | Admin Panel | Primary B2B buyer page |
| 5 | Parent App | Public-facing trust + adoption signal |
| 6 | Teacher App | Staff onboarding adoption signal |
| 7 | About | Trust foundation |
| 8 | Why Us | Differentiation page (paid-ad destination) |
| 9 | Security | Enterprise procurement requirement |
| 10 | FAQ | Sales support + SEO |
| 11–13 | Top 3 Module Pages (Fees, Attendance, Admissions) | Highest-search-volume modules; SEO drivers |
| 14 | Legal pages (Terms, Privacy, Cookies, DPA) | Compliance |

### Tier 2 — Post-Launch (Weeks 7–14)
**Goal:** Complete content depth, more SEO coverage. ~15 pages.

| Page | Reasoning |
|---|---|
| Remaining 10 module pages | Long-tail SEO |
| 4 Industry/Solutions pages | Vertical paid-ad destinations |
| Customer Stories index | Trust depth |
| 2–3 individual customer stories | Conversion case studies |
| Platform overview (`/platform`) | Cohesion |
| Resources index + initial blog setup | Content marketing foundation |
| Careers + Press | Recruiting / PR |

### Tier 3 — Growth & Scale (Months 4–6)
**Goal:** Content engine + experimentation.

- Blog content engine — 2 posts/week
- 5–10 more customer stories
- Webinar landing pages
- Migration Guide deep-dive
- Help Center / Knowledge Base
- API documentation (post API launch)
- Comparison pages vs specific competitors
- Localisation (Hindi first, then Tamil, Telugu)

## 12.2 MVP Website Launch Strategy

### Week 1–2: Foundation
- Next.js + Tailwind + Radix scaffolding
- Design system implementation (colors, typography, spacing, base components)
- Nav + footer + sticky CTAs
- Component library (heroes, cards, CTAs, stat strips, feature grids)
- Sitemap + robots + base SEO infrastructure
- Vercel deployment + Cloudflare DNS + analytics setup

### Week 3–4: Hero Pages
- Homepage (all 20 sections)
- Pricing page
- Contact page + form backend
- Demo booking integration (Calendly)

### Week 5: Product Pages
- Admin Panel
- Parent App
- Teacher App

### Week 6: Trust + Coverage Pages
- About, Why Us, Security, FAQ
- Top 3 module pages (Fees, Attendance, Admissions)
- Legal pages
- QA, performance audit, accessibility audit, Lighthouse CI
- **LAUNCH**

### Week 7+: Tier 2 Rollout (Post-Launch)
- One new page shipped every 2–3 days
- Customer story content sourcing in parallel
- Blog content engine spin-up

## 12.3 What to Delay to V2

Resist scope creep. Defer until post-launch:

| Feature | Defer Because |
|---|---|
| Customer Stories deep-dive page template (full multi-story) | Need real stories first; placeholder content harms trust |
| Blog | Needs sustained 2 posts/week to be credible; don't launch empty |
| Help Center / Knowledge Base | Better to launch from existing-customer demand, not assumption |
| API Documentation | API doesn't exist yet (2027 roadmap) |
| Multi-language | Launch in English; add Hindi after product-market fit |
| Self-serve signup flow | Pilot via sales-led; self-serve at scale (Year 2) |
| Comparison pages vs specific competitors | Need legal review per vendor; do strategically |
| Webinar registration system | Use third-party (Zoom + Mailchimp) until volume justifies in-house |
| Live chat widget | Defer; rely on demo booking + WhatsApp + email until support volume justifies |
| Interactive product demos (Tinybird-style scrolly) | Phase 3 production once base content is performing |
| Trust Center (dedicated /trust-center) | Defer past Security page until enterprise sales pipeline justifies |
| Investor / fundraising page | Only if raising capital — keep private otherwise |

## 12.4 Highest-Conversion Pages (Rank-Ordered)

Based on standard SaaS marketing benchmarks for the EdTech ERP category:

| Page | Estimated Share of Total Conversions |
|---|---|
| Homepage | 35–40% |
| Pricing | 18–22% |
| Why Us | 10–12% |
| Customer Stories (when fully populated) | 8–10% |
| Module pages (combined) | 8–10% |
| Contact | 5–7% |
| Industry pages | 4–6% |
| About | 2–3% |
| FAQ | 2–3% |
| Other | < 2% |

**Implication:** Spend disproportionate design and copy attention on Homepage, Pricing, and Why Us. These three pages account for ~60–75% of all conversions.

## 12.5 Launch Quality Gates (Non-Negotiable)

Do not launch until:

- ✅ Lighthouse score ≥ 95 on all four categories, all Tier-1 pages
- ✅ Mobile usability tested on 5+ real devices (range: low-end Android to flagship)
- ✅ Cross-browser tested: Chrome, Safari, Firefox, Edge
- ✅ Accessibility audit: axe-core clean + manual screen-reader pass on Homepage, Pricing, Contact
- ✅ All forms tested end-to-end with real email delivery
- ✅ All CTAs route correctly (no dead clicks)
- ✅ 404 page designed and tested
- ✅ 500 error page designed and tested
- ✅ Page-load time < 2s on simulated 4G
- ✅ Sitemap submitted to Google Search Console
- ✅ Schema.org validated via Google's Rich Results Test
- ✅ OG tags validated via Facebook Sharing Debugger and Twitter Card Validator
- ✅ Analytics tracking confirmed firing on all key events (demo CTA click, form submit, scroll milestones)
- ✅ Privacy policy, Terms, DPA reviewed by legal counsel
- ✅ Cookie consent banner functional and compliant
- ✅ Demo booking integration tested end-to-end (Calendly → CRM → email confirmation)
- ✅ Internal team review: 3 senior stakeholders signed off

## 12.6 First 30 Days Post-Launch

| Week | Focus |
|---|---|
| Week 1 | Daily Lighthouse, error monitoring, hotfix any critical bugs. Watch funnel analytics. |
| Week 2 | Begin A/B test on hero headline (control vs variant). Watch CTA click rates. |
| Week 3 | Ship first customer story. Begin blog content cadence (2/week). |
| Week 4 | Review funnel data with sales team. Identify highest-drop-off page; iterate. |

---

# Document End

This blueprint specifies enough for a senior frontend team to begin Tier 1 build immediately. The remaining work — visual design files in Figma, brand identity finalisation, photography commissioning, copywriter polish on tier-2 pages, content engine standup — runs in parallel with engineering.

**The strategic recommendation:**

Build Tier 1 in six weeks. Launch. Iterate against real conversion data, not assumptions. Tier 2 ships as a continuous stream over the next eight weeks. Treat the website as a product — not a project. The first version is the worst version.

---

**Awaiting your direction. Possible next phases:**

- **Phase 6** — Figma design file structure spec + design tokens export
- **Phase 7** — Component-by-component visual design briefs (one Figma frame per component)
- **Phase 8** — Sales enablement: email sequences, demo follow-ups, RFP responses, comparison sheets
- **Phase 9** — Blog content strategy + 10-article launch calendar with briefs
- **Phase 10** — Investor / fundraising pitch deck content (16 slides)
- **Phase 11** — Brand identity brief (logo direction, voice & tone document, photography brief)
- **Phase 12** — App store creative (screenshots, listing video script, feature graphic)

**Please respond with one of:**
- "Approved — proceed to Phase [N]"
- "Adjust [specific section] — [direction]"
- "Add [specific topic]"
- "Generate [specific deliverable]"
