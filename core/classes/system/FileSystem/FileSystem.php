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


namespace Core\System\FileSystem;

use Core\System\Config\Config;
use Core\System\Security\PathSanitizer;

/**
 * Абстрактный базовый класс для всех сущностей файловой системы.
 */
abstract class FileSystem
{
    protected string $path;
    protected Config $config;
    protected PathSanitizer $pathSanitizer;

    public function __construct(string $path, Config $config, ?PathSanitizer $pathSanitizer = null)
    {
        $this->config = $config;
        $this->pathSanitizer = $pathSanitizer ?? new PathSanitizer(
            // Корень проекта — единый резолвер (канон — APP_ROOT_PATH).
            $config->getRootPath(),
            $config->getBool('FS_ENFORCE_BASE_PATH', true)
        );
        $this->path = $this->resolvePath($path);
    }

    // ─── Абстрактные методы ───

    abstract public function getType(): FileSystemType;

    abstract public function exists(): bool;

    abstract public function getSize(): int;

    /**
     * @return array<string, mixed>
     */
    abstract public function getInfo(): array;

    // ─── Общие методы ───

    public function getPath(): string
    {
        return $this->path;
    }

    public function getName(): string
    {
        return basename($this->path);
    }

    public function getParentPath(): string
    {
        return dirname($this->path);
    }

    public function getPermissions(): int
    {
        if (!$this->exists()) {
            throw FileSystemException::notFound($this->path);
        }

        return fileperms($this->path) & 0777;
    }

    public function setPermissions(int $mode): bool
    {
        if (!$this->exists()) {
            throw FileSystemException::notFound($this->path);
        }

        if (!chmod($this->path, $mode)) {
            throw FileSystemException::permissionDenied($this->path);
        }

        return true;
    }

    public function rename(string $newName): bool
    {
        if (!$this->exists()) {
            throw FileSystemException::notFound($this->path);
        }

        // Санитизируем новое имя (убираем path traversal)
        $safeName = basename($newName);

        if ($safeName === '.' || $safeName === '..' || $safeName === '') {
            throw FileSystemException::permissionDenied($newName);
        }

        $newPath = $this->getParentPath() . DIRECTORY_SEPARATOR . $safeName;

        if (file_exists($newPath)) {
            throw FileSystemException::alreadyExists($newPath);
        }

        if (!rename($this->path, $newPath)) {
            throw new FileSystemException("Не удалось переименовать: {$this->path}");
        }

        $this->path = $newPath;

        return true;
    }

    public function delete(): bool
    {
        if (!$this->exists()) {
            throw FileSystemException::notFound($this->path);
        }

        return $this->remove($this->path);
    }

    public function getModificationTime(): int
    {
        if (!$this->exists()) {
            throw FileSystemException::notFound($this->path);
        }

        return filemtime($this->path) ?: 0;
    }

    public function isReadable(): bool
    {
        return is_readable($this->path);
    }

    public function isWritable(): bool
    {
        return is_writable($this->path);
    }

    // ─── Внутренние ───

    /**
     * Превращает относительный путь в абсолютный с защитой от Path Traversal
     */
    protected function resolvePath(string $path): string
    {
        return $this->pathSanitizer->sanitize($path);
    }

    /**
     * Рекурсивное удаление
     */
    protected function remove(string $path): bool
    {
        // Дополнительная проверка безопасности
        if (!$this->pathSanitizer->isInsideBasePath($path)) {
            throw FileSystemException::permissionDenied($path);
        }

        if (is_dir($path)) {
            $items = scandir($path);

            if ($items === false) {
                throw FileSystemException::permissionDenied($path);
            }

            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                $this->remove($path . DIRECTORY_SEPARATOR . $item);
            }

            return rmdir($path);
        }

        return unlink($path);
    }
}