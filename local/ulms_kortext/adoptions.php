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
require_once($CFG->libdir . '/csvlib.class.php');

/** @var moodle_page $PAGE */
/** @var core_renderer $OUTPUT */
/** @var stdClass $USER */

global $DB, $PAGE, $OUTPUT, $USER;

$service = new \local_ulms_kortext\local\service\adoption_service();

require_login();

/** @var mixed $syscontext */
$syscontext = \context_system::instance();
require_capability('local/ulms_kortext:manageadoptions', $syscontext);

$action = optional_param('action', 'list', PARAM_ALPHAEXT);
$id     = optional_param('id', 0, PARAM_INT);
$export = optional_param('export', '', PARAM_ALPHA);
$page   = optional_param('page', 0, PARAM_INT);
$perpage = optional_param('perpage', 25, PARAM_INT);

$filters = [
    'programmeid'  => optional_param('programmeid', 0, PARAM_INT),
    'departmentid' => optional_param('departmentid', 0, PARAM_INT),
    'semesterid'   => optional_param('semesterid', 0, PARAM_INT),
    'moodlecourseid' => optional_param('moodlecourseid', 0, PARAM_INT),
    'status'       => optional_param('status', '', PARAM_ALPHA),
    'q'            => optional_param('q', '', PARAM_TEXT),
];

$url = new \moodle_url('/local/ulms_kortext/adoptions.php', $filters + ['action' => $action, 'id' => $id, 'page' => $page, 'perpage' => $perpage]);
$PAGE->set_context($syscontext);
$PAGE->set_url($url);
$PAGE->set_title(get_string('adoptions_page_title', 'local_ulms_kortext'));
$PAGE->set_heading(get_string('adoptions_page_title', 'local_ulms_kortext'));
$PAGE->set_pagelayout('ulmsdashboard');
$PAGE->requires->css(new \moodle_url('/local/ulms_dashboard/styles.css'));
$PAGE->requires->js_call_amd('local_ulms_kortext/filter_cascade', 'init', [
    'coursesEndpointUrl' => (string)(new \moodle_url('/local/ulms_kortext/api_courses_for_programme.php')),
]);

$redirectback = new \moodle_url('/local/ulms_kortext/adoptions.php', $filters + ['action' => 'list']);

if ($export === 'csv' && confirm_sesskey()) {
    $csv = $service->csv_export($filters);
    $filename = 'ulms_kortext_adoptions_' . date('Ymd_Hi');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    echo "\xEF\xBB\xBF";
    echo $csv;
    exit;
}

if ($action === 'archive' && $id > 0 && confirm_sesskey()) {
    try {
        $service->archive_adoption($id, (int)$USER->id);
        redirect($redirectback, get_string('adoption_archived', 'local_ulms_kortext'), null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (\Throwable) {
        redirect($redirectback, get_string('adoption_not_found', 'local_ulms_kortext'), null, \core\output\notification::NOTIFY_ERROR);
    }
}

$flashmsg = '';
$flashtype = \core\output\notification::NOTIFY_INFO;

if ($action === 'save' && data_submitted() && confirm_sesskey()) {
    $data = [
        'programmeid'    => required_param('programmeid', PARAM_INT),
        'moodlecourseid' => required_param('moodlecourseid', PARAM_INT),
        'semesterid'     => required_param('semesterid', PARAM_INT),
        'isbn'           => trim(required_param('isbn', PARAM_TEXT)),
        'ebook_id'       => trim(optional_param('ebook_id', '', PARAM_ALPHANUMEXT)),
        'deeplink_url'   => trim(optional_param('deeplink_url', '', PARAM_URL)),
        'status'         => required_param('status', PARAM_ALPHA),
        'adopted_by'     => (int)$USER->id,
    ];
    if (!in_array($data['status'], ['active', 'archived'], true)) {
        $data['status'] = 'active';
    }
    try {
        if ($id > 0) {
            $service->update_adoption($id, $data, (int)$USER->id);
            $flashmsg = get_string('adoption_updated', 'local_ulms_kortext');
            $flashtype = \core\output\notification::NOTIFY_SUCCESS;
        } else {
            $id = $service->create_adoption($data, (int)$USER->id);
            $flashmsg = get_string('adoption_created', 'local_ulms_kortext');
            $flashtype = \core\output\notification::NOTIFY_SUCCESS;
        }
        $action = 'list';
    } catch (\Throwable $e) {
        $flashmsg = $e->getMessage();
        $flashtype = \core\output\notification::NOTIFY_ERROR;
        $action = $id > 0 ? 'edit' : 'create';
    }
}

if ($action === 'import' && data_submitted() && confirm_sesskey()) {
    $dryrun = optional_param('dryrun', 0, PARAM_INT) === 1;
    $importpreview = optional_param('preview_rows', '', PARAM_RAW);
    $rows = [];
    if ($importpreview !== '') {
        $decoded = json_decode($importpreview, true);
        if (is_array($decoded)) {
            $rows = $decoded;
        }
    } else {
        $importfile = required_param('importfile', PARAM_FILE);
        if (is_uploaded_file($_FILES['importfile']['tmp_name'] ?? '')) {
            $handle = fopen($_FILES['importfile']['tmp_name'], 'rb');
            if ($handle !== false) {
                $headers = fgetcsv($handle);
                if (is_array($headers)) {
                    $headers = array_map(static fn($_h) => trim((string)$_h), $headers);
                    while (($row = fgetcsv($handle)) !== false) {
                        if (count($row) !== count($headers)) {
                            continue;
                        }
                        $rows[] = array_combine($headers, $row);
                    }
                }
                fclose($handle);
            }
        }
    }
    $preview = $service->csv_import($rows, true, (int)$USER->id);
    if (!$dryrun && $importpreview !== '') {
        $result = $service->csv_import($rows, false, (int)$USER->id);
        $a = new stdClass();
        $a->created = $result['created'];
        $a->skipped = $result['skipped'];
        $a->errors  = count($result['errors']);
        $flashmsg = get_string('csv_import_done', 'local_ulms_kortext', $a);
        $flashtype = \core\output\notification::NOTIFY_SUCCESS;
        $action = 'list';
        $preview = $result;
    }
}

echo $OUTPUT->header();

local_ulms_dashboard_prepare_page(
    $syscontext,
    $url,
    get_string('adoptions_page_title', 'local_ulms_kortext')
);

echo local_ulms_dashboard_render_page_header([
    'eyebrow' => get_string('kortext', 'local_ulms_kortext'),
    'title'   => get_string('adoptions_page_title', 'local_ulms_kortext'),
    'meta'    => get_string('adoptions_page_meta', 'local_ulms_kortext'),
]);

local_ulms_dashboard_start_shell_wrap();

if ($flashmsg !== '') {
    $notification = new \core\output\notification($flashmsg, $flashtype);
    echo $OUTPUT->render($notification);
}

if ($action === 'create' || $action === 'edit') {
    $record = null;
    if ($action === 'edit' && $id > 0) {
        $record = $DB->get_record('local_ulms_kortext_adoptions', ['id' => $id], '*', MUST_EXIST);
    }
    $programmes = $DB->get_records_menu('local_ulms_programmes', ['status' => 'active'], 'name ASC', 'id, name');
    $semesters   = $DB->get_records_menu('local_ulms_semesters', [], 'startdate DESC', 'id, name');
    $coursesel   = ['' => get_string('field_course', 'local_ulms_kortext')];
    $pid = (int)($record->programmeid ?? $filters['programmeid'] ?? 0);
    if ($pid > 0) {
        $courseids = $DB->get_fieldset_sql(
            "SELECT DISTINCT moodlecourseid FROM {local_ulms_programme_courses} WHERE programmeid = :pid",
            ['pid' => $pid]
        );
        if (count($courseids) > 0) {
            [$in, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cid');
            $coursesel = $coursesel + $DB->get_records_sql_menu("SELECT id, CONCAT(shortname, ' — ', fullname) FROM {course} WHERE id {$in}", $params);
        }
    }
    echo html_writer::start_div('ulms-layout-grid');
    echo html_writer::start_div('ulms-panel');
    echo html_writer::tag('h2', $action === 'edit'
        ? get_string('editadoption', 'local_ulms_kortext')
        : get_string('addadoption', 'local_ulms_kortext'));
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => (string)(new \moodle_url('/local/ulms_kortext/adoptions.php', ['action' => 'save', 'id' => $id])),
        'class'  => 'mform',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save']);
    if ($id > 0) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]);
    }
    $renderfield = static function (string $labelkey, string $controlhtml, string $forid): string {
        $label = html_writer::tag('label', get_string($labelkey, 'local_ulms_kortext'), ['for' => $forid]);
        return html_writer::div($label . "\n" . $controlhtml, 'form-group row fitem');
    };
    $selprogramme = html_writer::select($programmes, 'programmeid', (int)($record->programmeid ?? $filters['programmeid']), ['' => get_string('field_programme', 'local_ulms_kortext')], ['id' => 'id_programmeid', 'class' => 'form-control']);
    echo $renderfield('field_programme', $selprogramme, 'id_programmeid');
    $selcourse = html_writer::select($coursesel, 'moodlecourseid', (int)($record->moodlecourseid ?? $filters['moodlecourseid']), ['' => get_string('field_course', 'local_ulms_kortext')], ['id' => 'id_moodlecourseid', 'class' => 'form-control']);
    echo $renderfield('field_course', $selcourse, 'id_moodlecourseid');
    $selsemester = html_writer::select($semesters, 'semesterid', (int)($record->semesterid ?? 0), ['' => get_string('field_semester', 'local_ulms_kortext')], ['id' => 'id_semesterid', 'class' => 'form-control']);
    echo $renderfield('field_semester', $selsemester, 'id_semesterid');
    $inpisbn = html_writer::empty_tag('input', [
        'type' => 'text', 'name' => 'isbn', 'id' => 'id_isbn', 'class' => 'form-control',
        'value' => $record->isbn ?? '', 'required' => 'required', 'maxlength' => 17,
        'placeholder' => get_string('field_isbn_placeholder', 'local_ulms_kortext'),
    ]);
    echo $renderfield('field_isbn', $inpisbn, 'id_isbn');
    $inpebook = html_writer::empty_tag('input', [
        'type' => 'text', 'name' => 'ebook_id', 'id' => 'id_ebookid', 'class' => 'form-control',
        'value' => $record->ebook_id ?? '',
    ]);
    echo $renderfield('field_ebookid', $inpebook, 'id_ebookid');
    $inpdeep = html_writer::empty_tag('input', [
        'type' => 'url', 'name' => 'deeplink_url', 'id' => 'id_deeplink', 'class' => 'form-control',
        'value' => $record->deeplink_url ?? '',
    ]);
    echo $renderfield('field_deeplink', $inpdeep, 'id_deeplink');
    $selstatus = html_writer::select(
        ['active' => get_string('status_active', 'local_ulms_kortext'), 'archived' => get_string('status_archived', 'local_ulms_kortext')],
        'status',
        $record->status ?? 'active',
        ['class' => 'form-control', 'id' => 'id_status']
    );
    echo $renderfield('field_status', $selstatus, 'id_status');
    echo html_writer::start_div('form-group row fitem');
    echo html_writer::empty_tag('input', [
        'type'  => 'submit',
        'class' => 'btn btn-primary',
        'value' => $action === 'edit' ? get_string('editadoption', 'local_ulms_kortext') : get_string('addadoption', 'local_ulms_kortext'),
    ]);
    echo ' ';
    echo html_writer::link($redirectback, get_string('cancel', 'core'), ['class' => 'btn btn-secondary']);
    echo html_writer::end_div();
    echo html_writer::end_tag('form');
    echo html_writer::end_div();
    echo html_writer::end_div();
    local_ulms_dashboard_end_shell_wrap();
    echo $OUTPUT->footer();
    exit;
}

if ($action === 'import') {
    $dryrun = $dryrun ?? true;
    echo html_writer::start_div('ulms-layout-grid');
    echo html_writer::start_div('ulms-panel');
    echo html_writer::tag('h2', get_string('csvimport', 'local_ulms_kortext'));
    echo html_writer::tag('p', get_string('csv_col_headers_required', 'local_ulms_kortext'));
    echo html_writer::start_tag('form', [
        'method'  => 'post',
        'action'  => (string)(new \moodle_url('/local/ulms_kortext/adoptions.php', ['action' => 'import'])),
        'enctype' => 'multipart/form-data',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    $upload = html_writer::empty_tag('input', ['type' => 'file', 'name' => 'importfile', 'id' => 'id_importfile', 'accept' => '.csv,text/csv']);
    echo html_writer::div(
        html_writer::tag('label', get_string('csvimport', 'local_ulms_kortext'), ['for' => 'id_importfile']) . "\n" . $upload,
        'form-group row fitem'
    );
    $drycb = html_writer::checkbox('dryrun', '1', true, get_string('csv_import_preview', 'local_ulms_kortext'), ['id' => 'id_dryrun']);
    echo html_writer::div($drycb, 'form-group row fitem');
    echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-primary', 'value' => get_string('csvimport', 'local_ulms_kortext')]);
    echo ' ';
    echo html_writer::link($redirectback, get_string('cancel', 'core'), ['class' => 'btn btn-secondary']);
    echo html_writer::end_tag('form');
    if (isset($preview)) {
        echo html_writer::tag('h3', get_string('csv_import_preview', 'local_ulms_kortext'));
        $counts = new stdClass();
        $counts->created = $preview['created'];
        $counts->skipped = $preview['skipped'];
        $counts->errors  = count($preview['errors']);
        echo html_writer::div(
            get_string('csv_import_done', 'local_ulms_kortext', $counts),
            'alert alert-info'
        );
        if (count($preview['errors']) > 0) {
            $errrows = [];
            foreach ($preview['errors'] as $_e) {
                $errrows[] = html_writer::tag('tr',
                    html_writer::tag('td', (string)$_e['row']) .
                    html_writer::tag('td', $_e['message']) .
                    html_writer::tag('td', s(implode(', ', $_e['data'])))
                );
            }
            echo html_writer::tag('table',
                html_writer::tag('thead',
                    html_writer::tag('tr',
                        html_writer::tag('th', '#') .
                        html_writer::tag('th', get_string('csv_import_errors', 'local_ulms_kortext')) .
                        html_writer::tag('th', 'Row data')
                    )
                ) .
                html_writer::tag('tbody', implode("\n", $errrows)),
                ['class' => 'table generaltable']
            );
        }
        if ($flashmsg === '' && $dryrun && count($preview['errors']) === 0 && $preview['created'] > 0) {
            echo html_writer::start_tag('form', [
                'method' => 'post',
                'action' => (string)(new \moodle_url('/local/ulms_kortext/adoptions.php', ['action' => 'import'])),
            ]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'import']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'dryrun', 'value' => '0']);
            echo html_writer::empty_tag('input', [
                'type'  => 'hidden',
                'name'  => 'preview_rows',
                'value' => json_encode($rows ?? [], JSON_UNESCAPED_UNICODE),
            ]);
            echo html_writer::empty_tag('input', [
                'type'  => 'submit',
                'class' => 'btn btn-success',
                'value' => get_string('csv_import_finalize', 'local_ulms_kortext', $preview['created']),
            ]);
            echo html_writer::end_tag('form');
        }
    }
    echo html_writer::end_div();
    echo html_writer::end_div();
    local_ulms_dashboard_end_shell_wrap();
    echo $OUTPUT->footer();
    exit;
}

$listdata = $service->list_adoptions($filters, $page, $perpage);
echo local_ulms_dashboard_render_summary_cards($service->kpi_summary());

$programmesmenu = $DB->get_records_menu('local_ulms_programmes', ['status' => 'active'], 'name ASC', 'id, name');
$semestersmenu = $DB->get_records_menu('local_ulms_semesters', [], 'startdate DESC', 'id, name');
$statusmenu     = ['active' => get_string('status_active', 'local_ulms_kortext'), 'archived' => get_string('status_archived', 'local_ulms_kortext')];

$filterform = '';
$filterform .= html_writer::start_tag('form', ['method' => 'get', 'class' => 'ulms-filters form-inline', 'action' => (string)$redirectback]);
$filterform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'list']);
$filterform .= html_writer::select($programmesmenu, 'programmeid', $filters['programmeid'], ['' => get_string('filter_programme', 'local_ulms_kortext')], ['class' => 'form-control mr-2 mb-2']);
$filterform .= html_writer::select($semestersmenu, 'semesterid', $filters['semesterid'], ['' => get_string('filter_semester', 'local_ulms_kortext')], ['class' => 'form-control mr-2 mb-2']);
$filterform .= html_writer::select($statusmenu, 'status', $filters['status'], ['' => get_string('filter_clear', 'local_ulms_kortext')], ['class' => 'form-control mr-2 mb-2']);
$filterform .= html_writer::empty_tag('input', ['type' => 'search', 'name' => 'q', 'value' => $filters['q'], 'placeholder' => get_string('filter_search_placeholder', 'local_ulms_kortext'), 'class' => 'form-control mr-2 mb-2']);
$filterform .= html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-secondary mb-2 mr-2', 'value' => get_string('go', 'core')]);
$filterform .= ' ' . html_writer::link($redirectback->out_omit_querystring(true), get_string('filter_clear', 'local_ulms_kortext'), ['class' => 'btn btn-outline-secondary mb-2']);
$filterform .= html_writer::end_tag('form');

$topactions = '';
$topactions .= html_writer::link(
    new \moodle_url('/local/ulms_kortext/adoptions.php', ['action' => 'create']),
    get_string('addadoption', 'local_ulms_kortext'),
    ['class' => 'btn btn-primary mr-2']
);
$topactions .= html_writer::link(
    new \moodle_url('/local/ulms_kortext/adoptions.php', ['action' => 'import']),
    get_string('csvimport', 'local_ulms_kortext'),
    ['class' => 'btn btn-secondary mr-2']
);
$topactions .= html_writer::link(
    (new \moodle_url('/local/ulms_kortext/adoptions.php', $filters + ['export' => 'csv', 'sesskey' => sesskey()]))->out(false),
    get_string('csvexport', 'local_ulms_kortext'),
    ['class' => 'btn btn-outline-secondary']
);

$rows = [];
foreach ($listdata['items'] as $_it) {
    $pname = $_it->programmename ?? $_it->programmecode ?? '';
    $coursetext = $_it->programmecode ?? '';
    try {
        $c = $DB->get_record('course', ['id' => (int)$_it->moodlecourseid], 'shortname, fullname');
        if ($c) {
            $coursetext = $coursetext !== '' ? ($coursetext . ' / ' . ($c->shortname ?? '')) : ($c->shortname ?? '');
        }
    } catch (\Throwable) {
        $coursetext = '#' . $_it->moodlecourseid;
    }
    try {
        $s = $DB->get_record('local_ulms_semesters', ['id' => (int)$_it->semesterid], 'code, name');
        if ($s) {
            $semtext = ($s->code ?? '') . ' ' . ($s->name ?? '');
        } else {
            $semtext = '#' . $_it->semesterid;
        }
    } catch (\Throwable) {
        $semtext = '#' . $_it->semesterid;
    }
    $badgeclass = $_it->status === 'active' ? 'badge badge-success' : 'badge badge-secondary';
    $actionbtns = '';
    $actionbtns .= html_writer::link(
        new \moodle_url('/local/ulms_kortext/adoptions.php', ['action' => 'edit', 'id' => $_it->id]),
        get_string('action_edit', 'local_ulms_kortext'),
        ['class' => 'btn btn-sm btn-outline-primary mr-1']
    );
    if ($_it->status === 'active') {
        $actionbtns .= html_writer::link(
            new \moodle_url('/local/ulms_kortext/adoptions.php', ['action' => 'archive', 'id' => $_it->id, 'sesskey' => sesskey()]),
            get_string('action_archive', 'local_ulms_kortext'),
            [
                'class'      => 'btn btn-sm btn-outline-danger',
                'onclick'    => 'return confirm(' . json_encode(get_string('archive_confirm', 'local_ulms_kortext')) . ');',
            ]
        );
    }
$rows[] = [
    'col_programme' => s($pname) . ($coursetext ? ' <small class="text-muted">' . s($coursetext) . '</small>' : ''),
    'col_semester'  => s($semtext),
    'col_isbn'      => s($_it->isbn),
    'col_status'    => html_writer::tag('span', get_string('status_' . ($_it->status ?? 'active'), 'local_ulms_kortext'), ['class' => $badgeclass]),
    'col_actions'   => $actionbtns,
];
}
$columns = [
    'col_programme' => get_string('list_col_programme', 'local_ulms_kortext'),
    'col_semester'  => get_string('list_col_semester', 'local_ulms_kortext'),
    'col_isbn'      => get_string('list_col_isbn', 'local_ulms_kortext'),
    'col_status'    => get_string('list_col_status', 'local_ulms_kortext'),
    'col_actions'   => get_string('list_col_actions', 'local_ulms_kortext'),
];
$tablehtml = '';
if (empty($rows)) {
    $tablehtml = html_writer::div(
        html_writer::tag('h3', format_string(get_string('adoptions_list_empty', 'local_ulms_kortext')), ['class' => 'ulms-empty-state__title']) .
        html_writer::tag('p', format_string(get_string('adoptions_list_empty_desc', 'local_ulms_kortext')), ['class' => 'ulms-empty-state__meta']),
        'ulms-empty-state'
    );
} else {
    $tablehtml .= html_writer::start_tag('table', ['class' => 'table ulms-table']);
    $tablehtml .= html_writer::start_tag('thead');
    $tablehtml .= html_writer::start_tag('tr');
    foreach ($columns as $colkey => $collabel) {
        $tablehtml .= html_writer::tag('th', format_string($collabel), ['scope' => 'col']);
    }
    $tablehtml .= html_writer::end_tag('tr');
    $tablehtml .= html_writer::end_tag('thead');
    $tablehtml .= html_writer::start_tag('tbody');
    foreach ($rows as $row) {
        $tablehtml .= html_writer::start_tag('tr');
        foreach ($columns as $colkey => $unused) {
            $tablehtml .= html_writer::tag('td', (string)($row[$colkey] ?? ''));
        }
        $tablehtml .= html_writer::end_tag('tr');
    }
    $tablehtml .= html_writer::end_tag('tbody');
    $tablehtml .= html_writer::end_tag('table');
}
$mainpanel = [
    'title'    => get_string('adoptions', 'local_ulms_kortext'),
    'subtitle' => get_string('nav_adoptions_desc', 'local_ulms_kortext'),
    'style'    => 'definition',
    'items'    => [],
];
echo local_ulms_dashboard_render_panel($mainpanel);
echo $topactions . "\n" . $filterform;
echo $tablehtml;

local_ulms_dashboard_end_shell_wrap();
echo $OUTPUT->footer();
