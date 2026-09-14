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

defined('MOODLE_INTERNAL') || die();

/**
 * Returns a selected subset of academics request parameters.
 *
 * This helper centralises the shared route-state shaping used by multiple
 * academics management controllers. Page-local helpers should remain co-located
 * with a controller only when they have no verified second use case.
 *
 * @param array<string, mixed> $params
 * @param array<int, string> $keys
 * @param bool $skipempty When true, empty scalar values are omitted.
 * @return array<string, mixed>
 */
function local_ulms_academics_select_request_params(array $params, array $keys, bool $skipempty = false): array {
    $selected = [];

    foreach ($keys as $key) {
        if (!array_key_exists($key, $params)) {
            continue;
        }

        $value = $params[$key];
        if ($skipempty && ($value === '' || $value === 0 || $value === false || $value === null)) {
            continue;
        }

        $selected[$key] = $value;
    }

    return $selected;
}

/**
 * Builds canonical management-context params for entity pages.
 *
 * The returned params are safe for redirects, paging links, and filter actions
 * because they preserve the required entity and current list state while
 * excluding transient edit/delete request flags.
 *
 * @param array<string, mixed> $params
 * @return array<string, mixed>
 */
function local_ulms_academics_build_manage_context_params(array $params): array {
    $contextparams = local_ulms_academics_select_request_params($params, [
        'entity',
        'search',
        'status',
        'sort',
        'dir',
        'perpage',
    ]);

    if (!empty($params['page'])) {
        $contextparams['page'] = (int)$params['page'];
    }

    return $contextparams;
}

/**
 * Builds the canonical form-action params for entity create/edit forms.
 *
 * @param array<string, mixed> $contextparams
 * @param int $id
 * @return array<string, mixed>
 */
function local_ulms_academics_build_manage_form_params(array $contextparams, int $id = 0): array {
    $formparams = $contextparams;

    if ($id > 0) {
        $formparams['id'] = $id;
    }

    return $formparams;
}

/**
 * Builds canonical list/filter params for course mapping management.
 *
 * @param array<string, mixed> $params
 * @return array<string, mixed>
 */
function local_ulms_academics_build_mapping_context_params(array $params): array {
    $contextparams = local_ulms_academics_select_request_params($params, [
        'search',
        'facultyfilter',
        'departmentfilter',
        'programmefilter',
        'semesterfilter',
        'coursetypefilter',
        'sort',
        'dir',
        'perpage',
    ]);

    if (!empty($params['page'])) {
        $contextparams['page'] = (int)$params['page'];
    }

    return $contextparams;
}

/**
 * Builds the canonical form-action params for course mapping edit forms.
 *
 * @param array<string, mixed> $contextparams
 * @param int $editid
 * @return array<string, mixed>
 */
function local_ulms_academics_build_mapping_form_params(array $contextparams, int $editid = 0): array {
    $formparams = $contextparams;

    if ($editid > 0) {
        $formparams['editid'] = $editid;
    }

    return $formparams;
}
