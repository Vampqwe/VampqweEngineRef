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

class LoggerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(Logger::class, fn() =>
            new Logger($this->config)
        );
    }

    public function boot(): void
    {
        if ($this->config->getBool('LOG_ENABLED', true)) {
            $logger = $this->container->get(Logger::class);
            $logger->info('Приложение запущено', [
                'base_path' => $this->app->getBasePath(),
                'php'       => PHP_VERSION,
            ]);
        }
    }
}