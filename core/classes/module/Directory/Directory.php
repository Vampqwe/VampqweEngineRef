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


namespace Core\Module\Directory;

use Core\System\Config\Config;
use Core\System\FileSystem\FileSystem;
use Core\System\FileSystem\FileSystemException;
use Core\System\FileSystem\FileSystemType;
use Core\Module\File\File;

/**
 * Класс для работы с директориями.
 */
class Directory extends FileSystem
{
    /** @var FileSystem[] */
    private array $children = [];

    public function __construct(string $path, Config $config)
    {
        parent::__construct($path, $config);
    }

    public function getType(): FileSystemType
    {
        return FileSystemType::Directory;
    }

    public function exists(): bool
    {
        return is_dir($this->path);
    }

    public function getSize(): int
    {
        if (!$this->exists()) {
            throw FileSystemException::notFound($this->path);
        }

        $totalSize = 0;
        $iterator  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->path, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $totalSize += $file->getSize();
            }
        }

        return $totalSize;
    }

    public function getInfo(): array
    {
        return [
            'type'        => $this->getType()->value,
            'path'        => $this->path,
            'name'        => $this->getName(),
            'size'        => $this->exists() ? $this->getSize() : 0,
            'files_count' => $this->exists() ? count($this->listContents()) : 0,
            'permissions' => $this->exists() ? decoct($this->getPermissions()) : null,
            'modified'    => $this->exists() ? date('Y-m-d H:i:s', $this->getModificationTime()) : null,
            'readable'    => $this->isReadable(),
            'writable'    => $this->isWritable(),
        ];
    }

    /**
     * Создать директорию на диске
     */
    public function create(int $mode = 0): self
    {
        if ($this->exists()) {
            throw FileSystemException::alreadyExists($this->path);
        }

        if ($mode === 0) {
            $mode = $this->config->getOctal('FS_DEFAULT_DIR_PERMISSION', 0755);
        }

        if (!mkdir($this->path, $mode, recursive: true)) {
            throw FileSystemException::notWritable($this->path);
        }

        return $this;
    }

    /**
     * Получить список содержимого
     *
     * @return FileSystem[]
     */
    public function listContents(bool $recursive = false): array
    {
        if (!$this->exists()) {
            throw FileSystemException::notFound($this->path);
        }

        $this->children = [];
        $items = scandir($this->path);

        if ($items === false) {
            throw FileSystemException::permissionDenied($this->path);
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $this->path . DIRECTORY_SEPARATOR . $item;
            $type     = FileSystemType::detect($fullPath);

            $child = match ($type) {
                FileSystemType::File      => new File($fullPath, $this->config),
                FileSystemType::Directory => new self($fullPath, $this->config),
                default                   => null,
            };

            if ($child !== null) {
                $this->children[] = $child;

                if ($recursive && $child instanceof self) {
                    $this->children = array_merge(
                        $this->children,
                        $child->listContents(recursive: true)
                    );
                }
            }
        }

        return $this->children;
    }

    /**
     * Пуста ли директория
     */
    public function isEmpty(): bool
    {
        if (!$this->exists()) {
            throw FileSystemException::notFound($this->path);
        }

        $items = scandir($this->path);

        if ($items === false) {
            throw FileSystemException::permissionDenied($this->path);
        }

        return count($items) <= 2;
    }

    /**
     * Создать файл внутри этой директории
     */
    public function createFile(string $name, string $content = ''): File
    {
        if (!$this->exists()) {
            throw FileSystemException::notFound($this->path);
        }

        $filePath = $this->path . DIRECTORY_SEPARATOR . $name;
        $file     = new File($filePath, $this->config);
        $file->write($content);

        $this->children[] = $file;

        return $file;
    }

    /**
     * Создать поддиректорию
     */
    public function createSubdirectory(string $name): self
    {
        if (!$this->exists()) {
            throw FileSystemException::notFound($this->path);
        }

        $subPath = $this->path . DIRECTORY_SEPARATOR . $name;
        $subDir  = new self($subPath, $this->config);
        $subDir->create();

        $this->children[] = $subDir;

        return $subDir;
    }

    /**
     * Получить кешированных детей
     *
     * @return FileSystem[]
     */
    public function getChildren(): array
    {
        return $this->children;
    }
}