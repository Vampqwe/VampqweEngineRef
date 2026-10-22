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


namespace Core\System\Error;

use Core\System\Config\Config;
use Core\System\Logger\Logger;
use Core\System\Logger\LogLevel;
use Throwable;

/**
 * Глобальный обработчик ошибок приложения
 */
class ErrorHandler
{
    private Config $config;
    private ?Logger $logger;
    private ErrorPage $errorPage;
    private bool $debug;
    private bool $registered = false;

    public function __construct(Config $config, ?Logger $logger = null, ?string $cspNonce = null)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->debug = $config->getBool('APP_DEBUG', false);
        $this->errorPage = new ErrorPage(
            $this->debug,
            $config->get('APP_NAME', 'Application'),
            $cspNonce
        );
    }

    /**
     * Зарегистрировать все обработчики
     */
    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        // Обработка исключений
        set_exception_handler([$this, 'handleException']);

        // Обработка ошибок PHP (warning, notice, etc.)
        set_error_handler([$this, 'handleError']);

        // Обработка фатальных ошибок (shutdown)
        register_shutdown_function([$this, 'handleShutdown']);

        $this->registered = true;
    }

    /**
     * Обработка исключений
     */
    public function handleException(Throwable $exception): void
    {
        // Логируем
        $this->logException($exception);

        // Отправляем ответ
        $this->sendResponse($this->errorPage->render($exception), 500);
    }

    /**
     * Обработка ошибок PHP
     */
    public function handleError(
        int $severity,
        string $message,
        string $file,
        int $line,
        ?array $context = null
    ): bool {
        // Если ошибка подавлена оператором @
        if (!(error_reporting() & $severity)) {
            return false;
        }

        $exception = new ErrorException($message, 0, $severity, $file, $line);

        // Логируем
        if ($this->logger !== null) {
            $level = $this->mapSeverityToLogLevel($severity);
            $this->logger->log($level, $message, [
                'file' => $file,
                'line' => $line,
                'severity' => $exception->getLevelName(),
            ]);
        }

        // Фатальные ошибки — показываем страницу
        if ($exception->isFatal()) {
            $this->sendResponse($this->errorPage->render($exception), 500);
            return true;
        }

        // Нефатальные — продолжаем выполнение
        return false;
    }

    /**
     * Обработка shutdown (фатальные ошибки без Throwable)
     */
    public function handleShutdown(): void
    {
        $error = error_get_last();

        if ($error === null) {
            return;
        }

        // Проверяем, что это фатальная ошибка
        $fatalTypes = [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE];

        if (!in_array($error['type'], $fatalTypes, true)) {
            return;
        }

        // Логируем
        if ($this->logger !== null) {
            $this->logger->emergency($error['message'], [
                'file' => $error['file'],
                'line' => $error['line'],
                'type' => 'PHP Fatal Error',
            ]);
        }

        // Показываем страницу
        $html = $this->errorPage->renderFatal(
            $error['message'],
            $error['file'],
            $error['line']
        );

        $this->sendResponse($html, 500);
    }

    /**
     * Отправить HTML-ответ
     */
    private function sendResponse(string $html, int $statusCode): void
    {
        // Если заголовки уже отправлены — ничего не делаем
        if (headers_sent()) {
            echo $html;
            return;
        }

        http_response_code($statusCode);
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Error-Handler: VampqweEngine');

        echo $html;
    }

    /**
     * Логирование исключения
     */
    private function logException(Throwable $exception): void
    {
        if ($this->logger === null) {
            return;
        }

        $this->logger->exception($exception);

        // Логируем цепочку previous
        $previous = $exception->getPrevious();
        while ($previous !== null) {
            $this->logger->error('Previous exception: ' . get_class($previous), [
                'message' => $previous->getMessage(),
                'file' => $previous->getFile(),
                'line' => $previous->getLine(),
            ]);
            $previous = $previous->getPrevious();
        }
    }

    /**
     * Маппинг severity на LogLevel
     */
    private function mapSeverityToLogLevel(int $severity): LogLevel
    {
        return match ($severity) {
            E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE => LogLevel::Emergency,
            E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING => LogLevel::Warning,
            E_NOTICE, E_USER_NOTICE => LogLevel::Notice,
            E_DEPRECATED, E_USER_DEPRECATED => LogLevel::Debug,
            default => LogLevel::Error,
        };
    }
}