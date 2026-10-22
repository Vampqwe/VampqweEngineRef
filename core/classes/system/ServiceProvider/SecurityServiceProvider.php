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


namespace Core\System\ServiceProvider;

use Core\System\Logger\Logger;
use Core\System\Security\CsrfToken;
use Core\System\Security\SecurityManager;

/**
 * Провайдер для security-компонентов
 */
class SecurityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(SecurityManager::class, fn() =>
            SecurityManager::fromConfig(
                $this->config,
                $this->container->get(Logger::class)
            )
        );
    }

    public function boot(): void
    {
        $security = $this->container->get(SecurityManager::class);
        $configPath = $this->app->getBasePath() . DIRECTORY_SEPARATOR . 'config.env';
        $security->checkConfigPermissions($configPath);

        // Для HTTP-запросов — запускаем сессию ДО любого вывода
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
            $this->startSession();
        }

        // Отправляем security-заголовки (если это HTTP-запрос)
        if (PHP_SAPI !== 'cli') {
            try {
                if (!headers_sent()) {
                    $security = $this->container->get(SecurityManager::class);
                    $security->sendHeaders();
                }
            } catch (\Throwable $e) {
                error_log("Failed to send security headers: {$e->getMessage()}");
            }
        }
    }

    /**
     * Настроить и запустить HTTP-сессию до отправки заголовков.
     * Ошибка здесь прерывает bootstrap: CSRF-защита не должна работать
     * в режиме, где PHP не смог применить обязательные параметры сессии.
     */
    private function startSession(): void
    {
        // Единый дефолт для SESSION_COOKIE_SECURE: безопасное значение true
        // (исторически здесь стоял false, что расходилось с SessionManager::destroy(),
        // где дефолт true — при пустом/отсутствующем значении в config.env куки
        // уходят без флага Secure). Конкретные значения задаются в config.env.
        $secure = $this->config->getBool('SESSION_COOKIE_SECURE', true);
        $sameSite = ucfirst(strtolower(
            $this->config->get('SESSION_COOKIE_SAMESITE', 'Lax') ?? 'Lax'
        ));

        if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            throw new \InvalidArgumentException(
                "Недопустимое значение SESSION_COOKIE_SAMESITE: {$sameSite}"
            );
        }

        // Браузеры требуют Secure для SameSite=None; иначе cookie будет отвергнута.
        if ($sameSite === 'None' && !$secure) {
            throw new \InvalidArgumentException(
                'SESSION_COOKIE_SECURE должен быть true при SESSION_COOKIE_SAMESITE=None'
            );
        }

        if (headers_sent($file, $line)) {
            throw new \RuntimeException(
                "Невозможно запустить сессию: заголовки уже отправлены в {$file}:{$line}"
            );
        }

        // PHP должен принимать ID только из cookie и отвергать незнакомые ID,
        // чтобы нельзя было навязать сессию через URL или фиктивный идентификатор.
        $sessionDirectives = [
            'session.use_strict_mode' => $this->config->getBool('SESSION_STRICT_MODE', true) ? '1' : '0',
            'session.use_only_cookies' => '1',
            'session.use_trans_sid' => '0',
        ];

        foreach ($sessionDirectives as $directive => $value) {
            if (ini_set($directive, $value) === false) {
                throw new \RuntimeException("Не удалось установить PHP-параметр {$directive}");
            }
        }

        // Параметры cookie задаются до session_start(), потому что после старта
        // PHP уже может отправить заголовок Set-Cookie с прежними настройками.
        $cookieOptions = [
            'lifetime' => 0,
            'path' => $this->config->get('SESSION_COOKIE_PATH', '/') ?? '/',
            'secure' => $secure,
            'httponly' => $this->config->getBool('SESSION_COOKIE_HTTPONLY', true),
            'samesite' => $sameSite,
        ];

        if (!session_set_cookie_params($cookieOptions)) {
            throw new \RuntimeException('Не удалось применить параметры cookie сессии');
        }

        if (!session_start()) {
            throw new \RuntimeException('Не удалось запустить HTTP-сессию');
        }
    }
}