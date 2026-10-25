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

use Redis;
use RedisException;
use RuntimeException;

/**
 * Redis-сессии (режим SESSION_DRIVER=redis).
 *
 * Ключевые преимущества перед файловым режимом:
 *  - отсутствие file-lock: параллельные запросы одного пользователя
 *    обрабатываются одновременно (PHP-сессии блокируют файл на всё время
 *    запроса, сериализуя их);
 *  - горизонтальное масштабирование: несколько web-нод работают с одним
 *    хранилищем, sticky sessions не нужны;
 *  - TTL управляется самим Redis — «протухшие» сессии удаляются автоматически.
 *
 * Существующие файловые сессии НЕ теряются при переключении: благодаря
 * lazy-миграции (SESSION_MIGRATE_ON_READ=true) данные читаются с диска при
 * первом обращении и записываются в Redis под тем же идентификатором.
 */
class RedisSessionStorage implements SessionStorageInterface
{
    private const DEFAULT_PREFIX = 'vampqwe:sess:';

    private Redis $redis;
    private string $prefix;

    /**
     * @param array{host?:string,port?:int,timeout?:float,password?:string,database?:int,prefix?:string} $options
     *        Параметры подключения из конфигурации (SESSION_REDIS_*).
     *
     * @throws RuntimeException если расширение redis не установлено или сервер недоступен
     */
    public function __construct(array $options = [])
    {
        if (!class_exists(Redis::class)) {
            throw new RuntimeException(
                'Для режима SESSION_DRIVER=redis необходимо расширение phpredis.'
            );
        }

        $this->prefix = (string) ($options['prefix'] ?? self::DEFAULT_PREFIX);

        $redis = new Redis();
        $host = (string) ($options['host'] ?? '127.0.0.1');
        $port = (int) ($options['port'] ?? 6379);
        $timeout = (float) ($options['timeout'] ?? 2.0);

        try {
            $redis->connect($host, $port, $timeout);

            $password = (string) ($options['password'] ?? '');
            if ($password !== '') {
                $redis->auth($password);
            }

            $database = (int) ($options['database'] ?? 0);
            if ($database !== 0) {
                $redis->select($database);
            }
        } catch (RedisException $e) {
            throw new RuntimeException('Не удалось подключиться к Redis-серверу сессий: ' . $e->getMessage(), 0, $e);
        }

        $this->redis = $redis;
    }

    public function read(string $id): ?string
    {
        if ($id === '') {
            return null;
        }

        $data = @$this->redis->get($this->key($id));

        return is_string($data) ? $data : null;
    }

    public function write(string $id, string $data, int $maxLifetime): void
    {
        if ($id === '') {
            return;
        }

        $ttl = max(1, $maxLifetime);
        // SETEX атомарно записывает значение и сбрасывает TTL — продление
        // «скользящей» сессии происходит на каждой записи.
        @$this->redis->setex($this->key($id), $ttl, $data);
    }

    public function destroy(string $id): void
    {
        if ($id === '') {
            return;
        }

        @$this->redis->del($this->key($id));
    }

    public function exists(string $id): bool
    {
        if ($id === '') {
            return false;
        }

        return (bool) @$this->redis->exists($this->key($id));
    }

    public function getDriverName(): string
    {
        return 'redis';
    }

    private function key(string $id): string
    {
        return $this->prefix . $id;
    }
}
