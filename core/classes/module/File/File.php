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


namespace Core\Module\File;

use Core\System\Config\Config;
use Core\System\FileSystem\FileSystem;
use Core\System\FileSystem\FileSystemException;
use Core\System\FileSystem\FileSystemType;
use Core\System\Security\SecurityException;

/**
 * Класс для работы с файлами с защитой от атак
 */
class File extends FileSystem
{
    private int $maxSize;
    private string $encoding;

    /** @var string[] Разрешённые MIME-типы (пусто = все разрешены) */
    private array $allowedMimeTypes = [];

    public function __construct(string $path, Config $config)
    {
        parent::__construct($path, $config);

        $this->maxSize  = $this->config->getInt('FS_MAX_FILE_SIZE', 10 * 1024 * 1024);
        $this->encoding = $this->config->get('FS_ENCODING', 'UTF-8');
    }

    /**
     * Установить разрешённые MIME-типы
     *
     * @param string[] $mimeTypes
     */
    public function setAllowedMimeTypes(array $mimeTypes): self
    {
        $this->allowedMimeTypes = $mimeTypes;
        return $this;
    }

    public function getType(): FileSystemType
    {
        return FileSystemType::File;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function getSize(): int
    {
        if (!$this->exists()) {
            throw FileSystemException::notFound($this->path);
        }

        return (int) filesize($this->path);
    }

    public function getInfo(): array
    {
        return [
            'type'        => $this->getType()->value,
            'path'        => $this->path,
            'name'        => $this->getName(),
            'extension'   => $this->getExtension(),
            'size'        => $this->exists() ? $this->getSize() : 0,
            'mime'        => $this->getMimeType(),
            'permissions' => $this->exists() ? decoct($this->getPermissions()) : null,
            'modified'    => $this->exists() ? date('Y-m-d H:i:s', $this->getModificationTime()) : null,
            'readable'    => $this->isReadable(),
            'writable'    => $this->isWritable(),
        ];
    }

    public function read(): string
    {
        if (!$this->exists()) {
            throw FileSystemException::notFound($this->path);
        }

        if (!$this->isReadable()) {
            throw FileSystemException::permissionDenied($this->path);
        }

        $content = file_get_contents($this->path);

        if ($content === false) {
            throw new FileSystemException("Не удалось прочитать файл: {$this->path}");
        }

        return $content;
    }

    public function write(string $content, bool $append = false): int
    {
        $this->ensureParentDirectoryExists();

        $contentSize = mb_strlen($content, $this->encoding);

        if (!$append && $contentSize > $this->maxSize) {
            throw SecurityException::fileTooLarge($contentSize, $this->maxSize);
        }

        $flags = $append ? FILE_APPEND : 0;
        $bytes = file_put_contents($this->path, $content, $flags);

        if ($bytes === false) {
            throw FileSystemException::notWritable($this->path);
        }

        if (!$append) {
            $defaultPerm = $this->config->getOctal('FS_DEFAULT_FILE_PERMISSION', 0644);
            chmod($this->path, $defaultPerm);
        }

        return $bytes;
    }

    public function append(string $content): int
    {
        return $this->write($content, append: true);
    }

    /**
     * Загрузить файл с валидацией MIME-типа
     *
     * @param string $sourcePath Путь к исходному файлу
     * @param string[] $allowedMimeTypes Разрешённые MIME-типы
     */
    public function upload(string $sourcePath, array $allowedMimeTypes = []): self
    {
        if (!file_exists($sourcePath)) {
            throw FileSystemException::notFound($sourcePath);
        }

        // Проверка размера
        $size = filesize($sourcePath);
        if ($size === false || $size > $this->maxSize) {
            throw SecurityException::fileTooLarge((int) $size, $this->maxSize);
        }

        // Проверка MIME-типа
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $actualMime = $finfo->file($sourcePath);

        if ($actualMime === false) {
            throw SecurityException::invalidMimeType('unknown', 'unknown');
        }

        $allowed = !empty($allowedMimeTypes) ? $allowedMimeTypes : $this->allowedMimeTypes;

        if (!empty($allowed) && !in_array($actualMime, $allowed, true)) {
            throw SecurityException::invalidMimeType(implode(', ', $allowed), $actualMime);
        }

        // Копируем файл
        $this->ensureParentDirectoryExists();

        if (!copy($sourcePath, $this->path)) {
            throw new FileSystemException("Не удалось загрузить файл: {$sourcePath}");
        }

        $defaultPerm = $this->config->getOctal('FS_DEFAULT_FILE_PERMISSION', 0644);
        chmod($this->path, $defaultPerm);

        return $this;
    }

    public function getExtension(): string
    {
        return strtolower(pathinfo($this->path, PATHINFO_EXTENSION));
    }

    public function getMimeType(): string
    {
        if (!$this->exists()) {
            return 'application/octet-stream';
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($this->path);

        return $mime ?: 'application/octet-stream';
    }

    /**
     * Проверить, является ли MIME-тип разрешённым
     */
    public function isMimeTypeAllowed(): bool
    {
        if (empty($this->allowedMimeTypes)) {
            return true;
        }

        return in_array($this->getMimeType(), $this->allowedMimeTypes, true);
    }

    public function copy(string $destination): self
    {
        if (!$this->exists()) {
            throw FileSystemException::notFound($this->path);
        }

        $destPath = $this->resolvePath($destination);

        if (!copy($this->path, $destPath)) {
            throw new FileSystemException("Не удалось скопировать: {$this->path} → {$destPath}");
        }

        return new self($destPath, $this->config);
    }

    public function move(string $destination): self
    {
        if (!$this->exists()) {
            throw FileSystemException::notFound($this->path);
        }

        $destPath = $this->resolvePath($destination);

        if (!rename($this->path, $destPath)) {
            throw new FileSystemException("Не удалось переместить: {$this->path} → {$destPath}");
        }

        $this->path = $destPath;

        return $this;
    }

    private function ensureParentDirectoryExists(): void
    {
        $parent = $this->getParentPath();

        if (!is_dir($parent)) {
            $dirPerm = $this->config->getOctal('FS_DEFAULT_DIR_PERMISSION', 0755);

            if (!mkdir($parent, $dirPerm, recursive: true)) {
                throw FileSystemException::notWritable($parent);
            }
        }
    }
}