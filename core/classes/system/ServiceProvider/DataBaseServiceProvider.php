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

use Core\System\DataBase\DataBase;
use Throwable;

/**
 * Провайдер для базы данных
 */
class DataBaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Примечание: замыкание выполняется ЛЕНИВО (при первом get(DataBase::class)),
        // поэтому исключение из конструктора (например, неактивный драйвер
        // DB_DRIVER=sqlite) всплывает внутри boot()/try-catch, а не на register().
        $this->container->singleton(DataBase::class, fn() =>
            new DataBase($this->config)
        );
    }

    /**
     * Проверка подключения на старте — ОПЦИОНАЛЬНА и БЕЗОПАСНА.
     *
     * Раньше здесь безусловно вызывался getConnection(): при первом запуске
     * (пустая/недоступная БД) bootstrap падал с фатальной ошибкой ДО
     * регистрации InstallGateMiddleware и ErrorHandler, и вместо страницы
     * «Движок не установлен» пользователь получал HTTP 500.
     *
     * Теперь:
     *  1) проверка уважает DB_CHECK_ON_BOOT (конфиг больше не игнорируется);
     *  2) любое исключение перехватывается — приложение стартует ВСЕГДА,
     *     подключение остаётся ленивым (реальная ошибка всплывёт там,
     *     где БД действительно нужна, или будет корректно обработана
     *     шлюзом установки / readiness-пробой).
     */
    public function boot(): void
    {
        if (!$this->config->getBool('DB_CHECK_ON_BOOT', true)) {
            return;
        }

        try {
            $db = $this->container->get(DataBase::class);
            $db->getConnection();
        } catch (Throwable $e) {
            // Не роняем приложение: состояние определит InstalledGuard
            // (EngineState), а смониторит — /api/health/ready.
            error_log('[DataBaseServiceProvider] DB unavailable at boot: ' . $e->getMessage());
        }
    }
}