<?php
require_once(__DIR__ . '/../../config.php');

if (method_exists(\theme_ulms_university\output\core_renderer::class, 'inject_http_security_headers')) {
    \theme_ulms_university\output\core_renderer::inject_http_security_headers();
}

if (!defined('ULMS_PUBLIC_ROUTE_REQUEST')) {
    $routingservice = new \local_ulms_auth\local\service\landing_page_service();
    $routingservice->maybe_redirect_legacy_request('lecturer.login');
}

define('ULMS_AUTH_PORTAL_KEY', 'lecturer');
require_once(__DIR__ . '/role_login.php');
