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
 * Extends site navigation for a Kortext adoptions entry (when user caps allow).
 *
 * @param global_navigation $navigation
 * @return void
 */
function local_ulms_kortext_extend_navigation(global_navigation $navigation): void {
    if (class_exists('\local_ulms_dashboard\local\service\dashboard_service')) {
        try {
            /** @var mixed $syscontext */
            $syscontext = \context_system::instance();
            if (has_capability('local/ulms_kortext:manageadoptions', $syscontext)) {
                $node = $navigation->add(
                    get_string('nav_adoptions', 'local_ulms_kortext'),
                    new moodle_url('/local/ulms_kortext/adoptions.php'),
                    navigation_node::TYPE_CUSTOM,
                    null,
                    'local_ulms_kortext_adoptions',
                    new pix_icon('i/navigationitem', get_string('nav_adoptions', 'local_ulms_kortext'))
                );
                if ($node) {
                    $node->showinflatnavigation = true;
                }
            }
        } catch (\Throwable) {
            return;
        }
    }
}

/**
 * Plugin entry-point for Materials-panel injection.
 *
 * This function is CALLED by the 4-portal page layer (lecturer_portal.php,
 * student_portal.php) via component_callback(). It delegates to
 * portal_injector_service and therefore must not be removed; the service
 * class itself lives in classes/local/service/.
 *
 * @param array<string, mixed> $sectiondata  MUTABLE reference to current section data (summarycards, mainpanel, secondarypanels)
 * @param string $role                       'lecturer' | 'student' | 'admin' | 'superadmin'
 * @param int $userid                        Current viewing user id
 * @param array<int> $courseids              Snapshot courseids (enrolled or allocated)
 * @param string $sectionview                e.g. 'materials', 'assignments'
 * @return void
 */
function local_ulms_kortext_inject_portal_materials(
    array &$sectiondata,
    string $role,
    int $userid,
    array $courseids,
    string $sectionview
): void {
    $injectviews = ['materials', 'catalog'];
    if (!in_array($sectionview, $injectviews, true)) {
        return;
    }
    if (!in_array($role, ['lecturer', 'student'], true)) {
        return;
    }
    if (!get_config('local_ulms_kortext', 'adapter_mode')) {
        return;
    }
    if ($userid <= 0) {
        return;
    }
    if (count($courseids) === 0) {
        try {
            $courseids = \local_ulms_kortext\local\service\adoption_service::resolve_courseids_for_user($userid, $role);
        } catch (\Throwable) {
            $courseids = [];
        }
    }
    try {
        $injector = new \local_ulms_kortext\local\service\portal_injector_service();
        $panel = $injector->build_materials_secondary_panel($userid, $role, $courseids);
        if ($panel !== null) {
            if (!isset($sectiondata['secondarypanels'])) {
                $sectiondata['secondarypanels'] = [];
            }
            array_unshift($sectiondata['secondarypanels'], $panel);
        } else {
            $banner = $injector->get_graceful_banner_text();
            if ($banner !== '' && isset($sectiondata['mainpanel']['subtitle'])) {
                $sectiondata['mainpanel']['subtitle'] = $banner . ' — ' . $sectiondata['mainpanel']['subtitle'];
            }
        }
    } catch (\Throwable) {
        return;
    }
}
