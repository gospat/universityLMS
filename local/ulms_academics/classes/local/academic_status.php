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

namespace local_ulms_academics\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Canonical status policy for ULMS academic structure records.
 *
 * Colleges, departments and programmes share a single status vocabulary. Every
 * write path (management form, CSV import, provisioning) must persist values
 * produced by {@see self::normalize_for_storage()}, and every read path that
 * needs to decide whether a record may be used must call {@see self::is_active()}.
 *
 * Rules:
 * - Only the canonical values returned by {@see self::get_choices()} are stored.
 * - Missing values default to "active" (historic behaviour of the CSV importer).
 * - Unrecognised values are rejected rather than silently coerced.
 * - Anything that is not explicitly active is treated as NOT active, so inactive
 *   or unknown records can never be selected or enrolled into.
 *
 * @package    local_ulms_academics
 * @copyright  2026 Bells University of Technology
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class academic_status {
    /** @var string Status of a record that may be selected and enrolled into. */
    public const ACTIVE = 'active';

    /** @var string Status of a record that exists but must not be used. */
    public const INACTIVE = 'inactive';

    /** @var string Value stored when a record is created without an explicit status. */
    public const DEFAULT = self::ACTIVE;

    /**
     * @var array<string, string> Legacy/external representations mapped to canonical values.
     */
    private const ALIASES = [
        'enabled' => self::ACTIVE,
        'enable' => self::ACTIVE,
        'yes' => self::ACTIVE,
        'true' => self::ACTIVE,
        'on' => self::ACTIVE,
        '1' => self::ACTIVE,
        'disabled' => self::INACTIVE,
        'disable' => self::INACTIVE,
        'suspended' => self::INACTIVE,
        'no' => self::INACTIVE,
        'false' => self::INACTIVE,
        'off' => self::INACTIVE,
        '0' => self::INACTIVE,
    ];

    /**
     * Returns the canonical status values accepted by the platform.
     *
     * @return string[]
     */
    public static function get_choices(): array {
        return [self::ACTIVE, self::INACTIVE];
    }

    /**
     * Returns the options map used to render status select elements.
     *
     * @return array<string, string>
     */
    public static function get_options(): array {
        return [
            self::ACTIVE => \get_string('active', 'local_ulms_academics'),
            self::INACTIVE => \get_string('inactive', 'local_ulms_academics'),
        ];
    }

    /**
     * Whether the supplied raw value is empty (i.e. the caller supplied no status).
     *
     * @param string|null $status
     * @return bool
     */
    public static function is_missing(?string $status): bool {
        return trim((string)$status) === '';
    }

    /**
     * Normalises any raw status value to its canonical form.
     *
     * @param string|null $status Raw value from a form, CSV cell or database record.
     * @return string|null Canonical value, or null when the value is unrecognised.
     */
    public static function normalize(?string $status): ?string {
        $value = \core_text::strtolower(trim((string)$status));
        if ($value === '') {
            return null;
        }
        if (in_array($value, self::get_choices(), true)) {
            return $value;
        }

        return self::ALIASES[$value] ?? null;
    }

    /**
     * Normalises a value for persistence.
     *
     * Missing values fall back to {@see self::DEFAULT} so that omitting the status
     * column keeps the historic "created as active" behaviour. Unrecognised values
     * return null so the caller can reject the row instead of storing a status the
     * platform cannot interpret.
     *
     * @param string|null $status
     * @return string|null Canonical value, or null when the value is invalid.
     */
    public static function normalize_for_storage(?string $status): ?string {
        if (self::is_missing($status)) {
            return self::DEFAULT;
        }

        return self::normalize($status);
    }

    /**
     * Whether the supplied value marks a record as usable (active).
     *
     * Missing and unrecognised values are deliberately treated as NOT active.
     *
     * @param string|null $status
     * @return bool
     */
    public static function is_active(?string $status): bool {
        return self::normalize($status) === self::ACTIVE;
    }

    /**
     * Whether the supplied value marks a record as inactive.
     *
     * Only an explicitly inactive value returns true; missing/unknown values are
     * reported as neither active nor inactive.
     *
     * @param string|null $status
     * @return bool
     */
    public static function is_inactive(?string $status): bool {
        return self::normalize($status) === self::INACTIVE;
    }

    /**
     * Normalises a status filter coming from a request.
     *
     * An empty filter means "all statuses" and is returned as an empty string.
     * Unrecognised filters are returned verbatim (trimmed) so the query matches
     * nothing instead of silently widening the result set.
     *
     * @param string|null $status
     * @return string
     */
    public static function normalize_filter(?string $status): string {
        $normalised = self::normalize($status);
        if ($normalised !== null) {
            return $normalised;
        }

        return trim((string)$status);
    }
}
