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

defined('MOODLE_INTERNAL') || die();

/**
 * Prepares a ULMS dashboard page before service data is generated.
 *
 * @param \context $context
 * @param \moodle_url $url
 * @param string $title
 * @return void
 */
function local_ulms_dashboard_prepare_page(\context $context, \moodle_url $url, string $title): void {
    global $PAGE;

    $PAGE->set_context($context);
    $PAGE->set_url($url);
    $PAGE->set_title($title);
    $PAGE->set_heading($title);
    $PAGE->set_pagelayout('ulmsdashboard');
}

/**
 * Returns the portal service that should render management functionality for
 * the current authenticated user.
 *
 * Admin users stay in the admin portal. Super admins may open management
 * functionality while retaining the super admin portal shell and header.
 *
 * @return object
 */
function local_ulms_dashboard_get_management_portal_service(): object {
    $routingservice = new \local_ulms_auth\local\service\landing_page_service();

    if (isloggedin() && !isguestuser() && $routingservice->get_current_user_portal_key() === 'superadmin') {
        return new \local_ulms_dashboard\local\service\super_admin_portal_service();
    }

    return new \local_ulms_dashboard\local\service\admin_portal_service();
}

/**
 * Returns header context for management functionality based on the user's
 * portal identity.
 *
 * @param string $section
 * @return array<string, mixed>
 */
function local_ulms_dashboard_get_management_header_context(string $section): array {
    $portalservice = local_ulms_dashboard_get_management_portal_service();

    if ($portalservice instanceof \local_ulms_dashboard\local\service\super_admin_portal_service) {
        return $portalservice->get_header_context_for_management_section($section);
    }

    return $portalservice->get_header_context_for_section($section);
}

/**
 * Renders a reusable ULMS page header.
 *
 * @param array<string, mixed> $config
 * @return string
 */
function local_ulms_dashboard_render_page_header(array $config): string {
    static $cssemitted = false;
    $content = '';
    if (!$cssemitted) {
        $css = <<<'ULMSCSS'
.ulms-action-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1rem}
.ulms-action-card{display:block;height:100%;padding:1.1rem 1.15rem;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;color:inherit;text-decoration:none;transition:border-color .2s ease,box-shadow .2s ease;min-width:0}
.ulms-action-card:hover,.ulms-action-card:focus-visible{color:inherit;text-decoration:none;border-color:#94a3b8;box-shadow:0 1px 2px rgba(15,23,42,.04)}
.ulms-action-card__title{margin:0;font-size:1rem;font-weight:700}
.ulms-action-card__meta{margin-top:.45rem;color:#64748b;line-height:1.55}
.ulms-action-card__footer{margin-top:.85rem;font-weight:600;color:#0f4c81}
.ulms-action-card img,
.ulms-action-card__cover,
.ulms-action-card__cover img,
.ulms-action-card [class*="__cover"]{display:block;width:100%;max-width:100%;height:auto;aspect-ratio:16/9;object-fit:cover;border-radius:10px;overflow:hidden;margin:0 0 .85rem;background:#eef2f7}
.ulms-summary-cards__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.9rem}
.ulms-quick-access__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.9rem}
.ulms-panel__body>.ulms-action-grid{margin-top:.1rem}
@media (min-width:1400px){.ulms-action-grid{grid-template-columns:repeat(auto-fit,minmax(260px,1fr))}}
@media (max-width:991.98px){.ulms-action-grid{grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.85rem}}
@media (max-width:767.98px){.ulms-action-grid{grid-template-columns:1fr;gap:.75rem}.ulms-action-card{padding:.95rem 1rem}}
@media (max-width:479.98px){.ulms-action-card{padding:.85rem .9rem;border-radius:10px}.ulms-action-card__cover,.ulms-action-card img{aspect-ratio:4/3}}
ULMSCSS;
        $content .= html_writer::tag('style', $css, ['data-ulms-inline' => 'cards-responsive']);
        $cssemitted = true;
    }
    $eyebrow = (string)($config['eyebrow'] ?? '');
    $title = (string)($config['title'] ?? '');
    $meta = (string)($config['meta'] ?? '');
    $actions = $config['actions'] ?? [];
    $navitems = $config['navitems'] ?? [];

    $content .= html_writer::start_div('ulms-page-header');
    if ($eyebrow !== '') {
        $content .= html_writer::tag('div', format_string($eyebrow), ['class' => 'ulms-page-header__eyebrow']);
    }

    $content .= html_writer::start_div('ulms-page-header__content');
    $content .= html_writer::start_div('ulms-page-header__main');
    $content .= html_writer::tag('h1', format_string($title), ['class' => 'ulms-page-header__title']);
    if ($meta !== '') {
        $content .= html_writer::tag('p', format_string($meta), ['class' => 'ulms-page-header__meta']);
    }
    $content .= html_writer::end_div();

    if (!empty($actions)) {
        $buttons = [];
        foreach ($actions as $action) {
            $label = (string)($action['label'] ?? '');
            $url = $action['url'] ?? null;
            $class = (string)($action['class'] ?? 'btn btn-outline-secondary');
            if ($label === '' || $url === null) {
                continue;
            }
            $attributes = ['class' => $class];
            foreach (($action['attributes'] ?? []) as $name => $value) {
                $attributes[(string)$name] = (string)$value;
            }
            $buttons[] = html_writer::link($url, format_string($label), $attributes);
        }
        if (!empty($buttons)) {
            $content .= html_writer::div(implode('', $buttons), 'ulms-page-header__actions');
        }
    }
    $content .= html_writer::end_div();

    if (!empty($navitems)) {
        $links = [];
        foreach ($navitems as $item) {
            $label = (string)($item['label'] ?? '');
            $url = $item['url'] ?? null;
            $active = !empty($item['active']);
            if ($label === '') {
                continue;
            }

            if ($active || $url === null) {
                $links[] = html_writer::tag('span', format_string($label), ['class' => 'ulms-nav-pill ulms-nav-pill--active']);
            } else {
                $links[] = html_writer::link($url, format_string($label), ['class' => 'ulms-nav-pill']);
            }
        }
        if (!empty($links)) {
            $content .= html_writer::div(implode('', $links), 'ulms-page-header__nav ulms-nav-pills');
        }
    }

    $content .= html_writer::end_div();
    return $content;
}

/**
 * Renders a summary KPI grid.
 *
 * @param array<int, array<string, mixed>> $cards
 * @return string
 */
function local_ulms_dashboard_render_summary_cards(array $cards): string {
    if (empty($cards)) {
        return '';
    }

    $content = html_writer::start_div('ulms-kpi-grid');
    foreach ($cards as $card) {
        $content .= html_writer::start_div('ulms-kpi-card');
        $content .= html_writer::tag('span', format_string((string)($card['label'] ?? '')), ['class' => 'ulms-kpi-card__label']);
        $content .= html_writer::tag('strong', format_string((string)($card['value'] ?? '')), ['class' => 'ulms-kpi-card__value']);
        if (!empty($card['description'])) {
            $content .= html_writer::tag('p', format_string((string)$card['description']), ['class' => 'ulms-kpi-card__meta']);
        }
        $content .= html_writer::end_div();
    }
    $content .= html_writer::end_div();

    return $content;
}

/**
 * Renders a panel using a small set of shared content types.
 *
 * @param array<string, mixed> $panel
 * @return string
 */
function local_ulms_dashboard_render_panel(array $panel): string {
    $variant = !empty($panel['soft']) ? ' ulms-panel--soft' : '';
    $content = html_writer::start_div('ulms-panel' . $variant);
    $content .= html_writer::start_div('ulms-panel__header');
    $content .= html_writer::start_div();
    $content .= html_writer::tag('h2', format_string((string)($panel['title'] ?? '')), ['class' => 'ulms-panel__title']);
    if (!empty($panel['subtitle'])) {
        $content .= html_writer::tag('p', format_string((string)$panel['subtitle']), ['class' => 'ulms-panel__subtitle']);
    }
    $content .= html_writer::end_div();
    $content .= html_writer::end_div();
    $content .= html_writer::start_div('ulms-panel__body');

    $style = (string)($panel['style'] ?? 'cards');
    $items = $panel['items'] ?? [];

    if (empty($items) && !empty($panel['emptytitle'])) {
        $content .= html_writer::div(
            html_writer::tag('h3', format_string((string)$panel['emptytitle']), ['class' => 'ulms-empty-state__title']) .
            html_writer::tag('p', format_string((string)($panel['emptydesc'] ?? '')), ['class' => 'ulms-empty-state__meta']),
            'ulms-empty-state'
        );
        $content .= html_writer::end_div();
        $content .= html_writer::end_div();
        return $content;
    }

    if ($style === 'list') {
        $rows = [];
        foreach ($items as $item) {
            $title = format_string((string)($item['title'] ?? $item['label'] ?? ''));
            $meta = format_string((string)($item['meta'] ?? ''));
            $body = !empty($item['url'])
                ? html_writer::link($item['url'], $title, ['class' => 'ulms-list__title'])
                : html_writer::div($title, 'ulms-list__title');
            if ($meta !== '') {
                $body .= html_writer::div($meta, 'ulms-list__meta');
            }
            $rows[] = html_writer::tag('li', $body, ['class' => 'ulms-list__item']);
        }
        $content .= html_writer::tag('ul', implode('', $rows), ['class' => 'ulms-list']);
    } else if ($style === 'definition') {
        $rows = [];
        foreach ($items as $item) {
            $rows[] = html_writer::tag('dt', format_string((string)($item['label'] ?? '')), ['class' => 'col-sm-4']) .
                html_writer::tag('dd', format_string((string)($item['value'] ?? '')), ['class' => 'col-sm-8']);
        }
        $content .= html_writer::tag('dl', implode('', $rows), ['class' => 'row']);
    } else if ($style === 'html') {
        $content .= (string)($panel['html'] ?? '');
    } else {
        $cards = [];
        foreach ($items as $item) {
            $cardbody = html_writer::tag('div', format_string((string)($item['title'] ?? $item['label'] ?? '')), ['class' => 'ulms-action-card__title']);
            if (!empty($item['meta'])) {
                $cardbody .= html_writer::tag('div', (string)$item['meta'], ['class' => 'ulms-action-card__meta']);
            }
            if (!empty($item['footer'])) {
                $cardbody .= html_writer::tag('div', (string)$item['footer'], ['class' => 'ulms-action-card__footer']);
            }

            $class = 'ulms-action-card';
            $cards[] = !empty($item['url'])
                ? html_writer::link($item['url'], $cardbody, ['class' => $class])
                : html_writer::div($cardbody, $class);
        }
        $content .= html_writer::div(implode('', $cards), 'ulms-action-grid');
    }

    $content .= html_writer::end_div();
    $content .= html_writer::end_div();

    return $content;
}

/**
 * Opens the canonical ULMS dashboard shell wrapper that provides consistent
 * vertical spacing between page sections. Safe to call multiple times; the
 * depth guard prevents double-wrapping.
 *
 * @return void
 */
function local_ulms_dashboard_start_shell_wrap(): void {
    $depth =& local_ulms_dashboard_shell_depth();
    if ($depth <= 0) {
        echo html_writer::start_div('ulms-dashboard-shell');
    }
    $depth++;
}

/**
 * Closes the canonical ULMS dashboard shell wrapper. Must be paired with
 * local_ulms_dashboard_start_shell_wrap(). The depth guard prevents closing
 * the wrapper more times than it was opened.
 *
 * @return void
 */
function local_ulms_dashboard_end_shell_wrap(): void {
    $depth =& local_ulms_dashboard_shell_depth();
    $depth = max(0, $depth - 1);
    if ($depth === 0) {
        echo html_writer::end_div();
    }
}

/**
 * Shared shell wrapper depth counter (private helper).
 *
 * @return int
 */
function &local_ulms_dashboard_shell_depth(): int {
    static $depth = 0;
    return $depth;
}

/**
 * Recursively scrubs sensitive PII fields from log payloads to prevent credential
 * leakage into `error_log`. Operates on both array payloads:
 *  • Scrubs keys matching: password, token, api[_-]?key, secret, apikey,
 *    auth, cookie, session, email, idnumber, phone, address, ssn, sin,
 *    (case-insensitive).
 *  • Replaces scalar values with '[REDACTED]' and preserves structure.
 *
 * @param mixed $data
 * @return mixed
 */
function local_ulms_dashboard_scrub_sensitive_details(mixed $data): mixed {
    $pattern = '/(password|token|api[_-]?key|secret|apikey|auth|cookie|session|email|idnumber|phone|address|ssn|sin)/i';

    if (is_array($data)) {
        $scrubbed = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match($pattern, $key)) {
                $scrubbed[$key] = is_array($value) || is_object($value) ? '[REDACTED: ' . gettype($value) . ']' : '[REDACTED]';
            } else {
                $scrubbed[$key] = local_ulms_dashboard_scrub_sensitive_details($value);
            }
        }
        return $scrubbed;
    }

    if (is_object($data)) {
        return (object)local_ulms_dashboard_scrub_sensitive_details((array)$data);
    }

    return $data;
}

/**
 * Emits the same Strict-Transport-Security, Content-Security-Policy,
 * X-Content-Type-Options, X-Frame-Options and Referrer-Policy HTTP headers
 * that core_renderer normally injects for HTML responses. Call this function
 * BEFORE any native PHP header(Content-Type/Disposition) in raw-download
 * endpoints that bypass the renderer pipeline (CSV templates etc.).
 *
 * @return void
 */
function local_ulms_dashboard_emit_security_headers(): void {
    if (headers_sent()) {
        return;
    }
    @header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    @header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self' https:;");
    @header('X-Content-Type-Options: nosniff');
    @header('X-Frame-Options: SAMEORIGIN');
    @header('Referrer-Policy: strict-origin-when-cross-origin');
}

/**
 * Logs a full operational error payload for developer review.
 * SCRUBS all PII/sensitive data (passwords, tokens, API keys, emails,
 * idnumbers etc.) from the extra payload and from exception context via
 * local_ulms_dashboard_scrub_sensitive_details BEFORE error_log output.
 *
 * @param \Throwable $exception
 * @param string $location
 * @param array $extradata
 * @return void
 */
function local_ulms_dashboard_log_operational_error(\Throwable $exception, string $location, array $extradata = []): void {
    global $USER;

    $payload = [
        'location' => $location,
        'type' => get_class($exception),
        'message' => $exception->getMessage(),
        'code' => $exception->getCode(),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'trace' => $exception->getTraceAsString(),
        'userid' => isset($USER->id) ? (int)$USER->id : 0,
        'url' => qualified_me(),
        'extra' => local_ulms_dashboard_scrub_sensitive_details($extradata),
    ];

    $payload = local_ulms_dashboard_scrub_sensitive_details($payload);
    error_log('[ULMS_PORTAL_ERROR] ' . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/**
 * Renders a user-friendly portal error without exposing implementation details.
 *
 * @param string $title
 * @param string $message
 * @param \moodle_url|null $returnurl
 * @return void
 */
function local_ulms_dashboard_render_operational_error(string $title, string $message, ?\moodle_url $returnurl = null): void {
    global $OUTPUT;

    echo $OUTPUT->header();
    echo html_writer::start_div('ulms-page');
    echo html_writer::start_div('ulms-panel ulms-panel--soft');
    echo html_writer::start_div('ulms-panel__header');
    echo html_writer::start_div();
    echo html_writer::tag('h1', format_string($title), ['class' => 'ulms-panel__title']);
    echo html_writer::tag('p', format_string($message), ['class' => 'ulms-panel__subtitle']);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::start_div('ulms-panel__body');
    echo $OUTPUT->notification($message, \core\output\notification::NOTIFY_ERROR);
    if ($returnurl !== null) {
        echo html_writer::div(
            html_writer::link($returnurl, get_string('continue')),
            'ulms-course-card__footer'
        );
    }
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo $OUTPUT->footer();
}

/**
 * Adds dashboard navigation entry points.
 *
 * @param global_navigation $navigation
 */
function local_ulms_dashboard_extend_navigation(global_navigation $navigation): void {
    $systemcontext = \context::instance_by_id(\context_system::instance()->id);
    $service = new \local_ulms_dashboard\local\service\dashboard_service();
    $routingservice = new \local_ulms_auth\local\service\landing_page_service();

    $canviewstudent = has_capability('local/ulms_dashboard:viewstudentdashboard', $systemcontext);
    $canviewlecturer = has_capability('local/ulms_dashboard:viewlecturerdashboard', $systemcontext);
    $canviewadmin = $service->current_user_has_admin_permissions();

    if (!$canviewstudent && !$canviewlecturer && !$canviewadmin) {
        return;
    }

    $node = $navigation->add(
        get_string('pluginname', 'local_ulms_dashboard'),
        $routingservice->get_dashboard_url_for_current_user()
    );

    if ($canviewadmin) {
        $node->add(
            get_string('admindashboard', 'local_ulms_dashboard'),
            $routingservice->get_url_for_route('management.dashboard')
        );
        $node->add(
            get_string('analyticsdashboard', 'local_ulms_dashboard'),
            $routingservice->get_url_for_route('management.analytics')
        );
    }
}
