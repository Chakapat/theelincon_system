---
name: Theelincon Office System
description: Warm bold Thai ERP — copper construction signal, Sarabun clarity, scannable data
colors:
  construction-orange: "#ea580c"
  burnt-copper: "#c2410c"
  copper-deep: "#9a3412"
  warm-peach-glow: "#f97316"
  orange-soft: "#ffedd5"
  orange-border: "#fdba74"
  office-canvas: "#f3f4f6"
  sidebar-mist: "#eceef2"
  slate-ink: "#0f172a"
  body-ink: "#1f2937"
  slate-muted: "#64748b"
  border-soft: "#e2e8f0"
  border-input: "#cbd5e1"
  surface-white: "#ffffff"
  success-soft: "#e6f4ea"
  success-ink: "#1e7e34"
  danger-soft: "#fdecea"
  danger-ink: "#c0392b"
typography:
  display:
    fontFamily: "'Sarabun', 'Noto Sans Thai', system-ui, sans-serif"
    fontSize: "clamp(1.85rem, 4.5vw, 2.5rem)"
    fontWeight: 800
    lineHeight: 1.12
    letterSpacing: "-0.02em"
  headline:
    fontFamily: "'Sarabun', 'Noto Sans Thai', system-ui, sans-serif"
    fontSize: "clamp(1.25rem, 2.5vw, 1.55rem)"
    fontWeight: 800
    lineHeight: 1.2
    letterSpacing: "-0.02em"
  title:
    fontFamily: "'Sarabun', 'Noto Sans Thai', system-ui, sans-serif"
    fontSize: "1rem"
    fontWeight: 700
    lineHeight: 1.4
    letterSpacing: "normal"
  body:
    fontFamily: "'Sarabun', 'Noto Sans Thai', system-ui, sans-serif"
    fontSize: "1rem"
    fontWeight: 400
    lineHeight: 1.5
    letterSpacing: "normal"
  label:
    fontFamily: "'Sarabun', 'Noto Sans Thai', system-ui, sans-serif"
    fontSize: "0.68rem"
    fontWeight: 800
    lineHeight: 1.3
    letterSpacing: "0.12em"
rounded:
  sm: "0.5rem"
  md: "0.625rem"
  lg: "0.875rem"
  pill: "50rem"
spacing:
  xs: "0.25rem"
  sm: "0.5rem"
  md: "1rem"
  lg: "1.5rem"
  xl: "2rem"
components:
  button-primary:
    backgroundColor: "{colors.construction-orange}"
    textColor: "{colors.surface-white}"
    rounded: "{rounded.pill}"
    padding: "0.55rem 1.5rem"
  button-primary-hover:
    backgroundColor: "{colors.burnt-copper}"
    textColor: "{colors.surface-white}"
    rounded: "{rounded.pill}"
    padding: "0.55rem 1.5rem"
  button-outline:
    backgroundColor: "transparent"
    textColor: "{colors.construction-orange}"
    rounded: "{rounded.pill}"
    padding: "0.55rem 1.25rem"
  input-default:
    backgroundColor: "{colors.surface-white}"
    textColor: "{colors.slate-ink}"
    rounded: "{rounded.md}"
    padding: "0.5rem 0.75rem"
  card-surface:
    backgroundColor: "{colors.surface-white}"
    textColor: "{colors.slate-ink}"
    rounded: "{rounded.lg}"
    padding: "1rem 1.25rem"
  nav-topbar:
    backgroundColor: "{colors.burnt-copper}"
    textColor: "{colors.surface-white}"
    rounded: "{rounded.sm}"
    padding: "0.5rem 1rem"
---

# Design System: Theelincon Office System

## 1. Overview

**Creative North Star: "The Warm Operations Desk"**

Theelincon Office System is a data-heavy internal ERP for Thai construction/operations staff. Visual language balances **bold warm identity** (copper orange, confident type scale) with **scan speed** for tables, forms, and money. Sarabun carries hierarchy; orange is a learned signal for brand and primary action — not wallpaper.

Rejects generic SaaS gray, Inter defaults, nested cards, marketing landing aesthetics inside app shells, glassmorphism, purple–blue AI gradients, and motion that slows workflows.

**Key Characteristics:**

- **Copper orange as signal** — navbar gradient, primary CTAs, active nav, welcome strips; ~15% fill max on content screens.
- **Bold hierarchy** — display/headline scale jumps; kickers (uppercase label) on section heads; money columns heavy weight.
- **Sarabun-first Thai readability** — one sans family; weights 400–800.
- **Solid surfaces** — white cards on warm canvas; shadows intentional, not glass blur.
- **Bootstrap 5 + `--tnc-*` tokens** — extend, don't fight; unify legacy `#fd7e14` / blue drift over time.

## 2. Colors

Warm **copper construction** on cool neutral office surfaces — confident, not playful.

### Primary (2026 — darker copper)

- **Construction Orange** (#ea580c): Primary brand, `.btn-orange`, hub accents. Replaces legacy #fd7e14 over time.
- **Burnt Copper** (#c2410c): Navbar gradient start, hover/pressed buttons, strong links.
- **Copper Deep** (#9a3412): Active sidebar text, emphasis on hover rows.
- **Warm Peach Glow** (#f97316): Gradient endpoint with Burnt Copper — navbar, sign-in hero only.

### Tints

- **Orange Soft** (#ffedd5) / **Orange Border** (#fdba74): Welcome bars, icon wells, table header rules.

### Neutral

- **Office Canvas** (#f3f4f6): Body background with subtle warm radial accents.
- **Sidebar Mist** (#eceef2): Hub sidebar panels.
- **Surface White** (#ffffff): Cards, inputs, tables.
- **Slate Ink** (#0f172a): Headings, money emphasis.
- **Body Ink** (#1f2937): Body copy.
- **Slate Muted** (#64748b): Helpers — must meet 4.5:1 on white.

### Semantic

- **Success Soft / Ink** (#e6f4ea / #1e7e34)
- **Danger Soft / Ink** (#fdecea / #c0392b)

### Named Rules

**The Orange Signal Rule.** Copper fill for navbar, primary CTAs, active navigation only. Under ~15% visible area on data screens.

**The No AI Slop Rule.** No purple–blue gradients, glassmorphism panels, or neon-on-dark in app chrome.

**The No Blue Drift Rule.** No new Bootstrap `#0d6efd` primary buttons.

## 3. Typography

**Font:** Sarabun (400, 600, 700, 800) — no Inter as primary.

### Hierarchy (bold product register)

- **Display** (800, clamp 1.85–2.5rem): Welcome titles, stat values.
- **Headline** (800, clamp 1.25–1.55rem): Page titles, invoice block heads.
- **Title** (700, 1rem): Section headers, active nav.
- **Body** (400, 1rem): Tables, forms.
- **Label** (800, 0.68rem, letter-spacing 0.12em, uppercase): Kickers (`PURCHASE MODULE`, `INVOICE HUB`).

## 4. Elevation

Flat data surfaces; **solid white cards** with border + medium shadow on key blocks (welcome bar, table shell). No backdrop-filter blur in app shell.

- **Card hover:** translateY(-3px) + shadow on stat/hub tiles only.
- **Welcome bar:** orange-tinted shadow `rgba(234, 88, 12, 0.18)`.
- **Sign-in:** orange ambient shadow allowed on entry only.

## 5. Components

### Buttons

- Pill primary: Construction Orange → Burnt Copper hover.
- Outline: `.btn-outline-orange` — copper text, orange-soft hover fill.

### Navigation

- **Navbar:** gradient `118deg` Burnt Copper → Construction Orange → Warm Peach Glow; inset highlight.
- **Sidebar hub:** module icon wells (master/purchase/docs/cash); 3px copper active bar.

### Welcome / Page heads

- Kicker + headline + optional badge pattern on index and list pages.

### Data Tables

- Thead: gradient gray + 2px orange-border bottom rule.
- Money column: 800 weight, tabular nums; copper-deep on row hover.

## 6. Do's and Don'ts

### Do:

- Use copper tokens (`#ea580c`, `#c2410c`) for new work; migrate legacy `#fd7e14` when touching a file.
- Bold hierarchy on index and list page headers before inner tables.
- Honor WCAG AA and **disable decorative motion** under `prefers-reduced-motion`.

### Don't:

- Don't use glassmorphism, purple gradients, or landing-page hero sections on every module.
- Don't animate every section on scroll.
- Don't nest cards or add motion that slows data entry.
