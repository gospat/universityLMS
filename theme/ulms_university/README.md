# theme_ulms_university

ULMS Moodle theme for branded portal rendering.

## Responsibilities

- provides the ULMS dashboard layout, renderer overrides, templates, and SCSS
- supplies the shared portal shell used by Student, Lecturer, Admin, and Super Admin pages
- applies branded dashboard context for the public sign-in experience and authenticated portal pages

## Important Implementation Notes

- renderer logic lives in `classes/output/core_renderer.php`
- the dashboard layout is `layout/ulmsdashboard.php`
- portal shell markup is rendered through `templates/ulms_dashboard_shell.mustache`
- ULMS SCSS tokens such as `$ulms-primary` are injected in `lib.php` via `theme_ulms_university_get_pre_scss()` before `scss/preset/default.scss` is compiled
