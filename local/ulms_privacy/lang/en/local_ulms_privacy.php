<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'ULMS Privacy & GDPR';
$string['privacy:metadata'] = 'The ULMS Privacy plugin stores GDPR consent and FERPA opt-out preferences on behalf of users. All data may be exported or erased via standard Moodle privacy tools.';
$string['privacy:metadata:user_info_data'] = 'Custom profile fields storing GDPR consent, next of kin, date of birth and FERPA directory opt-out.';
$string['privacy:metadata:user_info_data:fieldid:dob'] = 'Date of birth of the user (special category).';
$string['privacy:metadata:user_info_data:fieldid:nok_name'] = 'Full name of next of kin.';
$string['privacy:metadata:user_info_data:fieldid:nok_phone'] = 'Telephone contact for next of kin.';
$string['privacy:metadata:user_info_data:fieldid:nok_relation'] = 'Relationship of next of kin to user.';
$string['privacy:metadata:user_info_data:fieldid:ferpa_directory_optout'] = 'FERPA directory information opt-out flag (0 or 1).';
$string['privacy:metadata:user_info_data:fieldid:sms_marketing_consent'] = 'Explicit SMS marketing consent flag (0 or 1).';
$string['privacy:metadata:pii_field_accessed'] = 'Audit trail for any access to PII fields by administrators or staff.';
$string['gdpr_category_name'] = 'GDPR & Privacy';
$string['ulms_privacy_settings_header'] = 'Privacy & Consent';
$string['field_dob'] = 'Date of birth';
$string['field_nok_name'] = 'Next of kin — Full name';
$string['field_nok_phone'] = 'Next of kin — Phone';
$string['field_nok_relation'] = 'Next of kin — Relationship';
$string['field_ferpa_optout'] = 'FERPA directory opt-out';
$string['field_sms_consent'] = 'SMS marketing consent';
$string['piiaccessed'] = 'PII fields accessed.';
$string['piiaccessdesc'] = 'User id {{USERID}} ({{VIEWER}}) viewed PII fields ({{FIELDS}}) on user id {{SUBJECTID}} for purpose {{PURPOSE}}.';
$string['manageprivacy'] = 'Manage ULMS privacy settings';
$string['exportowndata'] = 'Export my personal data';
$string['viewpiiaccesslog'] = 'View PII access audit log';
