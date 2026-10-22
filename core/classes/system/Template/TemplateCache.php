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


namespace Core\System\Template;

class TemplateCache
{
    private string $cachePath;
    private bool $enabled;

    public function __construct(string $cachePath, bool $enabled = true)
    {
        $this->cachePath = rtrim($cachePath, DIRECTORY_SEPARATOR);
        $this->enabled = $enabled;
    }

    /**
     * Получить путь к кеш-файлу
     */
    public function getCacheFile(string $templatePath): string
    {
        $hash = md5($templatePath);
        return $this->cachePath . DIRECTORY_SEPARATOR . $hash . '.php';
    }

    /**
     * Проверить, актуален ли кеш
     */
    public function isFresh(string $templatePath): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $cacheFile = $this->getCacheFile($templatePath);

        if (!file_exists($cacheFile)) {
            return false;
        }

        // Кеш устарел если шаблон изменен после кеширования
        return filemtime($cacheFile) >= filemtime($templatePath);
    }

    /**
     * Сохранить скомпилированный шаблон
     */
    public function put(string $templatePath, string $compiled): bool
    {
        if (!$this->enabled) {
            return false;
        }

        if (!is_dir($this->cachePath)) {
            @mkdir($this->cachePath, 0755, true);
        }

        $cacheFile = $this->getCacheFile($templatePath);
        return file_put_contents($cacheFile, $compiled, LOCK_EX) !== false;
    }

    /**
     * Получить скомпилированный шаблон из кеша
     */
    public function get(string $templatePath): ?string
    {
        if (!$this->isFresh($templatePath)) {
            return null;
        }

        $cacheFile = $this->getCacheFile($templatePath);
        $content = file_get_contents($cacheFile);

        return $content === false ? null : $content;
    }

    /**
     * Очистить кеш для конкретного шаблона
     */
    public function forget(string $templatePath): bool
    {
        $cacheFile = $this->getCacheFile($templatePath);

        if (file_exists($cacheFile)) {
            return @unlink($cacheFile);
        }

        return true;
    }

    /**
     * Очистить весь кеш
     */
    public function flush(): int
    {
        $count = 0;

        if (!is_dir($this->cachePath)) {
            return 0;
        }

        $files = glob($this->cachePath . DIRECTORY_SEPARATOR . '*.php');

        if ($files === false) {
            return 0;
        }

        foreach ($files as $file) {
            if (@unlink($file)) {
                $count++;
            }
        }

        return $count;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    public function getCachePath(): string
    {
        return $this->cachePath;
    }
}