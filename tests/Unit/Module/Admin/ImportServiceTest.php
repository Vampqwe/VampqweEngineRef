<?php

declare(strict_types=1);

namespace Tests\Unit\Module\Admin;

use Core\Module\Admin\Services\ImportService;
use Core\System\Config\Config;
use Core\System\DataBase\DataBase;
use PHPUnit\Framework\TestCase;

/**
 * In-memory заглушка DataBase: перехватывает SQL и параметры, эмулирует
 * information_schema.COLUMNS и выборку существующих schema_type.
 */
final class FakeImportDb extends DataBase
{
    /** @var array<int,array{sql:string,params:array}> */
    public array $queries = [];
    /** @var array<string,array<string,bool>> table => column => exists */
    public array $schema = [];
    /** @var string[] существующие schema_type для дедупликации schema_org */
    public array $existingSchemaTypes = [];

    public function __construct()
    {
        // без обращения к реальному PDO
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params];

        if (str_contains($sql, 'information_schema.COLUMNS')) {
            $table = (string) ($params[0] ?? '');
            $cols = array_slice($params, 1);
            $out = [];
            foreach ($cols as $c) {
                if ($this->schema[$table][$c] ?? false) {
                    $out[] = ['c' => (string) $c];
                }
            }
            return $out;
        }

        if (str_contains($sql, 'SELECT schema_type FROM schema_org')) {
            return array_map(static fn (string $t): array => ['schema_type' => $t], $this->existingSchemaTypes);
        }

        return [];
    }

    public function execute(string $sql, array $params = []): int
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params];
        return 1;
    }
}

/**
 * Юнит-тесты generic-сервиса записи импорта в произвольные таблицы.
 */
final class ImportServiceTest extends TestCase
{
    private function makeService(FakeImportDb $db): ImportService
    {
        // Импорт сервиса использует только fetchAll/execute — заглушка совместима
        return new ImportService($db);
    }

    public function testWriteRowsInsertsMappedFieldsAndContext(): void
    {
        $db = new FakeImportDb();
        $db->schema['menu_items'] = ['label' => true, 'url' => true, 'page_id' => true];

        $service = $this->makeService($db);
        $report = $service->writeRows(
            'menu_items',
            [['label' => 'Главная', 'url' => '/'], ['label' => 'Услуги', 'url' => '/services']],
            ['label' => '{label}', 'url' => '{url}'],
            ['page_id' => 42]
        );

        self::assertSame(2, $report['inserted']);
        self::assertSame(0, $report['skipped']);
        self::assertSame([], $report['errors']);

        $inserts = array_values(array_filter($db->queries, static fn (array $q): bool => str_starts_with($q['sql'], 'INSERT INTO `menu_items`')));
        self::assertCount(2, $inserts);
        self::assertStringContainsString('`label`, `url`, `page_id`', $inserts[0]['sql']);
        self::assertSame(['label' => 'Главная', 'url' => '/', 'page_id' => 42], $inserts[0]['params']);
    }

    public function testWriteRowsRejectsUnsafeTableName(): void
    {
        $db = new FakeImportDb();
        $service = $this->makeService($db);

        $report = $service->writeRows('bad; DROP TABLE x--', [['a' => 1]], ['a' => '{a}']);
        self::assertSame(0, $report['inserted']);
        self::assertNotEmpty($report['errors']);
        self::assertSame([], array_filter($db->queries, static fn (array $q): bool => str_starts_with($q['sql'], 'INSERT')));
    }

    public function testWriteRowsMissingColumnsSkipsAll(): void
    {
        $db = new FakeImportDb();
        $db->schema['menu_items'] = ['label' => true]; // нет колонки url

        $service = $this->makeService($db);
        $report = $service->writeRows(
            'menu_items',
            [['label' => 'x', 'url' => '/y']],
            ['label' => '{label}', 'url' => '{url}']
        );

        self::assertSame(0, $report['inserted']);
        self::assertSame(1, $report['skipped']);
        self::assertStringContainsString('отсутствуют колонки url', $report['errors'][0]);
    }

    public function testLiteralFieldValuesAndTemplatePlaceholders(): void
    {
        $db = new FakeImportDb();
        $db->schema['blocks'] = ['html' => true, 'origin' => true, 'seq' => true, 'page_id' => true];

        $service = $this->makeService($db);
        $report = $service->writeRows(
            'blocks',
            [['html' => '<p>x</p>']],
            ['html' => '{html}', 'origin' => 'import:{block}', 'seq' => 7],
            ['page_id' => 5]
        );

        self::assertSame(1, $report['inserted']);
        $insert = end($db->queries);
        self::assertSame('<p>x</p>', $insert['params']['html']);
        self::assertSame(7, $insert['params']['seq']);
        self::assertSame(5, $insert['params']['page_id']);
    }

    public function testSchemaOrgDeduplicationByExistingType(): void
    {
        $db = new FakeImportDb();
        $db->schema['schema_org'] = ['schema_type' => true, 'data' => true, 'priority' => true, 'page_id' => true];
        $db->existingSchemaTypes = ['WebSite'];

        $service = $this->makeService($db);
        $report = $service->writeRows(
            'schema_org',
            [
                ['schema_type' => 'WebSite', 'json' => '{"a":1}'],
                ['schema_type' => 'Service', 'json' => '{"b":2}'],
            ],
            ['schema_type' => '{schema_type}', 'data' => '{json}', 'priority' => 10],
            ['page_id' => 9]
        );

        self::assertSame(1, $report['inserted']);
        self::assertSame(1, $report['skipped']);
    }

    public function testMaxRowsPerTableCap(): void
    {
        $db = new FakeImportDb();
        $db->schema['kv'] = ['k' => true];

        $rows = array_map(static fn (int $i): array => ['k' => "v{$i}"], range(1, 250));

        $service = $this->makeService($db);
        $report = $service->writeRows('kv', $rows, ['k' => '{k}']);

        self::assertSame(200, $report['inserted']);
        self::assertNotEmpty($report['errors']); // сообщение об ограничении
    }

    public function testWriteColumnValueValidatesIdentifierAndSchema(): void
    {
        $db = new FakeImportDb();
        $db->schema['settings'] = ['value' => true];

        $service = $this->makeService($db);

        self::assertSame(1, $service->writeColumnValue('settings', 'value', 'hello'));

        $thrown = false;
        try {
            $service->writeColumnValue('settings', 'missing_col', 'x');
        } catch (\RuntimeException) {
            $thrown = true;
        }
        self::assertTrue($thrown, 'Отсутствие колонки должно приводить к RuntimeException');

        $thrown = false;
        try {
            $service->writeColumnValue('setting`s', 'value', 'x');
        } catch (\RuntimeException) {
            $thrown = true;
        }
        self::assertTrue($thrown, 'Небезопасное имя таблицы должно отклоняться');
    }

    public function testTableColumnsExistFiltersUnsafeNames(): void
    {
        $db = new FakeImportDb();
        $db->schema['t'] = ['ok' => true];

        $service = $this->makeService($db);
        $res = $service->tableColumnsExist('t', ['ok', 'in valid', '1bad']);

        self::assertSame(['ok' => true], $res); // небезопасные имена отфильтрованы
    }
}
