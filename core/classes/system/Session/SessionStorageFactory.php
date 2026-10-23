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

/**
 * Фабрика хранилищ сессий.
 *
 * Режим выбирается конфигурационным ключом SESSION_DRIVER:
 *  - "files" (по умолчанию) — штатный файловый обработчик PHP, поведение
 *    движка не меняется, внешних зависимостей нет;
 *  - "redis" — RedisSessionStorage + SessionRedisHandler (см. настройки
 *    SESSION_REDIS_* в config.env).
 *
 * Неизвестное значение драйвера считается ошибкой конфигурации и приводит
 * к исключению — «молчаливый» возврат к файлам скрыл бы опечатку на проде.
 */
class SessionStorageFactory
{
    public const DRIVER_FILES = 'files';
    public const DRIVER_REDIS = 'redis';

    private Config $config;

    /** Кэш созданного хранилища: фабрика вызывается из нескольких точек запроса. */
    private ?SessionStorageInterface $storage = null;

    public function __construct(?Config $config = null)
    {
        $this->config = $config ?? Config::getInstance();
    }

    /** Имя активного драйвера («files» / «redis»). */
    public function driverName(): string
    {
        return strtolower(trim((string) ($this->config->get('SESSION_DRIVER', self::DRIVER_FILES) ?? self::DRIVER_FILES)));
    }

    /** Файловое ли хранилище активно (для условной установки save_handler). */
    public function isFilesDriver(): bool
    {
        return $this->driverName() === self::DRIVER_FILES;
    }

    /**
     * Создать хранилище согласно конфигурации.
     *
     * @throws RuntimeException при неизвестном SESSION_DRIVER или недоступном Redis
     */
    public function make(): SessionStorageInterface
    {
        if ($this->storage !== null) {
            return $this->storage;
        }

        $driver = $this->driverName();

        switch ($driver) {
            case self::DRIVER_FILES:
                return $this->storage = new FileSessionStorage();
            case self::DRIVER_REDIS:
                return $this->storage = new RedisSessionStorage([
                    'host'     => $this->config->get('SESSION_REDIS_HOST', '127.0.0.1'),
                    'port'     => $this->config->getInt('SESSION_REDIS_PORT', 6379),
                    'timeout'  => (float) $this->config->get('SESSION_REDIS_TIMEOUT', '2.0'),
                    'password' => $this->config->get('SESSION_REDIS_PASSWORD', ''),
                    'database' => $this->config->getInt('SESSION_REDIS_DB', 0),
                    'prefix'   => $this->config->get('SESSION_REDIS_PREFIX', 'vampqwe:sess:'),
                ]);
            default:
                throw new RuntimeException(
                    "Неизвестный SESSION_DRIVER '{$driver}'. Допустимые значения: files, redis."
                );
        }
    }

    /**
     * Собрать обработчик для session_set_save_handler() (режим redis).
     */
    public function makeHandler(): SessionRedisHandler
    {
        $storage = $this->make();
        if (!$storage instanceof RedisSessionStorage) {
            throw new RuntimeException('SessionRedisHandler доступен только при SESSION_DRIVER=redis');
        }

        return new SessionRedisHandler($storage, $this->config);
    }
}
