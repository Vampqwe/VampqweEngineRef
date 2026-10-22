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


namespace Core\System\DataBase;

use Core\System\Config\Config;
use Core\System\Security\SqlSanitizer;
use PDO;
use PDOStatement;
use Throwable;

/**
 * Класс для работы с базой данных с защитой от SQL-инъекций.
 *
 * ПОЛИТИКА ДВИЖКА: боевое подключение — только MySQL/MariaDB.
 * SQLite зарезервирован как потенциальный драйвер на будущее (см. DataBaseDriver),
 * в общей логике приложения не участвует: DB_PATH из конфига здесь не используется.
 */
class DataBase
{
    /** Конфиг по умолчанию для резолва параметров подключения. */
    private const DEFAULTS = [
        'DB_HOST'              => '127.0.0.1',
        'DB_PORT'              => '3306',
        'DB_NAME'              => '',
        'DB_CHARSET'           => 'utf8mb4',
        'DB_CONNECT_TIMEOUT_S' => 5,
    ];
    private ?PDO $connection = null;
    private Config $config;
    private DataBaseDriver $driver;
    private SqlSanitizer $sqlSanitizer;

    public function __construct(Config $config, ?SqlSanitizer $sqlSanitizer = null)
    {
        $this->config = $config;
        $this->driver = DataBaseDriver::fromString(
            $config->get('DB_DRIVER', 'mysql')
        );
        $this->sqlSanitizer = $sqlSanitizer ?? new SqlSanitizer(
            $config->getBool('DB_ENFORCE_PREPARED', true)
        );
    }

    public function getConnection(): PDO
    {
        if ($this->connection === null) {
            $this->connect();
        }

        return $this->connection;
    }

    public function isConnected(): bool
    {
        return $this->connection !== null;
    }

    public function disconnect(): void
    {
        $this->connection = null;
    }

    public function reconnect(): PDO
    {
        $this->disconnect();
        return $this->getConnection();
    }

    /**
     * Выполнить SQL-запрос с проверкой безопасности
     *
     * @param string $sql
     * @param array<mixed> $params
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        // Валидация SQL перед выполнением
        $this->sqlSanitizer->validate($sql, $params);

        try {
            $stmt = $this->getConnection()->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (Throwable $e) {
            throw DataBaseException::queryFailed($sql, $e->getMessage());
        }
    }

    /**
     * @param string $sql
     * @param array<mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param string $sql
     * @param array<mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $result = $this->query($sql, $params)->fetch(PDO::FETCH_ASSOC);
        return $result === false ? null : $result;
    }

    /**
     * @param string $sql
     * @param array<mixed> $params
     */
    public function fetchColumn(string $sql, array $params = [], int $column = 0): mixed
    {
        $result = $this->query($sql, $params)->fetchColumn($column);
        return $result === false ? null : $result;
    }

    /**
     * @param string $sql
     * @param array<mixed> $params
     */
    public function insert(string $sql, array $params = []): string
    {
        $this->query($sql, $params);
        return $this->getConnection()->lastInsertId();
    }

    /**
     * @param string $sql
     * @param array<mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    public function beginTransaction(): bool
    {
        return $this->getConnection()->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->getConnection()->commit();
    }

    public function rollBack(): bool
    {
        return $this->getConnection()->rollBack();
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (Throwable $e) {
            $this->rollBack();
            throw DataBaseException::transactionFailed($e->getMessage());
        }
    }

    public function getDriver(): DataBaseDriver
    {
        return $this->driver;
    }

    public function getSqlSanitizer(): SqlSanitizer
    {
        return $this->sqlSanitizer;
    }

    // ─── Приватные методы ───

    /**
     * @param array<string, string>|null $overrides временный конфиг мастера установки
     */
    private function resolveParams(?array $overrides = null): array
    {
        $params = [
            'host'    => $this->config->get('DB_HOST', self::DEFAULTS['DB_HOST']),
            'port'    => $this->config->get('DB_PORT', self::DEFAULTS['DB_PORT']),
            'name'    => $this->config->get('DB_NAME', self::DEFAULTS['DB_NAME']),
            'charset' => $this->config->get('DB_CHARSET', self::DEFAULTS['DB_CHARSET']),
        ];

        if ($overrides !== null) {
            $params = [
                'host'    => (string) ($overrides['host'] ?? $params['host']),
                'port'    => (string) ($overrides['port'] ?? $params['port']),
                'name'    => (string) ($overrides['name'] ?? $params['name']),
                'charset' => (string) ($overrides['charset'] ?? $params['charset']),
            ];
        }

        return $params;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, int|bool>
     */
    private function buildOptions(array $params): array
    {
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => $this->config->getBool('DB_PDO_PERSISTENT', false),
        ];

        // Таймаут соединения: без него недоступная БД вешает worker на
        // системный TCP-timeout (минуты), что ломает и сайт, и readiness-пробы.
        $timeout = max(1, $this->config->getInt('DB_CONNECT_TIMEOUT_S', (int) self::DEFAULTS['DB_CONNECT_TIMEOUT_S']));

        if ($this->driver === DataBaseDriver::MySQL) {
            // connect_timeout передаётся через DSN — это константа драйвера mysqlnd,
            // а не ядра PDO: она может быть не определена, если pdo_mysql собран
            // без неё. DSN-параметм при этом остаётся рабочим кросс-версионным
            // способом задать таймаут.
            $params['connect_timeout'] = (string) $timeout;
            if (defined('PDO::MYSQL_ATTR_CONNECT_TIMEOUT')) {
                $options[PDO::MYSQL_ATTR_CONNECT_TIMEOUT] = $timeout;
            }
        }

        return $options;
    }

    /**
     * Выполнить подключение к MySQL/MariaDB.
     *
     * @param array<string, string>|null $overrides
     */
    private function doConnect(?array $overrides = null): PDO
    {
        try {
            $params  = $this->resolveParams($overrides);
            $dsn     = $this->driver->buildDsn($params);
            $options = $this->buildOptions($params);

            $user = (string) ($overrides['user'] ?? $this->config->get('DB_USER', ''));
            $pass = (string) ($overrides['pass'] ?? $this->config->get('DB_PASS', ''));

            return new PDO($dsn, $user, $pass, $options);

        } catch (Throwable $e) {
            throw DataBaseException::connectionFailed($e->getMessage());
        }
    }

    private function connect(): void
    {
        $this->connection = $this->doConnect();
    }
}