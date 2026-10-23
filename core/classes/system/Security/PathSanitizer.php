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

/**
 * Санитизация путей для защиты от Path Traversal атак
 */
class PathSanitizer
{
    private string $basePath;
    private bool $enforceBasePath;

    public function __construct(string $basePath, bool $enforceBasePath = true)
    {
        $this->basePath = $this->normalizePath($basePath);
        $this->enforceBasePath = $enforceBasePath;
    }

    /**
     * Санитизировать и валидировать путь
     *
     * @throws SecurityException
     */
    public function sanitize(string $path): string
    {
        // Нормализуем путь
        $path = $this->normalizePath($path);

        // Убираем null bytes
        $path = str_replace("\0", '', $path);

        // Если путь абсолютный
        if ($this->isAbsolutePath($path)) {
            // Проверяем, находится ли он внутри базовой директории
            if ($this->isInsideBasePath($path)) {
                // Путь уже корректный — возвращаем как есть
                return $path;
            }

            // Если не принуждаем базовый путь — возвращаем абсолютный путь
            if (!$this->enforceBasePath) {
                return $path;
            }

            // Иначе — ошибка
            throw SecurityException::pathOutsideBase($path, $this->basePath);
        }

        // Убираем относительные компоненты
        $path = $this->removeRelativeComponents($path);

        // Формируем полный путь
        $fullPath = $this->basePath . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);

        // Пытаемся получить реальный путь
        $realPath = realpath($fullPath);

        if ($realPath !== false) {
            // Файл/директория существует — используем реальный путь
            $fullPath = $this->normalizePath($realPath);
        } else {
            // Файл не существует — проверяем родительскую директорию
            $parentDir = dirname($fullPath);
            $realParent = realpath($parentDir);

            if ($realParent !== false) {
                $basename = basename($fullPath);
                
                // Защита от ../ в basename
                if ($basename === '.' || $basename === '..') {
                    throw SecurityException::pathTraversal($path);
                }
                
                $fullPath = $this->normalizePath($realParent) . DIRECTORY_SEPARATOR . $basename;
            }
        }

        // Финальная проверка, что путь внутри базовой директории
        if (!$this->isInsideBasePath($fullPath)) {
            throw SecurityException::pathOutsideBase($path, $this->basePath);
        }

        return $fullPath;
    }

    /**
     * Нормализовать путь
     */
    private function normalizePath(string $path): string
    {
        // Заменяем все слеши на системный разделитель
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        // Убираем повторяющиеся разделители
        $path = preg_replace('#' . preg_quote(DIRECTORY_SEPARATOR, '#') . '{2,}#', DIRECTORY_SEPARATOR, $path);

        // Убираем завершающий разделитель (кроме корневой директории)
        if (strlen($path) > 1) {
            $path = rtrim($path, DIRECTORY_SEPARATOR);
        }

        return $path;
    }

    /**
     * Проверить, является ли путь абсолютным
     */
    public function isAbsolutePath(string $path): bool
    {
        // На Windows: C:\ или C:/
        if (preg_match('#^[A-Za-z]:[/\\\\]#', $path)) {
            return true;
        }

        // На Unix: начинается с /
        if (DIRECTORY_SEPARATOR === '/' && str_starts_with($path, '/')) {
            return true;
        }

        return false;
    }

    /**
     * Проверить, находится ли путь внутри базовой директории
     */
    public function isInsideBasePath(string $path): bool
    {
        $normalizedPath = $this->normalizePath($path);
        $normalizedBase = $this->normalizePath($this->basePath);

        // Пытаемся получить реальный путь (если существует)
        $realPath = realpath($normalizedPath);
        if ($realPath !== false) {
            $normalizedPath = $this->normalizePath($realPath);
        }

        // Проверяем, что путь начинается с базового
        $pathWithSep = $normalizedPath . DIRECTORY_SEPARATOR;
        $baseWithSep = $normalizedBase . DIRECTORY_SEPARATOR;

        return str_starts_with($pathWithSep, $baseWithSep)
            || $normalizedPath === $normalizedBase;
    }

    /**
     * Убрать относительные компоненты (../ и ./)
     */
    private function removeRelativeComponents(string $path): string
    {
        // Удаляем ./
        $path = preg_replace('#(^|' . preg_quote(DIRECTORY_SEPARATOR, '#') . ')\./#', '$1', $path);

        // Удаляем ../
        $path = preg_replace('#(^|' . preg_quote(DIRECTORY_SEPARATOR, '#') . ')\.\./#', '$1', $path);

        // Удаляем оставшиеся .. в конце
        $path = preg_replace('#' . preg_quote(DIRECTORY_SEPARATOR, '#') . '\.\.$#', '', $path);

        // Если путь начинается с .. — это попытка атаки
        if (str_starts_with($path, '..' . DIRECTORY_SEPARATOR) || $path === '..') {
            throw SecurityException::pathTraversal($path);
        }

        return $path;
    }

    /**
     * Получить базовый путь
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }
}