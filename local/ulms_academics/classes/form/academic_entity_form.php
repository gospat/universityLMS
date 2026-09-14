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

namespace local_ulms_academics\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Reusable form for ULMS academic entities.
 */
class academic_entity_form extends \moodleform {
    /**
     * Form definition.
     */
    public function definition(): void {
        $mform = $this->_form;
        $entity = $this->_customdata['entity'] ?? 'faculties';

        $mform->addElement('hidden', 'entity', $entity);
        $mform->setType('entity', PARAM_ALPHAEXT);

        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('text', 'code', get_string('code', 'local_ulms_academics'));
        $mform->setType('code', PARAM_TEXT);
        $mform->addRule('code', null, 'required', null, 'client');

        $mform->addElement('text', 'name', get_string('name'));
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        if (in_array($entity, ['departments', 'programmes', 'semesters'], true)) {
            $options = $this->_customdata['parentoptions'] ?? [];
            $mform->addElement(
                'select',
                'parentid',
                get_string('parentrecord', 'local_ulms_academics'),
                $options
            );
            $mform->setType('parentid', PARAM_INT);
        }

        if ($entity === 'programmes') {
            $mform->addElement('text', 'awardtype', get_string('awardtype', 'local_ulms_academics'));
            $mform->setType('awardtype', PARAM_TEXT);
            $mform->addRule('awardtype', null, 'required', null, 'client');

            $mform->addElement('text', 'durationyears', get_string('durationyears', 'local_ulms_academics'));
            $mform->setType('durationyears', PARAM_INT);
            $mform->addRule('durationyears', null, 'required', null, 'client');
        }

        if (in_array($entity, ['faculties', 'departments', 'programmes'], true)) {
            $mform->addElement(
                'select',
                'status',
                get_string('status', 'local_ulms_academics'),
                [
                    'active' => get_string('active', 'local_ulms_academics'),
                    'inactive' => get_string('inactive', 'local_ulms_academics'),
                ]
            );
            $mform->setType('status', PARAM_ALPHA);
        }

        if (in_array($entity, ['sessions', 'semesters'], true)) {
            $mform->addElement('date_selector', 'startdate', get_string('startdate', 'local_ulms_academics'));
            $mform->addElement('date_selector', 'enddate', get_string('enddate', 'local_ulms_academics'));
            $mform->addElement('advcheckbox', 'iscurrent', get_string('iscurrent', 'local_ulms_academics'));
        }

        $this->add_action_buttons();
    }

    /**
     * Form validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $entity = $data['entity'] ?? 'faculties';

        if (in_array($entity, ['departments', 'programmes', 'semesters'], true) && empty($data['parentid'])) {
            $errors['parentid'] = get_string('required');
        }

        if ($entity === 'programmes' && !empty($data['durationyears']) && (int)$data['durationyears'] < 1) {
            $errors['durationyears'] = get_string('err_numeric', 'form');
        }

        if ($entity === 'programmes' && empty(trim((string)($data['awardtype'] ?? '')))) {
            $errors['awardtype'] = get_string('required');
        }

        if (in_array($entity, ['sessions', 'semesters'], true)) {
            $startdate = (int)($data['startdate'] ?? 0);
            $enddate = (int)($data['enddate'] ?? 0);

            if ($startdate && $enddate && $enddate <= $startdate) {
                $errors['enddate'] = get_string('invaliddaterange', 'local_ulms_academics');
            }
        }

        return $errors;
    }
}
