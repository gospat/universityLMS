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

namespace theme_ulms_university;

defined('MOODLE_INTERNAL') || die();

/**
 * Regression tests for the theme's HTTP security header policy.
 *
 * Moodle frames its own same-origin documents (the file-picker upload/select
 * iframes, resourcelib PDF/H5P embeds, portfolio download) and Moodle core
 * already emits "X-Frame-Options: sameorigin". Forcing DENY, frame-ancestors
 * 'none', or Cross-Origin-Embedder-Policy: require-corp refuses those frames and
 * broke file uploads, file selection and media embedding.
 *
 * The policy lives in two artefacts that must agree:
 *  - theme/ulms_university/classes/output/core_renderer.php::inject_http_security_headers()
 *    (authoritative: production runs Nginx, which never reads .htaccess).
 *  - .htaccess, the Apache fallback.
 *
 * These tests pin the narrowest-safe values so the defect cannot silently return.
 * Runtime behaviour (the actual response headers and a working upload) is proven
 * separately at HTTP/browser level; a CLI test cannot observe header() output.
 *
 * @coversNothing
 */
final class security_headers_test extends \advanced_testcase {

    /**
     * Reads the PHP renderer that owns the authoritative header policy.
     *
     * @return string
     */
    private function get_renderer_source(): string {
        global $CFG;

        $path = $CFG->dirroot . '/theme/ulms_university/classes/output/core_renderer.php';

        return file_get_contents($path);
    }

    /**
     * Reads the Apache fallback header layer.
     *
     * @return string
     */
    private function get_htaccess_source(): string {
        global $CFG;

        return file_get_contents($CFG->dirroot . '/.htaccess');
    }

    /**
     * Both layers must permit same-origin framing and refuse cross-origin framing.
     */
    public function test_frame_policy_allows_moodle_same_origin_embedding(): void {
        $renderer = $this->get_renderer_source();
        $htaccess = $this->get_htaccess_source();

        $this->assertStringContainsString("@header('X-Frame-Options: SAMEORIGIN'", $renderer);
        $this->assertStringNotContainsString('X-Frame-Options: DENY', $renderer);
        $this->assertStringContainsString("frame-ancestors 'self'; ", $renderer);
        $this->assertStringNotContainsString("frame-ancestors 'none'", $renderer);
        $this->assertStringContainsString("frame-src 'self'; ", $renderer);

        $this->assertStringContainsString('Header always set X-Frame-Options "SAMEORIGIN"', $htaccess);
        $this->assertStringNotContainsString('X-Frame-Options "DENY"', $htaccess);
        $this->assertStringContainsString("frame-ancestors 'self'", $htaccess);
        $this->assertStringNotContainsString("frame-ancestors 'none'", $htaccess);
        $this->assertStringContainsString("frame-src 'self'", $htaccess);
    }

    /**
     * COEP must not be emitted by either layer.
     *
     * require-corp demands a CORP/CORS opt-in from every embedded subresource, which
     * Moodle core does not send on repository_ajax.php or draftfile.php.
     */
    public function test_embedder_policy_is_not_enabled(): void {
        $renderer = $this->get_renderer_source();
        $htaccess = $this->get_htaccess_source();

        $this->assertStringNotContainsString("@header('Cross-Origin-Embedder-Policy", $renderer);
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*Header\s+always\s+set\s+Cross-Origin-Embedder-Policy/im',
            $htaccess
        );

        // COOP/CORP stay in place: they are unrelated to the framing defect.
        $this->assertStringContainsString("@header('Cross-Origin-Opener-Policy: same-origin'", $renderer);
        $this->assertStringContainsString("@header('Cross-Origin-Resource-Policy: same-origin'", $renderer);
        $this->assertStringContainsString('Header always set Cross-Origin-Resource-Policy "same-origin"', $htaccess);
    }

    /**
     * The site-registration CSP exemption must be preserved.
     *
     * admin/registration/index.php is answered with a 303 redirect to
     * https://stats.moodle.org/local/hub/siteregistration.php, and browsers apply
     * form-action to every hop of the submission navigation.
     */
    public function test_registration_csp_exemption_is_preserved(): void {
        $renderer = $this->get_renderer_source();
        $htaccess = $this->get_htaccess_source();

        $this->assertStringContainsString('HUB_MOODLEORGHUBURL', $renderer);
        $this->assertStringContainsString('form-action {$formactionsrcs}; ', $renderer);

        $this->assertStringContainsString("form-action 'self' https://stats.moodle.org", $htaccess);
        $this->assertStringContainsString("default-src 'self'", $htaccess);
        $this->assertStringContainsString("object-src 'none'", $htaccess);
    }

    /**
     * The policy must be emitted for every layout, the authentication pages included.
     *
     * core_renderer::header() is the most general injection point and is the one that
     * covers the login, secure, standard and dashboard shells. Removing that override
     * would silently drop every security header from the login page.
     */
    public function test_security_headers_are_emitted_for_every_layout(): void {
        $renderer = $this->get_renderer_source();

        $this->assertMatchesRegularExpression(
            '/public function header\(\)\s*\{\s*self::inject_http_security_headers\(\);/',
            $renderer
        );
        $this->assertStringContainsString(
            'Strict-Transport-Security: max-age=31536000; includeSubDomains; preload',
            $renderer
        );
        $this->assertStringContainsString("@header('X-Content-Type-Options: nosniff'", $renderer);
    }
}
