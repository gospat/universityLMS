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

namespace local_ulms_kortext\local\service;

defined('MOODLE_INTERNAL') || die();

/**
 * Adoption × entitlement sync orchestration.
 *
 * The direction of sync (ULMS → Kortext vs Kortext → ULMS) is confined to
 * a SINGLE method, sync_once(), to satisfy AC-5 (adding a pull branch in
 * the future only requires changes inside this one method).
 *
 * @package local_ulms_kortext
 */
class adoption_sync_service {

    /**
     * ULMS is the single source of truth for adoption data; we PUSH entitlement
     * grants to Kortext. This is the MVP / default direction.
     */
    public const DIRECTION_ULMS_PUSH = 1;

    /**
     * Reserved for future: pull adoption data from Kortext and write to ULMS.
     * Not implemented in the MVP; present here so the direction switch lives
     * in exactly ONE method (AC-5).
     */
    public const DIRECTION_KORTEXT_PULL = 2;

    /**
     * Runs the adoption × entitlement sync once.
     *
     * All directional branching is contained here. Future pull-mode requires
     * no changes to any other class.
     *
     * @param int $direction One of the DIRECTION_* constants
     * @return array{granted: int, failed: int, skipped: int, usage_rows: int, errors: array<int, string>}
     */
    public function sync_once(int $direction = self::DIRECTION_ULMS_PUSH): array {
        $res = [
            'granted'    => 0,
            'failed'     => 0,
            'skipped'    => 0,
            'usage_rows' => 0,
            'errors'     => [],
        ];
        if ($direction === self::DIRECTION_ULMS_PUSH) {
            $this->purge_stale_archived();
            $pairs = $this->collect_pending_pairs();
            $out = $this->grant_entitlements($pairs);
            $res['granted'] = $out['granted'];
            $res['failed']  = $out['failed'];
            $res['skipped'] = $out['skipped'];
            foreach ($out['errors'] as $_e) {
                $res['errors'][] = $_e;
            }
            $res['usage_rows'] = $this->collect_usage_snapshots();
            return $res;
        }
        // DIRECTION_KORTEXT_PULL reserved for future implementation — no-op today.
        $res['errors'][] = 'direction-' . $direction . '-not-implemented';
        return $res;
    }

    /**
     * Hard-deletes any adoption rows that have been archived for >= 1 year.
     *
     * Entitlement logs remain (immutable audit trail per NFR-9).
     *
     * @return void
     */
    private function purge_stale_archived(): void {
        global $DB;
        $cutoff = time() - 86400 * 365;
        try {
            $DB->delete_records_select(
                'local_ulms_kortext_adoptions',
                "status = 'archived' AND timemodified < :cut",
                ['cut' => $cutoff]
            );
        } catch (\Throwable) {
            return;
        }
    }

    /**
     * Returns (userid, adoptionid) pairs for every ACTIVE adoption where the
     * user is enrolled in the adoption's Moodle course AND no prior
     * entitlement log row exists (granted OR skipped) for the same pair.
     *
     * The collection JOINs directly to the Moodle user_enrolments table
     * rather than relying on snapshot caches.
     *
     * @return array<int, array{adoptionid: int, userid: int, ebook_id: string, deeplink_url: ?string}>
     */
    private function collect_pending_pairs(): array {
        global $DB;
        $sql = "SELECT a.id AS adoptionid, ue.userid, a.ebook_id, a.deeplink_url
                  FROM {local_ulms_kortext_adoptions} a
                  JOIN {enrol} e ON e.courseid = a.moodlecourseid
                  JOIN {user_enrolments} ue ON ue.enrolid = e.id
                  JOIN {user} u ON u.id = ue.userid AND u.deleted = 0 AND u.suspended = 0
             LEFT JOIN {local_ulms_kortext_entitlement_log} el
                       ON el.adoptionid = a.id AND el.userid = ue.userid
                 WHERE a.status = 'active'
                       AND (u.email IS NOT NULL AND u.email <> '')
                       AND el.id IS NULL";
        $rs = $DB->get_recordset_sql($sql);
        $out = [];
        foreach ($rs as $_r) {
            $out[] = [
                'adoptionid'    => (int)$_r->adoptionid,
                'userid'        => (int)$_r->userid,
                'ebook_id'      => (string)($_r->ebook_id ?? ''),
                'deeplink_url'  => !empty($_r->deeplink_url) ? (string)$_r->deeplink_url : null,
            ];
        }
        $rs->close();
        return $out;
    }

    /**
     * Builds the idempotency key string for a (user, adoption) pair.
     *
     * @param int $userid
     * @param int $adoptionid
     * @return string
     */
    private function idempotency_key(int $userid, int $adoptionid): string {
        return hash('sha256', 'ulms.ktx.entitlement.v1|' . $userid . '|' . $adoptionid);
    }

    /**
     * Sends batch entitle() calls to the adapter and writes log rows.
     *
     * @param array<int, array{adoptionid: int, userid: int, ebook_id: string, deeplink_url: ?string}> $pairs
     * @return array{granted: int, failed: int, skipped: int, errors: array<int, string>}
     */
    private function grant_entitlements(array $pairs): array {
        global $DB, $CFG;
        $out = ['granted' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => []];
        if (count($pairs) === 0) {
            return $out;
        }
        $adapter = adapter\kortext_adapter_factory::get_instance();
        $sendpii = (bool)get_config('local_ulms_kortext', 'sendpii');
        foreach ($pairs as $_pair) {
            $aid = $_pair['adoptionid'];
            $uid = $_pair['userid'];
            $idk = $this->idempotency_key($uid, $aid);
            try {
                $exists = $DB->record_exists('local_ulms_kortext_entitlement_log', ['idempotency_key' => $idk]);
                if ($exists) {
                    $out['skipped']++;
                    continue;
                }
            } catch (\Throwable) {
                $out['skipped']++;
                continue;
            }
            $pii = [];
            if ($sendpii) {
                try {
                    $u = $DB->get_record('user', ['id' => $uid], 'id, firstname, lastname, email');
                    if ($u) {
                        $pii['firstname'] = (string)$u->firstname;
                        $pii['lastname']  = (string)$u->lastname;
                        $pii['email']     = (string)$u->email;
                    }
                } catch (\Throwable) {
                    $pii = [];
                }
            }
            $ebook_id = $_pair['ebook_id'] !== '' ? $_pair['ebook_id'] : ('ISBN-' . $DB->get_field('local_ulms_kortext_adoptions', 'isbn', ['id' => $aid]));
            $result = null;
            try {
                $result = $adapter->entitle($uid, $ebook_id, $pii, $idk);
            } catch (\Throwable) {
                $result = ['ok' => false, 'error' => 'entitle-throwable'];
            }
            $status = !empty($result['ok']) ? 'granted' : 'failed';
            $record = (object)[
                'adoptionid'       => $aid,
                'userid'           => $uid,
                'idempotency_key'  => $idk,
                'remote_ktx_id'    => !empty($result['remote_id']) ? (string)$result['remote_id'] : null,
                'status'           => $status,
                'http_code'        => isset($result['http_code']) ? (int)$result['http_code'] : null,
                'error_msg'        => !empty($result['error']) ? (string)$result['error'] : null,
                'timecreated'      => time(),
            ];
            try {
                $DB->insert_record_raw('local_ulms_kortext_entitlement_log', $record, false, true, false);
            } catch (\Throwable) {
                $out['skipped']++;
                continue;
            }
            if ($status === 'granted') {
                $out['granted']++;
                if (class_exists('\local_ulms_kortext\event\entitlement_granted')) {
                    /** @var mixed $ctx */
                    $ctx = \context_system::instance();
                    try {
                        $ev = \local_ulms_kortext\event\entitlement_granted::create([
                            'objectid' => $aid,
                            'context'  => $ctx,
                            'userid'   => $uid,
                            'relateduserid' => $uid,
                            'other'    => ['remote_ktx_id' => (string)($record->remote_ktx_id ?? '')],
                        ]);
                        $ev->trigger();
                    } catch (\Throwable) {
                        // event failure must not break the sync batch.
                    }
                }
            } else {
                $out['failed']++;
                if (!empty($result['error'])) {
                    $out['errors'][] = (string)$result['error'];
                }
                if (class_exists('\local_ulms_kortext\event\entitlement_failed')) {
                    /** @var mixed $ctx */
                    $ctx = \context_system::instance();
                    try {
                        $ev = \local_ulms_kortext\event\entitlement_failed::create([
                            'objectid' => $aid,
                            'context'  => $ctx,
                            'userid'   => $uid,
                            'relateduserid' => $uid,
                            'other'    => ['error' => (string)($record->error_msg ?? '')],
                        ]);
                        $ev->trigger();
                    } catch (\Throwable) {
                        // Intentionally swallowed.
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Fetches yesterday's usage snapshots per ACTIVE adoption via the adapter
     * and upserts rows into the usage_snapshot table.
     *
     * @return int Number of rows written (or updated).
     */
    private function collect_usage_snapshots(): int {
        global $DB;
        $adapter = adapter\kortext_adapter_factory::get_instance();
        $adoptions = $DB->get_records('local_ulms_kortext_adoptions', ['status' => 'active'], '', 'id, ebook_id, isbn');
        $yesterday_start = (int)gmmktime(0, 0, 0, (int)gmdate('n'), (int)gmdate('j') - 1, (int)gmdate('Y'));
        $yesterday_end   = $yesterday_start + 86399;
        $written = 0;
        foreach ($adoptions as $_a) {
            $ebook = !empty($_a->ebook_id) ? (string)$_a->ebook_id : ('ISBN-' . $_a->isbn);
            try {
                $rows = $adapter->usage_daily((int)$_a->id, $ebook, $yesterday_start, $yesterday_end);
            } catch (\Throwable) {
                continue;
            }
            foreach ($rows as $_r) {
                $day = (int)($_r['snapshot_date'] ?? 0);
                if ($day <= 0) {
                    continue;
                }
                $existing = $DB->get_record('local_ulms_kortext_usage_snapshot', [
                    'adoptionid'    => (int)$_a->id,
                    'snapshot_date' => $day,
                ]);
                $now = time();
                if ($existing) {
                    $existing->unique_users = (int)($_r['unique_users'] ?? 0);
                    $existing->opens_count  = (int)($_r['opens_count'] ?? 0);
                    $existing->timemodified = $now;
                    $DB->update_record('local_ulms_kortext_usage_snapshot', $existing);
                } else {
                    $DB->insert_record('local_ulms_kortext_usage_snapshot', (object)[
                        'adoptionid'    => (int)$_a->id,
                        'snapshot_date' => $day,
                        'unique_users'  => (int)($_r['unique_users'] ?? 0),
                        'opens_count'   => (int)($_r['opens_count'] ?? 0),
                        'timecreated'   => $now,
                        'timemodified'  => $now,
                    ]);
                }
                $written++;
            }
        }
        return $written;
    }
}
