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

[$options, $unrecognized] = cli_get_params([
    'to' => null,
    'subject' => 'ULMS Resend transport test',
    'help' => false,
], [
    'h' => 'help',
]);

if (!empty($unrecognized) || !empty($options['help']) || empty($options['to'])) {
    $help = <<<EOF
Send a test email through the active ULMS Resend transport.

Options:
  --to=EMAIL           Recipient email address (required)
  --subject="TEXT"    Message subject (optional)
  -h, --help           Show this help

Example:
  php local/ulms_mail/cli/send_test_email.php --to=test@example.com
EOF;

    cli_writeln($help);
    exit(empty($options['help']) ? 1 : 0);
}

if (($CFG->ulmsmailtransport ?? 'moodle') !== 'resend') {
    cli_error('ULMS mail transport is not set to resend.', 1);
}

if (!validate_email((string)$options['to'])) {
    cli_error('Recipient email is invalid.', 1);
}

$site = get_site();
$service = new \local_ulms_mail\local\service\resend_mail_service();
$subject = trim((string)$options['subject']);
$bodytext = "This is a ULMS Resend transport test email from " . format_string($site->fullname) . '.';
$bodyhtml = text_to_html($bodytext, false, false, true);
$replyto = !empty($CFG->ulmsreplyto) ? (string)$CFG->ulmsreplyto : (string)($CFG->supportemail ?? '');
$replytoname = !empty($CFG->supportname) ? (string)$CFG->supportname : format_string($site->shortname);

$result = $service->send_transactional_email([
    'to' => [[
        'email' => (string)$options['to'],
        'name' => 'ULMS Mail Test',
    ]],
    'subject' => $subject !== '' ? $subject : 'ULMS Resend transport test',
    'text' => $bodytext,
    'html' => $bodyhtml,
    'replyto' => $replyto !== '' ? [[
        'email' => $replyto,
        'name' => $replytoname,
    ]] : [],
    'idempotencykey' => sha1('ulms_mail_test|' . (string)$options['to'] . '|' . date('YmdHi')),
]);

if (empty($result['success'])) {
    $error = trim((string)($result['error'] ?? 'Unknown error'));
    $status = $result['statuscode'] ?? 'n/a';
    cli_error('Send failed. status=' . $status . ' error=' . $error, 1);
}

cli_writeln('Send succeeded. messageid=' . (string)($result['messageid'] ?? ''));
