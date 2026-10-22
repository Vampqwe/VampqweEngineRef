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


namespace Core\System\Security;

use Core\System\Config\Config;
use Core\System\Logger\Logger;
use Core\System\Logger\LogLevel;
use Throwable;

/**
 * Аудит-логгер для записи security-событий
 */
class AuditLogger
{
    private Logger $logger;
    private SensitiveDataMasker $masker;
    private bool $enabled;
    private ?string $userId;

    public function __construct(
        Logger $logger,
        SensitiveDataMasker $masker,
        bool $enabled = true,
        ?string $userId = null
    ) {
        $this->logger = $logger;
        $this->masker = $masker;
        $this->enabled = $enabled;
        $this->userId = $userId;
    }

    /**
     * Записать действие пользователя
     *
     * @param array<string, mixed> $context
     */
    public function logAction(
        string $action,
        ?string $resource = null,
        ?string $resourceId = null,
        array $context = [],
        LogLevel $level = LogLevel::Info
    ): void {
        if (!$this->enabled) {
            return;
        }

        $message = "AUDIT: {$action}";

        if ($resource !== null) {
            $message .= " [{$resource}]";

            if ($resourceId !== null) {
                $message .= "#{$resourceId}";
            }
        }

        $fullContext = array_merge([
            'user_id'   => $this->userId ?? 'anonymous',
            'ip'        => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'uri'       => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'method'    => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
        ], $this->masker->mask($context));

        $this->logger->log($level, $message, $fullContext);
    }

    /**
     * Записать успешный вход
     *
     * @param array<string, mixed> $context
     */
    public function logLogin(string $identifier, array $context = []): void
    {
        $this->logAction('LOGIN_SUCCESS', 'user', $identifier, $context);
    }

    /**
     * Записать неудачный вход
     *
     * @param array<string, mixed> $context
     */
    public function logFailedLogin(string $identifier, string $reason, array $context = []): void
    {
        $context['reason'] = $reason;
        $this->logAction('LOGIN_FAILED', 'user', $identifier, $context, LogLevel::Warning);
    }

    /**
     * Записать выход
     */
    public function logLogout(string $identifier): void
    {
        $this->logAction('LOGOUT', 'user', $identifier);
    }

    /**
     * Записать изменение данных
     *
     * @param array<string, mixed> $changes
     */
    public function logDataChange(string $resource, string $resourceId, array $changes = []): void
    {
        $this->logAction('DATA_CHANGE', $resource, $resourceId, [
            'changes' => $this->masker->mask($changes),
        ]);
    }

    /**
     * Записать security-событие (попытка атаки)
     *
     * @param array<string, mixed> $context
     */
    public function logSecurityEvent(string $eventType, array $context = []): void
    {
        $this->logAction('SECURITY_EVENT', $eventType, null, $context, LogLevel::Warning);
    }

    /**
     * Записать ошибку доступа
     */
    public function logAccessDenied(string $resource, ?string $resourceId = null): void
    {
        $this->logAction('ACCESS_DENIED', $resource, $resourceId, [], LogLevel::Warning);
    }

    /**
     * Записать исключение
     */
    public function logException(Throwable $e, array $context = []): void
    {
        $this->logAction('EXCEPTION', get_class($e), null, array_merge($context, [
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
        ]), LogLevel::Error);
    }

    /**
     * Установить ID текущего пользователя
     */
    public function setUserId(?string $userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    /**
     * Включить/выключить аудит
     */
    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }
}