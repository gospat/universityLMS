# ULMS Deployment Sync

## Root Cause

UI mismatches were caused by the runtime serving code from two different places:

- tracked application source in `moodle/`
- legacy runtime overlays in `../custom/`

This allowed `git pull` to update the tracked Moodle tree while the live app could still render older plugin or theme files from the overlay directories. In addition, Moodle caches can keep old UI assets visible until they are purged.

Production mail delivery also requires locked Composer runtime dependencies. The ULMS Resend transport uses `symfony/http-client` through the root Composer autoloader at `vendor/autoload.php`. A production deploy that skips `composer install --no-dev` can leave the application code up to date while runtime dependencies are missing.

## Supported Update Flow

After pulling updates in the Moodle repository, run:

```bash
./scripts/ulms_refresh_live.sh
```

This script:

1. installs locked production Composer dependencies with `composer install --no-dev`
2. synchronizes any legacy `../custom/` overlay directories from the tracked `moodle/` source
3. refreshes the running `moodle-app` and `nginx` services
4. runs Moodle upgrade
5. purges Moodle caches

## Automatic Refresh After `git pull`

Install the post-merge hook once:

```bash
./scripts/install_post_merge_hook.sh
```

After that, every successful `git pull` in this clone automatically runs the live refresh script.

## Production Safety Notes

The refresh flow installs dependencies from `composer.lock`, but it does not overwrite:

- `.env`
- `config.php`
- `moodledata/`

Those paths remain outside the Composer install step and must be preserved across deployments.

## Expected Production Commands

From the Moodle repository root:

```bash
git pull origin push_ulms_main
./scripts/ulms_refresh_live.sh
```

If you need to run the dependency step manually, use:

```bash
composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
```

If Composer is not installed globally, place `composer.phar` in the repository root and run:

```bash
php composer.phar install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
```

## Verification

To verify the Resend runtime dependency after deployment:

```bash
php -r 'define("CLI_SCRIPT", true); require "/var/www/ubecontent/config.php"; require_once "/var/www/ubecontent/local/ulms_mail/lib.php"; \local_ulms_mail_bootstrap_dependencies(); echo class_exists(\Symfony\Component\HttpClient\HttpClient::class) ? "HttpClient: AVAILABLE\n" : "HttpClient: MISSING\n";'
```
