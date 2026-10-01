<?php
namespace local_ulms_privacy\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\writer;

class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\user_preference_provider {

    /** @var string[] GDPR field shortnames managed by this plugin */
    private const MANAGED_FIELDS = [
        'dob',
        'nok_name',
        'nok_phone',
        'nok_relation',
        'ferpa_directory_optout',
        'sms_marketing_consent',
    ];

    public static function get_metadata(collection $collection): collection {
        $fieldmeta = [];
        foreach (self::MANAGED_FIELDS as $fn) {
            $fieldmeta[$fn] = "privacy:metadata:user_info_data:fieldid:{$fn}";
        }
        $collection->add_database_table(
            'user_info_data',
            $fieldmeta,
            'privacy:metadata:user_info_data'
        );
        $collection->add_subsystem_link('core_user', [], 'privacy:metadata');
        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $contextlist->add_from_sql(
            "SELECT cx.id
               FROM {context} cx
               JOIN {user_info_data} uid ON uid.userid = cx.instanceid
               JOIN {user_info_field} uif ON uif.id = uid.fieldid
              WHERE cx.contextlevel = :contextuser
                AND uif.shortname IN (" . self::in_sql_placeholders(count(self::MANAGED_FIELDS)) . ")
                AND uid.userid = :uid",
            array_merge(
                ['contextuser' => CONTEXT_USER, 'uid' => $userid],
                array_values(self::MANAGED_FIELDS)
            )
        );
        return $contextlist;
    }

    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $userid = (int)$contextlist->get_user()->id;
        if ($userid <= 0) {
            return;
        }
        $usercontext = \context_user::instance($userid, IGNORE_MISSING);
        if (!$usercontext) {
            return;
        }
        if (!$contextlist->count() || !in_array($usercontext->id, array_map('intval', $contextlist->get_contextids()), true)) {
            return;
        }
        $ids = [];
        foreach (self::MANAGED_FIELDS as $fn) {
            $fid = (int)$DB->get_field('user_info_field', 'id', ['shortname' => $fn], IGNORE_MISSING);
            if ($fid > 0) {
                $ids[$fn] = $fid;
            }
        }
        if (empty($ids)) {
            return;
        }
        [$insql, $inparams] = $DB->get_in_or_equal(array_values($ids), SQL_PARAMS_NAMED, 'fld');
        $rows = $DB->get_records_sql(
            "SELECT uif.shortname, uid.data
               FROM {user_info_data} uid
               JOIN {user_info_field} uif ON uif.id = uid.fieldid
              WHERE uid.fieldid {$insql}
                AND uid.userid = :uid",
            array_merge($inparams, ['uid' => $userid])
        );
        $export = [];
        foreach ($rows as $r) {
            $sn = (string)$r->shortname;
            $val = (string)$r->data;
            if ($sn === 'ferpa_directory_optout' || $sn === 'sms_marketing_consent') {
                $val = transform::yesno((int)$val);
            } elseif ($sn === 'dob' && ctype_digit($val)) {
                $val = transform::datetime((int)$val);
            }
            $export[$sn] = $val;
        }
        if (!empty($export)) {
            writer::with_context(\context::instance_by_id($usercontext->id, MUST_EXIST))
                ->export_data([get_string('ulms_privacy_settings_header', 'local_ulms_privacy')], (object)$export);
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context): void {
        if (!($context instanceof \context_user)) {
            return;
        }
        self::purge_user_privacy_fields((int)$context->instanceid);
    }

    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist as $ctx) {
            if ($ctx instanceof \context_user && (int)$ctx->instanceid === $userid) {
                self::purge_user_privacy_fields($userid);
                break;
            }
        }
    }

    public static function delete_data_for_users(\core_privacy\local\request\approved_userlist $userlist): void {
        foreach ($userlist->get_userids() as $uid) {
            self::purge_user_privacy_fields((int)$uid);
        }
    }

    public static function export_user_preferences(int $userid): void {
    }

    private static function purge_user_privacy_fields(int $userid): void {
        global $DB;
        if ($userid <= 0) {
            return;
        }
        $ids = [];
        foreach (self::MANAGED_FIELDS as $fn) {
            $fid = (int)$DB->get_field('user_info_field', 'id', ['shortname' => $fn], IGNORE_MISSING);
            if ($fid > 0) {
                $ids[] = $fid;
            }
        }
        if (empty($ids)) {
            return;
        }
        [$insql, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'fdel');
        $inparams['udel'] = $userid;
        $DB->delete_records_sql(
            "DELETE FROM {user_info_data} WHERE fieldid {$insql} AND userid = :udel",
            $inparams
        );
    }

    private static function in_sql_placeholders(int $count): string {
        if ($count <= 0) {
            return '';
        }
        return implode(',', array_fill(0, $count, '?'));
    }
}
