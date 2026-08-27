<?php

namespace Hub\Log;

use Monolog\Logger as MonologLogger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

class Logger
{
    private static array $instances = [];

    public static function channel(string $name = 'app'): MonologLogger
    {
        if (isset(self::$instances[$name])) {
            return self::$instances[$name];
        }

        $levelName = strtoupper(getenv('LOG_LEVEL') ?: 'info');
        $level = defined("Monolog\Logger::$levelName")
            ? constant("Monolog\Logger::$levelName")
            : MonologLogger::INFO;

        $formatter = new LineFormatter(
            "[%datetime%] %channel%.%level_name%: %message% %context%\n",
            'Y-m-d H:i:s'
        );
        // Guarda calibrada pelas respostas reais: a mais funda tem 10 níveis, o limite do Monolog tem 9.
        $formatter->setMaxNormalizeDepth(32);
        $handler = new StreamHandler('php://stdout', $level);
        $handler->setFormatter($formatter);

        $log = new MonologLogger($name);
        $log->pushHandler($handler);

        // O canal `api` sai à parte: o volume do registo de pedidos apaga a retenção operacional.
        $logFile = ($name === 'api' ? getenv('LOG_FILE_API') : false) ?: getenv('LOG_FILE');
        if ($logFile) {
            $dir = dirname($logFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $fileHandler = new StreamHandler($logFile, $level);
            $fileHandler->setFormatter($formatter);
            $log->pushHandler($fileHandler);
        }

        self::$instances[$name] = $log;
        return $log;
    }

    public static function reset(): void
    {
        self::$instances = [];
    }
}
