<?php
declare(strict_types=1);

/**
 * VampqweEngineMedium — проприетарный PHP-движок
 *
 * @copyright  Copyright (c) 2024-2026. Все права защищены.
 * @author     [Дедюля Александр Иванович / VampqweEngine]
 * @license    Proprietary
 * @link       https://github.com/Vampqwe/VampqweEngine.git
 *
 * Данный код является интеллектуальной собственностью автора.
 * Любое копирование, распространение, модификация или использование
 * без письменного разрешения правообладателя строго запрещено.
 */

namespace Core\System\Session;

/**
 * Файловое хранилище сессий (режим SESSION_DRIVER=files).
 *
 * Работает поверх стандартного обработчика PHP: чтение делегируется
 * session_encode() для уже запущенной сессии, а удаление — unlink()
 * файла в каталоге хранения. Это канонический режим совместимости:
 * движок behaves ровно как раньше, без внешних зависимостей.
 */
class FileSessionStorage implements SessionStorageInterface
{
    private string $savePath;

    public function __construct(?string $savePath = null)
    {
        $path = $savePath ?? (string) (ini_get('session.save_path') ?: sys_get_temp_dir());
        $this->savePath = rtrim($path, '/\\');
    }

    public function read(string $id): ?string
    {
        // Для файлового режима данные уже загружены PHP в $_SESSION при
        // session_start(); отдельное чтение с диска не требуется.
        if (session_status() === PHP_SESSION_ACTIVE && session_id() === $id) {
            return session_encode();
        }

        $file = $this->filePath($id);
        if ($file === null || !is_file($file)) {
            return null;
        }

        $data = @file_get_contents($file);

        return $data === false ? null : $data;
    }

    public function write(string $id, string $data, int $maxLifetime): void
    {
        $file = $this->filePath($id);
        if ($file === null) {
            return;
        }
        if (!is_dir($this->savePath)) {
            @mkdir($this->savePath, 0770, true);
        }
        @file_put_contents($file, $data, LOCK_EX);
    }

    public function destroy(string $id): void
    {
        $file = $this->filePath($id);
        if ($file !== null && is_file($file)) {
            @unlink($file);
        }
    }

    public function exists(string $id): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE && session_id() === $id) {
            return true;
        }
        $file = $this->filePath($id);

        return $file !== null && is_file($file);
    }

    public function getDriverName(): string
    {
        return 'files';
    }

    /**
     * Путь к файлу сессии с защитой от обхода каталога: идентификатор
     * обязан состоять только из разрешённых PHP символов.
     */
    private function filePath(string $id): ?string
    {
        if ($id === '' || !preg_match('/^[a-zA-Z0-9,\-]{2,}$/D', $id)) {
            return null;
        }

        return $this->savePath . '/sess_' . $id;
    }
}
