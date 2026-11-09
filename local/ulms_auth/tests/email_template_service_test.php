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

namespace local_ulms_auth\local\service;

defined('MOODLE_INTERNAL') || die();

use advanced_testcase;

/**
 * Tests the shared ULMS branded email template renderer.
 *
 * These assertions guard the properties that make the template safe to send to
 * real users: complete and client-compatible markup, exhaustive escaping, and
 * an action button that can never point somewhere different from the visible
 * fallback link.
 */
final class email_template_service_test extends advanced_testcase {
    /**
     * Returns a representative content structure for the assertions below.
     *
     * @param array $overrides Values to override on the base structure.
     * @return array
     */
    private function sample_content(array $overrides = []): array {
        return $overrides + [
            'brandname' => "Bell's University of Technology",
            'logourl' => 'https://learn.example.edu/theme/ulms_university/pix/logo.png',
            'siteurl' => 'https://learn.example.edu/',
            'preheader' => 'Preview line',
            'heading' => 'Activate your ULMS account',
            'intro' => "Your Student account has been created.\nSet a password to continue.",
            'details' => [
                ['label' => 'Account type', 'value' => 'Student', 'emphasis' => true],
                ['label' => 'Username', 'value' => 'jdoe', 'emphasis' => true],
            ],
            'cta' => ['label' => 'Activate account', 'url' => 'https://learn.example.edu/sign-in/activate/?token=abc&flow=1'],
            'fallback' => 'If the button above does not work, copy and paste this address into your browser:',
            'notice' => 'If you did not expect this account, contact support immediately.',
            'support' => 'Need help? Contact support at support@example.edu.',
            'footer' => 'This is an automated message. Please do not reply to this email.',
        ];
    }

    /**
     * The template must produce a complete, table-based document.
     */
    public function test_renders_a_complete_table_based_document(): void {
        $this->resetAfterTest(true);

        $html = (new email_template_service())->render_html($this->sample_content());

        $this->assertStringStartsWith('<!DOCTYPE html', $html);
        $this->assertStringEndsWith("</html>\n", $html);
        $this->assertStringContainsString('<table role="presentation"', $html);
        $this->assertStringContainsString('width="600"', $html);
        $this->assertStringContainsString('max-width:600px', $html);
        // Inline styling is the baseline every client supports.
        $this->assertStringContainsString('bgcolor="#0f3d67"', $html);
    }

    /**
     * Every interpolated value must be escaped; no caller value may inject markup.
     */
    public function test_escapes_every_interpolated_value(): void {
        $this->resetAfterTest(true);

        $payload = '<script>alert("xss")</script>';
        $html = (new email_template_service())->render_html($this->sample_content([
            'brandname' => $payload,
            'heading' => $payload,
            'intro' => $payload,
            'details' => [['label' => $payload, 'value' => $payload, 'emphasis' => false]],
            'fallback' => $payload,
            'notice' => $payload,
            'support' => $payload,
            'footer' => $payload,
        ]));

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $html);
    }

    /**
     * A URL placed in an attribute must have its query separators escaped.
     */
    public function test_escapes_urls_in_attributes(): void {
        $this->resetAfterTest(true);

        $url = 'https://learn.example.edu/sign-in/activate/?token=a&b=c';
        $html = (new email_template_service())->render_html($this->sample_content([
            'cta' => ['label' => 'Activate account', 'url' => $url],
        ]));

        $this->assertStringContainsString('href="' . \s($url) . '"', $html);
        $this->assertStringContainsString('&amp;b=c', $html);
        $this->assertStringNotContainsString('href="' . $url . '"', $html);
    }

    /**
     * The button and the visible fallback link must share one destination.
     */
    public function test_button_and_fallback_link_share_the_same_destination(): void {
        $this->resetAfterTest(true);

        $url = 'https://learn.example.edu/sign-in/activate/?token=abc&flow=1';
        $html = (new email_template_service())->render_html($this->sample_content([
            'cta' => ['label' => 'Activate account', 'url' => $url],
        ]));

        $this->assertSame(2, substr_count($html, 'href="' . \s($url) . '"'));
        // The fallback link must be readable as the same destination.
        $this->assertStringContainsString('>' . \s($url) . '</a>', $html);
    }

    /**
     * Without a destination there must be no button and no fallback link.
     */
    public function test_omits_action_markup_when_no_destination_is_supplied(): void {
        $this->resetAfterTest(true);

        $html = (new email_template_service())->render_html($this->sample_content([
            'siteurl' => '',
            'details' => [['label' => 'Account type', 'value' => 'Student', 'emphasis' => false]],
            'cta' => ['label' => 'Activate account', 'url' => ''],
        ]));

        $this->assertStringNotContainsString('href="', $html);
        $this->assertStringNotContainsString('Activate account', $html);
    }

    /**
     * Detail rows with no value must not render an empty row.
     */
    public function test_skips_detail_rows_without_a_value(): void {
        $this->resetAfterTest(true);

        $html = (new email_template_service())->render_html($this->sample_content([
            'details' => [
                ['label' => 'Account type', 'value' => 'Student', 'emphasis' => false],
                ['label' => 'Course', 'value' => '', 'emphasis' => false],
            ],
        ]));

        $this->assertStringContainsString('Account type', $html);
        $this->assertStringNotContainsString('Course', $html);
    }

    /**
     * The responsive and dark-mode rules must be present for capable clients.
     */
    public function test_declares_responsive_and_dark_mode_rules(): void {
        $this->resetAfterTest(true);

        $html = (new email_template_service())->render_html($this->sample_content());

        $this->assertStringContainsString('@media only screen and (max-width:600px)', $html);
        $this->assertStringContainsString('@media (prefers-color-scheme:dark)', $html);
        $this->assertStringContainsString('color-scheme', $html);
        $this->assertStringContainsString('ulms-email-button', $html);
    }
}
