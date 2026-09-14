<?php
require_once(__DIR__ . '/../../config.php');

use local_ulms_exam\local\service\exam_service;

global $DB, $USER;

require_login();
$context = \context::instance_by_id(\context_system::instance()->id);
require_capability('local/ulms_exam:takeany', $context);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header($_SERVER['SERVER_PROTOCOL'] . ' 405 Method Not Allowed');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}
if (!confirm_sesskey()) {
    header($_SERVER['SERVER_PROTOCOL'] . ' 400 Bad Request');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'sesskey invalid']);
    exit;
}
$submissionid = max(0, (int)required_param('submissionid', PARAM_INT));
$answersraw = optional_param('answers', '[]', PARAM_RAW);
$answers = json_decode($answersraw, true);
if (!is_array($answers)) {
    $answers = [];
}
$svc = exam_service::instance();
$saved = $svc->autosave_answers((int)$USER->id, $submissionid, $answers);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'saved' => $saved]);
