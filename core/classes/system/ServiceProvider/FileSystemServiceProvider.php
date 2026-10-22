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

class FileSystemServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // File и Directory создаются по требованию через new
    }

    public function boot(): void
    {
        // Корень проекта — единая точка определения (канон — APP_ROOT_PATH,
        // см. Config::getRootPath() → PathResolver/ProjectRoot).
        // Устаревший FS_BASE_PATH упразднён.
        $basePath = $this->config->getRootPath();

        // storage/ — всегда от канонического корня APP_ROOT_PATH (единый резолвер).
        $storage = $this->config->getStoragePath();
        if (!is_dir($storage)) {
            $perm = $this->config->getOctal('FS_DEFAULT_DIR_PERMISSION', 0755);
            @mkdir($storage, $perm, true);
        }

        // logs/ — всегда от канонического корня APP_ROOT_PATH (единый резолвер).
        $logs = $this->config->getLogsPath();
        if (!is_dir($logs)) {
            $perm = $this->config->getOctal('FS_DEFAULT_DIR_PERMISSION', 0755);
            @mkdir($logs, $perm, true);
        }
    }
}