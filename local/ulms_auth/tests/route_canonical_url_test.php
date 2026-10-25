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
 * Regression tests for canonical ULMS clean-route URLs.
 *
 * Every route returned by landing_page_service::get_route_definitions() is backed
 * by a physical directory containing an index.php shim. When a request targets
 * such a directory without its trailing slash, the web server answers with a 301
 * to the slash-terminated URL — nginx via
 * `location / { try_files $uri $uri/ @moodle_slashargs; }` and Apache via mod_dir
 * behind the `RewriteCond %{REQUEST_FILENAME} !-d` guard in the root .htaccess.
 *
 * Browsers follow a 301 on a POST as a GET, which discards the request body. That
 * is exactly what broke the bulk-upload CSV preview: the form posted to
 * /management/users/bulk-upload?section=bulk, the server redirected to
 * /management/users/bulk-upload/?section=bulk, and the uploaded CSV was lost.
 *
 * These tests fail if a directory-backed route loses its canonical trailing
 * slash, if a slash-terminated route is not backed by an index.php, if a
 * generated URL drifts from the declared path or drops a query parameter, or if
 * application code reintroduces a hard-coded slash-less route URL.
 */
final class route_canonical_url_test extends advanced_testcase {
    /**
     * Returns the private route definitions from the routing service.
     *
     * @return array<string, array<string, mixed>>
     */
    private function get_route_definitions(): array {
        $service = new landing_page_service();
        $method = new \ReflectionMethod($service, 'get_route_definitions');

        return $method->invoke($service);
    }

    /**
     * Returns the absolute path of the index.php backing a route path.
     *
     * @param string $path Route path as declared, e.g. "/student/courses/".
     * @return string
     */
    private function index_file_for_path(string $path): string {
        global $CFG;

        return rtrim($CFG->dirroot, '/') . $path . 'index.php';
    }

    /**
     * Returns the installation base path taken from $CFG->wwwroot.
     *
     * Route definitions are always base-free, but moodle_url::get_path() prefixes
     * the wwwroot path. Moodle is served from a subdirectory in CI (for example
     * /moodle), so a generated path there is "/moodle/student/courses/" while the
     * declared route path is "/student/courses/".
     *
     * @return string Base path without a trailing slash, e.g. "/moodle" or "".
     */
    private function installation_base_path(): string {
        global $CFG;

        $base = (string)(parse_url((string)($CFG->wwwroot ?? ''), PHP_URL_PATH) ?? '');

        return rtrim($base, '/');
    }

    /**
     * Every route backed by a physical directory must end with a trailing slash.
     */
    public function test_every_directory_backed_route_uses_a_canonical_trailing_slash(): void {
        $this->resetAfterTest(true);

        $definitions = $this->get_route_definitions();
        $violations = [];
        $directorybacked = 0;

        foreach ($definitions as $routekey => $definition) {
            $path = (string)$definition['path'];
            if (!is_file($this->index_file_for_path($path))) {
                // Not backed by a directory: no trailing slash is required.
                continue;
            }

            $directorybacked++;

            if (!str_ends_with($path, '/')) {
                $violations[] = $routekey . ' => ' . $path;
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Directory-backed routes must end with a trailing slash, otherwise the web server "
            . "answers with a 301 and any POST body is discarded:\n" . implode("\n", $violations)
        );

        // The production audit identified 56 mismatches; the canonical set is larger
        // than that, so the audit count is a safe lower bound for this guard.
        $this->assertGreaterThanOrEqual(
            56,
            $directorybacked,
            'Expected at least 56 directory-backed routes; the route table looks truncated.'
        );
    }

    /**
     * Every slash-terminated route must actually be backed by an index.php.
     */
    public function test_every_canonical_route_is_backed_by_an_index_php(): void {
        $this->resetAfterTest(true);

        $violations = [];

        foreach ($this->get_route_definitions() as $routekey => $definition) {
            $path = (string)$definition['path'];
            if (!str_ends_with($path, '/')) {
                continue;
            }

            if (!is_file($this->index_file_for_path($path))) {
                $violations[] = $routekey . ' => ' . $path;
            }
        }

        $this->assertSame(
            [],
            $violations,
            "A slash-terminated route without an index.php would 404:\n" . implode("\n", $violations)
        );
    }

    /**
     * Generated URLs and their query parameters must match the declared route.
     */
    public function test_generated_urls_match_the_declared_canonical_path_and_parameters(): void {
        $this->resetAfterTest(true);

        $service = new landing_page_service();

        foreach ($this->get_route_definitions() as $routekey => $definition) {
            $path = (string)$definition['path'];
            $url = $service->get_url_for_route($routekey);

            // moodle_url::get_path() prefixes the wwwroot path, so the generated
            // path must be the installation base path followed by the declared
            // route path — never the declared route path on its own.
            $this->assertSame(
                $this->installation_base_path() . $path,
                $url->get_path(),
                "Route {$routekey} generated a path that is not the installation base path "
                . "followed by its declared canonical path."
            );
            $this->assertSame(
                '/',
                substr($url->get_path(), -1),
                "Route {$routekey} generated a path without a trailing slash."
            );
            $this->assertStringNotContainsString(
                '//',
                $url->get_path(),
                "Route {$routekey} generated a duplicated slash."
            );

            foreach ((array)($definition['params'] ?? []) as $name => $value) {
                $this->assertSame(
                    (string)$value,
                    (string)$url->get_param($name),
                    "Route {$routekey} lost the declared '{$name}' parameter."
                );
            }
        }
    }

    /**
     * Caller-supplied query parameters must be preserved and additive.
     */
    public function test_route_query_parameters_are_preserved_and_additive(): void {
        $this->resetAfterTest(true);

        $service = new landing_page_service();

        $bulk = $service->get_url_for_route('management.bulkupload', [
            'section' => 'bulk',
            'bulktargetrole' => 'student',
        ]);
        $this->assertStringEndsWith('/management/users/bulk-upload/', $bulk->get_path());
        $this->assertSame('bulk', $bulk->get_param('section'));
        $this->assertSame('student', $bulk->get_param('bulktargetrole'));

        $activate = $service->get_url_for_route('public.activate', ['token' => 'abc123']);
        $this->assertStringEndsWith('/sign-in/activate/', $activate->get_path());
        $this->assertSame('token=abc123', $activate->get_query_string(false));

        $preview = $service->get_url_for_route('lecturer.examspreview', ['examid' => 7]);
        $this->assertStringEndsWith('/lecturer/exams/preview/', $preview->get_path());
        $this->assertSame('preview', $preview->get_param('view'));
        $this->assertSame('preview', $preview->get_param('page'));
        $this->assertSame('7', (string)$preview->get_param('examid'));

        $take = $service->get_url_for_route('student.examstake', ['examid' => 12]);
        $this->assertStringEndsWith('/student/exams/take/', $take->get_path());
        $this->assertSame('take', $take->get_param('view'));
        $this->assertSame('take', $take->get_param('page'));
        $this->assertSame('12', (string)$take->get_param('examid'));
    }

    /**
     * Nested route paths must canonicalise individually and normalise as a unit.
     */
    public function test_nested_routes_and_route_normalisation(): void {
        $this->resetAfterTest(true);

        $service = new landing_page_service();

        $nested = [
            'student.examstake' => '/student/exams/take/',
            'student.examsresult' => '/student/exams/result/',
            'lecturer.examsquestions' => '/lecturer/exams/questions/',
            'lecturer.scheduleedit' => '/lecturer/schedule/edit/',
            'management.bulkreport' => '/management/users/bulk-upload/report/',
            'management.academicsattendanceaudit' => '/management/academics/attendance-audit/',
            'management.lecturers' => '/management/academics/lecturer-allocations/',
            'superadmin.auditlogs' => '/super-admin/audit-logs/',
        ];

        foreach ($nested as $routekey => $expectedpath) {
            $this->assertSame($expectedpath, $service->get_path_for_route($routekey));
            $this->assertStringEndsWith(
                $expectedpath,
                $service->get_url_for_route($routekey)->get_path()
            );
        }

        // Normalisation strips the trailing slash, which is what keeps every
        // internal route consumer slash-insensitive and backward compatible.
        $this->assertSame('/management/users/bulk-upload', $service->normalise_path('/management/users/bulk-upload/'));
        $this->assertSame('/student/exams/take', $service->normalise_path('/student/exams/take/'));
        $this->assertSame('/super-admin/system', $service->normalise_path('/super-admin/system/'));
        $this->assertSame('/student', $service->normalise_path('/student/'));

        // The Moodle base path is stripped before the trailing slash, so both the
        // canonical and the legacy request form normalise to the same route path.
        $canonical = $service->get_url_for_route('management.bulkupload')->get_path();
        $this->assertSame(
            $service->normalise_path($canonical),
            $service->normalise_path(rtrim($canonical, '/'))
        );
    }

    /**
     * Route keys and their ordering contract must remain stable.
     */
    public function test_route_keys_are_preserved(): void {
        $this->resetAfterTest(true);

        $expectedkeys = [
            'public.landing', 'public.activate', 'public.passwordreset',
            'student.login', 'student.passwordreset', 'student.dashboard', 'student.courses',
            'student.catalog', 'student.assignments', 'student.quizzes', 'student.exams',
            'student.examstake', 'student.examsresult', 'student.grades', 'student.progress',
            'student.timetable', 'student.announcements', 'student.messages', 'student.profile',
            'student.attendance',
            'lecturer.login', 'lecturer.passwordreset', 'lecturer.dashboard', 'lecturer.courses',
            'lecturer.materials', 'lecturer.assignments', 'lecturer.quizzes', 'lecturer.exams',
            'lecturer.examscreate', 'lecturer.examsedit', 'lecturer.examsquestions',
            'lecturer.examspreview', 'lecturer.students', 'lecturer.attendance', 'lecturer.grades',
            'lecturer.announcements', 'lecturer.messages', 'lecturer.profile', 'lecturer.schedule',
            'lecturer.schedulecreate', 'lecturer.scheduleedit', 'lecturer.live',
            'management.login', 'management.passwordreset', 'management.dashboard',
            'management.users', 'management.usercreate', 'management.userview',
            'management.useredit', 'management.provisioning', 'management.bulkupload',
            'management.bulkreport', 'management.analytics', 'management.academicsmanage',
            'management.academicslevels', 'management.academicsimport',
            'management.academicsmappings', 'management.academicsreports', 'management.academics',
            'management.courses', 'management.academicsschedule',
            'management.academicsattendanceaudit', 'management.lecturers', 'management.reports',
            'management.auditlogs', 'management.settings',
            'superadmin.login', 'superadmin.passwordreset', 'superadmin.dashboard',
            'superadmin.administrators', 'superadmin.users', 'superadmin.institution',
            'superadmin.health', 'superadmin.integrations', 'superadmin.security',
            'superadmin.auditlogs', 'superadmin.reports', 'superadmin.settings',
        ];

        $actualkeys = array_keys($this->get_route_definitions());

        $this->assertSame(
            [],
            array_values(array_diff($expectedkeys, $actualkeys)),
            'A route key was renamed or removed; route keys are a public contract.'
        );
        $this->assertSame(
            [],
            array_values(array_diff($actualkeys, $expectedkeys)),
            'An unexpected route key was introduced; update this contract deliberately.'
        );
    }

    /**
     * Legacy redirects must resolve identically for canonical and legacy forms.
     */
    public function test_legacy_redirect_compatibility(): void {
        $this->resetAfterTest(true);

        $service = new landing_page_service();

        $expectedtargets = [
            '/privacy' => '/local/ulms_privacy/legal.php?page=privacy',
            '/privacy.php' => '/local/ulms_privacy/legal.php?page=privacy',
            '/gdpr' => '/local/ulms_privacy/legal.php?page=gdpr',
            '/gdpr.php' => '/local/ulms_privacy/legal.php?page=gdpr',
            '/cookie-policy' => '/local/ulms_privacy/legal.php?page=cookie-policy',
            '/data-protection' => '/local/ulms_privacy/legal.php?page=data-protection',
        ];

        foreach ($expectedtargets as $legacypath => $target) {
            $resolved = $service->resolve_route_redirect($legacypath);
            $this->assertNotNull($resolved, "Legacy path {$legacypath} no longer resolves.");
            $this->assertSame(302, (int)$resolved['statuscode']);
            $this->assertStringEndsWith($target, (string)$resolved['target']);

            // The slash-terminated form must resolve to the same destination.
            $slashed = $service->resolve_route_redirect($legacypath . '/');
            $this->assertNotNull($slashed, "Canonical form {$legacypath}/ no longer resolves.");
            $this->assertStringEndsWith($target, (string)$slashed['target']);
        }

        $this->assertNotNull($service->resolve_route_redirect('/local/ulms_auth'));
        $this->assertNotNull($service->resolve_route_redirect('/local/ulms_auth/index.php'));
        $this->assertNotNull($service->resolve_route_redirect('/local/ulms_dashboard'));
        $this->assertNull($service->resolve_route_redirect('/student/courses'));
    }

    /**
     * Canonicalisation must never turn a POST into a GET or drop a request body.
     */
    public function test_canonicalisation_never_redirects_a_post_request(): void {
        $this->resetAfterTest(true);

        if (defined('ULMS_PUBLIC_ROUTE_REQUEST')) {
            $this->markTestSkipped('Route-controller context already active; legacy redirect is short-circuited.');
        }

        $service = new landing_page_service();
        $originalmethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $originaluri = $_SERVER['REQUEST_URI'] ?? null;
        $originalget = $_GET;

        try {
            // A POST must return before any redirect, so the multipart body survives.
            // If this method redirected it would call exit() and abort the test run,
            // which is itself the failure signal.
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SERVER['REQUEST_URI'] = '/local/ulms_dashboard/user_provisioning.php?section=bulk';
            $_GET = [];
            $service->maybe_redirect_legacy_request('management.bulkupload');
            $this->assertTrue(true, 'A POST request was not redirected away from its handler.');

            // A GET already sitting on the canonical route must not self-redirect,
            // otherwise the canonical form would loop.
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $target = $service->get_url_for_route('management.bulkupload');
            $_SERVER['REQUEST_URI'] = $target->get_path() . '?' . $target->get_query_string(false);
            $_GET = [];
            $service->maybe_redirect_legacy_request('management.bulkupload');
            $this->assertTrue(true, 'The canonical route must not redirect to itself.');
        } finally {
            if ($originalmethod !== null) {
                $_SERVER['REQUEST_METHOD'] = $originalmethod;
            }
            if ($originaluri !== null) {
                $_SERVER['REQUEST_URI'] = $originaluri;
            }
            $_GET = $originalget;
        }
    }

    /**
     * The bulk-upload POST must reach its handler without any redirect hop.
     *
     * This asserts the exact condition the web servers use to emit the 301: the
     * requested path must end with "/" and must resolve to a real directory.
     */
    public function test_bulk_upload_preview_handler_is_reachable_without_a_redirect(): void {
        $this->resetAfterTest(true);

        global $CFG;

        $service = new landing_page_service();

        // The route definition itself is always installation-base-free, so it can be
        // resolved against $CFG->dirroot to locate the physical directory.
        $declaredpath = $service->get_path_for_route('management.bulkupload');
        $this->assertSame('/management/users/bulk-upload/', $declaredpath);

        // The generated action path is the installation base path followed by the
        // declared route path. Moodle is served from a subdirectory in CI (for
        // example /moodle), so the base path must never be assumed to be empty.
        $actionpath = $service->get_url_for_route('management.bulkupload')->get_path();
        $this->assertSame(
            $this->installation_base_path() . '/management/users/bulk-upload/',
            $actionpath
        );

        // The action must be slash-terminated, otherwise the web server answers the
        // POST with a 301 and the uploaded CSV body is discarded.
        $this->assertSame(
            '/',
            substr($actionpath, -1),
            'A slash-less form action is answered with a 301 and the CSV upload body is discarded.'
        );

        // Resolve the physical directory from $CFG->dirroot and the declared route
        // path — never from the URL path, which carries the installation base path.
        $actiondirectory = rtrim($CFG->dirroot, '/') . '/management/users/bulk-upload/';
        $this->assertDirectoryExists($actiondirectory);
        $this->assertFileExists($actiondirectory . 'index.php');
        $this->assertFileIsReadable($actiondirectory . 'index.php');

        // The route shim must boot a controller that exists on disk.
        $this->assertFileExists($CFG->dirroot . '/local/ulms_dashboard/user_provisioning.php');

        // The form action must carry the bulk section so the correct controller
        // branch is selected without depending on a redirect.
        $this->assertSame('bulk', $service->get_url_for_route('management.bulkupload')->get_param('section'));
    }

    /**
     * Previewing a bulk upload must validate without creating any account.
     *
     * The controller's preview branch calls
     * user_provisioning_service::preview_bulk_import() and persists nothing; only
     * the separate confirm branch calls import_bulk_rows(). This guards that
     * separation at the service boundary.
     */
    public function test_previewing_a_bulk_upload_creates_no_accounts(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $service = new \local_ulms_dashboard\local\service\user_provisioning_service();

        $validemail = 'route.preview.valid@example.edu';
        $invalidemail = 'not-an-email-address';

        // The preview is targeted at a staff account type, so each row carries the Staff ID
        // that a staff account requires. The only difference between the two rows is the email
        // address, which keeps this a test of validation-versus-creation rather than of the
        // account-type rules themselves.
        $rows = [
            [
                'firstname' => 'Route',
                'lastname' => 'Preview',
                'email' => $validemail,
                'username' => 'route.preview.valid',
                'idnumber' => 'ROUTE-PREVIEW-1',
                'staffid' => 'ROUTE-PREVIEW-STAFF-1',
                'programmecode' => '',
            ],
            [
                'firstname' => 'Route',
                'lastname' => 'Preview',
                'email' => $invalidemail,
                'username' => 'route.preview.invalid',
                'idnumber' => 'ROUTE-PREVIEW-2',
                'staffid' => 'ROUTE-PREVIEW-STAFF-2',
                'programmecode' => '',
            ],
        ];

        $usersbefore = $DB->count_records('user');

        $preview = $service->preview_bulk_import('lecturer', $rows);

        $usersafter = $DB->count_records('user');

        $this->assertSame(
            $usersbefore,
            $usersafter,
            'Previewing a bulk upload must not create user accounts.'
        );
        $this->assertFalse(
            $DB->record_exists('user', ['email' => $validemail]),
            'Previewing a bulk upload must not persist the previewed account.'
        );
        $this->assertSame(2, (int)$preview['processed']);
        $this->assertSame(1, (int)$preview['valid'], 'The preview must still report the creatable row as valid.');
        $this->assertSame(1, (int)$preview['invalid'], 'The preview must still report the invalid row as invalid.');
        $this->assertCount(1, $preview['validrows']);
    }

    /**
     * No ULMS source file may hard-code a slash-less directory-backed route URL.
     *
     * Form actions and redirect targets must be built through
     * landing_page_service::get_url_for_route() so they inherit the canonical
     * trailing slash. A literal slash-less route directory bypasses that and
     * reintroduces the 301 that discards POST bodies.
     */
    public function test_application_code_does_not_hardcode_slashless_route_urls(): void {
        $this->resetAfterTest(true);

        global $CFG;

        $slashless = [];
        foreach ($this->get_route_definitions() as $definition) {
            $path = (string)$definition['path'];
            if (str_ends_with($path, '/') && is_file($this->index_file_for_path($path))) {
                $slashless[rtrim($path, '/')] = true;
            }
        }

        $roots = [
            $CFG->dirroot . '/local',
            $CFG->dirroot . '/theme/ulms_university',
            $CFG->dirroot . '/student',
            $CFG->dirroot . '/lecturer',
            $CFG->dirroot . '/management',
            $CFG->dirroot . '/super-admin',
            $CFG->dirroot . '/sign-in',
            $CFG->dirroot . '/reset-password',
        ];

        $skippedsegments = ['tests', 'amd', 'lang', 'node_modules', 'vendor', 'yui', 'build', '.trae', 'pix'];
        $patterns = [
            "/\\baction\\s*(?:=>|=)\\s*['\"]([^'\"]+)['\"]/",
            "/\\bmoodle_url\\s*\\(\\s*['\"]([^'\"]+)['\"]/",
        ];

        $scannedfiles = 0;
        $violations = [];

        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                    static function (\SplFileInfo $file) use ($skippedsegments): bool {
                        if ($file->isDir()) {
                            return !in_array($file->getFilename(), $skippedsegments, true);
                        }

                        return str_ends_with($file->getFilename(), '.php');
                    }
                )
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $scannedfiles++;
                $contents = (string)file_get_contents($file->getPathname());

                foreach ($patterns as $pattern) {
                    if (!preg_match_all($pattern, $contents, $matches)) {
                        continue;
                    }

                    foreach ($matches[1] as $literal) {
                        $candidate = (string)parse_url($literal, PHP_URL_PATH);
                        if (!isset($slashless[$candidate])) {
                            continue;
                        }

                        $violations[] = str_replace($CFG->dirroot . '/', '', $file->getPathname())
                            . ' : ' . $literal;
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $scannedfiles, 'The source audit scanned no files.');
        $this->assertSame(
            [],
            array_values(array_unique($violations)),
            "Build route URLs with get_url_for_route() instead of hard-coding a slash-less "
            . "directory-backed path:\n" . implode("\n", array_values(array_unique($violations)))
        );
    }
}
