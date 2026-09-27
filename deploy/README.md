# ULMS deploy/ — reference configurations

This directory contains **infrastructure reference files** for deploying the
reusable ULMS codebase.  It uses a deliberate two-tier structure:

## Tier 1 — Per-institution TEMPLATE files (use these for NEW deployments)

These are clean, portable, tokenised starting points for any university.
Never deploy the TEMPLATE files directly — always replace tokens first.

| File | Purpose |
|---|---|
| `deploy/nginx/TEMPLATE.site.conf` | Generic Nginx site.  Replace `%%REPLACE_%%` tokens with `sed` (see header one-liner). |
| `deploy/php-fpm/TEMPLATE.pool.conf` | Generic PHP-FPM pool.  Replace tokens via sed header one-liner. |

For step-by-step multi-university setup instructions see the main
`ULMS_DEPLOYMENT_SYNC.md` §16 (Generic Multi-University Setup) and
`README.md` §9 (New University 7-Step Setup Guide).

## Tier 2 — Bells University reference examples (DO NOT reuse verbatim)

These files are the exact **production configurations used by the Bells
University of Technology deployment**.  They are kept in-tree with their
original filenames so the Bells operator can deploy drop-in references and
to provide a worked example for every token position in the TEMPLATE files.

Every Bells reference file has a **6-line banner at the top** that labels it
as a Bells-specific reference and explicitly tells operators to use the
TEMPLATE file for other institutions.

| File | Purpose |
|---|---|
| `deploy/nginx/learn.bellsuniversity.edu.ng.conf` | Bells Nginx site (`server_name`, log paths, cert paths, CF IPs are Bells-specific). |
| `deploy/php-fpm/www-ulms.conf.24.04` | Bells PHP-FPM 8.3 pool (`/run/php/php8.3-fpm.sock`, session path, pm sizing). |
| `deploy/operator/cloudflare-checklist.md` | Bells Cloudflare operator checklist.  Copy and replace `learn.bellsuniversity.edu.ng` + origin IP for other schools. |

## Rule of thumb

- **Covenant University / any other school:** start from `TEMPLATE.*.conf`.
- **Bells University operator:** you can still deploy the Tier 2 reference
  files directly — they are intentionally untouched (banner comments only) so
  the existing Bells drop-in deploy workflow from `ULMS_DEPLOYMENT_SYNC.md`
  §1–§15 continues to work unchanged.
