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

namespace Core\Module\Admin\Services;

use Core\System\DataBase\DataBase;
use PDO;

/**
 * Generic-запись результатов конфигурируемого импорта (config/import-parser.php,
 * секция 'blocks') в произвольные таблицы БД.
 *
 * Безопасность:
 *  - имена таблиц/колонок из конфига проходят строгий regex-фильтр
 *    ([A-Za-z_][A-Za-z0-9_]*) и дополнительно сверяются с information_schema
 *    перед выполнением любого запроса;
 *  - значения подставляются ТОЛЬКО как параметры подготовленных запросов.
 */
class ImportService
{
    /** Максимум записываемых rows-строк на один импорт (защита от раздувания). */
    private const MAX_ROWS_PER_TABLE = 200;

    public function __construct(private DataBase $db)
    {
    }

    /**
     * Проверка существования столбцов таблицы (information_schema).
     * Имена колонок предварительно фильтруются regex'ом — идентификаторы
     * из конфига никогда не попадают в SQL конкатенацией «сырыми».
     *
     * @param string[] $columns
     * @return array<string,bool> column => exists
     */
    public function tableColumnsExist(string $table, array $columns): array
    {
        if (!$this->isSafeIdentifier($table) || $columns === []) {
            return [];
        }

        $safe = array_values(array_filter(
            array_map(strval(...), $columns),
            $this->isSafeIdentifier(...)
        ));
        if ($safe === []) {
            return [];
        }

        $in = implode(',', array_fill_keys($safe, null)); // только для подсчёта плейсхолдеров
        $placeholders = rtrim(str_repeat('?,', substr_count($in, ',') + 1), ',');

        $rows = $this->db->fetchAll(
            "SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME IN ({$placeholders})",
            array_merge([$table], $safe)
        );

        $found = array_flip(array_map(static fn (array $r): string => (string) $r['c'], $rows));

        $result = [];
        foreach ($safe as $c) {
            $result[$c] = isset($found[$c]);
        }

        return $result;
    }

    /**
     * Записать набор скалярных значений в одну колонку одной таблицы
     * (block target: ['table'=>..., 'column'=>...]).
     *
     * @return int число вставленных строк (0 или 1)
     * @throws \RuntimeException при недопустимых именах/отсутствии таблицы
     */
    public function writeColumnValue(string $table, string $column, mixed $value): int
    {
        if (!$this->isSafeIdentifier($table) || !$this->isSafeIdentifier($column)) {
            throw new \RuntimeException("Импорт: недопустимое имя таблицы/колонки: {$table}.{$column}");
        }

        if (($this->tableColumnsExist($table, [$column])[$column] ?? false) !== true) {
            throw new \RuntimeException("Импорт: таблица {$table} не имеет колонки {$column}");
        }

        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $this->db->execute(
            "INSERT INTO `{$table}` (`{$column}`) VALUES (:value)",
            ['value' => $value]
        );
    }

    /**
     * Записать набор строк mode='rows' в произвольную таблицу
     * (block target: ['table'=>..., 'mode'=>'rows', 'fields'=>[col=>pattern]]).
     *
     * Каждому полю соответствует литеральное значение либо шаблон с
     * плейсхолдерами {json}, {schema_type}, {block}, {index}.
     *
     * Таблица, у которой нет всех колонок из fields, пропускается с ошибкой.
     * Для таблицы schema_org включается дедупликация по UNIQUE KEY
     * (page_id, schema_type): существующие типы схем не перевставляются.
     *
     * @param array<int,array<string,mixed>> $rows    готовые строки (колонка => значение)
     * @param array<string,string|int|float> $fields  описание целевых полей из конфига
     * @param array<string,mixed> $context доп. колонки (page_id, route и т.п.)
     * @return array{inserted:int, skipped:int, errors:string[]}
     */
    public function writeRows(string $table, array $rows, array $fields, array $context = []): array
    {
        $errors = [];
        $inserted = 0;
        $skipped = 0;

        if (!$this->isSafeIdentifier($table)) {
            return ['inserted' => 0, 'skipped' => count($rows), 'errors' => ["Недопустимое имя таблицы: {$table}"]];
        }
        if ($rows === []) {
            return ['inserted' => 0, 'skipped' => 0, 'errors' => []];
        }
        if (count($rows) > self::MAX_ROWS_PER_TABLE) {
            $errors[] = "Таблица {$table}: больше " . self::MAX_ROWS_PER_TABLE . " строк — лишние пропущены";
            $rows = array_slice($rows, 0, self::MAX_ROWS_PER_TABLE);
        }

        $columns = array_keys($fields);
        foreach ($context as $col => $_) {
            $columns[] = (string) $col;
        }
        $exists = $this->tableColumnsExist($table, $columns);
        $missing = array_keys(array_filter($exists, static fn (bool $v): bool => $v === false));
        if ($missing !== []) {
            return [
                'inserted' => 0,
                'skipped' => count($rows),
                'errors' => ["Таблица {$table}: отсутствуют колонки " . implode(', ', $missing)],
            ];
        }

        // Дедупликация для schema_org (uk_page_schema page_id+schema_type)
        $takenTypes = [];
        if ($table === 'schema_org' && isset($context['page_id'])) {
            $takenTypes = array_flip(array_map(
                static fn (array $r): string => (string) $r['schema_type'],
                $this->db->fetchAll(
                    "SELECT schema_type FROM schema_org WHERE page_id = :pid",
                    ['pid' => (int) $context['page_id']]
                )
            ));
        }

        foreach ($rows as $i => $row) {
            $data = [];
            foreach ($fields as $col => $pattern) {
                $data[(string) $col] = $this->resolveField((string) $col, $pattern, $row, $i);
            }
            foreach ($context as $col => $v) {
                $data[(string) $col] = $v;
            }

            if ($table === 'schema_org' && isset($data['schema_type'])
                && isset($takenTypes[(string) $data['schema_type']])) {
                $skipped++;
                continue;
            }

            $cols = array_keys($data);
            $ph = implode(', ', array_map(static fn (string $c): string => ':' . $c, $cols));
            $quoted = implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $cols));

            try {
                $inserted += $this->db->execute(
                    "INSERT INTO `{$table}` ({$quoted}) VALUES ({$ph})",
                    $data
                );
            } catch (\Throwable $e) {
                $errors[] = "Ошибка вставки в {$table}: " . $e->getMessage();
            }
        }

        return ['inserted' => $inserted, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Разрешить значение поля rows-строки: литерал или шаблон {placeholder}.
     */
    private function resolveField(string $column, mixed $pattern, array $row, int $index): mixed
    {
        if (!is_string($pattern)) {
            return $pattern; // литерал из конфига (число и т.п.)
        }

        // Точный плейсхолдер вида '{json}' — берём значение колонки как есть
        if (preg_match('/^\{([a-z_]+)\}$/i', $pattern, $m) === 1) {
            $key = $m[1] === 'index' ? $index : ($row[$m[1]] ?? null);
            return $key;
        }

        // Шаблон с несколькими плейсхолдерами — подстановка внутри строки
        return preg_replace_callback('/\{([a-z_]+)\}/i', static function (array $mm) use ($row, $index) {
            if ($mm[1] === 'index') {
                return (string) $index;
            }
            return (string) ($row[$mm[1]] ?? '');
        }, $pattern) ?? $pattern;
    }

    private function isSafeIdentifier(string $name): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1;
    }
}
