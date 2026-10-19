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

namespace local_ulms_academics;

defined('MOODLE_INTERNAL') || die();

use advanced_testcase;

require_once(__DIR__ . '/../../../config.php');

/**
 * Regression tests for the explicit academic activation workflow.
 *
 * Re-activating an inactive faculty, department or programme is the one status
 * transition bulk imports deliberately refuse, so `manage.php`'s `action=activate`
 * handler is the only route back. Its guards are therefore security-critical:
 *   - the entry point requires local/ulms_academics:manageacademics;
 *   - the delete and activate branches each require a valid session key;
 *   - the post-activation redirect only ever follows a local URL.
 *
 * Those guards live inside a Moodle script, which cannot be driven from a CLI test
 * without an HTTP request. The guard primitives are therefore exercised directly and
 * the handler wiring is asserted against the script source, so a guard cannot be
 * dropped silently. A real activation click is verified at HTTP level in staging.
 *
 * @coversNothing
 */
final class activation_authorization_test extends advanced_testcase {
    /**
     * Reads the management script that owns the activation entry point.
     *
     * @return string
     */
    private function get_manage_source(): string {
        global $CFG;

        return file_get_contents($CFG->dirroot . '/local/ulms_academics/manage.php');
    }

    /**
     * Overwrites the request session key and restores the previous value afterwards.
     *
     * @param string|null $sesskey Value to place in the request, or null to remove it.
     * @return callable Restores the original request state when invoked.
     */
    private function set_request_sesskey(?string $sesskey): callable {
        $previous = $_POST['sesskey'] ?? null;

        if ($sesskey === null) {
            unset($_POST['sesskey']);
        } else {
            $_POST['sesskey'] = $sesskey;
        }

        return static function () use ($previous): void {
            if ($previous === null) {
                unset($_POST['sesskey']);
            } else {
                $_POST['sesskey'] = $previous;
            }
        };
    }

    /**
     * A user without the capability must be refused by the gate manage.php applies.
     */
    public function test_activation_gate_refuses_a_user_without_the_capability(): void {
        $this->resetAfterTest(true);

        $this->setUser($this->getDataGenerator()->create_user());
        $context = \context::instance_by_id(SYSCONTEXTID);

        $this->assertFalse(has_capability('local/ulms_academics:manageacademics', $context));

        $this->expectException(\required_capability_exception::class);
        require_capability('local/ulms_academics:manageacademics', $context);
    }

    /**
     * The archetype the plugin grants the capability to must satisfy the same gate.
     */
    public function test_activation_gate_admits_a_manager(): void {
        global $DB;

        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $context = \context::instance_by_id(SYSCONTEXTID);
        $managerroleid = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($managerroleid, $user->id, $context->id);
        $this->setUser($user);

        $this->assertTrue(has_capability('local/ulms_academics:manageacademics', $context));

        // Must not throw: this is exactly what manage.php relies on.
        require_capability('local/ulms_academics:manageacademics', $context);
    }

    /**
     * The capability gate and the session-key check must precede both mutating branches.
     */
    public function test_capability_gate_and_session_key_check_guard_both_mutating_branches(): void {
        $source = $this->get_manage_source();

        $gate = strpos($source, "require_capability('local/ulms_academics:manageacademics'");
        $delete = strpos($source, "if (\$action === 'delete' && \$deleteid) {");
        $activate = strpos($source, "if (\$action === 'activate' && \$id) {");

        $this->assertNotFalse($gate, 'manage.php must require the manageacademics capability.');
        $this->assertNotFalse($delete, 'manage.php must keep its delete branch.');
        $this->assertNotFalse($activate, 'manage.php must keep its activate branch.');
        $this->assertLessThan($delete, $gate);
        $this->assertLessThan($activate, $gate);

        $this->assertStringContainsString('require_sesskey();', substr($source, $delete));
        $this->assertStringContainsString('require_sesskey();', substr($source, $activate));
    }

    /**
     * CSRF: the check manage.php performs must accept the current session key.
     */
    public function test_session_key_check_accepts_the_current_key(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $restore = $this->set_request_sesskey(sesskey());

        try {
            require_sesskey();
            $accepted = true;
        } catch (\moodle_exception $exception) {
            $accepted = false;
        } finally {
            $restore();
        }

        $this->assertTrue($accepted, 'A valid session key must be accepted.');
    }

    /**
     * CSRF: a forged or replayed session key must be refused.
     */
    public function test_session_key_check_rejects_a_forged_key(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $restore = $this->set_request_sesskey('forged-session-key');

        try {
            require_sesskey();
            $accepted = true;
        } catch (\moodle_exception $exception) {
            $accepted = false;
        } finally {
            $restore();
        }

        $this->assertFalse($accepted, 'A forged session key must be refused.');
    }

    /**
     * The activation redirect must only ever follow a local URL.
     */
    public function test_activation_redirect_only_follows_local_urls(): void {
        $source = $this->get_manage_source();

        $this->assertStringContainsString("optional_param('returnurl', '', PARAM_LOCALURL)", $source);
        $this->assertStringNotContainsString("optional_param('returnurl', '', PARAM_RAW)", $source);
        $this->assertStringNotContainsString("optional_param('returnurl', '', PARAM_URL)", $source);

        // PARAM_LOCALURL is what makes the guard real: cross-origin input is erased, so
        // manage.php falls back to the in-app context URL instead of redirecting away.
        $this->assertSame('', clean_param('https://evil.example.com/steal', PARAM_LOCALURL));
        $this->assertSame('', clean_param('//evil.example.com/steal', PARAM_LOCALURL));
        $this->assertSame('', clean_param('javascript:alert(1)', PARAM_LOCALURL));
        $this->assertSame('/course/view.php?id=2', clean_param('/course/view.php?id=2', PARAM_LOCALURL));
    }

    /**
     * The reactivation guidance must point at a control the administrator really has.
     *
     * manage.php renders only Edit and Delete for each record, so the import refusal
     * message has to direct the administrator to the record's status field, which the
     * entity form genuinely exposes.
     */
    public function test_reactivation_guidance_points_at_the_entity_form_status_field(): void {
        global $CFG;

        $formsource = file_get_contents($CFG->dirroot . '/local/ulms_academics/classes/form/academic_entity_form.php');

        // The entity form really does expose a status control for faculties, departments
        // and programmes, and it rejects an unrecognised value server-side.
        $this->assertStringContainsString(
            "in_array(\$entity, ['faculties', 'departments', 'programmes'], true)",
            $formsource
        );
        $this->assertStringContainsString("'status',", $formsource);
        $this->assertStringContainsString(
            "academic_status::normalize((string)(\$data['status'] ?? '')) === null",
            $formsource
        );

        $guidance = get_string('csvreactivationblocked', 'local_ulms_academics', (object)[
            'line' => 3,
            'code' => 'CSC',
        ]);

        $this->assertStringContainsString('status', $guidance);
        $this->assertStringNotContainsString('Activate action', $guidance);
    }
}
