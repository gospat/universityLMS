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

namespace local_ulms_auth\local\service\ratelimit;

defined('MOODLE_INTERNAL') || die();

/**
 * File-based counter store used as a bounded fallback when Redis is unavailable.
 *
 * The original implementation performed a lockless read-modify-write via rename, which
 * could lose updates under concurrency. This implementation serialises every update
 * with an exclusive advisory lock (flock) and guarantees expiry through a stored window
 * start, so a restriction can never become permanent. The directory is periodically
 * pruned so counters cannot grow without bound.
 *
 * @package    local_ulms_auth
 * @copyright  2026 Bells University of Technology
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class file_counter_store implements counter_store {
    /** Fallback directory beneath the dataroot cache. */
    private const SUBDIR = 'ulms_login_rl';

    /** Counters older than this many seconds are pruned. */
    private const PRUNE_AGE = 86400;

    /** Hard ceiling on stored counter files, enforced by age-based pruning. */
    private const MAX_FILES = 20000;

    /** @var string|null */
    private ?string $dir;

    /**
     * Constructor.
     *
     * @param string|null $dir Explicit directory, or null to derive from the dataroot.
     */
    public function __construct(?string $dir = null) {
        $this->dir = $dir ?? self::resolve_dir();
    }

    /**
     * Builds an instance from the dataroot.
     *
     * @return self
     */
    public static function from_config(): self {
        return new self();
    }

    /**
     * Resolves and prepares the counter directory.
     *
     * @return string|null
     */
    private static function resolve_dir(): ?string {
        global $CFG;
        $base = rtrim((string)($CFG->dataroot ?? ''), '/\\');
        if ($base === '') {
            return null;
        }
        $dir = $base . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . self::SUBDIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            return null;
        }
        return $dir;
    }

    /**
     * Returns the file path for a logical key.
     *
     * @param string $key
     * @return string|null
     */
    private function path(string $key): ?string {
        if ($this->dir === null) {
            return null;
        }
        return $this->dir . DIRECTORY_SEPARATOR . sha1($key) . '.json';
    }

    /**
     * Atomically increments a counter under an exclusive lock.
     *
     * @param string $key
     * @param int $windowseconds
     * @return array{count:int,ttl:int}|null
     */
    public function hit(string $key, int $windowseconds): ?array {
        $path = $this->path($key);
        if ($path === null) {
            return null;
        }
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return null;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return null;
            }
            $now = time();
            $state = $this->read_locked($handle, $windowseconds, $now);
            $state['count']++;
            $this->write_locked($handle, $state);

            return [
                'count' => $state['count'],
                'ttl' => max(0, $state['start'] + $windowseconds - $now),
            ];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Reads the current value and remaining lifetime without incrementing.
     *
     * @param string $key
     * @param int $windowseconds
     * @return array{count:int,ttl:int}|null
     */
    public function inspect(string $key, int $windowseconds): ?array {
        $path = $this->path($key);
        if ($path === null) {
            return null;
        }
        if (!is_file($path)) {
            return ['count' => 0, 'ttl' => 0];
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return ['count' => 0, 'ttl' => 0];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['count' => 0, 'ttl' => 0];
        }
        $now = time();
        $start = (int)($data['start'] ?? $now);
        $count = (int)($data['count'] ?? 0);
        if (($now - $start) >= $windowseconds || $start > $now) {
            return ['count' => 0, 'ttl' => 0];
        }
        return [
            'count' => max(0, $count),
            'ttl' => max(0, $start + $windowseconds - $now),
        ];
    }

    /**
     * Deletes a counter.
     *
     * @param string $key
     * @return bool
     */
    public function clear(string $key): bool {
        $path = $this->path($key);
        if ($path === null) {
            return false;
        }
        if (is_file($path)) {
            @unlink($path);
        }
        return true;
    }

    /**
     * Releases one admission reservation under an exclusive lock, clamped at zero.
     *
     * The read-modify-write happens entirely under flock(), so two concurrent releases
     * cannot lose an update or drive the counter negative. The window start is preserved
     * so the reservation keeps expiring with the window it was created under, and a key
     * that does not exist or whose window has already elapsed is a successful no-op:
     * releasing a reservation that has gone must never fail the request, and this method
     * never touches the failure ledger because that is a separate key.
     *
     * @param string $key
     * @param int $windowseconds
     * @return bool
     */
    public function release(string $key, int $windowseconds): bool {
        $path = $this->path($key);
        if ($path === null) {
            return false;
        }
        if (!is_file($path)) {
            return true;
        }
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return false;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return false;
            }
            rewind($handle);
            $raw = stream_get_contents($handle);
            $data = $raw !== false ? json_decode($raw, true) : null;
            if (!is_array($data)) {
                return true;
            }
            $now = time();
            $start = (int)($data['start'] ?? $now);
            $count = (int)($data['count'] ?? 0);
            if (($now - $start) >= $windowseconds || $start > $now || $count <= 0) {
                return true;
            }
            $this->write_locked($handle, ['start' => $start, 'count' => $count - 1]);
            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Reports availability of the fallback directory.
     *
     * @return bool
     */
    public function is_available(): bool {
        return $this->dir !== null;
    }

    /**
     * Backend identifier.
     *
     * @return string
     */
    public function name(): string {
        return 'file';
    }

    /**
     * The file store never withholds a runtime failure reason.
     *
     * A missing or unwritable directory is reported once, synthetically, so the limiter
     * can log the same triage contract it uses for Redis. Individual file operations
     * that fail are already surfaced to the caller as a null/false return.
     *
     * @return string|null
     */
    public function last_failure(): ?string {
        return $this->dir === null ? 'directory_unavailable' : null;
    }

    /**
     * Reads the counter state under the held lock, resetting an expired window.
     *
     * @param resource $handle
     * @param int $windowseconds
     * @param int $now
     * @return array{start:int,count:int}
     */
    private function read_locked($handle, int $windowseconds, int $now): array {
        rewind($handle);
        $raw = stream_get_contents($handle);
        $data = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return ['start' => $now, 'count' => 0];
        }
        $start = (int)($data['start'] ?? $now);
        if (($now - $start) >= $windowseconds || $start > $now) {
            return ['start' => $now, 'count' => 0];
        }
        return ['start' => $start, 'count' => max(0, (int)($data['count'] ?? 0))];
    }

    /**
     * Writes the counter state under the held lock.
     *
     * @param resource $handle
     * @param array{start:int,count:int} $state
     * @return void
     */
    private function write_locked($handle, array $state): void {
        $payload = json_encode(['start' => $state['start'], 'count' => $state['count']], JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return;
        }
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $payload);
        fflush($handle);
    }

    /**
     * Removes stale counters so the fallback directory cannot grow without bound.
     *
     * @return void
     */
    public function prune(): void {
        if ($this->dir === null) {
            return;
        }
        $cutoff = time() - self::PRUNE_AGE;
        $files = @glob($this->dir . DIRECTORY_SEPARATOR . '*.json');
        if (!is_array($files)) {
            return;
        }
        $count = count($files);
        foreach ($files as $index => $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $cutoff) {
                @unlink($file);
                continue;
            }
            // Hard ceiling: when over the limit, drop the oldest beyond the cutoff.
            if ($count > self::MAX_FILES && $index < ($count - self::MAX_FILES)) {
                @unlink($file);
            }
        }
    }
}
