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

/**
 * Programme → Course dropdown cascade for Kortext adoption create/edit forms.
 *
 * @module      local_ulms_kortext/filter_cascade
 * @copyright   2026 ULMS
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/str', 'core/notification'], function($, Str, Notification) {
    'use strict';

    var t = {
        endpointUrl: '',
        placeholderLabel: '',

        init: function(params) {
            t.endpointUrl = (params && params.coursesEndpointUrl)
                ? params.coursesEndpointUrl
                : M.cfg.wwwroot + '/local/ulms_kortext/api_courses_for_programme.php';

            var $programme = $('#id_programmeid');
            var $course = $('#id_moodlecourseid');

            if (!$programme.length || !$course.length) {
                return;
            }

            t.placeholderLabel = $course.find('option[value=""]').text() || '';

            $programme.on('change', function() {
                var pid = parseInt($programme.val(), 10) || 0;
                t.reloadCourses($course, pid);
            });

            var existingPid = parseInt($programme.val(), 10) || 0;
            if (existingPid > 0) {
                t.reloadCourses($course, existingPid);
            }
        },

        reloadCourses: function($course, programmeid) {
            var origVal = $course.val();
            var url = t.endpointUrl + '?programmeid=' + encodeURIComponent(programmeid);

            $course.prop('disabled', true);

            $.getJSON(url).done(function(resp) {
                t.rebuildOptions($course, (resp && resp.courses) ? resp.courses : [], origVal);
            }).fail(function() {
                Notification.exception(new Error('Kortext cascade load failed'));
            }).always(function() {
                $course.prop('disabled', false);
            });
        },

        rebuildOptions: function($course, courses, origVal) {
            $course.empty();
            if (t.placeholderLabel !== '') {
                $course.append($('<option></option>').attr('value', '').text(t.placeholderLabel));
            }
            $.each(courses, function(_i, c) {
                var $o = $('<option></option>').attr('value', String(c.id)).text(String(c.label));
                if (String(origVal) === String(c.id)) {
                    $o.prop('selected', true);
                }
                $course.append($o);
            });
            if (origVal && !$course.find('option[value="' + origVal + '"]').length) {
                $course.val('');
            }
        }
    };

    return t;
});
