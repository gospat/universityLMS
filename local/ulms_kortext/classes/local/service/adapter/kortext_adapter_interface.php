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
 * Contract for Kortext adapter implementations.
 *
 * Both mock and production adapters implement this exact interface so the
 * rest of the plugin never needs to know which mode is active (AC-4).
 *
 * @package local_ulms_kortext
 */
interface kortext_adapter_interface {

    /**
     * Runs a lightweight health probe.
     *
     * @return array{ok: bool, latency_ms: int, error?: string}
     */
    public function health(): array;

    /**
     * Grants an entitlement for a ULMS user to an e-book.
     *
     * @param int $userid Moodle user.id
     * @param string $ebook_id Kortext-supplied e-book identifier
     * @param array<string, string> $user_pii_context Optional firstname/lastname/email (only populated if sendpii config enabled)
     * @param string $idempotency_key Client-generated idempotency token (sent in request headers/body where supported)
     * @return array{ok: bool, remote_id?: string, http_code?: int, error?: string}
     */
    public function entitle(int $userid, string $ebook_id, array $user_pii_context = [], string $idempotency_key = ''): array;

    /**
     * Lists entitlements for a specific adoption × e-book pair.
     *
     * @param int $adoptionid local_ulms_kortext_adoptions.id
     * @param string $ebook_id Kortext e-book identifier
     * @param array<string, mixed> $filter Optional filter keys (limit, page, since)
     * @return array<int, array{userid: int, remote_id: string, granted_at: int}>
     */
    public function list_entitlements(int $adoptionid, string $ebook_id, array $filter = []): array;

    /**
     * Pulls daily usage aggregates (per adoption × e-book) between two UNIX timestamps.
     *
     * @param int $adoptionid local_ulms_kortext_adoptions.id
     * @param string $ebook_id Kortext e-book identifier
     * @param int $fromts Inclusive UNIX start timestamp (UTC)
     * @param int $tots Inclusive UNIX end timestamp (UTC)
     * @return array<int, array{snapshot_date: int, unique_users: int, opens_count: int}>
     */
    public function usage_daily(int $adoptionid, string $ebook_id, int $fromts, int $tots): array;

    /**
     * Returns Kortext LTI 1.3 tool configuration (URLs, keyset URL, privacy flags,
     * deep-linking support) so the Moodle "External tool" configuration can be
     * populated exactly as documented by Kortext integration guides.
     *
     * Values are read from plugin admin settings first, then fall back to environment
     * variables via ulms_env() so infrastructure can override per deployment tier
     * without touching the DB.
     *
     * @return array{
     *   tool_name: string,
     *   tool_url: string,
     *   lti_version: string,
     *   public_keyset_url: string,
     *   initiate_login_url: string,
     *   redirection_url: string,
     *   supports_deep_linking: bool,
     *   share_pii_always: bool,
     * }
     */
    public function lti_tool_config(): array;
}
