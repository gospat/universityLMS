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

namespace local_ulms_kortext\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Fired when a new ISBN adoption is created via Admin UI or CSV import.
 *
 * @package local_ulms_kortext
 */
class adoption_created extends \core\event\base {

    /**
     * Initialises required event metadata.
     *
     * @return void
     */
    protected function init(): void {
        $this->data['crud']        = 'c';
        $this->data['edulevel']    = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_ulms_kortext_adoptions';
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('event_adoption_created', 'local_ulms_kortext');
    }

    /**
     * Human-readable description of what triggered the event.
     *
     * @return string
     */
    public function get_description(): string {
        $isbn = $this->other['isbn'] ?? '';
        return "The user with id '{$this->userid}' created adoption id '{$this->objectid}' (ISBN {$isbn}).";
    }

    /**
     * URL to the adoption administration page.
     *
     * @return \moodle_url
     */
    public function get_url(): \moodle_url {
        return new \moodle_url('/local/ulms_kortext/adoptions.php', ['action' => 'list']);
    }

    /**
     * Object id mapping for backup/restore.
     *
     * @return array<string, string>
     */
    public static function get_objectid_mapping(): array {
        return ['db' => 'local_ulms_kortext_adoptions', 'restore' => 'local_ulms_kortext_adoption'];
    }
}
