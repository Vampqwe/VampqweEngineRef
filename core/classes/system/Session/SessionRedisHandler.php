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

use Core\System\Config\Config;
use RuntimeException;
use SessionHandler;
use SessionHandlerInterface;

/**
 * Пользовательский обработчик сессий PHP для режима SESSION_DRIVER=redis.
 *
 * Подключается через session_set_save_handler() до session_start()
 * (см. SecurityServiceProvider). Данные хранятся в Redis под тем же
 * форматом сериализации, что и файловый обработчик PHP, поэтому включена
 * lazy-миграция: если записи в Redis нет, данные читаются из старого
 * файла сессии и переносятся в Redis «на лету» — активные сессии
 * пользователей не обрываются при переключении драйвера.
 */
class SessionRedisHandler extends SessionHandler implements SessionHandlerInterface
{
    private RedisSessionStorage $storage;
    private FileSessionStorage $fileStorage;
    private bool $migrateOnRead;
    private int $maxLifetime;

    public function __construct(
        RedisSessionStorage $storage,
        Config $config,
        ?FileSessionStorage $fileStorage = null
    ) {
        $this->storage = $storage;
        $this->fileStorage = $fileStorage ?? new FileSessionStorage();
        $this->migrateOnRead = $config->getBool('SESSION_MIGRATE_ON_READ', true);
        // PHP-директива session.gc_maxlifetime задаёт время «молчания» сессии.
        $this->maxLifetime = max(1, (int) ini_get('session.gc_maxlifetime'));
    }

    public function open(string $path, string $name): bool
    {
        // Соединение с Redis уже установлено в конструкторе хранилища.
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    #[\ReturnTypeWillChange]
    public function read(string $id)
    {
        $data = $this->storage->read($id);
        if ($data !== null) {
            return $data;
        }

        // Lazy-миграция: перенос существующей файловой сессии в Redis.
        if ($this->migrateOnRead) {
            $legacy = $this->fileStorage->read($id);
            if ($legacy !== null && trim($legacy) !== '') {
                $this->storage->write($id, $legacy, $this->maxLifetime);

                return $legacy;
            }
        }

        return '';
    }

    #[\ReturnTypeWillChange]
    public function write(string $id, string $data): bool
    {
        $this->storage->write($id, $data, $this->maxLifetime);

        return true;
    }

    #[\ReturnTypeWillChange]
    public function destroy(string $id)
    {
        $this->storage->destroy($id);

        return true;
    }

    #[\ReturnTypeWillChange]
    public function gc(int $max_lifetime)
    {
        // TTL в Redis удаляет просрочанные ключи автоматически — сборщик
        // мусора не нужен. Возвращаем 0, чтобы не вводить в заблуждение логи
        // вызывающего кода.
        return 0;
    }

    /** Имя драйвера для диагностики/админ-панели. */
    public function getDriverName(): string
    {
        return $this->storage->getDriverName();
    }
}
