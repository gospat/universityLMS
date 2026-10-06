# ULMS Responsive UI Architecture Remediation (Phase 2) — Product Requirements Document

## Overview
- **Summary**: Replace the current shell/content layout with a genuinely fluid, responsive enterprise application shell that uses the full viewport width at all sizes (2560→320), with intentional layout modes for large desktop / standard desktop / small desktop tablet / tablet / mobile. Includes a corrected sidebar (280/76 widths, no label truncation at expanded state), mobile offcanvas drawer, portal header responsive trimming, a polished Profile page structure, an intrinsic Quick Access grid, balanced page density, and automated overflow + screenshot QA matrix with independent reviews.
- **Purpose**: Correct the underlying responsive architecture so that ULMS behaves as a university-grade digital platform. The current iteration passes "reasonable" at 1366 but collapses into a narrow column or exhibits hard-capped content wrappers at 1920/2560, leaves a residual 20px+ gutter on the shell content, truncates normal sidebar labels, and lacks a polished Profile page.
- **Target Users**: All 4 portal users (Super Admin / Admin / Lecturer / Student) accessing ULMS on 320–2560px viewports on Chrome, Edge, Firefox, and Safari desktop + WebKit mobile.

## Goals
- **G1 Architecture**: Provide a fluid 100% viewport shell at ALL desktop widths (≥1024), eliminating hard-coded container-fluid 1280px caps, fixed 1200 main-inner caps, and excess 40px gutters that create a centered "tube" at 1920/2560.
- **G2 Breakpoints**: Implement 5 intentional layout modes (Large ≥1440, Standard 1200–1439, Small Desktop 1024–1199, Tablet 768–1023, Mobile <768) with minimal media queries and only where a layout *mode* change is needed.
- **G3 Sidebar**: Expanded ~280 px wide enough that normal nav labels (Dashboard, My courses, Course catalogue, Academic progress, Announcements, Academic programme, Attendance, etc.) render WITHOUT ellipsis/truncation at 1200 and wider; collapsed desktop state 76 px ± icon-only, zero label fragments; mobile drawer offcanvas.
- **G4 Mobile Drawer**: Offcanvas drawer on mobile (< 768), with overlay backdrop, ESC close, body scroll-lock while open, focus-safe. No persistent 280 px empty column on mobile.
- **G5 Header**: Left-to-right identity/pill/menu on desktop, smart info truncation at tablet, compact Bells identity + avatar at mobile; no horizontal overflow at any viewport.
- **G6 Profile Page**: Polished Profile page with page header, avatar card + name/role/email/username/last access, actions (View/edit Moodle profile; Change/reset password), and responsive 2–3 column layout at XL → 2 at L → 1 at mobile.
- **G7 Quick Access**: Intrinsic grid `repeat(auto-fit, minmax(260px, 1fr))` → naturally 4 at XL, 2–3 at MD/LG, 1 at SM; cards never absurdly wide or tiny.
- **G8 Density**: Balanced page composition — page content feels intentional at 1366, 1440, 1920, 2560; no absurd vertical whitespace.
- **G9 QA**: Screenshot matrix (phone 6 widths / tablet 3 / desktop 6 / ultrawide 1) + 390 landscape / tablet landscape / zooms 125, 150, 200, plus automated overflow test. Independent UI/UX visual + responsive specialist reviews, with Accessibility QA.

## Non-Goals
- NG1: No visual identity / color / logo change.
- NG2: No changes to authentication, database, permissions, courses, grades, users, business logic, server configuration or production secrets.
- NG3: No new Moodle core edits. All changes in theme templates + SCSS + (optionally) theme-only renderer/PHP helpers.
- NG4: No "just bump a max-width" — fixes must be at the shell architecture level.
- NG5: No deployment to production before the 14 evidence items (per user PRD tail) are returned passing.

## Background & Context
### Observed current state (via browser_evaluate live on Student dashboard, 1983×1443 viewport):
**1. Shell inner wrapper creates a centered "tube" at ultrawide**:
  - `.ulms-shell-content` = `gridTemplateColumns: 1601.3px` hard-capped (shellContent.width 1681 px but inner box region-main-box is a single 1601 px grid track even though parent is 1681 px available).
  - `.ulms-shell-content` padL/padR = **40 px each**. Combined with inner single-column auto-flow, this creates an unexplained ~80+ px blank region between sidebar edge and the actual dashboard grid cards at XL widths.
  - `.ulms-page-header__main` has a separate inner `max-width: 832px` (per live computed), adding a second cap inside the header copy.
  - `.ulms-list` (Academic Profile list) caps at `826 px wide` inside a 1482 px container, producing huge right blank region.
  - On standard_shell pages (Profile etc.):
    - `navbar > .container-fluid.ulms-shell-topbar` = **`max-width: min(1280px,100%)` + `margin:0 auto`** ([default.scss:649-653](file:///Users/gloriousanjorin-adeboye/UNIVERSITY%20LMS/theme/ulms_university/scss/preset/default.scss#L649-L653)) — topbar content becomes a centered 1280 px column on ultrawide even when the rest of the shell is 100%.
    - `.ulms-portal-footer` = **`max-width: 1280px; margin:0 auto`** ([default.scss:1338-1344](file:///Users/gloriousanjorin-adeboye/UNIVERSITY%20LMS/theme/ulms_university/scss/preset/default.scss#L1338-L1344)) — footer matches the tube.
    - `.ulms-custom-page .main-inner` = `max-width: 1200px` ([default.scss:1522](file:///Users/gloriousanjorin-adeboye/UNIVERSITY%20LMS/theme/ulms_university/scss/preset/default.scss#L1522)) — third independent cap.
  - Result: at 2560 px the user sees a "small island app" centered in the viewport.

**2. Sidebar label truncation (expanded desktop, 280 sidebar outer)**:
  - Inner sidebar usable width for a nav link = `280 total` - `20 padL` - `17.6 padR` - `13.6+13.6 link pad` = **~215.2 px per label slot**.
  - Labels measured live:
    - `Course catalogue` 130 px natural → 104 px slot → **truncated ✗**
    - `Academic progress` 143 px → 106 px → **truncated ✗**
    - `Announcements` 121 px → 103 px → **truncated ✗**
  - Root cause: 20/17.6 inner pad on sidebar + 13.6 symmetric link pad are consuming 65 px *outside* the label slot; collapsed var `68 px` is also ~24 px below 72–80 target.

**3. Legacy base mobile breakpoints**:
  - Mobile drawer at 767.98 already mostly works (offcanvas + overlay). Two independent competing mobile blocks exist at (a) [default.scss:1919 mobile (<991.98) dashboard sidebar offcanvas] and (b) [default.scss:2121–2143 mobile (<767.98) final drawer offcanvas]. Conflict must be resolved to a single consistent 768 breakpoint; tablet 768–1023 rail mode.

**4. Profile page**: uses `standard_shell.mustache` (confirmed via layout/mypublic.php L69). Currently renders Moodle's raw user/profile HTML into the shell with no information hierarchy card. The only "profile polish" currently is the Academic Profile block inside the dashboard which is a separate page from `/student/profile` (actual Profile).

**5. Quick Access grid**: currently hard-coded `repeat(4,minmax(0,1fr))` at base + overridden at 1199/768. Should become intrinsic `auto-fit, minmax(260px, 1fr)` so it behaves correctly between breakpoints too.

## Functional Requirements
### Shell Fluidity
- **FR-1**: Remove / cancel hard 1280 px `max-width` on `.ulms-portal-navbar > .container-fluid.ulms-shell-topbar` inside portal-route pages (both dashboard shell AND standard/admin/mypublic shells). Container-fluid must be width 100%. Retain only horizontal outer gutters matching shell content's outer gutters (which will shrink with breakpoint, not cap).
- **FR-2**: Remove / cancel hard 1280 px max-width on `.ulms-portal-footer` on portal pages; footer inner content may be aligned inside a readable max only IF the footer bar itself spans full viewport width (no "footer island centered").
- **FR-3**: Cancel `.ulms-custom-page .main-inner { max-width: 1200px }` for portal-routed pages (class present on standard_shell rendered profile/grades/calendar pages).
- **FR-4**: `.ulms-shell-content` must be `width: 100%; min-width: 0; display: block` (not a 1-column grid with fixed track width) at all desktop widths; its inner `#region-main-box` must be `width: 100%; min-width: 0; display: block;`.
- **FR-5**: Sidebar/content grid must be `grid-template-columns: var(--ulms-sidebar-w) minmax(0, 1fr)` expanded / collapsed var rail; collapsed state toggled only via body class, no width hacks.
- **FR-6**: `.ulms-shell-content` padding shall be responsive and modest: XL 1.5–2.0 rem, L 1.25 rem, M 1.0 rem, S 0.75–0.85 rem — NOT 40 px invariant at all widths.
- **FR-7**: Where text-heavy sections *should* have readable max (page headers, copy paragraphs), scope the max-width **only on the specific text element**, and center it with auto margins or a column class, **never** propagate it upward to shell ancestor. Specifically:
  - `.ulms-page-header__main` max-width → wider (≈ 960 px) **and still inside content column (not capped independently at 832)**;
  - `.ulms-list` inside Academic Profile should become `repeat(auto-fit,minmax(280px,1fr))` grid so it uses available width instead of staying 826 px at XL.

### Breakpoint Modes
- **FR-8 Large desktop (≥1440 px)**:
  - grid sidebar 280 + 1fr; shell content 100% width; KPI/QA grids can expand to wider intrinsic caps.
- **FR-9 Standard desktop (1200–1439)**:
  - grid sidebar 280 + 1fr; slightly reduced shell content pad 1.25 rem;
- **FR-10 Small desktop / landscape tablet (1024–1199)**:
  - **collapsed rail (default)** 76 px wide; when `.is-sidebar-open`, sidebar expands to 280 (toggled by the hamburger).
- **FR-11 Tablet (768–1023)**: collapsed 76 px rail by default; expandable;
- **FR-12 Mobile (< 768 px)**: sidebar removed from document flow, uses offcanvas drawer only; main content 100% viewport width; no 280 px empty left margin.

### Sidebar
- **FR-13 Expanded sidebar width**: `--ulms-sidebar-w = 280px` (keep var) **BUT** reduce inner sidebar padding and link padding so **label usable slot ≥ 230 px** at desktop (enough for `Academic programme / Course catalogue / Attendance registers / Announcements` all fit at normal 14 px/16 px copy).
- **FR-14 Collapsed sidebar width**: `--ulms-sidebar-w-collapsed = 76px` ± tight (in 72–80 range).
- **FR-15 Expanded state guarantees**: no truncation of "normal" nav labels (≤ ~20 chars). Verify by measuring each label's natural width against its rendered slot (via evaluate script in browser). Long labels ≥ 24 chars → graceful ellipsis + title attribute tooltip.
- **FR-16 Collapsed state guarantees**: absolutely NO label fragments; `.ulms-shell-nav-link__label`, `.ulms-shell-sidebar__title`, `.ulms-shell-sidebar__eyebrow`, `.ulms-shell-nav-group__title` ALL hidden at collapsed; links center-only icons; profile section (if any) must be icon-only or hidden with tooltip.
- **FR-17 Sidebar sticky**: Sidebar remains sticky at top: topbar height on desktop; on mobile drawer no sticky (absolute full viewport).

### Mobile Offcanvas Drawer
- **FR-18 Drawer overlay**: Backdrop `fixed inset-0 z 1050` appears only while drawer open; click dismiss toggles close;
- **FR-19 Body scroll lock**: Drawer open → body `overflow: hidden; touch-action: none;`;
- **FR-20 Keyboard ESC close**: document key handler closes drawer via the sidebar toggle data attribute hook;
- **FR-21 Focus-safe**: Focus order: drawer open → first link focused; close → focus back to toggle; drawer itself scrolls (overflow-y auto).

### Header
- **FR-22 Desktop order**: [hamburger] [seal + BELLS TECH identity] … [portal pill + role pill] [messages] [notifications] [user menu/avatar].
- **FR-23 Tablet (1024–1199)**: reduce/hide role pill label if `display:none`; keep icon for secondary actions.
- **FR-24 Mobile (<768)**: [hamburger] [compact BELLS LMS identity (no long tagline)] … [user menu/avatar OR single primary action]; user menu should be icon-only; no overflow.
- **FR-25 Header overflow guard**: `min-width: 0` / `flex-wrap: nowrap` / `overflow: hidden clip` on flex children that cannot wrap. For identity text, clamp with ellipsis.

### Profile Page
- **FR-26**: When current page is the student.profile or lecturer.profile route (confirmed in renderer at core_renderer.php 301-304), apply profile-hero classes on #region-main so the SCSS below matches.
- **FR-27 Page header**: `h1 = "Profile"` + subtitle `"Account information and security"`.
- **FR-28 Profile hero card**:
  - Avatar + initials avatar fallback;
  - Full name; role badge (STUDENT / LECTURER / ADMIN / SUPER ADMIN);
  - Username; email; last access;
  - Responsive: ≥ 1440 → grid 3 columns (avatar/identity column 1, contact/academic grid cols 2-3); 1200–1439 → 2 cols; < 1200 → 2 cols; < 768 → 1 col.
- **FR-29 Profile actions**: View/edit Moodle profile → Moodle's /user/edit.php for the user; Change password → /login/change_password.php or portal route if one exists; Reset password only visible to appropriate roles (admins on users' profile view, or own "Forgot password" CTAs).
- **FR-30 Density Profile page composition**: no empty card. Sections are: (a) Page header; (b) Profile hero/card; (c) Academic Profile list (if on student profile); (d) Security section (last password change, MFA if any, active sessions if available). Vertical padding reduces at 2560 so page does not feel sparse.

### Quick Access + KPI grids
- **FR-31**: Quick access grid selector `.ulms-quick-access__grid` AND `.ulms-kpi-grid` change from hard-coded 4-cols → intrinsic:
  ```css
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 1rem;
  max-width: 100%;
  ```
- **FR-32**: Card upper-bound protection at ultrawide: each card `max-width: 1fr` and has internal min 260 px. At 2560 this yields 5–6 cards if QA section ever has 6; current 4 yields sensible widths.
- **FR-33**: Dashboard primary grid (`.ulms-dashboard-primary`) at ≥ 1920: `minmax(0, 1.8fr) minmax(380px, 0.85fr)`. So left column (courses) expands at ultrawide, right column (deadlines) does not become absurdly wide.

### Density / Composition
- **FR-34**: Shell content base padding ≤ 2.5 rem (max at ≥ 1920), not 40 px forever;
- **FR-35**: Page sections reduce section-top margins at XL (mt-4 → 1.25 rem clamp); no giant "stretch to fill" flex columns.
- **FR-36**: Cards max-width must never exceed `1fr`; no centered card inside 1482 px container at a fixed 826 px.

### Screenshots + QA Matrix
- **FR-37 Screenshot set 1 required before/after**:
  - 390×844 mobile portrait
  - 768×1024 tablet portrait
  - 1366×768 standard laptop
  - 1920×1080 standard desktop
  - 2560×1440 ultrawide
- **FR-38 Full screenshot matrix (after)**:
  - Phone: 320×568, 360×800, 375×812, 390×844, 412×915, 430×932
  - Tablet: 768×1024, 820×1180, 1024×768 (landscape)
  - Desktop: 1280×720, 1366×768, 1440×900, 1536×864, 1600×900, 1920×1080
  - Ultrawide: 2560×1440
  - Landscape + zoom: 390×844 landscape, tablet landscape, browser zoom 125%, 150%, 200%
- **FR-39 Overflow automation**: For every viewport in FR-38, verify:
  ```js
  document.documentElement.scrollWidth <= document.documentElement.clientWidth
  ```
  (exceptions only: data tables with own internal horizontal scroll). Report every element that exceeds.

## Non-Functional Requirements
### NFR-1 Browser QA
Chrome/Edge/Firefox/Safari desktop available on the tester environment. iOS Safari / Android Chrome noted if not available.
### NFR-2 Accessibility QA (via Accessibility agent)
- Keyboard only: mobile drawer ESC / focus order; header skip link; sidebar nav links;
- Screen reader aria-label correctness on toggle; collapsed labels on icon links have `aria-label` or visible tooltip title;
- Contrast pass (WCAG 2.1 AA, minimum 4.5:1) for all text on new profile hero / buttons;
- No focus traps / dialog trap for mobile drawer.
### NFR-3 Idempotent SCSS compilation
Changes MUST compile (Moodle lib.php EXTRA_SCSS path) without syntax errors, confirmed via revision bump + purge.
### NFR-4 Files touched
Maximum of: default.scss, config.php (revision only), standard_shell.mustache, ulms_dashboard_shell.mustache, drawers_shell.mustache (template only for profile hooks), optionally core_renderer.php for body-class/region-main profile-hero hook; optionally a very small JS only for ESC key + body lock if no existing handler. No PHP core business logic.

## Constraints
- Technical:
  - CSS-only solution where possible.
  - Must not break any existing 4-portal login/dashboard page (admin/lecturer/superadmin/student).
  - No Moodle core modifications.
  - Compiled CSS must be re-bumped via revision bump + `php admin/cli/purge_caches.php --theme`.
- Business:
  - No identity/color change.
  - Before deployment, all 14 return items in user PRD (bottom of doc) must be passing and returned.
- Dependencies:
  - PHP server running at 127.0.0.1:8000, Moodle install connected to ulms DB, `student`/`lecturer`/`admin`/`superadmin` credentials.
  - Integrated-browser for viewport resize + screenshot capture + overflow evaluate script.

## Assumptions
- A1: Browser viewport can be resized (via `window.resizeTo` or device-emulation / viewport meta override) in integrated browser — or, fallback: use CSS `transform: scale` override inside a wrapper div for screenshot capture if native device metrics API is unavailable; overflow test operates on `clientWidth` set accordingly.
- A2: Mobile (< 768 px) → no "collapsed persistent rail" behavior, only offcanvas drawer.
- A3: Profile pages use `standard_shell.mustache` + `#region-main` as the injection point; minimal SCSS + template injection needed to restructure academic list / profile hero into new sections, if needed use renderer override only.

## Open Questions
- [ ] OQ-1: Is mobile breakpoint exactly 768 (tablet 768+) or 992 as existing dashboard block? **Decision in this spec: 768 mobile / tablet 768 / desktop 1024**. Confirm with user if they want tablet 768 collapsed rail; otherwise revert.
- [ ] OQ-2: Profile page — Do we include Moodle's default dynamic user fields (institution, department) in the hero, or only name/role/username/email/last-access as per structure. **Assumption: include only the 5 specified, with Academic Profile as a grid below if present.**

## Acceptance Criteria

### AC-1: Application shell fills viewport at 2560 (no tube island)
- **Type**: `rule`
- **Given**: Signed in to Student dashboard portal at 2560×1440 viewport width, desktop expanded sidebar.
- **When**: Measuring width of the app chain (`body`, `#page-wrapper`, `#page`, `.main-inner`, `.ulms-shell`, `.ulms-shell-content`, `#region-main-box`, dashboard navbar `.container-fluid.ulms-shell-topbar`, `.ulms-portal-footer`) via browser_evaluate.
- **Then**:
  - All ancestors: computed `max-width` is `none` (or ≥ 2540 px — effectively no hard cap), `display`/`gridTpl` correct;
  - No ancestor before `.ulms-shell-content` applies a center `margin: 0 auto` cap;
  - `.ulms-shell-content` width = viewport width − sidebar width (280 px), minus shell-content horizontal pad;
  - Dashboard topbar `.container-fluid` inner width matches shell content content-box width (not capped 1280 px);
  - Footer `.ulms-portal-footer` width is ≥ 2540 px (full width bar; inner links can be readable max but the *bar* spans the screen).
- **Pass Condition**: No element among shell ancestors has a fixed pixel max-width ≤ 2000. Live measured: `.ulms-shell-content` right edge = `document.documentElement.clientWidth − shell_content_padding_right` with tolerance ± 4 px.
- **Evidence**: browser_evaluate output for DOM ancestor chain at 2560 px + screenshot.

### AC-2: Sidebar normal labels render fully (≥ 1200 px) without truncation
- **Type**: `rule`
- **Given**: Expanded sidebar open, viewport width ≥ 1200 px.
- **When**: Measuring all `.ulms-shell-nav-link__label` with text ≤ 22 chars against slot available width + ellipsis computed style.
- **Then**:
  - All of `Dashboard`, `My courses`, `Assignments`, `Quizzes`, `Exams`, `Grades`, `Timetable`, `Attendance`, `Messages`, `Profile`, `Course catalogue`, `Academic progress`, `Announcements` have label available width ≥ scrollWidth (no truncation), with `text-overflow` either clip OR ellipsis not triggered.
  - Any label ≥ 24 chars that must truncate has `title="…long label…"` set on the anchor.
- **Pass Condition**: 100% of labels with length ≤ 22 chars report `trunc:false` in the evaluate label results.
- **Evidence**: browser_evaluate JSON list of labels + truncated flags.

### AC-3: Collapsed sidebar has zero label fragments
- **Type**: `rule`
- **Given**: Sidebar collapsed class `.is-sidebar-collapsed` and `.not(.is-sidebar-open)`, viewport ≥ 1024 px.
- **When**: Inspect sidebar DOM for any visible label/title/group heading text.
- **Then**:
  - `.ulms-shell-nav-link__label`, `.ulms-shell-sidebar__title`, `.ulms-shell-sidebar__eyebrow`, `.ulms-shell-nav-group > h2/h3`, `.ulms-shell-nav-group__title` → all `display: none`.
  - Nav links center on icon, no partial text node showing.
- **Pass Condition**: All five element selectors return `getComputedStyle(...).display === 'none'` at collapsed state.
- **Evidence**: browser_evaluate JSON collapsed state.

### AC-4: Mobile offcanvas drawer + no residual empty column
- **Type**: `rule`
- **Given**: Viewport width 390 px (<768 px), signed in.
- **When**: Drawer closed first, drawer opened second.
- **Then**:
  - Drawer closed: main content width = clientWidth (full viewport); no 280 px left margin;
  - Drawer open: overlay opacity visible; body `overflow` = `hidden`; `.ulms-shell-sidebar` translateX(0); ESC key closes drawer; drawer can scroll;
  - Drawer closed again: overlay hidden; body overflow restored;
- **Pass Condition**: evaluate overflows at 390 px; overlay.classList.contains("is-open") / body overflow on open/close; ESC simulation listener active on toggle.
- **Evidence**: Screenshots (closed + open 390), evaluate JSON close/open.

### AC-5: Header no horizontal overflow at any required viewport
- **Type**: `rule`
- **Given**: All 6 phone / 3 tablet / 6 desktop / 1 ultrawide viewports + 3 zooms.
- **When**: Global overflow test for every viewport.
- **Then**:
  - `document.documentElement.scrollWidth <= document.documentElement.clientWidth` returns true at every viewport;
  - If any exception (data table only), it is inside `.ulms-table-wrap` with internal `overflow-x:auto`.
- **Pass Condition**: 100% of required viewports pass overflow; a list of any violating nodes + size returned for passing evidence.
- **Evidence**: Full JSON report per viewport.

### AC-6: Profile page structure + responsive grid
- **Type**: `rule`
- **Given**: Student profile page open at 1440 / 1200 / 768 / 390.
- **When**: Profile hero + sections visible.
- **Then**:
  - 1440: hero 3 columns (avatar, info, details);
  - 1200: hero 2 columns;
  - 768: hero 2 columns (or narrow 1 depending on label lengths, accepted 2);
  - 390: 1 column (avatar at top)
  - Sections: "Profile" / "Account information and security" page header; actions; security section (if available) below;
- **Pass Condition**: Screenshot + evaluate computed gridTemplateCols at each breakpoint.
- **Evidence**: Screenshots + evaluate.

### AC-7: Quick Access intrinsic responsive grid
- **Type**: `rule`
- **Given**: 2560 / 1920 / 1366 / 1024 / 768 / 390 viewports on dashboard.
- **When**: `.ulms-quick-access__grid` column count evaluated.
- **Then**:
  - 2560: columns = 4 or 5 depending minmax; (4 current cards ok).
  - 1366: columns ≥ 2;
  - 768: columns = 2;
  - 390: columns = 1.
  - No cards overflow the grid container.
- **Pass Condition**: Grid track count by viewport matches column count via computed `gridTpl` split.
- **Evidence**: browser_evaluate JSON per viewport + screenshots.

### AC-8: Sidebar widths (expanded 280 ± collapsed 76 ±)
- **Type**: `rule`
- **Given**: 1200 px viewport, expanded then collapsed states.
- **When**: Measuring `.ulms-shell-sidebar` getBoundingClientRect().width in both states.
- **Then**:
  - Expanded = 276–284 px (280 target);
  - Collapsed = 72–80 px (76 target);
- **Pass Condition**: Widths in range. CSS variables correctly set in `:root` + no overrides.
- **Evidence**: browser_evaluate width numbers + var values.

### AC-9: No horizontal overflow at any required viewport (automated PR check)
- **Type**: `rule`
- **Given**: The 6 phones / 3 tablets / 6 desktops / 1 ultrawide + landscape + zoom viewports all tested.
- **When**: Running overflow verification script on each viewport after load.
- **Then**: For every viewport:
  - `document.documentElement.scrollWidth <= clientWidth` (tolerance 1 px for scrollbar);
  - OR failing element reported;
- **Pass Condition**: 0 failing nodes across all 18+ tested viewports except whitelisted tables.
- **Evidence**: Automated overflow result table (viewport → PASS | FAIL + offending node).

### AC-10: Density 1366 / 1440 / 1920 / 2560 balanced
- **Type**: `rubric`
- **Dimension**: Page composition density at 4 widths.
- **Scale**: 1–5
- **Anchors**:
  - 1 = absurd blank whitespace > 30% vertical screen unused at 1920;
  - 3 = acceptable with minor gaps;
  - 5 = deliberate, well-balanced composition at all 4 widths, no "stretched" card or huge voids.
- **Pass Threshold**: >= 4
- **Evidence**: Screenshots of dashboard + profile at 4 widths, reviewer notes.

### AC-11: Accessibility QA
- **Type**: `rubric`
- **Dimension**: Accessibility correctness (WCAG 2.1 AA) + semantics across mobile drawer / keyboard / focus / contrast.
- **Scale**: 1–5
- **Anchors**:
  - 1 = focus trap, low contrast on key buttons, missing aria labels on collapsed icon buttons;
  - 3 = most pass with minor issues;
  - 5 = Passes all specified A11y FRs: keyboard only, drawer ESC/focus, skip link functional, contrast 4.5:1, collapsed button aria-labels.
- **Pass Threshold**: >= 4
- **Evidence**: Accessibility Engineer review report.

### AC-12: Browser / viewport consistency (Chrome/Edge/Firefox if available; Safari desktop (if not, Chrome/FF))
- **Type**: `rubric`
- **Dimension**: Cross-browser visual parity at 2+ tested browsers.
- **Scale**: 1–5
- **Anchors**:
  - 1 = Major layout break in one browser;
  - 3 = Minor text rendering differences only;
  - 5 = Identical layout, grids, header, sidebar widths, overflow consistent in tested browsers.
- **Pass Threshold**: >= 4
- **Evidence**: Review log by browser.

### AC-13: Profile and Dashboard page composition quality (university ship standard)
- **Type**: `rubric`
- **Dimension**: Would confidently ship to thousands of users on today's snapshot of dashboard + profile.
- **Scale**: 1–5
- **Anchors**:
  - 1 = unprofessional, clipped nav, gaps, identity inconsistency;
  - 3 = functional but unpolished;
  - 5 = polished flagship university platform: identity, hierarchy, density, typography, grids are all deliberate and beautiful across sizes.
- **Pass Threshold**: >= 4.
- **Evidence**: Independent UI/UX + responsive reviewers final notes.

### AC-14: 14 return items delivered before deployment
- **Type**: `rule`
- **Given**: Implementation complete and all AC passing.
- **When**: Deliver the final output list to user:
  1. Root cause of ultrawide failure.
  2. Exact constraining DOM element.
  3. Existing CSS rule causing it.
  4. Corrected application-shell architecture.
  5. Files changed.
  6. Before/after 390×844 screenshots.
  7. Before/after 768×1024 screenshots.
  8. Before/after 1366×768 screenshots.
  9. Before/after 1920×1080 screenshots.
  10. Before/after 2560×1440 screenshots.
  11. Horizontal-overflow test results.
  12. Browser QA results.
  13. Accessibility QA results.
  14. Any remaining defects.
- **Then**: All 14 items are present and populated with correct evidence paths.
- **Pass Condition**: 14/14 items populated with file evidence or data.
- **Evidence**: Final response + screenshot directory index.md 2.0.
