<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_ulms_privacy_install(): void {
    global $DB;

    $categoryshortname = 'ulms_gdpr_privacy';
    $categoryname = 'GDPR & Privacy';
    $sortorder = (int)$DB->get_field_sql(
        "SELECT COALESCE(MAX(sortorder), 0) + 1 FROM {user_info_category}"
    );

    $categoryid = (int)$DB->get_field(
        'user_info_category',
        'id',
        ['shortname' => $categoryshortname],
        IGNORE_MISSING
    );
    if ($categoryid <= 0) {
        $categoryid = (int)$DB->insert_record('user_info_category', (object)[
            'name'      => $categoryname,
            'shortname' => $categoryshortname,
            'sortorder' => $sortorder,
        ]);
    }

    $fields = [
        [
            'shortname' => 'dob',
            'name'      => 'Date of birth',
            'datatype'  => 'datetime',
            'description' => 'Student official date of birth (GDPR Article 9 special category data when combined with other records).',
            'required'  => 0,
            'locked'    => 1,
            'visible'   => 1,
            'forceunique' => 0,
            'signup'  => 0,
            'defaultdata' => '',
            'param1'    => '2000',
            'param2'    => '2015',
            'param3'    => '0',
            'param4'    => '0',
            'param5'    => '0',
        ],
        [
            'shortname' => 'nok_name',
            'name'      => 'Next of kin — Full name',
            'datatype'  => 'text',
            'description' => 'Full legal name of next of kin / emergency contact person.',
            'required'  => 0,
            'locked'    => 1,
            'visible'   => 1,
            'forceunique' => 0,
            'signup'  => 0,
            'defaultdata' => '',
            'param1'    => 200,
            'param2'    => 500,
            'param3'    => 0,
            'param4'    => 0,
            'param5'    => 0,
        ],
        [
            'shortname' => 'nok_phone',
            'name'      => 'Next of kin — Phone number',
            'datatype'  => 'text',
            'description' => 'Direct telephone or mobile contact for next of kin.',
            'required'  => 0,
            'locked'    => 1,
            'visible'   => 1,
            'forceunique' => 0,
            'signup'  => 0,
            'defaultdata' => '',
            'param1'    => 20,
            'param2'    => 50,
            'param3'    => 0,
            'param4'    => 0,
            'param5'    => 0,
        ],
        [
            'shortname' => 'nok_relation',
            'name'      => 'Next of kin — Relationship',
            'datatype'  => 'text',
            'description' => 'Relationship to the student (e.g. Parent, Sibling, Guardian, Spouse).',
            'required'  => 0,
            'locked'    => 1,
            'visible'   => 1,
            'forceunique' => 0,
            'signup'  => 0,
            'defaultdata' => '',
            'param1'    => 100,
            'param2'    => 250,
            'param3'    => 0,
            'param4'    => 0,
            'param5'    => 0,
        ],
        [
            'shortname' => 'ferpa_directory_optout',
            'name'      => 'FERPA directory opt-out',
            'datatype'  => 'checkbox',
            'description' => 'If checked, the student has opted out of FERPA directory information disclosure.',
            'required'  => 0,
            'locked'    => 0,
            'visible'   => 1,
            'forceunique' => 0,
            'signup'  => 0,
            'defaultdata' => '0',
            'param1'    => 0,
            'param2'    => 0,
            'param3'    => 0,
            'param4'    => 0,
            'param5'    => 0,
        ],
        [
            'shortname' => 'sms_marketing_consent',
            'name'      => 'SMS marketing consent',
            'datatype'  => 'checkbox',
            'description' => 'If checked, the student has given explicit consent to receive SMS marketing messages (GDPR Article 6 consent).',
            'required'  => 0,
            'locked'    => 0,
            'visible'   => 1,
            'forceunique' => 0,
            'signup'  => 0,
            'defaultdata' => '0',
            'param1'    => 0,
            'param2'    => 0,
            'param3'    => 0,
            'param4'    => 0,
            'param5'    => 0,
        ],
    ];

    $sort = 1;
    foreach ($fields as $f) {
        $exists = (int)$DB->get_field(
            'user_info_field', 'id',
            ['shortname' => $f['shortname']], IGNORE_MISSING);
        if ($exists > 0) {
            $sort++;
            continue;
        }
        $DB->insert_record('user_info_field', (object)array_merge($f, [
            'categoryid'         => $categoryid,
            'sortorder'          => $sort++,
            'descriptionformat'  => FORMAT_MOODLE,
            'defaultdataformat' => FORMAT_MOODLE,
        ]));
    }
}
