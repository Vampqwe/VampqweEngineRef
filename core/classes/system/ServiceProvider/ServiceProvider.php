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

use Core\System\Application\Application;
use Core\System\Config\Config;
use Core\System\Container\Container;

/**
 * Абстрактный базовый класс для провайдеров сервисов.
 * Каждый провайдер регистрирует свои сервисы в контейнере.
 */
abstract class ServiceProvider
{
    protected Application $app;
    protected Container $container;
    protected Config $config;

    public function __construct(Application $app)
    {
        $this->app       = $app;
        $this->container = $app->getContainer();
        $this->config    = $app->getConfig();
    }

    /**
     * Зарегистрировать сервисы в контейнере.
     * Вызывается при инициализации приложения.
     */
    abstract public function register(): void;

    /**
     * Выполнить действия после регистрации всех провайдеров.
     * Опционально — по умолчанию ничего не делает.
     */
    public function boot(): void
    {
        // По умолчанию ничего
    }
}