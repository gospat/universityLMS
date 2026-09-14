# local_ulms_dashboard

ULMS portal dashboard and management plugin.

## Responsibilities

- renders the Student, Lecturer, Admin, and Super Admin dashboard experiences
- provides portal services that decide shell context, header context, navigation state, and section content
- keeps Admin and Super Admin as separate portal identities while allowing Super Admin to inherit Admin feature permissions
- serves management tools such as user management, provisioning, analytics, settings, and production-readiness checks

## Architecture Notes

- student and lecturer portal detection lives in their portal service classes under `classes/local/service/`
- management pages use `local_ulms_dashboard_get_management_portal_service()` so Admin stays in `/management/` and Super Admin keeps the `/super-admin/` shell while opening shared management features
- the ULMS dashboard pagelayout is `ulmsdashboard`, rendered by the theme rather than a standalone duplicate stylesheet

## Production Readiness

- `cli/production_readiness_check.php` is the canonical ULMS readiness check
- the expected successful output is: `All ULMS production-readiness checks passed.`
- the check validates canonical authentication routes, portal routes, theme compilation, ULMS shell CSS markers, plugin availability, `alternateloginurl`, `forgottenpasswordurl`, and the configured mail transport requirements

## Deployment Safety

1. Fetch and review the incoming Git changes before pulling to the target environment.
2. Back up the currently deployed ULMS modules before synchronization.
3. Synchronize only approved ULMS application modules.
4. Preserve the target environment `config.php`; do not overwrite production configuration during deployment.
5. Run Moodle upgrade and cache-purge steps when required.
6. Run `php local/ulms_dashboard/cli/production_readiness_check.php`.
7. Perform browser smoke tests for sign-in, provisioning, activation, and portal entry routes.
