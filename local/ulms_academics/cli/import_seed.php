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

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');

// OQ-4 Production Boot Guard. MUST execute as EARLY as possible after $CFG populated.
// Prevents ANY production boot that still uses MySQL root account (minimum-privilege violation).
global $CFG;
$dbuser = $CFG->dbuser ?? $_ENV['DB_USER'] ?? getenv('DB_USER') ?: '';
$appenv = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'local';
if ((defined('APP_ENV') ? APP_ENV === 'production' : (stripos((string)$appenv, 'prod') !== false))
    && (strcasecmp((string)$dbuser, 'root') === 0)) {
    http_response_code(500);
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: text/plain; charset=utf-8');
    }
    $msg = "ULMS-SAFETY-P0: Production environment detected but DB_USER='root' in active configuration. "
         . "Create a dedicated minimum-privilege user (ulms_rw) per .env.example L64-68, update .env DB_USER/DB_PASS, "
         . "then retry. ULMS refuses to boot in production with root MySQL account. See: SECURITY.md / deployment checklist.";
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "ERROR [OQ-4]: " . $msg . PHP_EOL);
        exit(2);
    } else {
        die($msg);
    }
}

require_once($CFG->libdir . '/clilib.php');

$options = [
    'file' => null,
    'help' => false,
];

[$options, $unrecognized] = cli_get_params($options, ['h' => 'help']);

if (!empty($options['help']) || !empty($unrecognized)) {
    $help = "ULMS academic structure seed importer\n\n" .
        "Options:\n" .
        "--file=/absolute/path/to/seed.json   Path to the JSON seed file\n" .
        "--help                               Print out this help\n";
    echo $help;
    exit(0);
}

if (empty($options['file']) || !is_readable($options['file'])) {
    cli_error('A readable --file path is required.');
}

$json = file_get_contents($options['file']);
$payload = json_decode($json, true);

if (!is_array($payload)) {
    cli_error('Seed file must contain valid JSON.');
}

$service = new \local_ulms_academics\local\service\academic_structure_service();
$supported = $service->get_supported_entities();

foreach ($supported as $entity) {
    if (empty($payload[$entity]) || !is_array($payload[$entity])) {
        continue;
    }

    foreach ($payload[$entity] as $row) {
        $record = (object)$row;
        $service->save_entity_record($entity, $record);
    }

    echo 'Imported ' . count($payload[$entity]) . ' ' . $entity . PHP_EOL;
}

echo "Seed import completed.\n";
