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

/**
 * Template configuraton file for github actions CI/CD.
 *
 * @package    core
 * @copyright  2020 onwards Eloy Lafuente (stronk7) {@link https://stronk7.com}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// This cannot be used out from a github actions workflow, so just exit.
getenv('GITHUB_WORKFLOW') || die; // phpcs:ignore moodle.Files.MoodleInternal.MoodleInternalGlobalState

unset($CFG);
global $CFG;
$CFG = new stdClass();

$CFG->dbtype    = getenv('dbtype');
$CFG->dblibrary = 'native';
$CFG->dbhost    = '127.0.0.1';
$CFG->dbname    = 'test';
$CFG->dbuser    = 'test';
$CFG->dbpass    = 'test';
$CFG->prefix    = 'm_';
$CFG->dboptions = ['dbcollation' => 'utf8mb4_bin'];

$host = 'localhost';
$CFG->wwwroot   = "http://{$host}";
$CFG->dataroot  = realpath(dirname(__DIR__)) . '/moodledata';
$CFG->admin     = 'admin';
$CFG->directorypermissions = 0777;

// Debug options.
// PRODUCTION SAFETY: Debug must remain 0/off in any copied-to-production template.
// Bump these to E_ALL / 1 ONLY on individual developer workstations with local config.
$CFG->debug = 0; // PRODUCTION: E_NONE (0). DEVELOPER LOCAL: set (E_ALL | E_STRICT) temporarily.
$CFG->debugdisplay = 0; // PRODUCTION SAFETY: Always 0 to prevent stack traces and filesystem paths leaking via HTML.
$CFG->themedesignermode = 0; // PRODUCTION SAFETY: Always 0 to disable theme rebuild on every page load.
$CFG->debugstringids = 0;
$CFG->perfdebug = 0;
$CFG->debugpageinfo = 0;
$CFG->allowthemechangeonurl = 0;
$CFG->passwordpolicy = 1; // PRODUCTION SAFETY: Enforce Moodle password strength policy.
$CFG->cronclionly = 1; // PRODUCTION SAFETY: Require CLI for cron to prevent web-triggered abuse.
$CFG->pathtophp = getenv('pathtophp');

$CFG->phpunit_dataroot  = realpath(dirname(__DIR__)) . '/phpunitdata';
$CFG->phpunit_prefix = 't_';

define('TEST_EXTERNAL_FILES_HTTP_URL', 'http://localhost:8080');
define('TEST_EXTERNAL_FILES_HTTPS_URL', 'http://localhost:8080');

define('TEST_SESSION_REDIS_HOST', 'localhost');
define('TEST_CACHESTORE_REDIS_TESTSERVERS', 'localhost');

// TODO: add others (solr, mongodb, memcached, ldap...).

// Too much for now: define('PHPUNIT_LONGTEST', true); // Only leaves a few tests out and they are run later by CI.

require_once(__DIR__ . '/lib/setup.php');
