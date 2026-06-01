# Impeccable init refresh — 2026-06-01

User re-interviewed via `/impeccable init` (requirements reset).

## Decisions

| Topic | Choice |
|-------|--------|
| Scope | Refresh PRODUCT.md + DESIGN.md + design.json |
| Register | Product — bolder personality, still usable ERP |
| Visual energy | **Bold** — welcome/hero, contrast; not landing page |
| Primary color | **Darker copper** — `#ea580c` / `#c2410c` (replaces `#fd7e14` over time) |
| Personality | Warm + bold + trustworthy (construction) |
| Anti-refs | SaaS gray, Inter, nested cards, landing in app, glass, purple AI slop, heavy animation |
| Rollout | All modules gradually — **start index** |
| A11y | WCAG 2.1 AA + stricter `prefers-reduced-motion` |

## Next commands

1. `/impeccable bolder index.php` — align index to new copper tokens
2. `/impeccable polish sign-in.php`
3. `/impeccable polish pages/purchase` — migrate legacy `#fd7e14`
4. `/impeccable live` — browser variants (config already at `.impeccable/live/config.json`)
