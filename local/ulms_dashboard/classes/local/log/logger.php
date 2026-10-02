<?php
namespace local_ulms_dashboard\local\log;

defined('MOODLE_INTERNAL') || die();

use Monolog\Handler\StreamHandler;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Logger as MonologLogger;
use Monolog\Processor\UidProcessor;
use Monolog\Processor\WebProcessor;
use Monolog\Processor\ProcessIdProcessor;
use Monolog\Formatter\JsonFormatter;

class logger {

    private const CHANNELS = [
        'auth',
        'dashboard',
        'academics',
        'exam',
        'kortext',
        'mail',
        'privacy',
        'cli',
    ];

    private const DEFAULT_LEVEL = 200;

    private static ?string $traceid = null;

    private static array $instances = [];

    public static function trace_id(): string {
        if (self::$traceid === null) {
            // Prefer ULMS_TRACE_ID global if already set by mark_request_start() (lib.php).
            if (!empty($GLOBALS['ULMS_TRACE_ID']) && is_string($GLOBALS['ULMS_TRACE_ID'])) {
                self::$traceid = $GLOBALS['ULMS_TRACE_ID'];
            } elseif (PHP_SAPI === 'cli') {
                self::$traceid = 'cli-'
                    . substr(bin2hex(random_bytes(8)), 0, 12)
                    . '-' . substr((string)getmypid(), -4);
            } else {
                $existing = (string)($_SERVER['HTTP_X_TRACE_ID'] ?? $_SERVER['HTTP_X_REQUEST_ID'] ?? '');
                $existing = preg_replace('/[^A-Za-z0-9_\-]/', '', $existing);
                if ($existing !== '') {
                    self::$traceid = substr($existing, 0, 64);
                } else {
                    self::$traceid = 'req-'
                        . substr(bin2hex(random_bytes(8)), 0, 12)
                        . '-' . substr(str_pad(dechex((int)($_SERVER['REQUEST_TIME'] ?? time())), 8, '0', STR_PAD_LEFT), -6);
                }
            }
            // Ensure the global is set so emit_x_render_time() picks up the same value.
            $GLOBALS['ULMS_TRACE_ID'] = self::$traceid;
        }
        return self::$traceid;
    }

    public static function channel(string $name = 'dashboard'): MonologLogger {
        if (!in_array($name, self::CHANNELS, true)) {
            $name = 'dashboard';
        }
        if (isset(self::$instances[$name])) {
            return self::$instances[$name];
        }
        self::$instances[$name] = self::build($name);
        return self::$instances[$name];
    }

    public static function auth(): MonologLogger      { return self::channel('auth'); }
    public static function dashboard(): MonologLogger { return self::channel('dashboard'); }
    public static function academics(): MonologLogger { return self::channel('academics'); }
    public static function exam(): MonologLogger      { return self::channel('exam'); }
    public static function kortext(): MonologLogger   { return self::channel('kortext'); }
    public static function mail(): MonologLogger      { return self::channel('mail'); }
    public static function privacy(): MonologLogger   { return self::channel('privacy'); }
    public static function cli(): MonologLogger       { return self::channel('cli'); }

    private static function build(string $channel): MonologLogger {
        global $CFG;
        $logger = new MonologLogger($channel);

        $level = self::DEFAULT_LEVEL;
        if (!empty($CFG->debug) && (int)$CFG->debug >= DEBUG_DEVELOPER) {
            $level = 100;
        }

        $logger->pushProcessor(new UidProcessor(12));
        $logger->pushProcessor(new ProcessIdProcessor());
        if (PHP_SAPI !== 'cli') {
            $logger->pushProcessor(new WebProcessor());
        }
        // HMAC audit-trail integrity processor (REQUIRED per project rules / compliance P1-3)
        // Each log line gets an extra.integrity field: HMAC-SHA256 of canonical payload + static secret + per-line salt.
        $logger->pushProcessor(static function ($record) {
            try {
                global $CFG;
                $secret = (string)($CFG->local_ulms_log_secret ?? $_ENV['ULMS_LOG_HMAC_SECRET'] ?? getenv('ULMS_LOG_HMAC_SECRET') ?: '__ULMS_INTERNAL_LOG_SALT__REPLACE_IN_ENV__');
                $isRecord = ($record instanceof \Monolog\LogRecord);
                $datetime = $isRecord ? $record->datetime : ($record['datetime'] ?? new \DateTimeImmutable('now'));
                $channel  = $isRecord ? $record->channel  : ($record['channel'] ?? '');
                $level    = $isRecord ? $record->level->value : ($record['level'] ?? 0);
                $message  = $isRecord ? $record->message  : ($record['message'] ?? '');
                $context  = $isRecord ? $record->context  : ($record['context'] ?? new \stdClass());
                $extraArr = $isRecord ? $record->extra    : ($record['extra'] ?? []);
                $canonical = json_encode([
                    'ts'  => $datetime->format('c'),
                    'ch'  => $channel,
                    'lv'  => $level,
                    'msg' => $message,
                    'ctx' => $context,
                    'uid' => $extraArr['uid'] ?? '',
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $salt = bin2hex(random_bytes(6));
                $hmac = hash_hmac('sha256', $salt . '.' . $canonical, $secret);
                if ($isRecord) {
                    $newExtra = $record->extra;
                    $newExtra['integrity'] = sprintf('v1:%s:%s', $salt, $hmac);
                    if (!empty($GLOBALS['USER']) && is_object($GLOBALS['USER']) && !empty($GLOBALS['USER']->id)) {
                        $newExtra['actor_userid'] = (int)$GLOBALS['USER']->id;
                    }
                    $record = $record->with(extra: $newExtra);
                } else {
                    $record['extra']['integrity'] = sprintf('v1:%s:%s', $salt, $hmac);
                    if (!empty($GLOBALS['USER']) && is_object($GLOBALS['USER']) && !empty($GLOBALS['USER']->id)) {
                        $record['extra']['actor_userid'] = (int)$GLOBALS['USER']->id;
                    }
                }
            } catch (\Throwable) { /* never break logging */ }
            return $record;
        });
        $logger->pushProcessor(function ($record) {
            $isRecord = ($record instanceof \Monolog\LogRecord);
            if ($isRecord) {
                $newExtra = $record->extra;
                $newExtra['trace_id'] = self::trace_id();
                if (!empty($GLOBALS['USER']) && is_object($GLOBALS['USER']) && !empty($GLOBALS['USER']->id)) {
                    $newExtra['actor_userid'] = (int)$GLOBALS['USER']->id;
                }
                if (!empty($GLOBALS['SITE']) && is_object($GLOBALS['SITE']) && !empty($GLOBALS['SITE']->shortname)) {
                    $newExtra['site'] = (string)$GLOBALS['SITE']->shortname;
                }
                $record = $record->with(extra: $newExtra);
            } else {
                $record['extra']['trace_id'] = self::trace_id();
                if (!empty($GLOBALS['USER']) && is_object($GLOBALS['USER']) && !empty($GLOBALS['USER']->id)) {
                    $record['extra']['actor_userid'] = (int)$GLOBALS['USER']->id;
                }
                if (!empty($GLOBALS['SITE']) && is_object($GLOBALS['SITE']) && !empty($GLOBALS['SITE']->shortname)) {
                    $record['extra']['site'] = (string)$GLOBALS['SITE']->shortname;
                }
            }
            return $record;
        });

        $handlers = [];
        $handlers[] = new ErrorLogHandler(ErrorLogHandler::OPERATING_SYSTEM, $level, true, true);

        if (!empty($CFG->local_ulms_log_dir) && is_dir((string)$CFG->local_ulms_log_dir) && is_writable((string)$CFG->local_ulms_log_dir)) {
            $logdir = rtrim((string)$CFG->local_ulms_log_dir, '/\\');
        } else {
            $fallback = null;
            if (!empty($CFG->dataroot)) {
                $fallback = rtrim((string)$CFG->dataroot, '/\\') . '/ulms_logs';
            }
            if ($fallback !== null && !is_dir($fallback)) {
                @mkdir($fallback, 02770, true);
            }
            if ($fallback !== null && is_dir($fallback) && is_writable($fallback)) {
                $logdir = $fallback;
            } else {
                $logdir = null;
            }
        }

        if ($logdir !== null) {
            $filename = $logdir . '/' . $channel . '-' . gmdate('Y-m-d') . '.log';
            try {
                $streamHandler = new StreamHandler($filename, $level, true, 0660);
                $streamHandler->setFormatter(new JsonFormatter(JsonFormatter::BATCH_MODE_JSON, true));
                $handlers[] = $streamHandler;
            } catch (\Throwable) {
            }
        }

        foreach ($handlers as $h) {
            try {
                $logger->pushHandler($h);
            } catch (\Throwable) {
            }
        }

        return $logger;
    }
}
