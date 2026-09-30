---
name: frontend-css-design
description: Use for SmartBook front-end/admin CSS and markup work - styling admin pages, meta boxes, and public-facing templates/shortcodes, adding design tokens, dark mode, or new components. Covers both assets/css/sb-admin.css (wp-admin screens) and assets/css/sb-public.css (frontend).
---

# SmartBook Front-End CSS Design

## When to use

Any visual/styling work in this plugin: new admin page markup, meta box UI, dashboard cards/charts,
the public book listing/scan templates, or extending the design token set (colors, spacing, radius).

## Where things live

- `assets/css/sb-admin.css` — wp-admin screens only (dashboard, books table, meta boxes, import/export,
  settings). Enqueued by `app/Assets/AdminAssetLoader.php`, restricted to screens whose id starts with
  `sb_` or is listed via the `sb_admin_asset_screens` filter.
- `assets/css/sb-public.css` — frontend only (shortcode output, book scan page/actions). Enqueued by
  `app/Assets/FrontendAssetLoader.php`, gated by the `sb_should_enqueue_frontend_assets` filter.
- No preprocessor, no build step — these are the actual files the browser loads. Edit them directly.
  Cache-busting is automatic (`sb_asset_version()` uses the file's own mtime), so no version bump needed.
- Markup that carries these classes is rendered from PHP, not templates: admin pages under
  `app/Admin/Pages/*.php` and `app/Admin/Tables/BooksListTable.php`; meta boxes under `app/MetaBoxes/*.php`;
  public markup in `app/Frontend/BooksShortcode.php`, `app/Frontend/BookContentDisplay.php`,
  `app/Frontend/BookScanPage.php`, `app/Frontend/BookScanActions.php`. When a component needs a new class,
  add it in both the PHP `render()`/`echo` markup and the stylesheet in the same change.

## Conventions to follow (already established — don't invent alternatives)

**Design tokens on `:root`.** Every color, radius, shadow, spacing, and transition value is a custom
property (`--sb-color-*`, `--sb-radius`, `--sb-shadow`, `--sb-space-1`…`--sb-space-6`, `--sb-transition`).
Rules consume tokens via `var(--sb-...)`; they never hardcode a hex value or px size that already has a
token. If a new color/spacing value is needed, add a token to `:root` (and its dark-mode override) rather
than inlining it.

**Dark mode via token redeclaration.** `@media (prefers-color-scheme: dark)` redeclares the same
`--sb-color-*` names with dark values inside a second `:root` block near the top of the file. Component
rules below never contain their own dark-mode media queries — they just reference the token, and it
already resolves correctly in both modes. Add a dark-mode value alongside every new light-mode color
token.

**BEM naming, `sb-` prefixed.** `sb-block`, `sb-block__element`, `sb-block--modifier` /
`sb-block__element--modifier` (e.g. `.sb-badge`, `.sb-badge--overdue`, `.sb-book-panel__progress-fill`,
`.sb-scan__status--available`). One top-level block class per component; elements are always
double-underscore children of it, modifiers always double-dash. This mirrors the `sb`/`SB`/`Sb` prefix
PHPCS enforces on the PHP side (`phpcs.xml.dist`'s `PrefixAllGlobals` rule) — CSS classes get the same
treatment even though nothing lints it automatically.

**Utility classes stay minimal.** `.sb-hidden { display: none; }` is the only general-purpose utility;
don't grow a utility-class system — prefer a BEM modifier on the component instead (e.g. `--placeholder`,
`--secondary`, `--on`).

**Admin vs. public token sets differ on purpose.** `sb-admin.css` has a fuller token set (radius, shadow,
spacing scale, transition, star colors) matching wp-admin's own visual density; `sb-public.css` is leaner
(just color tokens) since it inherits the active theme's spacing/typography. Don't copy the full admin
token set into the public stylesheet — add only what a given public component actually needs.

**Formatting.** Tabs for indentation, one selector per line, space inside parens for CSS custom property
usage in this codebase's existing style (`var( --sb-color-bg )`, `rgba( 0, 0, 0, 0.06 )`) — match whichever
file you're editing.

## Verification

There is no CSS linter/build step configured. After changes:

- Reload the relevant screen (a `sb_`-prefixed wp-admin page, or a frontend page using the
  `[smartbook_books]` shortcode / book scan page) and visually confirm in both light and dark OS theme.
- Confirm new classes match between the PHP markup and the stylesheet (a class only used in one place is
  either dead CSS or a typo).
- If markup changed, check for any JS in `assets/js/` that selects the same classes (e.g.
  `sb-import-progress`, `sb-books-filters__favorite`, `sb-scan__button`) so a rename doesn't silently break
  behavior — `node --check` only validates syntax, not selector usage, so grep for the class name across
  `assets/js/*.js`.
