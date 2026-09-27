# local_ulms_mail

ULMS transactional mail plugin.  Sends password-reset, welcome,
provisioning-confirmation, manual-password-reset, and future receipt emails
through either:

1. **Resend HTTP API transport** (recommended — set `ULMS_MAIL_TRANSPORT=resend`).
2. Moodle's native `email_to_user()` via SMTP (`ULMS_MAIL_TRANSPORT=moodle`, legacy fallback).

The Resend transport ships a **3-tier duck-typed HTTP client** so it works
on any PHP host even when Composer vendor is not installed — only ext-cURL
is required.

---

## 1. Configuration

All configuration lives in the middle layer of the standard hierarchy:
**Database → .env → compile-time defaults.**

### 1.1 `.env` Template Snippet

```
# === Mail transport: pick "resend" or "moodle" ===
ULMS_MAIL_TRANSPORT=resend

# === Resend (used only when ULMS_MAIL_TRANSPORT=resend) ===
# Resend API keys start with "re_" prefix.
# Production keys: 40+ chars.
# Resend test keys: 32+ chars after the re_ prefix (so total ~35-36).
# Both are accepted by the validation helper (min 32 chars total).
RESEND_API_KEY=re_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx

# Sender email / name.  Domain MUST be a verified sender at Resend or
# only the admin/owner account that added it will receive mail.
RESEND_FROM_EMAIL=no-reply@youruniversity.edu
RESEND_FROM_NAME="ULMS Support"

# Optional reply-to address (shows on welcome emails)
ULMS_REPLY_TO=support@youruniversity.edu

# === Moodle SMTP fallback (used only when ULMS_MAIL_TRANSPORT=moodle) ===
SMTP_HOSTS=smtp.sendgrid.net:587
SMTP_USER=apikey
SMTP_PASS='replace-with-smtp-secret'
SMTP_SECURE=tls
SMTP_PORT=587
ULMS_DEFAULT_NO_REPLY=no-reply@youruniversity.edu
ULMS_DEFAULT_SUPPORT_EMAIL=support@youruniversity.edu
ULMS_DEFAULT_SUPPORT_NAME="ULMS Support"
```

The transport block that reads these vars lives in the root config at
[config.php §4](../../config.php#L295-L367).  When transport is `resend`, the
same block also **sets the legacy Moodle SMTP hosts fallback** to
`smtp.resend.com:587` so any stray Moodle `email_to_user()` call that doesn't
use the ULMS service still has a path to deliver.

### 1.2 Database Overrides (highest layer)

After installation, use the Super Admin settings panel
`/super-admin/settings/ulms_mail` to override any of the values above.
Those edits survive `.env` changes and server restarts (Database layer wins).

---

## 2. Resend HTTP Transport — 3-tier Duck-typed HTTP Client

The mail service class is at
[classes/local/service/resend_mail_service.php](./classes/local/service/resend_mail_service.php).
It selects an HTTP transport in strict order:

1. **Caller-injected client** (tests, Symfony HttpClient users).  Any duck-typed object exposing `request(string $method, string $url, array $options):object`, `withOptions(array):static`, `getOptions():array`, `stream():Generator` is accepted.
2. **Composer vendor Symfony HttpClient** — used automatically iff `class_exists('Symfony\Component\HttpClient\HttpClient')`.  Unlocks connection pooling + HTTP/2.
3. **Native PHP `ext-cURL` wrapped in a duck-typed anonymous class.**  This is the **always-available fallback on 99% of production PHP hosts** and requires zero Composer vendor install.

The duck-typed cURL response object exposes exactly the same public surface as
Symfony's ResponseInterface so every caller is unchanged:
`getStatusCode():int`, `getHeaders(bool $throw = true):array`,
`getContent(bool $throw = true):string`, `toArray(bool $throw = true):array`,
`__toString():string`, plus the vendor-optional `getInfo()` and `stream()`
(the latter throws `\LogicException` since cURL fallback does not implement
async streaming — this is acceptable because ULMS never uses streaming with
Resend, and the vendor path does not either).

### 2.1 Validation

`resend_mail_service::is_valid_resend_api_key(string $apikey): bool`

Accepts a key iff:
- trimmed starts with `re_`,
- trimmed total length ≥ 32 characters (accommodates both 36-char Resend test keys and 40+ char production keys),
- only contains base64url-safe characters after the prefix.

The PRC check `sec:resend-key-format` runs this validator against the live
`$CFG->resendapikey` value on every deploy.

---

## 3. Usage

From any PHP file that has already required `config.php`:

```php
$mailer = new \local_ulms_mail\local\service\resend_mail_service();

$result = $mailer->send_transactional_email([
    'to'          => 'student123@students.youruniversity.edu',
    'subject'     => 'Your ULMS account has been created',
    'htmlbody'    => '<h1>Welcome</h1>…',
    'textbody'    => "Welcome\n…",
    'replyto'     => 'support@youruniversity.edu',
    'replytoname' => 'ULMS Support',
    'attachments' => [],
    'tags'        => ['ulms:provisioning:welcome'],
]);

if ($result['success'] ?? false) {
    // $result['messageid'], $result['statuscode'] populated
} else {
    // $result['error'], $result['statuscode'] (if any HTTP call completed)
}
```

Moodle `email_to_user()` callers (password reset, activation, enrol expiry)
are routed to the Resend service automatically when `ULMS_MAIL_TRANSPORT=resend`
by the bootstrap in [lib.php](./lib.php)
`\local_ulms_mail_bootstrap_dependencies()`.

---

## 4. CLI Smoke Test

```bash
php local/ulms_mail/cli/send_test_email.php --to=sysadmin@youruniversity.edu
```

Sends a real transactional email via Resend (or Moodle SMTP fallback) and
prints the full result (message id / HTTP status / error).  Use this after
every mail config change and after every deploy.  The smoke helper uses an
idempotency key per recipient per hour so accidental re-runs don't
needlessly consume your Resend quota.

---

## 5. What ULMS Sends Through This Plugin Today

| Trigger | Recipient | Content | Notes |
|---|---|---|---|
| Admin creates user (single) | New user | Welcome + activation link | Force-pw-change flag set when the admin toggle is on. |
| CSV bulk user provisioning confirmed | Each new user | Welcome + activation link | Sent inside the same DB transaction as user-creation so the audit log ties email attempts directly to rows. |
| Admin resets user password | Target user | "Your password was reset" + one-time login link | Target user is forced to set a new password on next login via `auth_forcepasswordchange`. |
| User initiates password reset via `/reset-password/` | User | Password reset token link | 5/30 min rate limit enforced + account enumeration prevented by the form returning identical success whether email is known or not. |
| (future) Payment receipts | Paying student | Receipt PDF + confirmation | Already linked to the transport selection; Flow-B inline ALAT Pay by WEMA will use the same send path. |

---

## 6. Testing

[tests/resend_mail_service_test.php](./tests/resend_mail_service_test.php)
covers the 3-tier transport selector, is_valid_resend_api_key boundaries,
and duck-typed response behaviour when cURL is used.

Run with:

```bash
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit --testsuite local_ulms_mail_tests
```

Composer vendor is not required to use the plugin (cURL fallback covers it)
but you do need `phpunit` / Moodle's test infrastructure to run the suite.
