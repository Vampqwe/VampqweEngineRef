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


namespace Core\System\Session;

use Core\System\Config\Config;

/**
 * Модуль «Система управления сессиями».
 *
 * Единая точка доступа к $_SESSION для всего движка: раньше контроллеры
 * трогали суперглобальный массив напрямую, из-за чего невозможно было
 * централизованно обеспечить регенерацию идентификатора (защита от
 * session fixation) и корректное завершение сессии.
 */
class SessionManager
{
    /** Ключ администратора в данных сессии */
    public const ADMIN_KEY = 'admin_user';

    private Config $config;

    public function __construct(?Config $config = null)
    {
        $this->config = $config ?? Config::getInstance();
    }

    /** Активна ли сессия в текущем запросе. */
    public function isActive(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Регенерация идентификатора сессии.
     *
     * Обязательна при любом изменении привилегий (вход/выход), иначе
     * злоумышленник, заранее подсадивший свой session id жертве, получает
     * аутентифицированную сессию (session fixation).
     */
    public function regenerate(bool $deleteOldSession = true): void
    {
        if ($this->isActive()) {
            @session_regenerate_id($deleteOldSession);
        }
    }

    /** Полное уничтожение сессии (данные + storage + cookie). */
    public function destroy(): void
    {
        if (!$this->isActive()) {
            return;
        }

        $_SESSION = [];

        // Параметры куки берём из того же конфига, что и SecurityServiceProvider.
        if (!headers_sent()) {
            setcookie(session_name() ?: 'PHPSESSID', '', [
                'expires' => time() - 42000,
                'path' => $this->config->get('SESSION_COOKIE_PATH', '/') ?? '/',
                'domain' => '',
                'secure' => $this->config->getBool('SESSION_COOKIE_SECURE', true),
                'httponly' => $this->config->getBool('SESSION_COOKIE_HTTPONLY', true),
                'samesite' => $this->config->get('SESSION_COOKIE_SAMESITE', 'Lax') ?? 'Lax',
            ]);
        }

        @session_destroy();
    }

    // ─── Доменные хелперы админ-аутентификации ───

    /** Данные текущего администратора (или null, если не авторизован). */
    public function adminUser(): ?array
    {
        $user = $this->get(self::ADMIN_KEY);
        return is_array($user) ? $user : null;
    }

    public function isAdminAuthenticated(): bool
    {
        return $this->adminUser() !== null;
    }

    /**
     * Установить аутентификацию администратора: запись данных + обязательная
     * регенерация ID перед записью (защита от session fixation).
     */
    public function loginAdmin(array $userData): void
    {
        $this->regenerate(true);
        $this->set(self::ADMIN_KEY, $userData);
    }

    /** Снять аутентификацию администратора, сохранив flash-сообщения сессии. */
    public function logoutAdmin(): void
    {
        $this->remove(self::ADMIN_KEY);
        // Разграничение привилегий: при logout тоже регенерируем ID —
        // старый идентификатор не должен остаться «запоминаемым» маркером.
        $this->regenerate(true);
    }

    /**
     * Забыть одноразовые ключи троттлинга (например, счётчика просмотров)
     * в текущей сессии. Вызывается при logout, чтобы новый посетитель на
     * общем компьютере не наследовал состояние предыдущего пользователя.
     */
    public function forgetTransientMarkers(string $prefix): void
    {
        foreach (array_keys($_SESSION ?? []) as $key) {
            if (is_string($key) && str_starts_with($key, $prefix)) {
                unset($_SESSION[$key]);
            }
        }
    }
}
