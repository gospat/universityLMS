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
 * Builds the "Adopted eTextbooks" Materials section panel card injected into
 * the existing Lecturer + Student portal Materials views.
 *
 * Graceful-degradation contract (AC-6): if adapter health() fails, both
 * `build_materials_secondary_panel()` returns null AND the caller can
 * display a localized banner via `get_graceful_banner_text()`.
 *
 * @package local_ulms_kortext
 */
class portal_injector_service {

    /**
     * Cached last graceful banner message (populated if health failed).
     *
     * @var string
     */
    private string $last_banner = '';

    /**
     * Builds a secondary panel structure compatible with existing ULMS
     * `local_ulms_dashboard_render_panel()` helper.
     *
     * @param int $userid Current user id
     * @param string $role 'lecturer' | 'student'
     * @param array<int> $snapshot_courseids Course ids from the user's snapshot
     * @return array{title: string, subtitle: string, style: string, items: array<int, array{title:string, meta:?string, description:?string, url:?string, actions?:array<int, array{label:string, url:string, target?:string, aria?:string}>}>}|null
     */
    public function build_materials_secondary_panel(int $userid, string $role, array $snapshot_courseids): ?array {
        $this->last_banner = '';
        if (count($snapshot_courseids) === 0) {
            return null;
        }
        try {
            $adapter = adapter\kortext_adapter_factory::get_instance();
            $health = $adapter->health();
            if (empty($health['ok'])) {
                $this->last_banner = get_string('graceful_banner_degraded', 'local_ulms_kortext');
                return null;
            }
        } catch (\Throwable) {
            $this->last_banner = get_string('graceful_banner_degraded', 'local_ulms_kortext');
            return null;
        }

        $items = $this->load_items($role, $snapshot_courseids);
        return [
            'title'      => get_string('adopted_etextbooks', 'local_ulms_kortext'),
            'subtitle'   => $role === 'student'
                ? get_string('portal_panel_subtitle_student', 'local_ulms_kortext')
                : get_string('portal_panel_subtitle_lecturer', 'local_ulms_kortext'),
            'style'      => 'list',
            'items'      => $items,
            'emptytitle' => get_string('portal_empty', 'local_ulms_kortext'),
            'emptydesc'  => get_string('portal_empty_desc', 'local_ulms_kortext'),
        ];
    }

    /**
     * Returns the localized graceful-degradation banner string if health()
     * failed during the last `build_materials_secondary_panel()` call.
     *
     * @return string
     */
    public function get_graceful_banner_text(): string {
        return $this->last_banner;
    }

    /**
     * Loads adopted eTextbook items for the given role scoped to course ids.
     *
     * @param string $role
     * @param array<int> $courseids
     * @return array<int, array{title: string, meta: string, description: string, url: string, actions: array<int, array{label: string, url: string, target: string, aria: string}>}>
     */
    private function load_items(string $role, array $courseids): array {
        global $DB;
        if ($courseids === []) {
            return [];
        }
        [$in_sql, $in_params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cid');
        $sql = "SELECT a.id, a.isbn, a.ebook_id, a.deeplink_url, a.status,
                       c.id AS courseid, c.shortname AS courseshort, c.fullname AS coursefull
                  FROM {local_ulms_kortext_adoptions} a
                  JOIN {course} c ON c.id = a.moodlecourseid
                 WHERE a.status = 'active' AND c.id {$in_sql}
              ORDER BY c.fullname ASC, a.isbn ASC";
        $rs = $DB->get_recordset_sql($sql, $in_params);
        $out = [];
        $adapter = null;
        foreach ($rs as $_r) {
            $title = (string)($_r->coursefull ?: $_r->courseshort);
            $meta  = get_string('field_isbn', 'local_ulms_kortext') . ': ' . (string)$_r->isbn;
            if (!empty($_r->ebook_id)) {
                $meta .= ' · e-book ID: ' . (string)$_r->ebook_id;
            }
            $url = !empty($_r->deeplink_url) ? (string)$_r->deeplink_url : '';
            if ($url === '') {
                if ($adapter === null) {
                    try {
                        $adapter = adapter\kortext_adapter_factory::get_instance();
                    } catch (\Throwable) {
                        $adapter = false;
                    }
                }
                if ($adapter !== false && method_exists($adapter, 'build_lti_launch_url')) {
                    $url = (string)$adapter->build_lti_launch_url(
                        'book',
                        (string)$_r->isbn,
                        (string)($_r->ebook_id ?? ''),
                        null
                    );
                } elseif ($adapter !== false) {
                    $cfg = method_exists($adapter, 'lti_tool_config') ? $adapter->lti_tool_config() : null;
                    if (is_array($cfg) && !empty($cfg['tool_url']) && !empty($_r->isbn)) {
                        $url = rtrim($cfg['tool_url'], '/') . '/#/book/' . rawurlencode((string)$_r->isbn);
                    }
                }
            }
            $aria = get_string('portal_etextbook_open_aria', 'local_ulms_kortext', (object)[
                'isbn'        => (string)$_r->isbn,
                'coursetitle' => $title,
            ]);
            $out[] = [
                'title'       => $title,
                'meta'        => $meta,
                'description' => $role === 'student'
                    ? get_string('portal_panel_subtitle_student', 'local_ulms_kortext')
                    : get_string('portal_panel_subtitle_lecturer', 'local_ulms_kortext'),
                'url'         => $url,
                'actions'     => [
                    [
                        'label'  => get_string('portal_etextbook_open', 'local_ulms_kortext'),
                        'url'    => $url,
                        'target' => '_blank',
                        'aria'   => $aria,
                    ],
                ],
            ];
        }
        $rs->close();
        return $out;
    }
}
