<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace theme_ulms_university\output;

defined('MOODLE_INTERNAL') || die();

/**
 * Core renderer overrides for the ULMS university theme.
 */
class core_renderer extends \theme_boost\output\core_renderer {
    /** @var bool */
    protected bool $ulmsbehaviourregistered = false;

    /**
     * Removes the default Boost breadcrumb/navbar shell from ULMS dashboards.
     *
     * @return string
     */
    public function navbar(): string {
        if ($this->get_portal_header_context() !== null) {
            return '';
        }

        if ($this->is_ulms_dashboard_page()) {
            return '';
        }

        return parent::navbar();
    }

    /**
     * Determines whether the current page is a ULMS dashboard route.
     *
     * @return bool
     */
    protected function is_ulms_dashboard_page(): bool {
        $path = $this->page->url ? $this->page->url->get_path() : '';
        $routingservice = class_exists(\local_ulms_auth\local\service\landing_page_service::class)
            ? new \local_ulms_auth\local\service\landing_page_service()
            : null;
        return $this->page->pagelayout === 'ulmsdashboard'
            || ($routingservice !== null && $routingservice->is_ulms_dashboard_path($path))
            || (!empty($path) && str_starts_with($path, '/local/ulms_dashboard/'));
    }

    /**
     * Determines whether the ULMS brand banner should render on the current page.
     *
     * @return bool
     */
    protected function should_render_brand_banner(): bool {
        if ($this->get_portal_header_context() !== null) {
            return false;
        }

        return in_array($this->page->pagelayout, ['mydashboard', 'frontpage'], true);
    }

    /**
     * Adds a theme-specific body class for future UI enhancements.
     *
     * @param array $additionalclasses
     * @return string
     */
    public function body_css_classes(array $additionalclasses = []): string {
        $additionalclasses[] = 'theme-ulms-university';
        $path = $this->page->url ? $this->page->url->get_path() : '';
        $studentportalservice = $this->get_student_portal_service();
        $lecturerportalservice = $this->get_lecturer_portal_service();
        $superadminportalservice = $this->get_super_admin_portal_service();
        $routingservice = class_exists(\local_ulms_auth\local\service\landing_page_service::class)
            ? new \local_ulms_auth\local\service\landing_page_service()
            : null;

        if ((!empty($path) && str_starts_with($path, '/local/ulms_'))
            || ($routingservice !== null && $routingservice->is_ulms_route_path($path))) {
            $additionalclasses[] = 'ulms-custom-page';
        }

        if ((!empty($path) && str_starts_with($path, '/local/ulms_dashboard/'))
            || ($routingservice !== null && $routingservice->is_ulms_dashboard_path($path))) {
            $additionalclasses[] = 'ulms-dashboard-page';
        }

        if (!empty($path) && str_starts_with($path, '/local/ulms_academics/')) {
            $additionalclasses[] = 'ulms-academics-page';
        }

        if (\isloggedin() && !\isguestuser() && $routingservice !== null) {
            $additionalclasses[] = 'ulms-' . $routingservice->get_current_user_portal_key() . '-portal-page';
        }

        if ($studentportalservice !== null && $studentportalservice->is_student_portal_page($this->page)) {
            $additionalclasses[] = 'ulms-student-portal-page';
        }

        if ($lecturerportalservice !== null && $lecturerportalservice->is_lecturer_portal_page($this->page)) {
            $additionalclasses[] = 'ulms-lecturer-portal-page';
        }

        if ($superadminportalservice !== null && $superadminportalservice->get_shell_context_for_page($this->page) !== null) {
            $additionalclasses[] = 'ulms-superadmin-portal-page';
        }

        return parent::body_css_classes($additionalclasses);
    }

    /**
     * Emits the ULMS global HTTP security headers before Moodle begins any
     * output flushing for the page. This is the third injection entry-point
     * and the most general: it guarantees headers are sent for login layout,
     * secure layout, standard layout, and dashboard ulms_dashboard shells.
     *
     * @return string
     */
    public function header() {
        self::inject_http_security_headers();
        return parent::header();
    }

    /**
     * Renders the shared portal context header for linked portal pages.
     *
     * @param array|null $headerinfo
     * @param int $headinglevel
     * @return string
     */
    public function context_header($headerinfo = null, $headinglevel = 1): string {
        self::inject_http_security_headers();
        $portalcontext = $this->get_portal_header_context();
        if ($portalcontext !== null) {
            return $this->render_from_template('theme_ulms_university/portal_context_header', $portalcontext);
        }

        return parent::context_header($headerinfo, $headinglevel);
    }

    /**
     * Injects 6 standard HTTP security headers before any portal output is sent.
     *
     * Gate: no-op when headers_sent() (already flushed) to avoid warnings.
     *       HSTS only when host is HTTPS and not localhost/127.0.0.1 (prevents
     *       accidental STS pinning in development).
     *
     * @return void
     */
    public static function inject_http_security_headers(): void {
        static $httpSecurityHeadersInjected = false;
        if ($httpSecurityHeadersInjected) {
            return;
        }
        $httpSecurityHeadersInjected = true;

        if (headers_sent()) {
            return;
        }

        global $CFG;

        @header('X-Content-Type-Options: nosniff', false);
        @header('X-Frame-Options: DENY', false);
        @header('Referrer-Policy: strict-origin-when-cross-origin', false);
        @header("Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), interest-cohort=(), usb=(), bluetooth=(), magnetometer=(), accelerometer=(), gyroscope=(), ambient-light-sensor=(), battery=(), document-domain=(), execution-while-not-rendered=(), execution-while-out-of-viewport=(), gamepad=(), hid=(), idle-detection=(), screen-wake-lock=(), serial=(), sync-xhr=(self), window-placement=(), xr-spatial-tracking=()", false);
        @header('X-XSS-Protection: 1; mode=block', false);
        @header('X-Download-Options: noopen', false);
        @header('X-Permitted-Cross-Domain-Policies: none', false);
        @header('Cross-Origin-Embedder-Policy: require-corp', false);
        @header('Cross-Origin-Opener-Policy: same-origin', false);
        @header('Cross-Origin-Resource-Policy: same-origin', false);

        // CSP unsafe-inline/unsafe-eval are retained for functional necessity (NOT
        // silently relaxed):
        //   - 'unsafe-inline' script:  Moodle core_renderer::js_init_code() emits page-
        //     specific inline behavioural code required for drawer toggles, sidebar
        //     state, form handling and user-menu dropdowns across every shell.
        //   - 'unsafe-inline' style:   Mustache template inline style= attributes plus
        //     legacy filter plugins (multilang, algebra, mathjax) cannot relocate.
        //   - 'unsafe-eval'  script:   Some contrib question types (STACK, Formulas)
        //     and legacy report plugins rely on eval() for user-supplied expressions.
        // All CSP-restricted origins (connect-src, frame-*, object-src, base-uri,
        // form-action, manifest-src, media-src) remain explicitly allow-listed and
        // restrictive.
        $csp = "default-src 'self'; "
            . "script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
            . "style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data: https:; "
            . "font-src 'self' data:; "
            . "connect-src 'self' https://api.resend.com https://api.korttext.com https://vle.korttext.com; "
            . "frame-ancestors 'none'; "
            . "frame-src 'self'; "
            . "object-src 'none'; "
            . "base-uri 'self'; "
            . "form-action 'self'; "
            . "manifest-src 'self'; "
            . "media-src 'self'; "
            . "upgrade-insecure-requests;";
        @header("Content-Security-Policy: {$csp}", false);

        if (function_exists('is_moodle_https') && \is_moodle_https()) {
            $host = parse_url($CFG->wwwroot ?? '', PHP_URL_HOST) ?: '';
            $isdevloopback = in_array($host, ['127.0.0.1', 'localhost'], true);
            if (!$isdevloopback) {
                @header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload', false);
            }
        }
    }

    /**
     * Removes stale header buttons from portal pages.
     *
     * @return string
     */
    public function page_heading_button(): string {
        if ($this->get_portal_header_context() !== null) {
            return '';
        }

        return parent::page_heading_button() ?? '';
    }

    /**
     * Returns a filtered user menu for ULMS dashboard pages.
     *
     * @return array
     */
    public function ulms_dashboard_user_menu(): array {
        $primary = new \core\navigation\output\primary($this->page);
        $menu = $primary->export_for_template($this->page->get_renderer('core'))['user'] ?? [];

        if (empty($menu['items']) || !is_array($menu['items'])) {
            return $menu;
        }

        $studentportalservice = $this->get_student_portal_service();
        $isstudentportalpage = $studentportalservice !== null && $studentportalservice->is_student_portal_page($this->page);
        $lecturerportalservice = $this->get_lecturer_portal_service();
        $islecturerportalpage = $lecturerportalservice !== null && $lecturerportalservice->is_lecturer_portal_page($this->page);
        $adminportalservice = $this->get_admin_portal_service();
        $isadminportalpage = $adminportalservice !== null && $adminportalservice->get_shell_context_for_page($this->page) !== null;
        $superadminportalservice = $this->get_super_admin_portal_service();
        $issuperadminportalpage = $superadminportalservice !== null && $superadminportalservice->get_shell_context_for_page($this->page) !== null;

        $allowedpaths = null;
        if ($isstudentportalpage) {
            $allowedpaths = [
                '/user/profile.php',
                '/grade/report/overview/index.php',
                '/grade/report/user/index.php',
                '/user/files.php',
                '/login/logout.php',
            ];
        } else if ($islecturerportalpage) {
            $allowedpaths = [
                '/user/profile.php',
                '/user/files.php',
                '/login/logout.php',
            ];
        } else if ($isadminportalpage || $issuperadminportalpage) {
            $allowedpaths = [
                '/login/logout.php',
            ];
        }

        $items = array_values(array_filter($menu['items'], function($item) use ($allowedpaths): bool {
            if (!is_object($item)) {
                return true;
            }

            $url = (string)($item->url ?? '');
            $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');

            if ($allowedpaths !== null) {
                return in_array($path, $allowedpaths, true);
            }

            return true;
        }));

        $routingservice = class_exists(\local_ulms_auth\local\service\landing_page_service::class)
            ? new \local_ulms_auth\local\service\landing_page_service()
            : null;

        foreach ($items as $item) {
            if (is_object($item)) {
                if ($routingservice !== null && ($isstudentportalpage || $islecturerportalpage)) {
                    $path = (string)(parse_url((string)($item->url ?? ''), PHP_URL_PATH) ?? '');
                    if ($path === '/user/profile.php') {
                        $item->url = $routingservice->get_url_for_route(
                            $isstudentportalpage ? 'student.profile' : 'lecturer.profile'
                        )->out(false);
                    } else if ($isstudentportalpage && in_array($path, ['/grade/report/overview/index.php', '/grade/report/user/index.php'], true)) {
                        $item->url = $routingservice->get_url_for_route('student.grades')->out(false);
                    } else if ($isstudentportalpage && $path === '/message/index.php') {
                        $item->url = $routingservice->get_url_for_route('student.messages')->out(false);
                    } else if ($islecturerportalpage && $path === '/message/index.php') {
                        $item->url = $routingservice->get_url_for_route('lecturer.messages')->out(false);
                    }
                }
                $item->divider = false;
            }
        }

        if (count($items) >= 2 && is_object($items[count($items) - 2])) {
            $items[count($items) - 2]->divider = true;
        }

        $menu['items'] = $items;
        return $menu;
    }

    /**
     * Returns sidebar shell context for the current authenticated portal page.
     *
     * @return array|null
     */
    public function get_portal_shell_context(): ?array {
        $studentportalservice = $this->get_student_portal_service();
        if ($studentportalservice !== null) {
            $studentcontext = $studentportalservice->get_shell_context_for_page($this->page);
            if ($studentcontext !== null) {
                return $studentcontext;
            }
        }

        $lecturerportalservice = $this->get_lecturer_portal_service();
        if ($lecturerportalservice !== null) {
            $lecturercontext = $lecturerportalservice->get_shell_context_for_page($this->page);
            if ($lecturercontext !== null) {
                return $lecturercontext;
            }
        }

        $superadminportalservice = $this->get_super_admin_portal_service();
        if ($superadminportalservice !== null) {
            $superadmincontext = $superadminportalservice->get_shell_context_for_page($this->page);
            if ($superadmincontext !== null) {
                return $superadmincontext;
            }
        }

        $adminportalservice = $this->get_admin_portal_service();
        if ($adminportalservice !== null) {
            return $adminportalservice->get_shell_context_for_page($this->page);
        }

        return null;
    }

    /**
     * Registers lightweight interaction enhancements for ULMS custom pages.
     */
    protected function register_ulms_interface_behaviour(): void {
        if ($this->ulmsbehaviourregistered) {
            return;
        }

        $path = $this->page->url ? $this->page->url->get_path() : '';
        $portalcontext = $this->get_portal_header_context();
        $routingservice = class_exists(\local_ulms_auth\local\service\landing_page_service::class)
            ? new \local_ulms_auth\local\service\landing_page_service()
            : null;
        $isulmsroute = (!empty($path) && str_starts_with($path, '/local/ulms_'))
            || ($routingservice !== null && $routingservice->is_ulms_route_path($path));
        if (!$isulmsroute && $portalcontext === null) {
            return;
        }

        $this->ulmsbehaviourregistered = true;

        $dashboardurl = $this->get_current_user_dashboard_url();
        $dashboardurljs = $dashboardurl !== null ? json_encode($dashboardurl) : 'null';
        $this->page->requires->js_init_code(
            "(function() {
                var isPortalPage =
                    document.body.classList.contains('ulms-student-portal-page') ||
                    document.body.classList.contains('ulms-lecturer-portal-page') ||
                    document.body.classList.contains('ulms-dashboard-page');

                if (isPortalPage) {
                    var pageHeader = document.getElementById('page-header');
                    if (pageHeader) {
                        pageHeader.style.display = 'block';
                    }

                    var dashboardUrl = {$dashboardurljs};
                    var brandLink = document.querySelector('.navbar .navbar-brand');
                    if (dashboardUrl && brandLink) {
                        brandLink.setAttribute('href', dashboardUrl);
                    }

                    ['.secondary-navigation', '.tertiary-navigation', '.moremenu.navigation', '#region-main-settings-menu']
                        .forEach(function(selector) {
                            document.querySelectorAll(selector).forEach(function(element) {
                                element.style.display = 'none';
                            });
                        });
                }

                var BP_XL = 1200;
                var BP_MD = 768;
                var STORAGE_KEY = 'ulms_sidebar_state_v2';
                var DRAWER_TRANSITION_MS = 300;
                var portalShell = document.querySelector('.ulms-shell');
                var sidebar = document.getElementById('ulms-shell-sidebar');
                var sidebarToggles = document.querySelectorAll('[data-ulms-sidebar-toggle=\"true\"]');
                var sidebarDismissers = document.querySelectorAll('[data-ulms-sidebar-dismiss=\"true\"]');
                var sidebarTransitionLock = 0;
                var lastToggleSource = null;
                var focusReturnTarget = null;
                var bodyScrollRestoreY = 0;
                var resizeRAF = 0;
                var lastViewMode = null;

                var sidebarUnlock = function() {
                    sidebarTransitionLock = 0;
                    if (document.body && document.body.style.pointerEvents !== '') {
                        document.body.style.pointerEvents = '';
                    }
                };
                var readState = function() {
                    try {
                        var raw = window.localStorage.getItem(STORAGE_KEY);
                        if (!raw) return { collapsed: false, open: false };
                        var parsed = JSON.parse(raw);
                        return { collapsed: !!parsed.collapsed, open: !!parsed.open };
                    } catch (e) { return { collapsed: false, open: false }; }
                };
                var writeState = function(overrides) {
                    try {
                        var base = readState();
                        window.localStorage.setItem(STORAGE_KEY, JSON.stringify({
                            collapsed: overrides && typeof overrides.collapsed === 'boolean' ? overrides.collapsed : base.collapsed,
                            open: overrides && typeof overrides.open === 'boolean' ? overrides.open : base.open
                        }));
                    } catch (e) {}
                };
                var setAllTogglesExpanded = function(expanded) {
                    sidebarToggles.forEach(function(t) { t.setAttribute('aria-expanded', expanded ? 'true' : 'false'); });
                };
                var viewportMode = function(w) {
                    if (w == null) w = window.innerWidth;
                    if (w >= BP_XL) return 'xl';
                    if (w >= BP_MD) return 'md';
                    return 'sm';
                };
                var lockBodyScroll = function(on) {
                    if (!document.body) return;
                    var htmlEl = document.documentElement;
                    if (on) {
                        bodyScrollRestoreY = window.scrollY || window.pageYOffset || 0;
                        document.body.style.position = 'fixed';
                        document.body.style.top = '-' + bodyScrollRestoreY + 'px';
                        document.body.style.left = '0';
                        document.body.style.right = '0';
                        document.body.style.width = '100%';
                        if (htmlEl) htmlEl.style.overflowY = 'scroll';
                    } else {
                        var restoreY = bodyScrollRestoreY;
                        document.body.style.position = '';
                        document.body.style.top = '';
                        document.body.style.left = '';
                        document.body.style.right = '';
                        document.body.style.width = '';
                        document.body.style.overflow = '';
                        if (htmlEl) htmlEl.style.overflowY = '';
                        if (restoreY) window.scrollTo(0, restoreY);
                    }
                };
                var getDrawerFocusables = function() {
                    if (!sidebar) return [];
                    var sel = 'a[href], area[href], input:not([disabled]):not([type=\"hidden\"]), select:not([disabled]), textarea:not([disabled]), button:not([disabled]), iframe, object, embed, [contenteditable], [tabindex]:not([tabindex=\"-1\"])';
                    return Array.prototype.filter.call(sidebar.querySelectorAll(sel), function(el) {
                        return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
                    });
                };
                var trapTabWithinDrawer = function(ev) {
                    if (!portalShell || !portalShell.classList.contains('is-sidebar-open')) return;
                    if (ev.key !== 'Tab') return;
                    var focusables = getDrawerFocusables();
                    if (!focusables.length) return;
                    var first = focusables[0];
                    var last = focusables[focusables.length - 1];
                    var active = document.activeElement;
                    if (ev.shiftKey && active === first) {
                        ev.preventDefault(); last.focus();
                    } else if (!ev.shiftKey && active === last) {
                        ev.preventDefault(); first.focus();
                    }
                };
                var drawerKeydown = function(ev) {
                    if (!portalShell || !portalShell.classList.contains('is-sidebar-open')) return;
                    if (ev.key === 'Escape') {
                        ev.stopPropagation();
                        ev.preventDefault();
                        closeSidebar(true);
                    }
                };
                var closeSidebar = function(restoreFocus) {
                    if (!portalShell || !sidebar) return;
                    var wasOpen = portalShell.classList.contains('is-sidebar-open');
                    portalShell.classList.remove('is-sidebar-open');
                    setAllTogglesExpanded(!portalShell.classList.contains('is-sidebar-collapsed'));
                    sidebar.setAttribute('aria-hidden', 'true');
                    if (wasOpen) {
                        (function removeInert() {
                            try {
                                var inertTargets = document.querySelectorAll('.ulms-shell-content, #page, .app-main, [role=\"main\"]');
                                inertTargets.forEach(function (el) { if (el.removeAttribute) el.removeAttribute('inert'); });
                            } catch (e) {}
                        })();
                        lockBodyScroll(false);
                        document.removeEventListener('keydown', trapTabWithinDrawer, true);
                        document.removeEventListener('keydown', drawerKeydown, true);
                        writeState({ open: false });
                    }
                    if (restoreFocus && focusReturnTarget && typeof focusReturnTarget.focus === 'function') {
                        try { focusReturnTarget.focus(); } catch (e) {}
                    }
                };
                var openSidebar = function(triggerEl) {
                    if (!portalShell || !sidebar) return;
                    focusReturnTarget = triggerEl && typeof triggerEl.focus === 'function' ? triggerEl : (sidebarToggles[0] || null);
                    portalShell.classList.add('is-sidebar-open');
                    portalShell.classList.remove('is-sidebar-collapsed');
                    setAllTogglesExpanded('true');
                    sidebar.setAttribute('aria-hidden', 'false');
                    (function applyInert() {
                        try {
                            var inertTargets = document.querySelectorAll('.ulms-shell-content, #page, .app-main, [role=\"main\"]');
                            inertTargets.forEach(function (el) { if (el.setAttribute) el.setAttribute('inert', ''); });
                        } catch (e) {}
                    })();
                    lockBodyScroll(true);
                    writeState({ open: true });
                    document.addEventListener('keydown', trapTabWithinDrawer, true);
                    document.addEventListener('keydown', drawerKeydown, true);
                    window.setTimeout(function() {
                        var focusables = getDrawerFocusables();
                        if (focusables.length) { try { focusables[0].focus(); } catch (e) {} }
                        else {
                            sidebar.setAttribute('tabindex', '-1');
                            try { sidebar.focus(); } catch (e) {}
                        }
                    }, 50);
                };
                var applyStateForMode = function(mode) {
                    if (!portalShell || !sidebar) return;
                    var stored = readState();
                    closeSidebar(false);
                    if (mode === 'xl') {
                        portalShell.classList.toggle('is-sidebar-collapsed', !!stored.collapsed);
                        setAllTogglesExpanded(!stored.collapsed);
                    } else if (mode === 'md') {
                        portalShell.classList.add('is-sidebar-collapsed');
                        setAllTogglesExpanded('false');
                    } else {
                        portalShell.classList.remove('is-sidebar-collapsed');
                        setAllTogglesExpanded('false');
                    }
                };
                var toggleFromUser = function(ev) {
                    ev.preventDefault();
                    if (sidebarTransitionLock) return;
                    var trigger = ev.currentTarget || sidebarToggles[0] || null;
                    var mode = viewportMode();
                    if (mode === 'sm') {
                        lastToggleSource = 'drawer';
                        if (portalShell.classList.contains('is-sidebar-open')) {
                            closeSidebar(true);
                        } else {
                            openSidebar(trigger);
                        }
                        return;
                    }
                    lastToggleSource = 'rail';
                    var nextCollapsed = !portalShell.classList.contains('is-sidebar-collapsed');
                    sidebarTransitionLock = 1;
                    if (document.body) document.body.style.pointerEvents = 'none';
                    window.setTimeout(sidebarUnlock, DRAWER_TRANSITION_MS + 80);
                    portalShell.classList.toggle('is-sidebar-collapsed', nextCollapsed);
                    setAllTogglesExpanded(!nextCollapsed);
                    writeState({ collapsed: nextCollapsed });
                };
                var scheduleResizeApply = function() {
                    if (resizeRAF) return;
                    resizeRAF = window.requestAnimationFrame ? window.requestAnimationFrame(function() {
                        resizeRAF = 0;
                        var mode = viewportMode();
                        if (mode === lastViewMode) return;
                        lastViewMode = mode;
                        applyStateForMode(mode);
                    }) : (window.setTimeout(function() { resizeRAF = 0; applyStateForMode(viewportMode()); lastViewMode = viewportMode(); }, 60) || 1);
                };

                if (portalShell && sidebar) {
                    lastViewMode = viewportMode();
                    applyStateForMode(lastViewMode);
                    sidebarToggles.forEach(function(t) {
                        t.addEventListener('click', toggleFromUser);
                    });
                    sidebarDismissers.forEach(function(d) {
                        d.addEventListener('click', function() { closeSidebar(true); });
                    });
                    sidebar.addEventListener('click', function(ev) {
                        var a = ev.target;
                        while (a && a !== sidebar) {
                            if (a instanceof Element && a.tagName === 'A' && a.getAttribute('href')) {
                                if (viewportMode() === 'sm') {
                                    setTimeout(function() { closeSidebar(false); }, 100);
                                }
                                return;
                            }
                            a = a.parentNode;
                        }
                    }, true);
                    var startX = null;
                    sidebar.addEventListener('touchstart', function(e) {
                        if (!e.touches || !e.touches.length) return;
                        startX = e.touches[0].clientX;
                    }, { passive: true });
                    sidebar.addEventListener('touchend', function(e) {
                        if (startX == null || !e.changedTouches || !e.changedTouches.length) return;
                        if (e.changedTouches[0].clientX - startX <= -55) closeSidebar(true);
                        startX = null;
                    }, { passive: true });
                    window.addEventListener('resize', scheduleResizeApply);
                    if (window.matchMedia) {
                        var mqXL = window.matchMedia('(min-width: ' + BP_XL + 'px)');
                        var mqMD = window.matchMedia('(min-width: ' + BP_MD + 'px) and (max-width: ' + (BP_XL - 0.02) + 'px)');
                        var onBP = function() { scheduleResizeApply(); };
                        [mqXL, mqMD].forEach(function(m) {
                            if (typeof m.addEventListener === 'function') m.addEventListener('change', onBP);
                            else if (typeof m.addListener === 'function') m.addListener(onBP);
                        });
                    }
                    document.addEventListener('click', function(ev) {
                        if (!portalShell.classList.contains('is-sidebar-open')) return;
                        if (viewportMode() !== 'sm') return;
                        var node = ev.target;
                        while (node && node !== document.body) {
                            if (node === sidebar) return;
                            if (node instanceof Element && node.closest && node.closest('[data-ulms-sidebar-toggle=\"true\"]')) return;
                            node = node.parentNode;
                        }
                        closeSidebar(true);
                    }, true);
                }


                var userMenuToggle = document.getElementById('user-menu-toggle');
                var userMenuDropdown = userMenuToggle ? userMenuToggle.closest('.dropdown') : null;
                var userMenu = userMenuDropdown ? userMenuDropdown.querySelector('.dropdown-menu') : null;
                if (userMenuToggle && userMenu && userMenuDropdown) {
                    var closeUserMenu = function() {
                        userMenu.classList.remove('show');
                        userMenuDropdown.classList.remove('show');
                        userMenuToggle.setAttribute('aria-expanded', 'false');
                    };
                    var openUserMenu = function() {
                        userMenu.classList.add('show');
                        userMenuDropdown.classList.add('show');
                        userMenuToggle.setAttribute('aria-expanded', 'true');
                    };
                    userMenuToggle.setAttribute('aria-expanded', userMenu.classList.contains('show') ? 'true' : 'false');
                    userMenuToggle.addEventListener('click', function(event) {
                        event.preventDefault();
                        event.stopPropagation();
                        if (userMenu.classList.contains('show')) {
                            closeUserMenu();
                            return;
                        }
                        openUserMenu();
                    });
                    document.addEventListener('click', function(event) {
                        if (!userMenuDropdown.contains(event.target)) {
                            closeUserMenu();
                        }
                    });
                    document.addEventListener('keydown', function(event) {
                        if (event.key === 'Escape') {
                            closeUserMenu();
                        }
                    });
                }

                (function wrapOrphanTables() {
                    try {
                        if (!document.querySelectorAll) return;
                        var tables = document.querySelectorAll('table');
                        if (!tables || !tables.length) return;
                        for (var i = 0; i < tables.length; i++) {
                            var t = tables[i];
                            if (!t) continue;
                            if (t.classList && t.classList.contains('ulms-native-table')) continue;
                            var p = t.parentNode;
                            var guard = 6;
                            var alreadyWrapped = false;
                            while (p && guard-- > 0) {
                                if (p.classList && (
                                    p.classList.contains('ulms-table-scroll') ||
                                    p.classList.contains('table-wrap') ||
                                    p.classList.contains('no-overflow')
                                )) { alreadyWrapped = true; break; }
                                if (p.tagName && p.tagName === 'TD') { alreadyWrapped = true; break; }
                                p = p.parentNode;
                            }
                            if (alreadyWrapped) continue;
                            var wrapper = document.createElement('div');
                            wrapper.className = 'ulms-table-scroll';
                            wrapper.setAttribute('data-ulms-table-wrap', '1');
                            var parent = t.parentNode;
                            if (!parent) continue;
                            parent.insertBefore(wrapper, t);
                            wrapper.appendChild(t);
                        }
                    } catch (e) {}
                })();

                document.querySelectorAll('[data-ulms-history-back=\"true\"]').forEach(function(link) {
                    link.addEventListener('click', function(event) {
                        var fallback = link.getAttribute('data-ulms-back-fallback');
                        var sameOriginReferrer = document.referrer && document.referrer.indexOf(window.location.origin) === 0;
                        if (window.history.length > 1 && sameOriginReferrer) {
                            event.preventDefault();
                            window.history.back();
                            return;
                        }

                        if (!fallback) {
                            return;
                        }

                        link.setAttribute('href', fallback);
                    });
                });
                var forms = document.querySelectorAll('form[data-ulms-loading-form], .ulms-panel form, .ulms-page form.mform');
                forms.forEach(function(form) {
                    form.addEventListener('submit', function(event) {
                        if (form.getAttribute('data-ulms-submitting') === '1') {
                            event.preventDefault();
                            return;
                        }

                        var submitter = event.submitter;
                        if (!submitter) {
                            submitter = form.querySelector('button[type=\"submit\"]:not([disabled]), input[type=\"submit\"]:not([disabled])');
                        }

                        form.querySelectorAll('input[data-ulms-submit-preserver=\"1\"]').forEach(function(field) {
                            field.remove();
                        });

                        if (submitter && submitter.name) {
                            var preservedSubmitter = document.createElement('input');
                            preservedSubmitter.type = 'hidden';
                            preservedSubmitter.name = submitter.name;
                            preservedSubmitter.value = submitter.value;
                            preservedSubmitter.setAttribute('data-ulms-submit-preserver', '1');
                            form.appendChild(preservedSubmitter);
                        }

                        form.setAttribute('data-ulms-submitting', '1');
                        form.setAttribute('aria-busy', 'true');
                        var buttons = form.querySelectorAll('button[type=\"submit\"], input[type=\"submit\"]');
                        buttons.forEach(function(button) {
                            var loadingText = button.getAttribute('data-loading-text');
                            if (loadingText) {
                                if (button.tagName === 'INPUT') {
                                    button.setAttribute('data-original-value', button.value);
                                    button.value = loadingText;
                                } else {
                                    button.setAttribute('data-original-html', button.innerHTML);
                                    button.innerHTML = loadingText;
                                }
                            }
                            button.disabled = true;
                            button.classList.add('ulms-is-loading');
                        });
                    });
                });
            })();"
        );
    }

    /**
     * Ensures ULMS interaction helpers are always registered for portal shells.
     *
     * @return string
     */
    public function standard_top_of_body_html(): string {
        $this->register_ulms_interface_behaviour();
        $ariaLive = '<div id="ulms-a11y-announcements" role="status" aria-live="polite" aria-atomic="true" class="sr-only" aria-relevant="additions"></div>';
        return $ariaLive . parent::standard_top_of_body_html();
    }

    /**
     * Adds a branded banner to selected Moodle layouts.
     *
     * @return string
     */
    public function full_header(): string {
        self::inject_http_security_headers();
        $this->register_ulms_interface_behaviour();
        $header = parent::full_header();

        if ($this->should_render_brand_banner()) {
            $header .= $this->render_from_template(
                'theme_ulms_university/core/brand_banner',
                \theme_ulms_university_get_dashboard_context()
            );
        }

        return $header;
    }

    /**
     * Returns student portal header context for the current page when applicable.
     *
     * @return array|null
     */
    protected function get_portal_header_context(): ?array {
        $studentportalservice = $this->get_student_portal_service();
        if ($studentportalservice !== null) {
            $studentcontext = $studentportalservice->get_header_context_for_page($this->page);
            if ($studentcontext !== null) {
                return $studentcontext;
            }
        }

        $lecturerportalservice = $this->get_lecturer_portal_service();
        if ($lecturerportalservice !== null) {
            $lecturercontext = $lecturerportalservice->get_header_context_for_page($this->page);
            if ($lecturercontext !== null) {
                return $lecturercontext;
            }
        }

        $superadminportalservice = $this->get_super_admin_portal_service();
        if ($superadminportalservice !== null) {
            $superadmincontext = $superadminportalservice->get_header_context_for_page($this->page);
            if ($superadmincontext !== null) {
                return $superadmincontext;
            }
        }

        $adminportalservice = $this->get_admin_portal_service();
        if ($adminportalservice !== null) {
            $admincontext = $adminportalservice->get_header_context_for_page($this->page);
            if ($admincontext !== null) {
                return $admincontext;
            }
        }

        return null;
    }

    /**
     * Returns the current user's dashboard URL when routing support is available.
     *
     * @return string|null
     */
    protected function get_current_user_dashboard_url(): ?string {
        if (!\isloggedin() || \isguestuser() || !class_exists(\local_ulms_auth\local\service\landing_page_service::class)) {
            return null;
        }

        $routingservice = new \local_ulms_auth\local\service\landing_page_service();
        return $routingservice->get_dashboard_url_for_current_user()->out(false);
    }

    /**
     * Overrides core login_info to rewrite footer profile URLs to the correct
     * student/lecturer portal profile page, preventing users from dropping
     * out of their portal shell via the footer "Logged in as" link.
     *
     * @param bool|null $withlinks
     * @return string
     */
    public function login_info($withlinks = null): string {
        $output = parent::login_info($withlinks);

        if (!\isloggedin() || \isguestuser()) {
            return $output;
        }

        $studentportalservice = $this->get_student_portal_service();
        $isstudent = $studentportalservice !== null && $studentportalservice->is_student_portal_page($this->page);
        $lecturerportalservice = $this->get_lecturer_portal_service();
        $islecturer = $lecturerportalservice !== null && $lecturerportalservice->is_lecturer_portal_page($this->page);

        if (!$isstudent && !$islecturer) {
            return $output;
        }

        $routingservice = class_exists(\local_ulms_auth\local\service\landing_page_service::class)
            ? new \local_ulms_auth\local\service\landing_page_service()
            : null;

        if ($routingservice === null) {
            return $output;
        }

        $portalprofile = $routingservice->get_url_for_route(
            $isstudent ? 'student.profile' : 'lecturer.profile'
        )->out(false);

        $output = preg_replace(
            '#href="[^"]*/user/profile\.php[^"]*"#',
            'href="' . $portalprofile . '"',
            $output
        );

        if ($isstudent) {
            $portalgrades = $routingservice->get_url_for_route('student.grades')->out(false);
            $portalmessages = $routingservice->get_url_for_route('student.messages')->out(false);
            $output = preg_replace(
                '#href="[^"]*/grade/report/overview/index\.php[^"]*"#',
                'href="' . $portalgrades . '"',
                $output
            );
            $output = preg_replace(
                '#href="[^"]*/message/index\.php[^"]*"#',
                'href="' . $portalmessages . '"',
                $output
            );
        }

        return $output;
    }

    /**
     * Overrides the core renderer get_compact_logo_url() so the Bells
     * University full banner logo URL is always served for ULMS portal
     * templates (navbar non-collapsed, sidebar brand, footer, login hero,
     * brand banner seal).  Parameters match the parent signature exactly
     * to avoid LSP inheritance fatals.
     *
     * @param int|null $maxwidth  Maximum acceptable width (ignored — PNG is
     *                            rasterized at final 600×200, CSS handles resizing).
     * @param int|null $maxheight Maximum acceptable height (same note).
     * @return \moodle_url|string Always returns our theme logo URL (string form
     *                            so Mustache direct src attribute use works).
     */
    public function get_compact_logo_url($maxwidth = 300, $maxheight = 300) {
        try {
            return (string)$this->image_url('logo', 'theme_ulms_university');
        } catch (\Throwable $_unused) {
            global $CFG;
            return ($CFG->wwwroot ?? '') . '/theme/ulms_university/pix/logo.png';
        }
    }

    /**
     * ULMS portal pages always display the branded navbar logo to the left
     * of the institution sitename text.  Falls back to the parent check if
     * the current page is a non-portal Moodle admin page.
     *
     * @return bool
     */
    public function should_display_navbar_logo() {
        return true;
    }

    /**
     * Overrides the core renderer image_url() so that the 0-argument form
     * (invoked by Mustache {{output.image_url}} property access) returns
     * the standalone Bells circular seal URL used by collapsed-sidebar
     * mini-logo state and responsive compact headers.
     *
     * Normal 1-2 argument calls are delegated unchanged to the parent.
     *
     * @param string|null $imagename
     * @param string $component
     * @return \moodle_url|string
     */
    public function image_url($imagename = null, $component = 'moodle') {
        if ($imagename === null || $imagename === '') {
            try {
                return (string)parent::image_url('logo-mini', 'theme_ulms_university');
            } catch (\Throwable $_unused) {
                global $CFG;
                return ($CFG->wwwroot ?? '') . '/theme/ulms_university/pix/logo-mini.png';
            }
        }
        return parent::image_url($imagename, $component);
    }

    /**
     * Returns the student portal service when available.
     *
     * @return \local_ulms_dashboard\local\service\student_portal_service|null
     */
    protected function get_student_portal_service(): ?\local_ulms_dashboard\local\service\student_portal_service {
        if (!class_exists(\local_ulms_dashboard\local\service\student_portal_service::class)) {
            return null;
        }

        return new \local_ulms_dashboard\local\service\student_portal_service();
    }

    /**
     * Returns the lecturer portal service when available.
     *
     * @return \local_ulms_dashboard\local\service\lecturer_portal_service|null
     */
    protected function get_lecturer_portal_service(): ?\local_ulms_dashboard\local\service\lecturer_portal_service {
        if (!class_exists(\local_ulms_dashboard\local\service\lecturer_portal_service::class)) {
            return null;
        }

        return new \local_ulms_dashboard\local\service\lecturer_portal_service();
    }

    /**
     * Returns the admin portal service when available.
     *
     * @return \local_ulms_dashboard\local\service\admin_portal_service|null
     */
    protected function get_admin_portal_service(): ?\local_ulms_dashboard\local\service\admin_portal_service {
        if (!class_exists(\local_ulms_dashboard\local\service\admin_portal_service::class)) {
            return null;
        }

        return new \local_ulms_dashboard\local\service\admin_portal_service();
    }

    /**
     * Returns the super admin portal service when available.
     *
     * @return \local_ulms_dashboard\local\service\super_admin_portal_service|null
     */
    protected function get_super_admin_portal_service(): ?\local_ulms_dashboard\local\service\super_admin_portal_service {
        if (!class_exists(\local_ulms_dashboard\local\service\super_admin_portal_service::class)) {
            return null;
        }

        return new \local_ulms_dashboard\local\service\super_admin_portal_service();
    }

    /**
     * Generates a short opaque reference id printed on error banners so users
     * can reference concrete incidents with support teams.
     *
     * @return string
     */
    private static function get_support_reference_id(): string {
        if (!empty($_SERVER['ULMS_ERROR_REF'])) {
            return (string)$_SERVER['ULMS_ERROR_REF'];
        }
        $bytes = random_bytes(4);
        $ref = strtoupper(substr(bin2hex($bytes), 0, 8));
        $_SERVER['ULMS_ERROR_REF'] = $ref;
        return $ref;
    }

    /**
     * Wraps Moodle flash notifications with a branded ULMS pill gradient
     * card so success/info/warn/error banners match the LMS visual system.
     *
     * Stack traces and raw exception messages are suppressed from user-visible
     * HTML output; they are preserved for error_log() only.
     *
     * @param \core\output\notification $notification
     * @return string
     */
    protected function render_notification(\core\output\notification $notification): string {
        global $CFG;

        $message = (string)$notification->get_message();
        $message = preg_replace('/<pre[^>]*>.*?<\/pre>/si', '', $message);
        $message = preg_replace('/(Stack trace|Call stack|Backtrace|Debug info|Additional info):?\s*#?[0-9\s\S.]*$/i', '', $message);
        if (!empty($CFG->dirroot)) {
            $pattern = '/' . preg_quote((string)$CFG->dirroot, '/') . '[^"\']*[^"\'\s:<]+/i';
            $message = preg_replace($pattern, '[internal path]', $message);
        }

        $type = (int)$notification->get_message_type();
        $levelmap = [
            \core\output\notification::NOTIFY_SUCCESS => 'ulms-notice ulms-notice--success',
            \core\output\notification::NOTIFY_INFO => 'ulms-notice ulms-notice--info',
            \core\output\notification::NOTIFY_WARNING => 'ulms-notice ulms-notice--warn',
            \core\output\notification::NOTIFY_ERROR => 'ulms-notice ulms-notice--danger',
        ];
        $cssclass = $levelmap[$type] ?? 'ulms-notice ulms-notice--info';

        return sprintf(
            '<div class="%s"><div class="ulms-notice__message">%s</div></div>',
            $cssclass,
            $message
        );
    }

    /**
     * Renders the Moodle fatal / exception error box used when the public
     * default_error_output_moodle_box fallback is triggered.
     *
     * This override guarantees:
     *   1. No stack trace, sql error text or filesystem path is visible to users.
     *   2. A short 8-char ULMS support reference ID is printed on-screen
     *      and error_log() so NOC teams can correlate user reports with logs.
     *   3. The card is styled with the ULMS gradient pill / 20px radius.
     *
     * @param string $message
     * @param string $_moreinfourl
     * @param bool $backtrace
     * @param bool $supportemail
     * @return string
     */
    public function error_message($message, $_moreinfourl = '', $backtrace = false, $supportemail = null) {
        global $CFG;

        $ref = self::get_support_reference_id();
        $safeheader = 'An error occurred';

        $publicmessage = (string)$message;
        $publicmessage = preg_replace('/<pre[^>]*>.*?<\/pre>/si', '', $publicmessage);
        $publicmessage = preg_replace('/(Stack trace|Call stack|Backtrace|Debug info|Additional info):?\s*#?[^\n]*\n(?:\s*#[^\n]*\n?)*/si', '', $publicmessage);
        if (!empty($CFG->dirroot)) {
            $pattern = '/' . preg_quote((string)$CFG->dirroot, '/') . '[^"\']*[^"\'\s:<]+/i';
            $publicmessage = preg_replace($pattern, '[internal path]', $publicmessage);
        }

        if (!empty($CFG->debugdeveloper) && !empty($backtrace) && function_exists('debugging')) {
            error_log(sprintf(
                '[ULMS ERROR REF=%s] %s | backtrace=%s',
                $ref,
                (string)$message,
                (is_array($backtrace) || is_object($backtrace)) ? json_encode($backtrace, JSON_UNESCAPED_SLASHES) : (string)$backtrace
            ));
        } else {
            error_log(sprintf('[ULMS ERROR REF=%s] %s', $ref, (string)$message));
        }

        $contact = $supportemail ?? ($CFG->supportemail ?? 'support@ulms.local');

        if (trim($publicmessage) === '') {
            $publicmessage = 'The platform encountered an unexpected condition while handling your request.';
        }

        $html = '<div class="ulms-error-card" role="alert" aria-labelledby="ulms-error-title" aria-describedby="ulms-error-detail">'
            . '<h2 id="ulms-error-title" class="ulms-error-card__title">' . s($safeheader) . '</h2>'
            . '<div id="ulms-error-detail" class="ulms-error-card__body">'
            . '<p>' . format_text($publicmessage, FORMAT_HTML, ['trusted' => false, 'noclean' => false]) . '</p>'
            . '<p class="ulms-error-card__ref"><strong>Support reference:</strong> <code>' . s($ref) . '</code></p>'
            . '<p class="ulms-error-card__contact">If the problem persists, please contact <a href="mailto:' . s($contact) . '">' . s($contact) . '</a> and include the support reference above.</p>'
            . '</div></div>';

        return $html;
    }
}
