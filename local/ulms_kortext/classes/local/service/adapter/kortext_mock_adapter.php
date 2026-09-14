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

namespace local_ulms_kortext\local\service\adapter;

defined('MOODLE_INTERNAL') || die();

/**
 * Offline deterministic mock adapter.
 *
 * Emulates Kortext API behaviour without any network calls so the entire
 * Admin UI, Materials injection panel, and cron workflow can operate and
 * be acceptance-tested before live OAuth2 credentials are issued.
 *
 * Simulated errors: pass $ebook_id = 'FAIL-ME' to trigger a fake failure;
 * pass $ebook_id = 'THROW-ME' to force a Throwable inside entitle() to
 * verify graceful degradation (AC-6).
 *
 * @package local_ulms_kortext
 */
class kortext_mock_adapter implements kortext_adapter_interface {

    use kortext_reads_lti_config;

    /**
     * Absolute path to the mock call log (under $CFG->dataroot/temp).
     *
     * @var string|null
     */
    private ?string $logpath = null;

    /**
     * Switch for health() failure mode (used by AC-6 degradation tests).
     *
     * Callers SHOULD NOT rely on this in production; it is mutated ONLY by
     * task-level integration tests.
     *
     * @var bool
     */
    public bool $force_health_fail = false;

    /**
     * Resolves a directory and log file path under $CFG->dataroot/temp.
     *
     * @return string
     */
    private function get_log_path(): string {
        global $CFG;
        if ($this->logpath !== null) {
            return $this->logpath;
        }
        $dir = $CFG->dataroot . '/temp/local_ulms_kortext';
        if (!is_dir($dir)) {
            @mkdir($dir, $CFG->directorypermissions ?? 0755, true);
        }
        $this->logpath = $dir . '/mock_adapter.log';
        if (!file_exists($this->logpath)) {
            @touch($this->logpath);
        }
        return $this->logpath;
    }

    /**
     * Appends a structured JSON line to the mock call log.
     *
     * @param string $method
     * @param array<string, mixed> $payload
     * @return void
     */
    private function append_log(string $method, array $payload): void {
        try {
            $line = json_encode([
                'ts'      => date('c'),
                'method'  => $method,
                'payload' => $payload,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($line === false) {
                return;
            }
            $fh = @fopen($this->get_log_path(), 'a');
            if (!$fh) {
                return;
            }
            fwrite($fh, $line . PHP_EOL);
            fclose($fh);
        } catch (\Throwable) {
            return;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function health(): array {
        $start = hrtime(true);
        if ($this->force_health_fail) {
            return [
                'ok'         => false,
                'latency_ms' => (int)((hrtime(true) - $start) / 1e6),
                'error'      => 'mock-forced-health-fail',
            ];
        }
        usleep(random_int(20000, 50000));
        return [
            'ok'         => true,
            'latency_ms' => (int)((hrtime(true) - $start) / 1e6),
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function entitle(int $userid, string $ebook_id, array $user_pii_context = [], string $idempotency_key = ''): array {
        if ($ebook_id === 'THROW-ME') {
            throw new \RuntimeException('mock-simulated-throwable');
        }
        $this->append_log('entitle', [
            'userid'         => $userid,
            'ebook_id'       => $ebook_id,
            'pii_keys'       => array_keys($user_pii_context),
            'idempotency'    => $idempotency_key,
        ]);
        if ($ebook_id === 'FAIL-ME') {
            return [
                'ok'        => false,
                'http_code' => 422,
                'error'     => 'mock-simulated-entitlement-rejected',
            ];
        }
        $remote = 'MOCK-KTX-' . substr(hash('sha256', (string)$userid . '|' . $ebook_id . '|' . $idempotency_key), 0, 24);
        return [
            'ok'        => true,
            'remote_id' => $remote,
            'http_code' => 201,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function list_entitlements(int $adoptionid, string $ebook_id, array $filter = []): array {
        $this->append_log('list_entitlements', ['adoptionid' => $adoptionid, 'ebook_id' => $ebook_id]);
        $limit = (int)($filter['limit'] ?? 10);
        $limit = max(1, min($limit, 50));
        $out = [];
        for ($i = 1; $i <= $limit; $i++) {
            $out[] = [
                'userid'      => 10000 + $i,
                'remote_id'   => 'MOCK-LIST-' . substr(hash('sha256', (string)$adoptionid . '|' . $i), 0, 16),
                'granted_at'  => time() - 86400 * $i,
            ];
        }
        return $out;
    }

    /**
     * {@inheritDoc}
     */
    public function usage_daily(int $adoptionid, string $ebook_id, int $fromts, int $tots): array {
        $this->append_log('usage_daily', ['adoptionid' => $adoptionid, 'ebook_id' => $ebook_id, 'from' => $fromts, 'to' => $tots]);
        $out = [];
        $day = strtotime('today 00:00:00 UTC', $fromts);
        $end = max($day, $tots);
        while ($day <= $end) {
            $out[] = [
                'snapshot_date' => $day,
                'unique_users'  => random_int(5, 30),
                'opens_count'   => random_int(10, 120),
            ];
            $day += 86400;
        }
        return $out;
    }
}
