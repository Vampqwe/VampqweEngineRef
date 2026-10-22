<?php

declare(strict_types=1);

/**
 * VampqweEngineMedium — проприетарный PHP-движок
 *
 * @copyright  Copyright (c) 2024-2026. Все права защищены.
 * @author     [Дедюля Александр Иванович / VampqweEngine]
 * @license    Proprietary
 * @link       https://github.com/Vampqwe/VampqweEngine.git
 * @contact      Указывается в LICENSE-файле дистрибутива
 *
 * Данный код является интеллектуальной собственностью автора.
 * Любое копирование, распространение, модификация или использование
 * без письменного разрешения правообладателя строго запрещено.
 *
 * This software is proprietary and confidential.
 * Unauthorized copying, distribution, or use is strictly prohibited.
 */


namespace Core\System\Logger;

use Core\System\Config\Config;
use Core\System\Security\SensitiveDataMasker;
use Throwable;

/**
 * Класс для логирования событий приложения с маскированием чувствительных данных.
 */
class Logger
{
    private Config $config;
    private bool $enabled;
    private LogLevel $minLevel;
    private string $logFile;
    private int $maxSize;
    private bool $rotation;
    private string $dateFormat;
    private bool $maskSensitive;
    private ?SensitiveDataMasker $masker;

    /** @var resource|null */
    private $stream = null;

    public function __construct(Config $config, ?SensitiveDataMasker $masker = null)
    {
        $this->config        = $config;
        $this->enabled       = $config->getBool('LOG_ENABLED', true);
        $this->minLevel      = LogLevel::fromString($config->get('LOG_LEVEL', 'debug'));
        // LOG_FILE — путь ОТНОСИТЕЛЬНО корня проекта (APP_ROOT_PATH):
        // /log/app.log или logs/app.log → {корень}/log|logs/.... Абсолютные
        // пути в конфиге не поддерживаются (единая точка входа — APP_ROOT_PATH).
        $this->logFile       = $config->resolvePath(
            (string) $config->get('LOG_FILE', ''),
            'logs/app.log'
        );
        $this->maxSize       = $config->getInt('LOG_MAX_SIZE', 10 * 1024 * 1024);
        $this->rotation      = $config->getBool('LOG_ROTATION', true);
        $this->dateFormat    = $config->get('LOG_DATE_FORMAT', 'Y-m-d H:i:s.u');
        $this->maskSensitive = $config->getBool('LOG_MASK_SENSITIVE', true);

        if ($this->maskSensitive && $masker === null) {
            $sensitiveKeys = $config->get('SECURITY_SENSITIVE_KEYS', '');
            $this->masker = SensitiveDataMasker::fromString($sensitiveKeys);
        } else {
            $this->masker = $masker;
        }
    }

    // ─── Управление логгером ───

    public function enable(): self
    {
        $this->enabled = true;
        return $this;
    }

    public function disable(): self
    {
        $this->enabled = false;
        $this->closeStream();
        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setLevel(LogLevel $level): self
    {
        $this->minLevel = $level;
        return $this;
    }

    public function getLevel(): LogLevel
    {
        return $this->minLevel;
    }

    public function setFile(string $path): self
    {
        $this->closeStream();
        $this->logFile = $path;
        return $this;
    }

    public function getFile(): string
    {
        return $this->logFile;
    }

    /**
     * Копия логгера с другим целевым файлом (напр. отдельный аудит-лог).
     * Уровень, формат и маскирование наследуются; поток — свой.
     */
    public function forFile(string $path): self
    {
        $clone = clone $this;
        $clone->stream   = null;
        $clone->logFile  = $path;
        return $clone;
    }

    public function setMasker(?SensitiveDataMasker $masker): self
    {
        $this->masker = $masker;
        return $this;
    }

    // ─── Основные методы логирования ───

    /**
     * @param array<mixed> $context
     */
    public function log(LogLevel $level, string $message, array $context = []): void
    {
        if (!$this->enabled) {
            return;
        }

        if ($level->priority() < $this->minLevel->priority()) {
            return;
        }

        // Маскируем чувствительные данные
        if ($this->maskSensitive && $this->masker !== null) {
            $context = $this->masker->mask($context);
        }

        $this->write($level, $message, $context);
    }

    /**
     * @param array<mixed> $context
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log(LogLevel::Debug, $message, $context);
    }

    /**
     * @param array<mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->log(LogLevel::Info, $message, $context);
    }

    /**
     * @param array<mixed> $context
     */
    public function notice(string $message, array $context = []): void
    {
        $this->log(LogLevel::Notice, $message, $context);
    }

    /**
     * @param array<mixed> $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log(LogLevel::Warning, $message, $context);
    }

    /**
     * @param array<mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->log(LogLevel::Error, $message, $context);
    }

    /**
     * @param array<mixed> $context
     */
    public function critical(string $message, array $context = []): void
    {
        $this->log(LogLevel::Critical, $message, $context);
    }

    /**
     * @param array<mixed> $context
     */
    public function alert(string $message, array $context = []): void
    {
        $this->log(LogLevel::Alert, $message, $context);
    }

    /**
     * @param array<mixed> $context
     */
    public function emergency(string $message, array $context = []): void
    {
        $this->log(LogLevel::Emergency, $message, $context);
    }

    public function exception(Throwable $e, LogLevel $level = LogLevel::Error): void
    {
        $context = [
            'class'   => get_class($e),
            'message' => $e->getMessage(),
            'code'    => $e->getCode(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
            'trace'   => $e->getTraceAsString(),
        ];

        $this->log($level, "Исключение: {$e->getMessage()}", $context);
    }

    public function dump(mixed $variable, ?string $label = null): void
    {
        if (!$this->enabled || LogLevel::Dump->priority() < $this->minLevel->priority()) {
            return;
        }

        $output = $this->formatDump($variable, $label);
        $this->write(LogLevel::Dump, $output);
    }

    public function dd(mixed $variable, ?string $label = null): never
    {
        $this->dump($variable, $label);
        exit(1);
    }

    /**
     * @param array<mixed> $params
     */
    public function sql(string $sql, array $params = [], float $time = 0.0): void
    {
        $context = [
            'sql'    => $sql,
            'params' => $params,
            'time'   => round($time * 1000, 2) . ' ms',
        ];

        $this->log(LogLevel::Debug, "SQL: {$sql}", $context);
    }

    /**
     * @param array<string, mixed> $headers
     */
    public function http(string $method, string $url, int $status, array $headers = []): void
    {
        $context = [
            'method'  => $method,
            'url'     => $url,
            'status'  => $status,
            'headers' => $headers,
        ];

        $level = $status >= 500 ? LogLevel::Error : ($status >= 400 ? LogLevel::Warning : LogLevel::Info);

        $this->log($level, "HTTP {$method} {$url} → {$status}", $context);
    }

    // ─── Приватные методы ───

    /**
     * @param array<mixed> $context
     */
    private function write(LogLevel $level, string $message, array $context = []): void
    {
        try {
            $this->ensureLogFileReady();
            $this->rotateIfNeeded();

            $formatted = $this->formatMessage($level, $message, $context);

            if (fwrite($this->stream, $formatted) === false) {
                throw LoggerException::writeFailed("Не удалось записать в {$this->logFile}");
            }

            fflush($this->stream);

        } catch (Throwable $e) {
            error_log("Logger error: {$e->getMessage()}");
        }
    }

    /**
     * @param array<mixed> $context
     */
private function formatMessage(LogLevel $level, string $message, array $context = []): string
{
    // Проверяем, есть ли уже микросекунды в формате
    $hasMicroseconds = str_contains($this->dateFormat, '.u') 
                    || str_contains($this->dateFormat, '.v');
    
    if ($hasMicroseconds) {
        // Микросекунды уже в формате — используем просто date()
        $date = date($this->dateFormat);
    } else {
        // Добавляем микросекунды вручную
        $timestamp = microtime(true);
        $date = date($this->dateFormat, (int) $timestamp) .
            sprintf('.%06d', ($timestamp - floor($timestamp)) * 1000000);
    }

    $emoji    = $level->emoji();
    $levelStr = str_pad($level->value, 9, ' ', STR_PAD_RIGHT);

    $message = $this->interpolate($message, $context);

    $line = "[{$date}] {$emoji} [{$levelStr}] {$message}";

    if (!empty($context)) {
        $line .= "\n" . $this->formatContext($context);
    }

    return $line . PHP_EOL;
}

    /**
     * @param array<mixed> $context
     */
    private function interpolate(string $message, array $context): string
    {
        $replace = [];

        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $replace['{' . $key . '}'] = (string) $value;
            }
        }

        return strtr($message, $replace);
    }

    /**
     * @param array<mixed> $context
     */
    private function formatContext(array $context): string
    {
        $lines = [];

        foreach ($context as $key => $value) {
            $formatted = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $lines[] = "  [{$key}] => {$formatted}";
        }

        return implode(PHP_EOL, $lines);
    }

    private function formatDump(mixed $variable, ?string $label): string
    {
        $type = get_debug_type($variable);

        $output = "────────────────────────────────────────\n";

        if ($label !== null) {
            $output .= "📌 {$label} ({$type}):\n";
        } else {
            $output .= "📦 Dump ({$type}):\n";
        }

        $output .= "────────────────────────────────────────\n";

        if (is_string($variable)) {
            $output .= "string({$this->strlen($variable)}) \"{$variable}\"\n";
        } elseif (is_int($variable) || is_float($variable) || is_bool($variable)) {
            $output .= var_export($variable, true) . "\n";
        } elseif (is_null($variable)) {
            $output .= "null\n";
        } elseif (is_array($variable)) {
            // Маскируем массив перед дампом
            if ($this->maskSensitive && $this->masker !== null) {
                $variable = $this->masker->mask($variable);
            }
            $output .= "array(" . count($variable) . ") " .
                json_encode($variable, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        } elseif (is_object($variable)) {
            $output .= get_class($variable) . " " .
                json_encode($variable, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        } elseif (is_resource($variable)) {
            $output .= "resource(" . get_resource_type($variable) . ")\n";
        } else {
            $output .= var_export($variable, true) . "\n";
        }

        $output .= "────────────────────────────────────────";

        return $output;
    }

    private function strlen(string $str): int
    {
        return function_exists('mb_strlen') ? mb_strlen($str) : strlen($str);
    }

    private function ensureLogFileReady(): void
    {
        if ($this->stream !== null) {
            return;
        }

        $dir = dirname($this->logFile);

        if (!is_dir($dir)) {
            // Логи могут содержать персональные данные и внутренние пути —
            // каталог создаётся с правами 0750 (без group/world доступа на чтение).
            $dirPerm = 0750;

            if (!mkdir($dir, $dirPerm, true) && !is_dir($dir)) {
                throw LoggerException::directoryCreationFailed($dir);
            }
            @chmod($dir, $dirPerm);
        }

        if (!is_writable($dir)) {
            throw LoggerException::fileNotWritable($this->logFile);
        }

        $existed = is_file($this->logFile);
        $this->stream = fopen($this->logFile, 'ab');

        if ($this->stream === false) {
            throw LoggerException::fileNotWritable($this->logFile);
        }

        // Файл лога создаётся/открывается с правами 0640: читает только веб-группа.
        if (!$existed) {
            @chmod($this->logFile, 0640);
        }
    }

    private function rotateIfNeeded(): void
    {
        if (!$this->rotation || $this->stream === null) {
            return;
        }

        clearstatcache(true, $this->logFile);
        $size = filesize($this->logFile);

        if ($size === false || $size < $this->maxSize) {
            return;
        }

        $this->closeStream();

        $backupName = $this->logFile . '.' . date('Y-m-d_H-i-s');

        if (!rename($this->logFile, $backupName)) {
            error_log("Logger: не удалось выполнить ротацию логов");
            return;
        }

        $this->cleanupOldLogs();
    }

    private function cleanupOldLogs(int $keep = 10): void
    {
        $dir   = dirname($this->logFile);
        $base  = basename($this->logFile);
        $files = glob($dir . DIRECTORY_SEPARATOR . $base . '.*');

        if ($files === false || count($files) <= $keep) {
            return;
        }

        usort($files, fn(string $a, string $b) => filemtime($b) - filemtime($a));

        $toDelete = array_slice($files, $keep);

        foreach ($toDelete as $file) {
            @unlink($file);
        }
    }

    private function closeStream(): void
    {
        if ($this->stream !== null) {
            fclose($this->stream);
            $this->stream = null;
        }
    }

    public function __destruct()
    {
        $this->closeStream();
    }
}