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

use Core\System\Http\Request;
use Core\System\Security\SecurityManager;
use Core\System\Template\Template;

class TemplateServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $security = $this->container->get(SecurityManager::class);
        $cspNonce = $security->getCspNonce();

        // Nonce привязан к SecurityManager этого Application, поэтому Template
        // нельзя хранить в статике между повторными инициализациями процесса.
        $this->container->singleton(
            Template::class,
            fn() => new Template($this->config, $cspNonce)
        );
    }

    public function boot(): void
    {
        /** @var Template $template */
        $template = $this->container->get(Template::class);

        // Текущий путь запроса — используется шаблонами (например,
        // подсветка активного пункта меню в админ-панели).
        // Обёрнут в try/catch: шаблон может рендериться в CLI без HTTP-запроса.
        try {
            $requestPath = $this->container->get(Request::class)->getPath();
        } catch (\Throwable) {
            $requestPath = '/';
        }

        $template->shareMany([
            'app_name'     => $this->config->get('APP_NAME', 'Достойный уход'),
            'base_url'     => rtrim($this->config->get('APP_BASE_URL', ''), '/'),
            'config'       => $this->config,
            'request_path' => $requestPath,
        ]);
    }
}