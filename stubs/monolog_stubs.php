<?php
/**
 * Intelephense stub file for Monolog classes.
 * This file is NEVER executed by Moodle runtime (MOODLE_INTERNAL check inside the first namespace block).
 * It exists solely to provide type information for static analysis.
 *
 * This file lives outside of Moodle's lib/ and plugin class autoload paths and
 * guards itself with MOODLE_INTERNAL, so it will never be executed at runtime.
 */
namespace Monolog {

    if (!defined('MOODLE_INTERNAL')) {
        // Die immediately if accessed via web or CLI outside Moodle bootstrap.
        die;
    }

    use Monolog\Handler\HandlerInterface;

    class Logger {
        public const DEBUG = 100;
        public const INFO = 200;
        public const NOTICE = 250;
        public const WARNING = 300;
        public const ERROR = 400;
        public const CRITICAL = 500;
        public const ALERT = 550;
        public const EMERGENCY = 600;

        /** @var string */
        protected $name;

        /** @var HandlerInterface[] */
        protected $handlers = [];

        /** @var callable[] */
        protected $processors = [];

        public function __construct(string $name, array $handlers = [], array $processors = []) {}

        public function getName(): string { return (string)$this->name; }

        /** @return $this */
        public function pushHandler(HandlerInterface $handler): self { return $this; }

        public function popHandler(): HandlerInterface {
            $h = end($this->handlers);
            return $h instanceof HandlerInterface ? $h : new \Monolog\Handler\StreamHandler('php://stdout');
        }

        /** @return $this */
        public function pushProcessor(callable $callback): self { return $this; }

        public function popProcessor(): callable {
            return function (array $record): array { return $record; };
        }

        public function addDebug(string $message, array $context = []): bool { return true; }
        public function addInfo(string $message, array $context = []): bool { return true; }
        public function addNotice(string $message, array $context = []): bool { return true; }
        public function addWarning(string $message, array $context = []): bool { return true; }
        public function addError(string $message, array $context = []): bool { return true; }
        public function addCritical(string $message, array $context = []): bool { return true; }
        public function addAlert(string $message, array $context = []): bool { return true; }
        public function addEmergency(string $message, array $context = []): bool { return true; }

        public function debug(string $message, array $context = []): void {}
        public function info(string $message, array $context = []): void {}
        public function notice(string $message, array $context = []): void {}
        public function warning(string $message, array $context = []): void {}
        public function error(string $message, array $context = []): void {}
        public function critical(string $message, array $context = []): void {}
        public function alert(string $message, array $context = []): void {}
        public function emergency(string $message, array $context = []): void {}
    }
}

namespace Monolog\Handler {

    interface HandlerInterface {
        public function isHandling(array $record): bool;
        public function handle(array $record): bool;
        public function handleBatch(array $records): void;
        public function close(): void;
    }

    abstract class AbstractHandler implements HandlerInterface {
        /** @var int */
        protected $level;
        /** @var bool */
        protected $bubble = true;

        /**
         * @param int|string $level
         * @param bool $bubble
         */
        public function __construct($level = \Monolog\Logger::DEBUG, bool $bubble = true) {}

        public function isHandling(array $record): bool { return true; }

        public function handleBatch(array $records): void {}

        public function close(): void {}

        /** @return $this */
        public function setLevel($level): self { return $this; }

        /** @return int|string */
        public function getLevel() { return $this->level; }

        /** @return $this */
        public function setBubble(bool $bubble): self { return $this; }

        public function getBubble(): bool { return $this->bubble; }

        public function handle(array $record): bool { return false; }
    }

    /**
     * Used for type-checking in static analysis.  Not used at runtime.
     * @internal
     */
    interface FormatterAwareInterface {
        public function setFormatter(\Monolog\Formatter\FormatterInterface $formatter): HandlerInterface;
        public function getFormatter(): \Monolog\Formatter\FormatterInterface;
    }

    /**
     * Used for type-checking in static analysis.  Not used at runtime.
     * @internal
     */
    interface ProcessableHandlerInterface {
        /** @return HandlerInterface */
        public function pushProcessor(callable $callback);
        public function popProcessor(): callable;
    }

    abstract class FormattableHandler extends AbstractHandler implements FormatterAwareInterface {
        /** @var \Monolog\Formatter\FormatterInterface|null */
        protected $formatter;

        /** @return HandlerInterface */
        public function setFormatter(\Monolog\Formatter\FormatterInterface $formatter): HandlerInterface { $this->formatter = $formatter; return $this; }

        public function getFormatter(): \Monolog\Formatter\FormatterInterface {
            if ($this->formatter === null) {
                $this->formatter = new \Monolog\Formatter\JsonFormatter();
            }
            return $this->formatter;
        }
    }

    abstract class ProcessableHandler extends FormattableHandler implements ProcessableHandlerInterface {
        /** @var callable[] */
        protected $processors = [];

        /** @return HandlerInterface */
        public function pushProcessor(callable $callback) { $this->processors[] = $callback; return $this; }

        public function popProcessor(): callable {
            return function (array $r): array { return $r; };
        }
    }

    abstract class AbstractProcessingHandler extends ProcessableHandler {
        public function handle(array $record): bool { return false; }

        abstract protected function write(array $record): void;
    }

    class StreamHandler extends AbstractProcessingHandler {
        /** @var resource|null */
        protected $stream;
        /** @var string|null */
        protected $url;

        /**
         * @param resource|string $stream
         * @param int|string $level
         * @param bool $bubble
         * @param int|null $filePermission
         * @param bool $useLocking
         */
        public function __construct($stream, $level = \Monolog\Logger::DEBUG, bool $bubble = true, ?int $filePermission = null, bool $useLocking = false) {}

        protected function write(array $record): void {}
    }

    class ErrorLogHandler extends AbstractProcessingHandler {
        public const OPERATING_SYSTEM = 0;
        public const SAPI = 4;

        /** @var int */
        protected $messageType;
        /** @var bool */
        protected $expandNewlines;

        /**
         * @param int $messageType
         * @param int|string $level
         * @param bool $bubble
         * @param bool $expandNewlines
         */
        public function __construct(int $messageType = self::OPERATING_SYSTEM, $level = \Monolog\Logger::DEBUG, bool $bubble = true, bool $expandNewlines = false) {}

        protected function write(array $record): void {}
    }
}

namespace Monolog\Processor {

    class UidProcessor {
        /** @var string */
        protected $uid;
        /** @var int */
        protected $length;

        /**
         * @param int $length
         * @param string $prefix
         */
        public function __construct(int $length = 7, string $prefix = 'uid') {}

        public function getUid(): string { return (string)$this->uid; }

        public function __invoke(array $record): array { return $record; }
    }

    class ProcessIdProcessor {
        public function __invoke(array $record): array { return $record; }
    }

    class WebProcessor {
        /** @var array|null */
        protected $serverData;
        /** @var array|null */
        protected $extraFields;

        /**
         * @param array|string|null $serverData
         * @param array|null $extraFields
         */
        public function __construct($serverData = null, ?array $extraFields = null) {}

        public function __invoke(array $record): array { return $record; }
    }
}

namespace Monolog\Formatter {

    interface FormatterInterface {
        /**
         * @param array $record
         * @return mixed
         */
        public function format(array $record);

        /**
         * @param array $records
         * @return mixed
         */
        public function formatBatch(array $records);
    }

    abstract class NormalizerFormatter implements FormatterInterface {
        public const SIMPLE_DATE = "Y-m-d\TH:i:sP";

        /** @var string */
        protected $dateFormat;

        public function __construct(?string $dateFormat = null) {}

        /**
         * @param array $record
         * @return mixed
         */
        public function format(array $record) { return $record; }

        /**
         * @param array $records
         * @return mixed
         */
        public function formatBatch(array $records) { return $records; }
    }

    class JsonFormatter extends NormalizerFormatter {
        public const BATCH_MODE_JSON = 1;
        public const BATCH_MODE_NEWLINES = 2;

        /** @var int */
        protected $batchMode;
        /** @var bool */
        protected $appendNewline;

        /**
         * @param int $batchMode
         * @param bool $appendNewline
         */
        public function __construct(int $batchMode = self::BATCH_MODE_JSON, bool $appendNewline = true) {}

        public function format(array $record): string { return (string)json_encode($record); }

        public function formatBatch(array $records): string { return (string)json_encode($records); }
    }
}
