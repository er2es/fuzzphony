# 0.8.2: demo interface update: design and plan

Status: plan, not started. Demo only: no library change, no new composer dependency, no new JS dependency.
Written for: the maintainer, who decides the open points at the end before any code is written.

## 1. Goal

A visitor who lands on the demo should understand within ten seconds that this search is **fast** and **forgiving**,
without reading anything, and then want to try their own words. Today the demo is a correct, honest toolbox; this makes it
a product you want. "I want it" comes from three things, in this order: the first interaction feels instant, the result
looks like something they know (a shop, Spotlight), and the clever parts (typos, accents, synonyms, facets) show themselves
at the moment they matter instead of being explained above.

Success is checked, not felt (section 9): the first search is possible without scrolling on a phone and a laptop, the
text is readable in light and dark, every control is reachable by keyboard, and no page needs an explanation paragraph to
be used.

## 2. What is wrong today (facts from the code)

- One 163-line stylesheet, 15 px body, a handful of colour variables; the type scale, spacing and radii are ad hoc per rule.
- Nine flat navigation items (ILIKE vs Fuzzphony, Relevance, Synonyms, Playground, Languages, Config wizard, Benchmark,
  Doctor, Observability): the three that sell the product and the four that are for operators have equal weight.
- Every page opens with a paragraph of explanation, then examples, then the result: the thing to do comes last.
- Results are plain text lists. The speed, the product's main argument, is a grey sentence ("warm median 1.3 ms").
- A purple-to-teal gradient progress bar, an emoji logo and a status pill: decoration that says "template", not "product".
- Three different dropdown/chip/button looks (datalist era leftovers, `.chip`, `.button`).

## 3. Design principles (Apple Human Interface Guidelines, applied)

The HIG is a set of habits more than a style. These are the rules this plan holds itself to; each one removes something.

1. **Clarity.** One primary action per screen (the search field). Legible type (17 px body, a real scale), generous
   spacing, no decorative colour. Colour carries meaning only: one accent for "you can act on this", semantic colours for
   state (match, warning, error). No gradients, no glows, no emoji as ornament.
2. **Deference.** The content is the product: the results. Chrome recedes: a translucent toolbar (a material, not a
   coloured bar), hairline separators instead of boxes, no card around everything. Explanations are one disclosure away
   ("How this works"), never in front of the task.
3. **Depth.** Hierarchy through elevation and motion that explain cause and effect: the suggestion panel rises from the
   field; a facet choice updates the list in place; a page change cross-fades. Motion is 150 to 300 ms, eased, and
   removed for `prefers-reduced-motion`.
4. **Familiar patterns.** The Apple search field (rounded, magnifier, clear button), the segmented control, the inset
   grouped list (Settings, Spotlight), the sidebar for filters, tokens for chosen facets. No invented widgets.
5. **Direct manipulation and feedback.** Results change as you type; every state has a visible response within 100 ms
   (pressed, loading, empty, error). Nothing is a dead end: an empty result says what to try (the prefix tip, the did-you-mean).
6. **Accessibility is not a phase.** Text contrast at least 4.5:1 (3:1 for large), targets at least 44 x 44 px on touch,
   visible focus, complete keyboard use, correct roles (combobox/listbox for the suggestion panel), light and dark from
   the system setting, sizes in `rem` so the browser's text size applies.
7. **Consistency.** One token set drives every page; a component looks and behaves the same wherever it appears.

## 4. Foundation (one stylesheet, rewritten, tokens first)

`assets/styles/` is split into layers with `@layer tokens, base, components, pages;` (native CSS, no build step):

- **Type**: the system stack only (`-apple-system, "SF Pro Text", "Segoe UI Variable", system-ui`): zero font download, native
  look on Apple devices. Scale (rem): 0.8125 caption, 0.9375 callout, 1.0625 body, 1.25 title 3, 1.5 title 2, 2.125 title 1,
  3.0 display. Weights 400/500/600 only. `font-variant-numeric: tabular-nums` for every number. Tracking tightened on display sizes.
- **Space**: a 4 px grid (4, 8, 12, 16, 24, 32, 48, 64); content width 720 px for text, 1120 px for result + sidebar.
- **Shape**: radii 8 (controls), 12 (grouped lists), 20 (the search field and panels). Hairlines 1 px at 12% of the text colour.
- **Colour**: neutral greys with a very slight cool tint, one accent (see open point A), semantic ok/warn/bad. Light and dark
  defined once as tokens with `light-dark()`; surfaces: background, secondary (grouped list), elevated (panels). Text:
  label, secondary, tertiary. `color-mix()` derives hover and pressed states, so no hand-picked variants.
- **Material**: the toolbar and the suggestion panel use `backdrop-filter: blur(20px) saturate(1.4)` over a translucent
  surface, with an opaque fallback.
- **Motion tokens**: `--ease: cubic-bezier(.2, .8, .2, 1)`, durations 120/200/320 ms; all transitions read them; reduced
  motion sets them to zero.
- **Icons**: a small inline SVG sprite (magnifier, xmark, chevron, check, sliders, bolt, globe): 1.5 px strokes, drawn to
  match SF Symbols' weight, `currentColor`. No icon font, no emoji.
- **Components** (each a Twig macro plus a CSS block, documented once in `demo/README.md`): Toolbar, Search field, Suggestion
  panel, Segmented control, Grouped list and row, Token (chosen facet), Sidebar section, Stat (big number + caption),
  Disclosure ("How this works"), Empty state, Toast.

### 4a. Category illustrations (decided: simple SVG, one per category)

The benchmark catalogue has ten categories (Mice, Keyboards, Headphones, Drills, Kitchen, Monitors, Cameras, Lighting, Garden,
Office); the language catalogue adds a few (Tools, Computers, Audio, Sports, Electronics ...) that map onto them or onto a generic
fallback. Each gets one drawing:

- **Style**: line illustrations on a 48 x 48 grid, 1.5 px stroke, rounded joins, one or two flat fill shapes at low opacity for
  body, drawn with `currentColor` so the same SVG works in light and dark. No gradients, no shading, no faces: an object
  silhouette that reads at 24 px and at 64 px, in the family of SF Symbols (monoline, optical weight matching the type).
- **Tile**: a rounded 12 px square in a tint of the category's hue (`color-mix(in oklch, hue 14%, surface)`), the drawing in
  the full hue; ten hues spaced evenly in OKLCH at equal lightness and chroma, so no category shouts and all pass 3:1 against the tile.
- **Delivery**: one inline `<svg>` sprite (`<symbol id="cat-mouse">` ...) in `base.html.twig`, referenced by `<use>`: no request,
  cached with the page, about 4 KB. A `cat-generic` fallback covers unknown categories; the televisions get the Monitors drawing
  with a stand.
- **Decorative**: `aria-hidden`, the category name stays text next to the name; the tile never carries meaning alone.
- The drawings are made once, by hand, as part of commit 3 and reviewed in the screenshot harness at 24, 40 and 64 px.

## 5. Information architecture: nine items become four plus one

| New | Replaces | Why |
|---|---|---|
| **Search** (home) | ILIKE vs Fuzzphony, Benchmark | the same comparison; the benchmark is its "Measure" mode (segmented control: Results / Speed) |
| **Relevance** | Relevance, Synonyms | typo tolerance, synonyms and did-you-mean on one page; the synonym editor is a sheet opened from it |
| **Languages** | Languages | stays; the federated "all five at once" becomes the first segment |
| **Tune** | Playground | the sliders; named for what you do |
| **Under the hood** (a menu, last) | Config wizard, Doctor, Observability | for operators; out of the way |

The toolbar shows four items and the menu; the wordmark "Fuzzphony" (text, no emoji) on the left; a search shortcut hint
(`/`) on the right. Decided: **the old URLs are not kept**: the routes are renamed freely (`/`, `/relevance`, `/languages`, `/tune`, `/under-the-hood/...`), with no redirects; the demo is a showcase, and the README, CHANGELOG and docs links that name a demo page are updated in the same PR.

## 6. The pages

### 6.1 Search (home): "the first ten seconds"

- A large centred search field (rounded 20 px, magnifier, clear button), focused on load, 56 px high. Placeholder in
  the product's voice: "Try `hedphones`, `cr` or `tv`".
- Under the field, quiet **example tokens** (the existing `EXAMPLES`), each one a tap that fills the field. Three groups
  are enough: *typos* (hedphones), *beginnings* (cr), *meaning* (tv).
- While typing, the **suggestion panel** (already built) drops from the field: Searches, Categories, Products. It becomes the
  one dropdown of the whole demo. Material surface, 20 px radius, rows of 44 px (product rows carry the small illustration), arrow keys, `aria-activedescendant`.
- Results fill the page in place (no navigation): an **inset grouped list**, one row per product: a **category illustration tile** (a simple SVG drawing
  per category, see section 4a; no images to download), the name with the matched part set in medium weight (not a yellow
  `<mark>` highlighter), brand and category as secondary text, the price right-aligned in tabular figures.
- **Speed as the headline stat**: above the list "**14 ms** · 500,003 products" (Stat component). In the segmented control
  *Speed*, the ILIKE comparison appears as two bars on one scale with the factor ("31x faster"), the 1 cold + 5 warm
  method as a disclosure. ILIKE loads lazily as today, so Fuzzphony's answer is never held back.
- **Facets in a sidebar** (a bottom sheet on phones): Category, Stock; chosen values become **tokens** inside the search
  field; counts update with the list; "+" on lower bounds. The exact-counts switch lives in *Tune*.
- Empty state: a quiet sentence and the actionable tips (did you mean X, try `cr*`, remove a filter), never just "Nothing found".
- "How this works" disclosure under the results: the interpreted query (`(cr OR cr*)`), the statements, a link to *Tune*.

### 6.2 Relevance

Three stacked sections, each a grouped list with one live example the visitor can edit: **Typos** (by word length vs flat:
the existing two columns become a segmented control and a single list, with the changed rows marked), **Synonyms** (the
terms the catalogue knows, rendered as readable pairs; "Edit" opens a sheet with the textarea, validation inline,
"Group or rule?" help right there), **Did you mean** (a live field that shows the suggestion as you type).

### 6.3 Languages

A segmented control for the language (five plus "All"), the same search field and result rows as home, and the analysis
("how the words were analysed") as a side column on wide screens and a disclosure on phones. The federated view is the "All"
segment: rows carry a small language tag.

### 6.4 Tune (Playground)

Keeps its function. Changes: sliders get the system look (accent track, large thumb, live value), grouped into Ranking /
Typos / Filters sections of one inset list; a sticky result pane; the SQL/EXPLAIN panel becomes a sheet; the score
breakdown bar gets labels and a legend that works without colour alone.

### 6.5 Under the hood

Config wizard, Doctor, Observability get the new shell and tokens, tables become grouped lists, status uses icon + text
(never colour alone). No new functions.

## 7. Content and tone

One short sentence of purpose under each page title at most; everything else is a disclosure or the interface itself. Microcopy
in plain English, second person, no exclamation marks, no "magic" or "blazing". Numbers are specific (14 ms, 500,003). Error and
empty states say what happened and what to do.

## 8. Technical approach

- **Stack stays**: Twig, Stimulus (AssetMapper), Live Components; nothing to install. CSS uses what browsers ship in 2026:
  `@layer`, nesting, `light-dark()`, `color-mix()`, container queries (the result row adapts to its container, not the
  viewport), `:has()`, and **cross-document view transitions** (`@view-transition { navigation: auto }`) for the page cross-fade,
  with the existing loading bar removed (its job is replaced by the transition and the field's own busy state).
- **Stimulus controllers**: `suggest` (extended: `aria-activedescendant`, tokens, example fill), `facet-sheet`, `speed`
  (replaces `sequence`/`ilike` glue), `theme`-free (system setting only), `shortcut` (`/` focuses the field, Esc closes).
- **Speed**: no web fonts, no images, one stylesheet, `fetchpriority`/`preload` on the CSS, results render server-side on
  first paint, updates are Live Component or fetch partials; the suggestion endpoint stays at ~20 ms.
- **Performance budget** (checked): CSS under 20 KB gzip, JS under 30 KB gzip, LCP under 1 s locally, no layout shift on result updates.
- **Compatibility**: evergreen browsers (the demo already needs import maps). Features without support degrade to the plain
  layout (no blur, no transition), never to a broken one.

## 9. Verification (how I will know, since I cannot judge a UI from code)

The weak point of earlier demo work was that the UI was only checked at the HTTP level. This plan adds a small harness
(a script under `demo/tools/`, run through the official `mcr.microsoft.com/playwright` image, nothing added to composer or npm
of the project): it opens every page at 390 x 844 (phone), 1280 x 800 and 1920 x 1080, in light and dark and with reduced motion,
saves screenshots, and runs **axe-core** for accessibility violations plus a keyboard walk (Tab order, Escape, arrow keys in the
suggestion panel). Screenshots are reviewed by me and shown to the maintainer before the PR is opened. Contrast is computed from
the tokens in a unit-style script. Pass criteria: zero axe violations, no horizontal scroll at 390 px, focus visible on every
interactive element, text contrast at least 4.5:1.

## 10. Delivery

One pull request ("0.8.2: demo interface"), built in reviewable commits: 1 tokens and base, 2 shell and navigation , 3 components and the suggestion panel, 4 Search home, 5 Relevance (with the synonym sheet), 6 Languages, 7 Tune,
8 Under the hood, 9 harness and fixes from it, 10 docs (`demo/README.md` pages table, screenshots, CHANGELOG under Unreleased).
No release is cut by this plan; the maintainer decides the tag. Demo only: `src/` is untouched, so the library tests and
mutation score are unaffected.

## 11. Not in this plan

Real product photos (no assets to ship: the category illustrations stand in), a settings page, a theme switcher (the system setting rules),
user accounts, analytics, translation of the interface, any change to ranking or the library.

## 12. Open points for the maintainer

A. **Accent colour.** Keep the brand purple (`#5b3cc4`, used as a single tint), or move to a system-like blue? Recommendation:
   keep the purple, as one flat tint, no gradients: it is recognisable and it does not read as "default iOS".
B. **Navigation labels.** Search / Relevance / Languages / Tune / Under the hood: agree, or other names?
C. **Merging Benchmark into Search ("Speed" mode).** It removes a page; the load-test style numbers stay in the README. Agree?
D. ~~Monograms or illustrations~~ decided: simple SVG illustrations, one per category (section 4a).
E. ~~Redirects for the old URLs~~ decided: none, the routes change freely (breaking is fine for the demo).
F. **Scope guard.** If the PR grows, the cut line is: items 1 to 6 ship in 0.8.2, Tune and Under the hood restyle follow in 0.8.3.
