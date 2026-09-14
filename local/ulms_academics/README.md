# local_ulms_academics

ULMS academic structure management plugin.

## Responsibilities

- manages academic structure records such as faculties, departments, programmes, sessions, and semesters
- exposes management-side academics routes used from the ULMS Admin portal
- provides import, mapping, reporting, and CRUD helpers for academic structure data
- keeps reusable academics controller helpers in `locallib.php`, while page-only rendering helpers stay co-located with their controller until a second real reuse case exists

## Access Notes

- navigation is added only for users with `local/ulms_academics:viewstructure`
- management URLs are resolved through the shared ULMS routing service instead of hardcoded raw links

## Controller Conventions

- management controllers use shared route generation through `landing_page_service`
- repeated request-state selection belongs in `locallib.php` when used by more than one academics controller
- controller-specific presentation helpers, such as the course-mappings sortable header renderer, stay page-local
