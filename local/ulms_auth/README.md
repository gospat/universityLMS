# local_ulms_auth

ULMS authentication, landing-page, and clean-route plugin.

## Responsibilities

- defines canonical public routes such as `/sign-in/`, `/student/login/`, `/management/`, and `/super-admin/`
- resolves the current user's portal and dashboard destination through `landing_page_service`
- provides shared activation and password-reset helpers
- sends authentication mail through the active ULMS mail transport

## Routing Notes

- public clean routes are defined in `classes/local/service/landing_page_service.php`
- clean route wrapper entry points use `clean_route_entry.php`
- `/reset-password/` is the canonical user-facing password recovery route
- raw files such as `student_login.php` and `admin_login.php` remain internal controller entry points and should not be linked as the preferred public URLs
- `password_reset.php` remains an internal controller entry point and should not be shared as the preferred password recovery URL
