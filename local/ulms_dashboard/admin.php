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

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/ulms_dashboard/lib.php');

/** @var moodle_page $PAGE */
/** @var core_renderer $OUTPUT */
global $PAGE, $OUTPUT;

require_login();

$routingservice = new \local_ulms_auth\local\service\landing_page_service();
$routingservice->maybe_redirect_legacy_request('management.dashboard');

$service = new \local_ulms_dashboard\local\service\dashboard_service();
$service->enforce_admin_feature_access();
$service->require_admin_permissions();

$context = \context::instance_by_id(context_system::instance()->id);

$url = $routingservice->get_url_for_route('management.dashboard');
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_title(get_string('admindashboard', 'local_ulms_dashboard'));
$PAGE->set_heading(get_string('admindashboard', 'local_ulms_dashboard'));
$PAGE->set_pagelayout('ulmsdashboard');

$portalservice = new \local_ulms_dashboard\local\service\admin_portal_service();
$headercontext = $portalservice->get_header_context_for_section('dashboard');

/** @var array<string, mixed> $data */
$data = $service->get_admin_dashboard_data();

echo $OUTPUT->header();
echo local_ulms_dashboard_render_page_header($headercontext);

local_ulms_dashboard_start_shell_wrap();

$primarysection = $data['sections'][0] ?? null;
$secondarysections = array_slice($data['sections'], 1);

$focusitems = [
    ['label' => $data['focusheading'], 'value' => $data['currentperiod']],
    ['label' => get_string('admindashboardusermeta', 'local_ulms_dashboard'), 'value' => get_string('admindashboardprovisioningmeta', 'local_ulms_dashboard')],
];
echo local_ulms_dashboard_render_panel([
    'soft' => true,
    'title' => $data['focusheading'],
    'subtitle' => $data['focusintro'],
    'style' => 'definition',
    'items' => $focusitems,
]);

if (!empty($primarysection)) {
    /** @var array<int, array<string, mixed>> $primarycards */
    $primarycards = $primarysection['cards'] ?? [];
    $primaryitems = array_map(static function(array $card): array {
        /** @noinspection PhpUnusedParameterInspection */
        $cardlabel = (string)($card['label'] ?? '');
        $carddescription = (string)($card['description'] ?? '');
        $cardurl = $card['url'] ?? '#';
        return [
            'title' => $cardlabel,
            'meta' => $carddescription,
            'url' => $cardurl,
        ];
    }, $primarycards);
    echo local_ulms_dashboard_render_panel([
        'title' => (string)($primarysection['heading'] ?? ''),
        'subtitle' => (string)($primarysection['intro'] ?? ''),
        'style' => 'cards',
        'items' => $primaryitems,
    ]);
}

echo local_ulms_dashboard_render_summary_cards($data['summary'] ?? []);

if (!empty($secondarysections)) {
    foreach ($secondarysections as $section) {
        /** @var array<string, mixed> $section */
        /** @var array<int, array<string, mixed>> $sectioncards */
        $sectioncards = $section['cards'] ?? [];
        $sectionitems = array_map(static function(array $card): array {
            /** @noinspection PhpUnusedParameterInspection */
            $cardlabel = (string)($card['label'] ?? '');
            $carddescription = (string)($card['description'] ?? '');
            $cardurl = $card['url'] ?? '#';
            return [
                'title' => $cardlabel,
                'meta' => $carddescription,
                'url' => $cardurl,
            ];
        }, $sectioncards);
        echo local_ulms_dashboard_render_panel([
            'soft' => true,
            'title' => (string)($section['heading'] ?? ''),
            'subtitle' => (string)($section['intro'] ?? ''),
            'style' => 'cards',
            'items' => $sectionitems,
        ]);
    }
}

if (!empty($data['panels'])) {
    echo html_writer::start_div('ulms-layout-grid');
    foreach ($data['panels'] as $panel) {
        /** @var array<string, mixed> $panel */
        $panelitems = array_map(static function(array $item): array {
            /** @noinspection PhpUnusedParameterInspection */
            return [
                'title' => (string)($item['label'] ?? ''),
                'meta' => (string)($item['subtitle'] ?? ''),
            ];
        }, $panel['items'] ?? []);
        echo local_ulms_dashboard_render_panel([
            'title' => (string)($panel['heading'] ?? ''),
            'subtitle' => (string)($panel['intro'] ?? ''),
            'style' => 'list',
            'items' => $panelitems,
            'emptytitle' => (string)($panel['emptyheading'] ?? ''),
            'emptydesc' => (string)($panel['emptydesc'] ?? ''),
        ]);
    }
    echo html_writer::end_div();
}

local_ulms_dashboard_end_shell_wrap();
echo $OUTPUT->footer();
