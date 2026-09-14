<?php
require_once(__DIR__ . '/../../local/ulms_auth/clean_route_entry.php');
local_ulms_auth_boot_public_route('/local/ulms_dashboard/super_admin.php', ['view' => 'security']);
