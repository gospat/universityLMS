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
 * frame-src carries exactly two cross-origin exceptions for the Kortext LTI 1.3 content
 * picker, https://vle.kortext.com and https://app.kortext.com. Everything else in the
 * policy remains same-origin, and each exception is asserted to stay an exact origin so
 * it cannot drift into "frame-src *".
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
        $this->assertStringContainsString(
            "frame-src 'self' https://vle.kortext.com https://app.kortext.com; ",
            $renderer
        );

        $this->assertStringContainsString('Header always set X-Frame-Options "SAMEORIGIN"', $htaccess);
        $this->assertStringNotContainsString('X-Frame-Options "DENY"', $htaccess);
        $this->assertStringContainsString("frame-ancestors 'self'", $htaccess);
        $this->assertStringNotContainsString("frame-ancestors 'none'", $htaccess);
        $this->assertStringContainsString(
            "frame-src 'self' https://vle.kortext.com https://app.kortext.com",
            $htaccess
        );
    }

    /**
     * The Kortext LTI 1.3 content picker must be allowed to be framed.
     *
     * Choosing the Kortext activity and clicking "Select content" frames the tool's
     * content-item pages. Under "frame-src 'self'" the browser refused the LTI host with
     *   Framing 'https://vle.kortext.com/' violates the following Content Security
     *   Policy directive: "frame-src 'self'".
     * and, once the LTI host was allowed, the picker application itself was still refused
     * with
     *   Framing 'https://app.kortext.com/' violates the following Content Security
     *   Policy directive: "frame-src 'self' https://vle.kortext.com".
     * so both https://vle.kortext.com (the LTI host local/ulms_kortext/settings.php
     * already defaults to) and https://app.kortext.com (the content-picker app) must be
     * named explicitly in both layers.
     */
    public function test_kortext_lti_content_picker_origin_is_frame_allowed(): void {
        $renderer = $this->get_renderer_source();
        $htaccess = $this->get_htaccess_source();

        $this->assertStringContainsString(
            "frame-src 'self' https://vle.kortext.com https://app.kortext.com; ",
            $renderer
        );
        $this->assertStringContainsString(
            "frame-src 'self' https://vle.kortext.com https://app.kortext.com",
            $htaccess
        );

        // The LTI host must not be left as the only origin (the pre-fix policy).
        $this->assertStringNotContainsString("frame-src 'self' https://vle.kortext.com; ", $renderer);

        // Each exception must be the https origin only: no bare host, no subdomain wildcard.
        $this->assertStringNotContainsString('frame-src ' . "'self' vle.kortext.com", $renderer);
        $this->assertStringNotContainsString('frame-src ' . "'self' app.kortext.com", $renderer);
        $this->assertStringNotContainsString('*.kortext.com', $renderer);
        $this->assertStringNotContainsString('frame-src https:', $renderer);

        // Framing a host grants no scripting, form or data access, so allowing the picker
        // in frame-src must not widen connect-src with the same origin.
        $this->assertDoesNotMatchRegularExpression('/connect-src[^;\n]*app\.kortext\.com/', $renderer);
        $this->assertDoesNotMatchRegularExpression('/connect-src[^;\n]*app\.kortext\.com/', $htaccess);
    }

    /**
     * The Kortext host must be spelled with the double "t" transposition removed.
     *
     * The allow-list previously read "korttext.com" for both connect-src entries, which
     * named hosts that do not exist and left api.kortext.com / vle.kortext.com refused.
     */
    public function test_kortext_hostname_is_not_misspelled(): void {
        $renderer = $this->get_renderer_source();

        $this->assertStringNotContainsString('korttext', $renderer);
        $this->assertStringContainsString(
            "connect-src 'self' https://api.resend.com https://api.kortext.com https://vle.kortext.com; ",
            $renderer
        );
    }

    /**
     * The framing exception must not have widened the directive generally.
     *
     * "frame-src *" (or a bare scheme source) would allow any origin to be framed, which
     * is a much larger relaxation than the two trusted LTI origins this fix requires.
     */
    public function test_frame_src_does_not_allow_unrestricted_framing(): void {
        $renderer = $this->get_renderer_source();
        $htaccess = $this->get_htaccess_source();

        $this->assertStringNotContainsString("frame-src *", $renderer);
        $this->assertStringNotContainsString("frame-src 'self' *", $renderer);
        $this->assertStringNotContainsString("frame-src *;", $htaccess);
        $this->assertStringNotContainsString("frame-src 'self' *", $htaccess);
        $this->assertStringNotContainsString("frame-ancestors *", $renderer);
        $this->assertStringNotContainsString("frame-ancestors *", $htaccess);
    }

    /**
     * Every directive other than frame-src and the corrected connect-src must be intact.
     *
     * The Kortext exceptions are deliberately the only change: script, style, image, font,
     * object, base-uri, form-action, manifest and media restrictions, plus the transport
     * and nosniff headers, all keep their previous values.
     */
    public function test_unrelated_csp_directives_are_unchanged(): void {
        $renderer = $this->get_renderer_source();

        $this->assertStringContainsString("default-src 'self'; ", $renderer);
        $this->assertStringContainsString("script-src 'self' 'unsafe-inline' 'unsafe-eval'; ", $renderer);
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline'; ", $renderer);
        $this->assertStringContainsString("img-src 'self' data: https:; ", $renderer);
        $this->assertStringContainsString("font-src 'self' data:; ", $renderer);
        $this->assertStringContainsString("object-src 'none'; ", $renderer);
        $this->assertStringContainsString("base-uri 'self'; ", $renderer);
        $this->assertStringContainsString('form-action {$formactionsrcs}; ', $renderer);
        $this->assertStringContainsString("manifest-src 'self'; ", $renderer);
        $this->assertStringContainsString("media-src 'self'; ", $renderer);
        $this->assertStringContainsString('upgrade-insecure-requests;', $renderer);
        $this->assertStringContainsString("@header('X-Frame-Options: SAMEORIGIN'", $renderer);
        $this->assertStringContainsString("@header('X-Content-Type-Options: nosniff'", $renderer);
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
