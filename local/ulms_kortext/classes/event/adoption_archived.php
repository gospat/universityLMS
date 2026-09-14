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
 * Fired when an adoption is soft-archived (status set to 'archived').
 *
 * @package local_ulms_kortext
 */
class adoption_archived extends \core\event\base {

    /**
     * @return void
     */
    protected function init(): void {
        $this->data['crud']        = 'd';
        $this->data['edulevel']    = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_ulms_kortext_adoptions';
    }

    /**
     * @return string
     */
    public static function get_name(): string {
        return get_string('event_adoption_archived', 'local_ulms_kortext');
    }

    /**
     * @return string
     */
    public function get_description(): string {
        $isbn = $this->other['isbn'] ?? '';
        return "The user with id '{$this->userid}' archived adoption id '{$this->objectid}' (ISBN {$isbn}).";
    }

    /**
     * @return \moodle_url
     */
    public function get_url(): \moodle_url {
        return new \moodle_url('/local/ulms_kortext/adoptions.php', ['action' => 'list']);
    }

    /**
     * @return array<string, string>
     */
    public static function get_objectid_mapping(): array {
        return ['db' => 'local_ulms_kortext_adoptions', 'restore' => 'local_ulms_kortext_adoption'];
    }
}
