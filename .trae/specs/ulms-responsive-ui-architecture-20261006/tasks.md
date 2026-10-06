# ULMS Responsive UI Architecture Remediation Phase 2 — Implementation Plan

## Task 1: Root-cause shell fluidity hard-cap removal + 40px invariant padding removal
- **Status**: `pending`
- **Priority**: high
- **Depends On**: None
- **Description**:
  - Cancel / override (in order of selector strength) the three 1200/1280 hard caps that create the centered tube at ≥1440:
    - `.ulms-portal-navbar > .container-fluid.ulms-shell-topbar { max-width: min(1280px,100%); margin:0 auto }` → `max-width: none !important; width:100% !important; margin: 0 !important; padding-left: matches shell-content-left + toggle/brand-gutter; padding-right: matches shell-content-right`
    - `.ulms-portal-footer { max-width:1280px; margin:0 auto; width: calc(100% - 2rem); }` → footer bar must be 100% viewport width; footer inner links can have readable max only *inside* the inner, but bar spans full width. Footer bar `max-width: none !important; width:100% !important; margin: 0 !important; padding-inline: same as shell content`.
    - `.ulms-custom-page .main-inner { max-width:1200px }` for portal-route pages → override to `max-width: none !important; width: 100% !important;` on pages where `body.path-student.path-lecturer.path-admin + .standard-shell / mypublic` layout.
  - Remove `.ulms-shell-content` 1-col fixed track grid layout: currently `.ulms-shell-content { display: grid; grid-template-columns: 1601.3px }` creates an unexplained hard width inside a fluid parent. Rewrite with `display:block; width:100%; min-width:0;`.
  - `#region-main-box` same treatment `display:block; width:100%; min-width:0;`.
  - Replace invariant shell-content pad/padR with responsive paddings (2.5/2.0/1.25/1.0/0.85 rem) across modes (XL/L/M/tablet/mobile).
  - `.ulms-page-header__main` max-width: remove independent 832 px cap; inside a text-wide readable max (960 px) so the *header copy* is readable but not the *shell*. For non-text (grid cards) no cap.
  - `.ulms-list` for Academic Profile at ≥1440 → 2-cols responsive grid `repeat(auto-fit, minmax(280px,1fr))` instead of a 826 px fixed width list.
- **Acceptance Criteria Addressed**: AC-1, AC-9, AC-10
- **Test Requirements**:
  - `rule` TR-1.1 At 2560×1440 viewport width, chain ancestors (`html`→`body`→`#page-wrapper`→`#page`→`.main-inner`→`.ulms-shell`→`.ulms-shell-content`→`#region-main-box` → header `.container-fluid`) → computed `max-width:none` AND none have `margin:0 auto` with a pixel hard cap; `.ulms-shell-content` display is block; `region-main-box` block. Evidence: browser_evaluate JSON.
  - `rule` TR-1.2 At 1920×1080 `.ulms-dashboard-primary` actual width ≥ 1400 px (side ~280). Evidence: evaluate.
  - `rule` TR-1.3 `.ulms-shell-content` padL/padR at 1920 equal to the responsive values (e.g., 2 rem). Evidence: computedStyle JSON.
  - `rubric` TR-1.4 Dashboard at 2560 — no "tube island" effect, content uses 80%+ of horizontal space; scale 1-5 threshold 4. Evidence: screenshot 2560 + 1920 before/after side by side.

## Task 2: Sidebar widths + expanded label usable-slot expansion + collapsed icon-only guarantee
- **Status**: `pending`
- **Priority**: high
- **Depends On**: None
- **Description**:
  - CSS vars at `:root, body.theme-ulms-university`: `--ulms-sidebar-w: 280px` (keep) + `--ulms-sidebar-w-collapsed: 76px` (from 68 → 76).
  - Reduce expanded sidebar `.ulms-shell-sidebar__inner` horizontal padding from `28px 20px` (or current) to `1.5rem 1rem` so label slot width increases.
  - Reduce nav-link horizontal padding from 13.6/13.6 → 10/14 px.
  - Ensure `.ulms-shell-nav-link` flex: icon (24–26 px) fixed, label `flex: 1 1 auto; min-width:0`; total slot 280 − 16 inner pad − (10+14) link pad − 26 icon = **~214 px**. If still insufficient → bump sidebar var to 288 or 290 just enough for longest labels.
  - Verify all ≤22 char labels fit: Course catalogue 130 px / Academic progress 143 px / Announcements 121 px all fully fit.
  - For long labels ≥24 chars add `title="{{label}}"` on nav anchors via the 3 shells mustache loops (dashboard_shell/secure_shell/standard_shell already have `title="{{label}}"` on active span + link — confirm yes from the mustache files, if not present add it.
  - Collapsed state 76 px: ensure `.ulms-shell-nav-link__label`, `.ulms-shell-sidebar__title`, `.ulms-shell-sidebar__eyebrow`, `.ulms-shell-nav-group__title`, `.ulms-shell-nav-group > h2/h3` all `display:none !important; opacity:0; visibility:hidden;` NO label fragments.
  - Sidebar collapsed links: icon centered 26×26 inside 52 px height; no profile text visible.
- **Acceptance Criteria Addressed**: AC-2, AC-3, AC-8
- **Test Requirements**:
  - `rule` TR-2.1 At ≥1200 px expanded sidebar, nav labels ≤ 22 chars (list from AC-2) all `trunc:false`. Evidence: browser_evaluate label list JSON.
  - `rule` TR-2.2 Collapsed `.is-sidebar-collapsed` state at 1440: all 5 label selectors display=none. sidebar total width 72–80 px.
  - `rule` TR-2.3 Collapsed state, no label glyphs visible in screenshot crop. Evidence: 1440 collapsed screenshot + cropped sidebar strip.

## Task 3: Mobile drawer consistency / single breakpoint / ESC / scroll-lock / focus
- **Status**: `pending`
- **Priority**: high
- **Depends On**: T2 optional
- **Description**:
  - Unify duplicate mobile/tablet rules: the 767.98 px mobile block (2121–2143) is the correct one; remove the earlier offcanvas that triggers at 991.98 for dashboard shell so the 1024–1199 range remains a small-desktop collapsed rail. Do this by rewriting earlier block via higher specificity override OR (preferred) move all shell responsive grid breakpoint rules to ONE cascaded set at lines ~2080–2190 so they are the winner across all shells.
  - Add handler for ESC key: if `.ulms-shell.is-sidebar-open`, on ESC keydown, toggle the sidebar closed via the existing data attribute handler on toggle button (click the toggle).
  - Add/confirm body scroll lock on drawer open: on `.ulms-shell.is-sidebar-open` body `overflow: hidden; touch-action: pan-y; -webkit-overflow-scrolling: touch; height: 100dvh;`. Verify drawer itself can scroll (overflow-y: auto inside inner).
  - Focus on open: when drawer opens, focus `.ulms-shell-sidebar` (tabindex -1) or first nav link. Close → focus returns to hamburger toggle; keep current state.
  - Drawer widths: max-width `min(86vw, 360px)` (current 320 ok).
- **Acceptance Criteria Addressed**: AC-4, AC-5
- **Test Requirements**:
  - `rule` TR-3.1 At viewport 390 px → closed drawer: main content width ≥ 388 px (full viewport minus safe area); no 280 empty margin left.
  - `rule` TR-3.2 Open drawer: overlay has opacity>0.5; body overflow=hidden; on ESC closes.
  - `rule` TR-3.3 Drawer inner scroll: content-height > drawer-height, inner element scrollable. Evaluate scrollHeight vs clientHeight for overflow.

## Task 4: Header responsive identity / pills / actions trimming
- **Status**: `pending`
- **Priority**: medium
- **Depends On**: None
- **Description**:
  - Desktop: [hamburger] [seal + BELLS TECH] … [portal name pill] [role pill] [messages] [bell] [avatar/user menu].
  - Small desktop tablet 1024: hide role pill label text content (only keep badge icon if one exists), keep portal pill.
  - Tablet 768: portal pill label `max-width: 33vw; ellipsis`.
  - Mobile 390: hamburger + compact BELLS LMS identity (the brand's seal + "LMS" or "BELLS LMS", no long full name if text overflows; `ulms-brand__name` clamp with ellipsis + max-width 40vw). Avatar/user menu icon-only; no pill labels visible at mobile.
  - Ensure header never horizontally overflows: `.ulms-shell-topbar > children` min-width 0 where flex; brand name `overflow clip`.
- **Acceptance Criteria Addressed**: AC-5, AC-10
- **Test Requirements**:
  - `rule` TR-4.1 390×844, header horizontal no overflow (global overflow test).
  - `rule` TR-4.2 2560×1440, header `.container-fluid` width ≥ 2540 px; no left/right caps.
  - `rubric` TR-4.3 Header order, spacing, hierarchy correct at 1440 / 1024 / 390; threshold 4+; evidence screenshot triptych.

## Task 5: Profile page hero / sections structure (polished page)
- **Status**: `pending`
- **Priority**: high
- **Depends On**: T1
- **Description**:
  - Detect profile page via renderer: when body class includes `#page-student-profile` or `#page-lecturer-profile` and layout is standard shell, add `class ulms-profile-page` on region-main or via extra body class in core_renderer.php `ulms-dashboard-shell` body class chain.
  - Profile page SCSS scoped to `.path-user.ulms-profile-page #region-main` or equivalent selector that works reliably with existing body ids from Moodle (use the stable ones per earlier search: body id `page-student-profile` etc).
  - Structure:
    1. Page header with H1 "Profile" + subtitle "Account information and security".
    2. Profile hero responsive grid:
       - Col1: Avatar (80×80) / initials circle if no user picture + name/role below.
       - Col2: Identity card — Full name, role (STUDENT/LECTURER) pill, Username.
       - Col3: Contact card — Email; last access; institution if available.
       - Breakpoint: ≥ 1440 3 cols; 1200 2 cols (col1 identity stacked, col2 contact); <1200 2 cols compact; <768 1 col stacked.
    3. Profile actions row 2× cols/1 col mobile:
       - View/edit Moodle profile → /user/edit.php for own id;
       - Change password → /login/change_password.php (or portal change password route).
    4. Academic section (only if page has existing Academic Profile block): inherit and restructure cards into grid consistent with dashboard.
    5. Security section if Moodle exposes any blocks or fields — last logins / MFA hint.
  - Use responsive grid `repeat(auto-fit, minmax(260px,1fr))` for contact/academic cards below hero.
  - Density: hero card `min-height clamp(200px, 26vh, 260px)`, no huge paddings.
- **Acceptance Criteria Addressed**: AC-6, AC-10
- **Test Requirements**:
  - `rule` TR-5.1 At 1440: profile hero grid 3 cols; 1200: 2 cols; 390:1 col. Evidence: evaluate gridTplCols JSON.
  - `rule` TR-5.2 Profile actions: (a) View/edit profile button resolves to /user/edit.php correctly, (b) Change password button resolves. Evidence: attribute extraction via evaluate.
  - `rubric` TR-5.3 Visual polish / hierarchy quality; 1-5 scale threshold 4+; screenshot evidence before/after.

## Task 6: Quick Access / KPI intrinsic grids + Dashboard primary balanced XL
- **Status**: `pending`
- **Priority**: medium
- **Depends On**: T1
- **Description**:
  - Rewrite `.ulms-quick-access__grid`, `.ulms-kpi-grid` to intrinsic:
    ```scss
    .ulms-quick-access__grid, .ulms-kpi-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
      gap: clamp(0.85rem, 1.2vw, 1.25rem);
    }
    .ulms-qa-card {
      min-width: 0;
    }
    ```
  - Remove per-breakpoint `repeat(4/2/1)` hard override statements; or rewrite them to narrower window-specific overrides only if intrinsic misbehaves.
  - `.ulms-dashboard-primary` at ≥1920: widen left column proportion:
    - ≥1920: `minmax(0, 1.8fr) minmax(380px, .8fr)`.
    - ≥2560: `minmax(0, 1.9fr) minmax(420px, .7fr)`.
- **Acceptance Criteria Addressed**: AC-7
- **Test Requirements**:
  - `rule` TR-6.1 QA grid columns: 2560=4; 1920=4; 1024=2-3; 768=2; 390=1. Evidence evaluate JSON.
  - `rule` TR-6.2 Dashboard primary left col ≥ right col * 2.1× at 2560; left col ≥ right * 1.8× at 1920.

## Task 7: Responsive density + zoom 125/150/200 safety + mobile/tablet clamp type
- **Status**: `pending`
- **Priority**: medium
- **Depends On**: T1, T6
- **Description**:
  - `ulms-fluid-space` / `ulms-fluid-font` already exist in SCSS — keep; ensure zoom 200% no horizontal overflow on header / main grid / profile.
  - Replace `padding: 40px` etc with `clamp()` so at 1440/1920/2560 padding tapers naturally.
  - Page sections vertical spacing between hero / profile / academic → clamp 0.75→1.25 rem depending width.
  - At zoom 200%, shell sidebar width relative (grid) + main content 100% block → no overflow; header wrap still horizontal nowrap with clamp.
- **Acceptance Criteria Addressed**: AC-5, AC-9, AC-10
- **Test Requirements**:
  - `rule` TR-7.1 At zoom 200% on 1920×1080 viewport, global overflow PASS.
  - `rule` TR-7.2 At zoom 150% 1920, dashboard grid OK; profile hero still structured.
  - `rubric` TR-7.3 Density feel across 1366/1440/1920/2560; threshold 4+.

## Task 8: Revision bump + SCSS cache purge deterministic
- **Status**: `pending`
- **Priority**: high
- **Depends On**: T1-T7 all
- **Description**:
  - Bump `$THEME->revision` in [config.php](file:///Users/gloriousanjorin-adeboye/UNIVERSITY%20LMS/theme/ulms_university/config.php#L17-L25) sequentially from `2026100506` → `2026100601` (next major rev for Phase 2).
  - Purge MUC/localcache/theme/temp/theme before every QA re-run.
- **Acceptance Criteria Addressed**: NFR-3, plus all ACs depending correct compiled load.
- **Test Requirements**:
  - `rule` TR-8.1 Load portal pages after purging; DevTools shows the new SCSS rules (line numbers matching our appended block); revision number in compiled CSS link query matches new one or higher.

## Task 9: Before/after screenshots 5 required pairs + Full matrix (18+ viewports + landscape + zooms)
- **Status**: `pending`
- **Priority**: high
- **Depends On**: T1–T8 implement done
- **Description**:
  - **BEFORE shots**: capture dashboard student + profile student at the 5 required viewports (390×844, 768×1024, 1366×768, 1920×1080, 2560×1440) pre implementation, stored as `screenshots/before-*.png`.
  - **AFTER shots**: same 5 at end.
  - **Full matrix AFTER**: Phone (320/360/375/390/412/430), Tablet (768/820/1024 landscape), Desktop (1280/1366/1440/1536/1600/1920), Ultrawide (2560). Landscape 390×844 rotated; tablet landscape; zoom 125/150/200 on desktop Chrome.
  - For each screenshot: run overflow script.
- **Acceptance Criteria Addressed**: AC-1, AC-4, AC-6, AC-7, AC-9, AC-14.
- **Test Requirements**:
  - `rule` TR-9.1 Before/after 5 pairs exist in screenshots folder.
  - `rule` TR-9.2 Full matrix 18+ screenshots per dashboard; profile at least 5 widths.
  - `rule` TR-9.3 Automated overflow pass report per viewport; ≤ 1 (known table) failing.

## Task 10: Deploy-independent review artifacts + 14 return items collection
- **Status**: `pending`
- **Priority**: high
- **Depends On**: T9, and Review gate
- **Description**:
  - Collect root-cause / constricting elements / before CSS rules / corrected architecture / files changed / 5 pairs / overflow report / browser QA (Chrome + Firefox on this machine) / accessibility QA via agent / remaining defects.
  - DO NOT push to production / deploy.
- **Acceptance Criteria Addressed**: AC-14.
- **Test Requirements**:
  - `rule` TR-10.1 All 14 return items populated with file paths or data before the user final-answer message.
