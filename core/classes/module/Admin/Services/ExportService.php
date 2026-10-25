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

/**
 * Generic-выгрузка строк произвольных таблиц БД (зеркало ImportService).
 *
 * Безопасность (те же гарантии, что у импорта):
 *  - имена таблиц/колонок проходят строгий regex-фильтр идентификаторов и
 *    сверяются с information_schema перед выполнением любого запроса;
 *  - значения фильтруются ТОЛЬКО параметрами подготовленных запросов.
 *
 * Используется модулем экспорта страниц: выгрузка rows-наборов
 * (schema_org и любые другие таблицы из config/import-parser.php) при
 * обратном конвертировании страницы в HTML-пакет.
 */
class ExportService
{
    /** Максимум выгружаемых строк на один набор (защита от раздувания). */
    private const MAX_ROWS_PER_TABLE = 5000;

    public function __construct(private DataBase $db)
    {
    }

    /**
     * Выгрузить строки таблицы по равенству контекстных колонок
     * (например, page_id => 42).
     *
     * @param string[]          $columns колонки, которые нужны потребителю
     * @param array<string,mixed> $where   равенства колонка => значение
     * @return array<int,array<string,mixed>>
     * @throws \RuntimeException при недопустимых именах/отсутствии таблицы
     */
    public function fetchRows(string $table, array $columns, array $where = []): array
    {
        if (!$this->isSafeIdentifier($table)) {
            throw new \RuntimeException("Экспорт: недопустимое имя таблицы: {$table}");
        }

        $safeColumns = array_values(array_filter(
            array_map(strval(...), $columns),
            $this->isSafeIdentifier(...)
        ));
        if ($safeColumns === []) {
            return [];
        }

        $exists = $this->tableColumnsExist($table, $safeColumns);
        $missing = array_keys(array_filter($exists, static fn (bool $v): bool => $v === false));
        if ($missing !== []) {
            throw new \RuntimeException("Экспорт: таблица {$table} не имеет колонок " . implode(', ', $missing));
        }

        $sql = "SELECT `" . implode('`, `', $safeColumns) . "` FROM `{$table}`";
        $params = [];
        $conditions = [];
        foreach ($where as $col => $value) {
            $col = (string) $col;
            if (!$this->isSafeIdentifier($col)) {
                throw new \RuntimeException("Экспорт: недопустимое имя колонки фильтра: {$col}");
            }
            if (($exists[$col] ?? false) !== true) {
                // колонка фильтра отсутствует — набор пуст (не ошибка: конфиг мог измениться)
                return [];
            }
            $conditions[] = "`{$col}` = :w_{$col}";
            $params['w_' . $col] = $value;
        }
        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' LIMIT ' . self::MAX_ROWS_PER_TABLE;

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Проверка существования столбцов таблицы (information_schema).
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

        $placeholders = rtrim(str_repeat('?,', count($safe)), ',');

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

    private function isSafeIdentifier(string $name): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1;
    }
}
