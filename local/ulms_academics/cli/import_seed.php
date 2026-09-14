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
