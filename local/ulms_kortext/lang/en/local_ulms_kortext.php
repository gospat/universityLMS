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

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'ULMS Kortext Integration';
$string['kortext'] = 'Kortext';
$string['adoptions'] = 'Textbook Adoptions';
$string['adoption'] = 'Adoption';
$string['etextbooks'] = 'eTextbooks';
$string['etextbook'] = 'eTextbook';
$string['adopted_etextbooks'] = 'Adopted eTextbooks';
$string['adopted_etextbooks_desc'] = 'Programme-mapped eTextbooks available via the institutional subscription.';
$string['manageadoptions'] = 'Manage textbook adoptions';
$string['viewadoptions'] = 'View adopted textbooks for allocated courses';
$string['viewowndoptions'] = 'View adopted textbooks for enrolled courses';

$string['settings_adapter_mode'] = 'Adapter mode';
$string['settings_adapter_mode_desc'] = 'Switch between the zero-credential mock adapter (for development, UI tests, cron dry-runs) and the live Kortext REST API (production OAuth2). Changing this setting updates all behaviour in real time with no code changes required.';
$string['settings_adapter_mode_mock'] = 'Mock adapter (offline, deterministic, logs to temp)';
$string['settings_adapter_mode_production'] = 'Production REST adapter (Kortext OAuth2 client credentials)';
$string['settings_oauth2_client_id'] = 'OAuth2 client ID';
$string['settings_oauth2_client_id_desc'] = 'Client identifier issued by the Kortext integrations team for your institution. Required only when adapter mode = Production.';
$string['settings_oauth2_client_secret'] = 'OAuth2 client secret';
$string['settings_oauth2_client_secret_desc'] = 'Confidential secret paired with the OAuth2 client ID. Stored password-masked; never rendered in the HTML output.';
$string['settings_oauth2_token_endpoint'] = 'OAuth2 token endpoint';
$string['settings_oauth2_token_endpoint_desc'] = 'Full URL of the Kortext OAuth2 token endpoint (grant_type=client_credentials). Default value provided; override if Kortext provides a region-specific endpoint.';
$string['settings_kortext_rest_base'] = 'Kortext REST base URL';
$string['settings_kortext_rest_base_desc'] = 'Base URL prefix for all Kortext REST API endpoints (without trailing slash).';
$string['settings_adapter_timeout'] = 'Per-request timeout (seconds)';
$string['settings_adapter_timeout_desc'] = 'Maximum time allowed for a single Kortext REST call before the request is abandoned and marked status=failed. 1-60 range.';
$string['settings_sendpii'] = 'Send PII to Kortext';
$string['settings_sendpii_desc'] = 'When UNCHECKED (default secure), only a hashed user identifier is sent to Kortext. When CHECKED, first name, last name, and verified email are included to enhance the Kortext user profile. Keep OFF for zero-trust PII compliance.';

$string['settings_heading_lti'] = 'LTI 1.3 tool configuration';
$string['settings_heading_lti_desc'] = 'These values are plugged into the Moodle "External tool" configuration (Site Administration → Plugins → Activity modules → Manage tools → Configure a tool manually). They are also exposed via the adapter lti_tool_config() contract so admin UI screens can render copy-to-clipboard help text. Values are sourced from plugin settings first, then fall back to KORTEXT_LTI_* environment variables.';
$string['settings_lti_tool_name'] = 'LTI tool name (friendly)';
$string['settings_lti_tool_name_desc'] = 'Display name that appears to course editors when choosing the External tool provider. Kortext documentation recommends simply "Kortext".';
$string['settings_lti_tool_url'] = 'LTI tool URL (Base launch URL)';
$string['settings_lti_tool_url_desc'] = 'Kortext-supplied base tool URL. Per the Kortext LTI 1.3 Moodle Integration guide this is: https://vle.kortext.com';
$string['settings_lti_version'] = 'LTI version';
$string['settings_lti_version_desc'] = 'Should be set to LTI 1.3 to match Kortext platform capabilities.';
$string['settings_lti_jwks'] = 'Public Keyset (JWKS) URL';
$string['settings_lti_jwks_desc'] = 'URL where Kortext publishes the signing keys for validating LTI messages: https://vle.kortext.com/api/v1/lti/v1.3/jwks';
$string['settings_lti_initiate_login'] = 'Initiate Login URL (OIDC auth endpoint)';
$string['settings_lti_initiate_login_desc'] = 'OIDC third-party-initiated-login endpoint used by the Moodle platform to start an LTI 1.3 launch: https://vle.kortext.com/api/v1/lti/v1.3/auth';
$string['settings_lti_redirection'] = 'Redirection URI (Launch callback URL)';
$string['settings_lti_redirection_desc'] = 'URL that Kortext returns control to after a successful OIDC authentication flow: https://vle.kortext.com/api/v1/lti/v1.3/launch';
$string['settings_lti_deep_linking'] = 'Supports Deep Linking (Content Selection)';
$string['settings_lti_deep_linking_desc'] = 'Kortext recommends CHECKED. Enables the "Select content" button on External Tool instances to insert per-book, per-page, or HTML iFrame deep links automatically.';
$string['settings_lti_share_pii'] = 'Privacy: Always share launcher name and email';
$string['settings_lti_share_pii_desc'] = 'Kortext recommends CHECKED so each LTI launch includes the user\'s display name and email address, enabling the Kortext KLP admin to trace access events back to enrolled students/lecturers.';
$string['settings_lti_env_fallback_note'] = 'Any empty setting falls back to the corresponding KORTEXT_LTI_* environment variable, then to Kortext public documentation defaults.';
$string['field_lti_tool_summary'] = 'LTI tool summary (copy to configure Moodle External Tool)';
$string['field_lti_deploy_id'] = 'LTI deployment ID (share with Kortext after tool creation)';

$string['status_active'] = 'Active';
$string['status_archived'] = 'Archived';
$string['status_granted'] = 'Granted';
$string['status_failed'] = 'Failed';
$string['status_skipped'] = 'Skipped (idempotent)';
$string['health_ok'] = 'Service OK';
$string['health_degraded'] = 'Service degraded';
$string['health_offline'] = 'Service offline';
$string['graceful_banner_degraded'] = 'eTextbook service temporarily unavailable; showing offline fallback PDFs.';

$string['summary_adopted_isbns'] = 'Adopted ISBNs';
$string['summary_adopted_isbns_desc'] = 'All ISBNs with status = Active across Programme × Course × Semester.';
$string['summary_active_programmes'] = 'Active Programmes';
$string['summary_active_programmes_desc'] = 'Programmes that have at least one active adoption.';
$string['summary_entitled_24h'] = 'Users entitled (24h)';
$string['summary_entitled_24h_desc'] = 'New entitlement grants recorded by the last successful cron run in the past 24 hours.';
$string['summary_health'] = 'API Health';
$string['summary_health_desc'] = 'Last known health-check result from the Kortext adapter.';

$string['csvimport'] = 'Bulk import adoptions (CSV)';
$string['csvexport'] = 'Export filtered adoptions (CSV)';
$string['addadoption'] = 'Add adoption';
$string['editadoption'] = 'Edit adoption';
$string['archiveadoption'] = 'Archive adoption';
$string['archive_confirm'] = 'Are you sure you want to archive this adoption? Existing entitlements will not be revoked.';
$string['deleteadoption'] = 'Delete adoption';
$string['csvcol_programmecode'] = 'programmecode';
$string['csvcol_courseid'] = 'courseid';
$string['csvcol_sessioncode'] = 'sessioncode';
$string['csvcol_semestercode'] = 'semestercode';
$string['csvcol_levelcode'] = 'levelcode';
$string['csvcol_isbn'] = 'isbn';
$string['csvcol_ebookid'] = 'ebook_id';
$string['csvcol_status'] = 'status';
$string['csvcol_adoptedby'] = 'adopted_by_username';
$string['csvcol_createddate'] = 'created_date';
$string['csv_col_headers_required'] = 'The CSV file must contain headers: programmecode, courseid, semestercode, isbn.';
$string['csv_import_preview'] = 'CSV import preview (dry-run)';
$string['csv_import_created'] = 'Rows to create';
$string['csv_import_skipped'] = 'Rows to skip (duplicate)';
$string['csv_import_errors'] = 'Rows with validation errors';
$string['csv_import_finalize'] = 'Import {$a} valid rows';
$string['csv_import_done'] = 'CSV import completed: created {$a->created}, skipped {$a->skipped}, errors {$a->errors}.';

$string['field_programme'] = 'Programme';
$string['field_department'] = 'Department';
$string['field_college'] = 'College / Faculty';
$string['field_course'] = 'Moodle course';
$string['field_semester'] = 'Semester';
$string['field_session'] = 'Academic Session';
$string['field_level'] = 'Academic Level';
$string['field_level_all'] = 'All levels (wide)';
$string['field_session_all'] = 'Any academic session';
$string['field_isbn'] = 'ISBN (10 or 13 digits)';
$string['field_ebookid'] = 'Kortext e-book ID (optional)';
$string['field_deeplink'] = 'LTI deep-link URL (optional)';
$string['field_status'] = 'Status';
$string['filter_programme'] = 'Filter by programme';
$string['filter_department'] = 'Filter by department';
$string['filter_college'] = 'Filter by college';
$string['filter_semester'] = 'Filter by semester';
$string['filter_session'] = 'Filter by academic session';
$string['filter_level'] = 'Filter by academic level';
$string['filter_clear'] = 'Clear filters';
$string['field_level_invalid'] = 'Invalid academic level selected';
$string['field_session_invalid'] = 'Invalid academic session selected';

$string['isbn_invalid'] = 'ISBN validation failed: {$a}';
$string['isbn_invalid_tooshort'] = 'Too short (expecting 10 or 13 digits)';
$string['isbn_invalid_checksum'] = 'Checksum mismatch (not a valid ISBN-10 or ISBN-13)';
$string['isbn_invalid_chars'] = 'Contains non-numeric characters (hyphens and trailing X allowed for ISBN-10)';
$string['sesskey_invalid'] = 'Invalid session key, please reload the page and try again.';
$string['adoption_created'] = 'Adoption created.';
$string['adoption_updated'] = 'Adoption updated.';
$string['adoption_archived'] = 'Adoption archived.';
$string['adoption_exists'] = 'An adoption already exists for this Programme × Course × Semester × ISBN combination.';
$string['adoption_not_found'] = 'Adoption not found.';
$string['cap_manage_required'] = 'You do not have permission to manage textbook adoptions.';
$string['cap_settings_superadmin'] = 'Kortext settings are configurable only by site Super Admins.';

$string['cron_header'] = 'ULMS Kortext: adoption × entitlement sync';
$string['cron_acquiring_lock'] = 'Acquiring exclusive cron lock...';
$string['cron_locked_skip'] = 'Another sync run is active; exiting.';
$string['cron_lock_ok'] = 'Lock acquired.';
$string['cron_step_purge'] = 'Purging stale archived adoptions...';
$string['cron_step_collect'] = 'Collecting pending (adoption × enrolled user) pairs...';
$string['cron_pairs_count'] = 'Pending pairs: {$a}';
$string['cron_step_entitle'] = 'Processing entitlements via adapter...';
$string['cron_entitle_progress'] = 'Processed {$a->done}/{$a->total} (granted: {$a->granted}, failed: {$a->failed}, skipped: {$a->skipped})';
$string['cron_step_usage'] = 'Collecting daily usage snapshots...';
$string['cron_done'] = 'Sync complete.';
$string['cron_error_throwable'] = 'Unhandled sync error: {$a}';

$string['event_adoption_created'] = 'Adoption created';
$string['event_adoption_updated'] = 'Adoption updated';
$string['event_adoption_archived'] = 'Adoption archived';
$string['event_entitlement_granted'] = 'Kortext entitlement granted';
$string['event_entitlement_failed'] = 'Kortext entitlement failed';

$string['portal_etextbook_open'] = 'Open eTextbook';
$string['portal_etextbook_open_aria'] = 'Open adopted eTextbook (ISBN {$a->isbn}) for {$a->coursetitle} in a new window';
$string['portal_empty'] = 'No adopted eTextbooks';
$string['portal_empty_desc'] = 'eTextbooks will appear here once programme adoptions are published for your current semester courses.';
$string['portal_panel_subtitle_student'] = 'Institutional eTextbooks mapped to your enrolled courses.';
$string['portal_panel_subtitle_lecturer'] = 'Institutional eTextbooks adopted for your allocated courses this semester.';
$string['adoptions_page_title'] = 'Textbook Adoptions';
$string['adoptions_page_meta'] = 'Manage programme-level eTextbook ISBN adoptions, review entitlements, and inspect API health.';
$string['adoptions_list_empty'] = 'No adoptions found';
$string['adoptions_list_empty_desc'] = 'Use "Add adoption" or CSV bulk import to map ISBNs to Programme × Course × Semester.';
$string['list_col_programme'] = 'Programme';
$string['list_col_course'] = 'Course';
$string['list_col_session'] = 'Academic Session';
$string['list_col_semester'] = 'Semester';
$string['list_col_level'] = 'Academic Level';
$string['list_col_isbn'] = 'ISBN';
$string['list_col_status'] = 'Status';
$string['list_col_adoptedby'] = 'Adopted by';
$string['list_col_actions'] = 'Actions';
$string['action_edit'] = 'Edit';
$string['action_archive'] = 'Archive';
$string['action_view'] = 'View';
$string['nav_adoptions'] = 'Adoptions';
$string['nav_adoptions_desc'] = 'Programme-level eTextbook adoption administration';
$string['field_isbn_placeholder'] = 'e.g. 9780262533051';
$string['filter_search_placeholder'] = 'Search ISBN, course code, programme...';
