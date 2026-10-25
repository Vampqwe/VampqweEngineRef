<?php

declare(strict_types=1);

namespace Tests\Unit\Module\Admin\Fakes;

use Core\System\Config\Config;
use Core\System\DataBase\DataBase;

/**
 * Тестовая заглушка DataBase для in-memory таблицы redirects.
 * Эмулирует только те запросы, которые реально отправляет
 * RedirectAdminService (prepared statements, именованные параметры).
 */
final class FakeRedirectDb extends DataBase
{
    /** @var array<int, array<string, mixed>> id => строка */
    public array $rows = [];

    private int $autoId = 0;

    /** @var list<array{sql: string, params: array<string, mixed>}> */
    public array $log = [];

    public function __construct(Config $config)
    {
        parent::__construct($config);
    }

    public function seed(int $id, string $source, string $destination, int $statusCode = 301, bool $active = true): void
    {
        $this->rows[$id] = [
            'id' => $id,
            'source' => $source,
            'destination' => $destination,
            'status_code' => $statusCode,
            'is_active' => $active ? 1 : 0,
            'hit_count' => 0,
            'note' => null,
        ];
        $this->autoId = max($this->autoId, $id);
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $this->log[] = ['sql' => $sql, 'params' => $params];

        if (!str_contains($sql, 'FROM redirects')) {
            throw new \LogicException('Unexpected fetchAll SQL: ' . $sql);
        }

        // LIKE-параметры раскрываем как «подстрока» (в MySQL — регистронезависимо
        // из-за utf8mb4_unicode_ci; в тесте — строгое вхождение). Сервис экранирует
        // спецсимволы LIKE как \% \_ \\ — эмуляция снимает экранирование посимвольно,
        // поэтому поиск трактуется буквально ('\%%' → '%', а не пустая строка).
        $items = array_values($this->rows);
        if (isset($params['s1']) && isset($params['s2'])) {
            $raw = (string) $params['s1'];
            if (strlen($raw) >= 2 && $raw[0] === '%' && $raw[strlen($raw) - 1] === '%') {
                $raw = substr($raw, 1, -1); // снимаем внешние wildcard'ы LIKE
            }
            $needle = '';
            $len = strlen($raw);
            for ($i = 0; $i < $len; $i++) {
                if ($raw[$i] === '\\' && $i + 1 < $len) {
                    $needle .= $raw[++$i]; // \%, \_, \\ → литерал
                } else {
                    $needle .= $raw[$i];
                }
            }
            $items = array_values(array_filter(
                $items,
                static fn(array $r): bool => stripos($r['source'], $needle) !== false
                    || stripos($r['destination'], $needle) !== false
            ));
        }

        usort($items, static fn(array $a, array $b): int => strcmp($a['source'], $b['source']));

        $offset = (int) ($params['_off'] ?? $this->extractInt($sql, 'OFFSET'));
        $limit = (int) ($params['_lim'] ?? $this->extractInt($sql, 'LIMIT'));

        return array_slice($items, $offset, $limit > 0 ? $limit : null);
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $this->log[] = ['sql' => $sql, 'params' => $params];

        if (!str_contains($sql, 'WHERE id = :id')) {
            throw new \LogicException('Unexpected fetchOne SQL: ' . $sql);
        }

        $row = $this->rows[(int) ($params['id'] ?? 0)] ?? null;

        return $row === null ? null : ['id' => $row['id'], 'source' => $row['source'], 'destination' => $row['destination'], 'status_code' => $row['status_code'], 'is_active' => $row['is_active'], 'hit_count' => $row['hit_count'], 'note' => $row['note']];
    }

    public function fetchColumn(string $sql, array $params = [], int $column = 0): mixed
    {
        $this->log[] = ['sql' => $sql, 'params' => $params];

        if (str_contains($sql, 'COUNT(*) FROM redirects')) {
            if (str_contains($sql, 'WHERE')) {
                return count($this->fetchAll('SELECT * FROM redirects WHERE', $params));
            }
            return count($this->rows);
        }

        throw new \LogicException('Unexpected fetchColumn SQL: ' . $sql);
    }

    public function insert(string $sql, array $params = []): string
    {
        $this->log[] = ['sql' => $sql, 'params' => $params];

        if (!str_contains($sql, 'INSERT INTO redirects')) {
            throw new \LogicException('Unexpected insert SQL: ' . $sql);
        }

        foreach ($this->rows as $row) {
            if ($row['source'] === $params['source']) {
                $dup = new \PDOException('Duplicate entry for key uk_source', 1062);
                $dup->errorInfo = ['42S02', 1062, 'dup'];
                throw $dup;
            }
        }

        $id = ++$this->autoId;
        $this->rows[$id] = [
            'id' => $id,
            'source' => (string) $params['source'],
            'destination' => (string) $params['destination'],
            'status_code' => (int) $params['status_code'],
            'is_active' => (int) $params['is_active'],
            'hit_count' => 0,
            'note' => $params['note'],
        ];

        return (string) $id;
    }

    public function execute(string $sql, array $params = []): int
    {
        $this->log[] = ['sql' => $sql, 'params' => $params];

        if (str_contains($sql, 'UPDATE redirects') && str_contains($sql, 'hit_count = 0')) {
            $id = (int) $params['id'];
            if (!isset($this->rows[$id])) {
                return 0;
            }
            $this->rows[$id]['hit_count'] = 0;
            return 1;
        }

        if (str_contains($sql, 'UPDATE redirects')) {
            $id = (int) $params['id'];
            if (!isset($this->rows[$id])) {
                return 0;
            }
            foreach ($this->rows as $row) {
                if ($row['id'] !== $id && $row['source'] === $params['source']) {
                    $dup = new \PDOException('Duplicate entry for key uk_source', 1062);
                    $dup->errorInfo = ['42S02', 1062, 'dup'];
                    throw $dup;
                }
            }
            $this->rows[$id] = array_merge($this->rows[$id], [
                'source' => (string) $params['source'],
                'destination' => (string) $params['destination'],
                'status_code' => (int) $params['status_code'],
                'is_active' => (int) $params['is_active'],
                'note' => $params['note'],
            ]);
            return 1;
        }

        if (str_contains($sql, 'DELETE FROM redirects')) {
            $id = (int) $params['id'];
            if (!isset($this->rows[$id])) {
                return 0;
            }
            unset($this->rows[$id]);
            return 1;
        }

        throw new \LogicException('Unexpected execute SQL: ' . $sql);
    }

    private function extractInt(string $sql, string $keyword): int
    {
        if (preg_match('/' . $keyword . '\s+(\d+)/i', $sql, $m) === 1) {
            return (int) $m[1];
        }
        return 0;
    }
}
