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


namespace Core\System\Http;

/**
 * Класс для работы с HTTP-куки
 */
class Cookie
{
    private string $name;
    private string $value;
    private int $expire;
    private string $path;
    private string $domain;
    private bool $secure;
    private bool $httpOnly;
    private string $sameSite;

    public function __construct(
        string $name,
        string $value = '',
        int $expire = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = true,
        string $sameSite = 'Lax'
    ) {
        $this->name = $name;
        $this->value = $value;
        $this->expire = $expire;
        $this->path = $path;
        $this->domain = $domain;
        $this->secure = $secure;
        $this->httpOnly = $httpOnly;
        $this->sameSite = $sameSite;
    }

    /**
     * Отправить куку клиенту
     */
    public function send(): bool
    {
        if (headers_sent($file, $line)) {
            error_log("Cannot send cookie '{$this->name}': headers already sent in {$file}:{$line}");
            return false;
        }

        $options = [
            'expires'  => $this->expire,
            'path'     => $this->path,
            'domain'   => $this->domain,
            'secure'   => $this->secure,
            'httponly' => $this->httpOnly,
            'samesite' => $this->sameSite,
        ];

        return setcookie($this->name, $this->value, $options);
    }

    /**
     * Удалить куку (установить срок в прошлом)
     */
    public function delete(): bool
    {
        $this->expire = time() - 3600;
        $this->value = '';
        return $this->send();
    }

    /**
     * Получить значение куки из $_COOKIE
     */
    public static function get(string $name, mixed $default = null): mixed
    {
        return $_COOKIE[$name] ?? $default;
    }

    /**
     * Проверить существование куки
     */
    public static function has(string $name): bool
    {
        return isset($_COOKIE[$name]);
    }

    // ─── Геттеры ───

    public function getName(): string { return $this->name; }
    public function getValue(): string { return $this->value; }
    public function getExpire(): int { return $this->expire; }
    public function getPath(): string { return $this->path; }
    public function getDomain(): string { return $this->domain; }
    public function isSecure(): bool { return $this->secure; }
    public function isHttpOnly(): bool { return $this->httpOnly; }
    public function getSameSite(): string { return $this->sameSite; }

    // ─── Сеттеры (fluent) ───

    public function setValue(string $value): self { $this->value = $value; return $this; }
    public function setExpire(int $expire): self { $this->expire = $expire; return $this; }
    public function setPath(string $path): self { $this->path = $path; return $this; }
    public function setDomain(string $domain): self { $this->domain = $domain; return $this; }
    public function setSecure(bool $secure): self { $this->secure = $secure; return $this; }
    public function setHttpOnly(bool $httpOnly): self { $this->httpOnly = $httpOnly; return $this; }
    public function setSameSite(string $sameSite): self { $this->sameSite = $sameSite; return $this; }

    /**
     * Создать куку на определённое количество секунд
     */
    public static function make(string $name, string $value, int $seconds): self
    {
        return new self($name, $value, time() + $seconds);
    }

    /**
     * Создать сессионную куку (удаляется при закрытии браузера)
     */
    public static function session(string $name, string $value): self
    {
        return new self($name, $value, 0);
    }

    /**
     * Создать безопасную куку (secure + httpOnly + SameSite=Strict)
     */
    public static function secure(string $name, string $value, int $seconds = 0): self
    {
        $cookie = new self($name, $value, $seconds > 0 ? time() + $seconds : 0);
        $cookie->secure = true;
        $cookie->httpOnly = true;
        $cookie->sameSite = 'Strict';
        return $cookie;
    }
}