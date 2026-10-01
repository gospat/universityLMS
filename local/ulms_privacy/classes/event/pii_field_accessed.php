<?php
namespace local_ulms_privacy\event;

defined('MOODLE_INTERNAL') || die();

class pii_field_accessed extends \core\event\base {

    protected function init(): void {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'user_info_data';
    }

    public static function get_name(): string {
        return get_string('piiaccessed', 'local_ulms_privacy');
    }

    public function get_description(): string {
        $viewerid   = (int)($this->userid ?? 0);
        $subjectid  = (int)($this->relateduserid ?? 0);
        $fields     = is_array($this->other['fields'] ?? null)
            ? implode(', ', $this->other['fields'])
            : (string)($this->other['fields'] ?? 'unknown');
        $purpose    = (string)($this->other['purpose'] ?? 'unspecified');
        return "User id {$viewerid} (viewer) accessed PII fields [{$fields}] on subject user id {$subjectid} for purpose: {$purpose}.";
    }

    public function get_url(): ?\moodle_url {
        if (empty($this->other['urlpath'])) {
            return null;
        }
        try {
            return new \moodle_url((string)$this->other['urlpath']);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function get_objectid_mapping(): array {
        return ['db' => 'user_info_data', 'restore' => 'user_info_data'];
    }

    public static function get_other_mapping(): array {
        return [];
    }

    protected function validate_data(): void {
        parent::validate_data();
        $fields = $this->other['fields'] ?? null;
        if ($fields === null || $fields === []) {
            throw new \coding_exception('The \'fields\' key must be set in $other (non-empty list).');
        }
        if (empty($this->contextinstanceid) && empty($this->relateduserid)) {
            throw new \coding_exception('Either contextinstanceid (user context id) or relateduserid must be set.');
        }
    }

    /**
     * Helper factory: fires the event with required fields.
     *
     * @param int $vieweruserid
     * @param int $subjectuserid
     * @param array<int, string> $fieldnames
     * @param string $purpose
     * @param string|null $urlpath
     * @return self
     */
    public static function fire_for(
        int $vieweruserid,
        int $subjectuserid,
        array $fieldnames,
        string $purpose = 'admin_provisioning_report',
        ?string $urlpath = null
    ): self {
        $ctx = \context_user::instance($subjectuserid, IGNORE_MISSING);
        $contextid = $ctx ? (int)$ctx->id : \context_system::instance()->id;
        $data = [
            'userid'      => $vieweruserid,
            'relateduserid' => $subjectuserid,
            'contextid'   => $contextid,
            'other'       => [
                'fields'  => array_values(array_filter(array_map('strval', $fieldnames))),
                'purpose' => $purpose,
                'urlpath' => $urlpath ?? '',
            ],
        ];
        /** @var self $event */
        $event = self::create($data);
        $event->trigger();
        return $event;
    }
}
