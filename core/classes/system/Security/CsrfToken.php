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

use Core\System\Session\SessionManager;

/**
 * Генерация и валидация CSRF токенов
 */
class CsrfToken
{
    private string $tokenName;
    private int $expireSeconds;
    private bool $enabled;
    private bool $sessionStarted = false;
    private SessionManager $sessions;

    public function __construct(
        string $tokenName = '_csrf_token',
        int $expireSeconds = 3600,
        bool $enabled = true
    ) {
        $this->tokenName = $tokenName;
        $this->expireSeconds = $expireSeconds;
        $this->enabled = $enabled;
        $this->sessions = new SessionManager();
    }

    /**
     * Запустить сессию (вызывать ДО любого вывода!)
     */
    public function startSession(): void
    {
        if ($this->sessionStarted) {
            return;
        }

        if (PHP_SAPI === 'cli') {
            // В CLI используем массив вместо сессии
            $this->sessionStarted = true;
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->sessionStarted = true;
            return;
        }

        if (headers_sent($file, $line)) {
            // Заголовки уже отправлены — пробуем через output buffering
            throw new \RuntimeException(
                "Невозможно запустить сессию: заголовки уже отправлены в {$file}:{$line}. " .
                "Вызовите startSession() ДО любого вывода или используйте output buffering."
            );
        }

        if (!@session_start()) {
            throw new \RuntimeException('Не удалось запустить сессию');
        }

        $this->sessionStarted = true;
    }

    /**
     * Сгенерировать новый CSRF токен
     */
    public function generate(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->storeToken($token);
        return $token;
    }

    /**
     * Получить текущий токен (или сгенерировать новый)
     */
    public function getToken(): string
    {
        if (!$this->enabled) {
            return 'csrf_disabled';
        }

        $this->startSession();

        // В CLI-режиме используем статическое хранилище
        if (PHP_SAPI === 'cli') {
            return $this->getOrCreateCliToken();
        }

        $stored = $this->sessions->get($this->tokenName);
        if ($stored !== null && !$this->isExpired()) {
            return $stored['token'];
        }

        return $this->generate();
    }

    /**
     * Валидировать CSRF токен
     *
     * @throws SecurityException
     */
    public function validate(?string $token): bool
    {
        if (!$this->enabled) {
            return true;
        }

        if ($token === null || $token === '') {
            throw SecurityException::invalidCsrfToken();
        }

        $this->startSession();

        // В CLI-режиме
        if (PHP_SAPI === 'cli') {
            $stored = $this->getCliToken();
            if ($stored === null) {
                throw SecurityException::invalidCsrfToken();
            }

            if (!hash_equals($stored['token'], $token)) {
                throw SecurityException::invalidCsrfToken();
            }

            return true;
        }

        $stored = $this->sessions->get($this->tokenName);
        if ($stored === null) {
            throw SecurityException::invalidCsrfToken();
        }

        // Проверяем срок действия
        if (time() - $stored['time'] > $this->expireSeconds) {
            $this->sessions->remove($this->tokenName);
            throw SecurityException::invalidCsrfToken();
        }

        // Timing-safe сравнение
        if (!hash_equals($stored['token'], $token)) {
            throw SecurityException::invalidCsrfToken();
        }

        return true;
    }

    /**
     * Получить HTML-поле с токеном
     */
    public function getField(): string
    {
        try {
            $token = htmlspecialchars($this->getToken(), ENT_QUOTES, 'UTF-8');
            $name = htmlspecialchars($this->tokenName, ENT_QUOTES, 'UTF-8');

            return '<input type="hidden" name="' . $name . '" value="' . $token . '">';
        } catch (\Throwable $e) {
            error_log("CSRF getField error: {$e->getMessage()}");
            return '<!-- CSRF token unavailable: ' . htmlspecialchars($e->getMessage()) . ' -->';
        }
    }

    /**
     * Получить meta-тег для AJAX-запросов
     */
    public function getMetaTag(): string
    {
        try {
            $token = htmlspecialchars($this->getToken(), ENT_QUOTES, 'UTF-8');
            $name = htmlspecialchars($this->tokenName, ENT_QUOTES, 'UTF-8');

            return '<meta name="csrf-token" data-name="' . $name . '" content="' . $token . '">';
        } catch (\Throwable $e) {
            error_log("CSRF getMetaTag error: {$e->getMessage()}");
            return '';
        }
    }

    public function getTokenName(): string
    {
        return $this->tokenName;
    }

    public function isExpired(): bool
    {
        $this->startSession();

        if (PHP_SAPI === 'cli') {
            $stored = $this->getCliToken();
            return $stored === null || (time() - $stored['time'] > $this->expireSeconds);
        }

        $stored = $this->sessions->get($this->tokenName);
        if ($stored === null) {
            return true;
        }

        return time() - $stored['time'] > $this->expireSeconds;
    }

    public function clear(): void
    {
        $this->startSession();

        if (PHP_SAPI === 'cli') {
            $this->clearCliToken();
            return;
        }

        $this->sessions->remove($this->tokenName);
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    // ─── CLI-режим (эмуляция сессии) ───

    /** @var array<string, array{token: string, time: int}>|null */
    private static ?array $cliStorage = null;

    private function getOrCreateCliToken(): string
    {
        $stored = $this->getCliToken();

        if ($stored !== null && (time() - $stored['time']) <= $this->expireSeconds) {
            return $stored['token'];
        }

        $token = bin2hex(random_bytes(32));
        $this->storeCliToken($token);
        return $token;
    }

    private function getCliToken(): ?array
    {
        if (self::$cliStorage === null || !isset(self::$cliStorage[$this->tokenName])) {
            return null;
        }

        return self::$cliStorage[$this->tokenName];
    }

    private function storeCliToken(string $token): void
    {
        if (self::$cliStorage === null) {
            self::$cliStorage = [];
        }

        self::$cliStorage[$this->tokenName] = [
            'token' => $token,
            'time'  => time(),
        ];
    }

    private function clearCliToken(): void
    {
        if (self::$cliStorage !== null) {
            unset(self::$cliStorage[$this->tokenName]);
        }
    }

    // ─── Приватные методы ───

    private function storeToken(string $token): void
    {
        $this->startSession();

        if (PHP_SAPI === 'cli') {
            $this->storeCliToken($token);
            return;
        }

        $this->sessions->set($this->tokenName, [
            'token' => $token,
            'time'  => time(),
        ]);
    }
}