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
    'to'      => null,
    'subject' => 'ULMS Resend transport test',
    'from'    => null,
    'help'    => false,
], [
    'h' => 'help',
    't' => 'to',
    's' => 'subject',
    'f' => 'from',
]);

if (!empty($unrecognized) || !empty($options['help']) || empty($options['to'])) {
    $help = <<<'EOF'
Send a test email through the active ULMS Resend transport and print
diagnostics with every CFG setting that matters for delivery.

Options:
  --to=EMAIL             Recipient email address (required)
  --subject="TEXT"       Message subject (optional)
  --from="Name <email>"  Override Resend sender (optional; default = CFG->resendfromemail/name)
  -h, --help             Show this help

Environment overrides (useful on a developer laptop where APP_ENV=local):
  ULMS_MAIL_TRANSPORT=resend     Enables Resend API transport
  RESEND_API_KEY=re_xxx          Your Resend production/test API key
  RESEND_FROM_EMAIL=a@b.com      Verified sender domain in Resend
  RESEND_FROM_NAME="ULMS"        Sender display name
  ULMS_FORCE_EMAIL=1             Override CFG->noemailever for CLI-only send attempts

Example (APP_ENV=local + force delivery test):
  ULMS_MAIL_TRANSPORT=resend \
  RESEND_API_KEY=re_your_real_key \
  RESEND_FROM_EMAIL="no-reply@your-verified-domain.com" \
  RESEND_FROM_NAME="ULMS Support" \
  ULMS_FORCE_EMAIL=1 \
  php local/ulms_mail/cli/send_test_email.php --to=your.email@example.com

Diagnostic-only (does NOT send; prints all CFG values + key-format validity):
  ULMS_MAIL_TRANSPORT=resend php local/ulms_mail/cli/send_test_email.php --diagnose
EOF;
    cli_writeln($help);
    exit(empty($options['help']) ? 1 : 0);
}

$transport = (string)($CFG->ulmsmailtransport ?? 'moodle');

// Pre-flight diagnostics: build structured validation BEFORE trying to send so
// the user gets actionable "FIX THIS FIRST" messages in one go.
$checks = [];

$checks['ulmsmailtransport'] = [
    'label'   => 'ULMS mail transport',
    'value'   => $transport,
    'ok'      => $transport === 'resend',
    'hint'    => "Set env ULMS_MAIL_TRANSPORT=resend. Current CFG->ulmsmailtransport is '{$transport}'.",
];

$apikey = trim((string)($CFG->resendapikey ?? ''));
$keyValid = $apikey !== '' && \local_ulms_mail\local\service\resend_mail_service::is_valid_resend_api_key($apikey);
$checks['resendapikey'] = [
    'label'   => 'CFG->resendapikey (RESEND_API_KEY)',
    'value'   => $apikey === '' ? '(EMPTY)' : strlen($apikey) . ' chars, prefix=' . substr($apikey, 0, 4),
    'ok'      => $keyValid,
    'hint'    => 'Set env RESEND_API_KEY=re_... (starts "re_" and is >= 40 chars long).',
];

$fromEmail = trim((string)($options['from'] ?? ''));
$senderEmail = trim((string)($CFG->resendfromemail ?? ''));
$senderName  = trim((string)($CFG->resendfromname ?? ''));
if ($fromEmail !== '') {
    if (preg_match('/^\s*(?:([^<]+)\s+)?<([^>]+)>\s*$/', $fromEmail, $m)) {
        $senderName  = $m[1] !== '' ? trim($m[1]) : $senderName;
        $senderEmail = trim($m[2]);
    } else {
        $senderEmail = trim($fromEmail);
    }
}
$checks['resendfromemail'] = [
    'label'   => 'CFG->resendfromemail (RESEND_FROM_EMAIL)',
    'value'   => $senderEmail !== '' ? $senderEmail : '(EMPTY)',
    'ok'      => $senderEmail !== '' && validate_email($senderEmail),
    'hint'    => 'Set env RESEND_FROM_EMAIL= to an address you verified in the Resend dashboard Senders page.',
];
$checks['resendfromname'] = [
    'label'   => 'CFG->resendfromname (RESEND_FROM_NAME)',
    'value'   => $senderName !== '' ? $senderName : '(EMPTY; will fall back to CFG->supportname)',
    'ok'      => true, // name is optional (falls back to supportname in build_sender())
    'hint'    => null,
];
$checks['smtphosts_legacy'] = [
    'label'   => 'Legacy Moodle SMTP fallback (CFG->smtphosts)',
    'value'   => !empty($CFG->smtphosts) ? (string)$CFG->smtphosts : '(EMPTY)',
    'ok'      => !empty($CFG->smtphosts),
    'hint'    => "On Resend branch, config.php must set CFG->smtphosts='smtp.resend.com:587' for legacy Moodle emails.",
];
$checks['smtpuser_legacy'] = [
    'label'   => 'Legacy Moodle SMTP fallback (CFG->smtpuser)',
    'value'   => !empty($CFG->smtpuser) ? (string)$CFG->smtpuser : '(EMPTY)',
    'ok'      => !empty($CFG->smtpuser),
    'hint'    => "On Resend branch, CFG->smtpuser must be 'resend'.",
];
$checks['smtppass_legacy'] = [
    'label'   => 'Legacy Moodle SMTP fallback (CFG->smtppass)',
    'value'   => empty($CFG->smtppass) ? '(EMPTY)' : strlen((string)$CFG->smtppass) . ' chars',
    'ok'      => !empty($CFG->smtppass),
    'hint'    => 'CFG->smtppass must equal RESEND_API_KEY so legacy enrol/reset emails route through Resend.',
];
$checks['noemailever'] = [
    'label'   => 'CFG->noemailever (email suppression)',
    'value'   => !empty($CFG->noemailever) ? 'ON (ALL EMAILS SUPPRESSED)' : 'off',
    'ok'      => empty($CFG->noemailever),
    'hint'    => 'Set APP_ENV=production or pass ULMS_FORCE_EMAIL=1 for CLI overrides to enable delivery.',
];

$recipient = trim((string)$options['to']);
$checks['recipient'] = [
    'label'   => 'Recipient email',
    'value'   => $recipient,
    'ok'      => validate_email($recipient),
    'hint'    => 'Provide --to= with a valid RFC-5322 address.',
];

// Pretty diagnostic table.
$anyFail = false;
cli_writeln('ULMS Resend pre-flight diagnostics');
cli_writeln(str_repeat('=', 72));
foreach ($checks as $k => $c) {
    $status = $c['ok'] ? '✅ PASS' : '❌ FAIL';
    if (!$c['ok']) {
        $anyFail = true;
    }
    cli_writeln(sprintf('%-2s %-36s %s', $status, $c['label'], $c['value']));
    if (!$c['ok'] && is_string($c['hint'] ?? null)) {
        cli_writeln(sprintf('     hint: %s', wordwrap($c['hint'], 68, "\n           ")));
    }
}
cli_writeln(str_repeat('=', 72));

if ($anyFail) {
    cli_writeln('');
    cli_writeln('Aborting send attempt: fix FAIL items above first, then rerun.');
    cli_writeln('Tip (dev laptop): set ULMS_FORCE_EMAIL=1 to bypass noemailever for only-this-run delivery.');
    exit(2);
}

if (!validate_email($recipient)) {
    cli_error('Recipient email is invalid.', 1);
}

// Transport gate is guaranteed now by $checks['ulmsmailtransport']['ok'].
$site = get_site();
$service = new \local_ulms_mail\local\service\resend_mail_service();
$subject = trim((string)$options['subject']);
if ($subject === '') {
    $subject = 'ULMS Resend transport test';
}
$bodytext = "This is a ULMS Resend transport test email from " . format_string($site->fullname) . ".\n"
    . "Sent at " . date('r') . " via resend_mail_service::send_transactional_email().\n"
    . "Sender: {$senderName} <{$senderEmail}>\n"
    . "Recipient: {$recipient}\n"
    . "Transport: {$transport}\n";
$bodyhtml = text_to_html($bodytext, false, false, true);
$replyto = !empty($CFG->ulmsreplyto) ? (string)$CFG->ulmsreplyto : (string)($CFG->supportemail ?? '');
$replytoname = !empty($CFG->supportname) ? (string)$CFG->supportname : format_string($site->shortname);

$result = $service->send_transactional_email([
    'to' => [[
        'email' => $recipient,
        'name'  => 'ULMS Mail Test Recipient',
    ]],
    'subject' => $subject,
    'text'    => $bodytext,
    'html'    => $bodyhtml,
    'from'    => ['email' => $senderEmail, 'name' => $senderName],
    'replyto' => $replyto !== '' ? [[
        'email' => $replyto,
        'name'  => $replytoname,
    ]] : [],
    'idempotencykey' => sha1('ulms_mail_test|' . $recipient . '|' . date('YmdHi')),
]);

if (empty($result['success'])) {
    $error  = trim((string)($result['error'] ?? 'Unknown error'));
    $status = $result['statuscode'] ?? 'n/a';
    $body   = !empty($result['body']) ? (string)$result['body'] : '';
    $bodySnippet = $body !== '' ? "\nresponse_body_snippet: " . substr($body, 0, 400) : '';
    cli_error('Send failed. status=' . $status . ' error=' . $error . $bodySnippet, 1);
}

$msgid = (string)($result['messageid'] ?? '');
$status = (string)($result['statuscode'] ?? '200');
cli_writeln('✅ Send succeeded.');
cli_writeln('  message_id    : ' . $msgid);
cli_writeln('  http_status   : ' . $status);
cli_writeln('  from          : ' . $senderName . ' <' . $senderEmail . '>');
cli_writeln('  to            : ' . $recipient);
cli_writeln('  subject       : ' . $subject);
cli_writeln('  delivered_in  : Check recipient inbox; Resend may queue for 5-30 seconds.');
cli_writeln('');
cli_writeln('Next step to verify FULL legacy pipeline: use Moodle admin/testoutgoingmailconf.php');
cli_writeln('to send an email_to_user() test — that exercises the smtp.resend.com:587 legacy fallback path.');
