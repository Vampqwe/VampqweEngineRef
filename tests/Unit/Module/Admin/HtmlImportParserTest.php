<?php

declare(strict_types=1);

namespace Tests\Unit\Module\Admin;

use Core\Module\Admin\Services\HtmlImportParser;
use Core\System\Config\Config;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты конфиг-ориентированного парсера импорта (config/import-parser.php):
 * извлечение блоков по маркерам/XPath/meta, валидация описаний блоков,
 * трансформации, fallback-поведение и отчёт blocksPlainText.
 */
final class HtmlImportParserTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/importparser_' . uniqid('', true);
        mkdir($this->tmpRoot . '/storage', 0777, true);
        mkdir($this->tmpRoot . '/logs', 0777, true);

        file_put_contents(
            $this->tmpRoot . '/config.env',
            "APP_ENV=test\nAPP_DEBUG=false\nAPP_NAME=TestSite\n"
            . "SECURITY_ENFORCE_CONFIG_PERMS=false\nAPP_ROOT_PATH=\"{$this->tmpRoot}\"\n"
        );
        chmod($this->tmpRoot . '/config.env', 0640);

        Config::reset();
        Config::init($this->tmpRoot . '/config.env');
    }

    protected function tearDown(): void
    {
        Config::reset();
        $this->rrmdir($this->tmpRoot);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $p = $dir . '/' . $item;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    /**
     * Переопределение секции 'blocks' конфига парсера для теста.
     */
    private function makeParserWithBlocks(array $blocks): HtmlImportParser
    {
        $parser = new HtmlImportParser(Config::getInstance());

        $ref = new \ReflectionProperty(HtmlImportParser::class, 'rules');
        $ref->setAccessible(true);
        $rules = (array) $ref->getValue($parser);
        $rules['blocks'] = $blocks;
        $rules['marker_aliases'] = [];
        $ref->setValue($parser, $rules);

        return $parser;
    }

    // ─────────────────────────── Валидация описаний блоков ───────────────────────────

    public function testValidateBlockDefinitionAcceptsMarkerBlock(): void
    {
        $check = HtmlImportParser::validateBlockDefinition('nav', [
            'type'   => 'marker',
            'start'  => '<---nav--->',
            'end'    => '<---end nav--->',
            'target' => ['table' => 'menu_items', 'column' => 'html'],
        ]);
        self::assertTrue($check['ok']);
    }

    public function testValidateBlockDefinitionRejectsBadNamesAndTypes(): void
    {
        // Неверное имя блока
        $check = HtmlImportParser::validateBlockDefinition('bad-name!', ['type' => 'title']);
        self::assertFalse($check['ok']);

        // Неизвестный type
        $check = HtmlImportParser::validateBlockDefinition('x', ['type' => 'magic']);
        self::assertFalse($check['ok']);

        // marker без start
        $check = HtmlImportParser::validateBlockDefinition('m', ['type' => 'marker']);
        self::assertFalse($check['ok']);

        // SQL-injection в имени таблицы target
        $check = HtmlImportParser::validateBlockDefinition('t', [
            'type'   => 'title',
            'target' => ['table' => 'pages; DROP TABLE users--', 'column' => 'title'],
        ]);
        self::assertFalse($check['ok']);

        // rows без fields
        $check = HtmlImportParser::validateBlockDefinition('r', [
            'type'   => 'schema',
            'target' => ['table' => 'schema_org', 'mode' => 'rows'],
        ]);
        self::assertFalse($check['ok']);
    }

    // ─────────────────────────── Извлечение по маркерам ───────────────────────────

    public function testMarkerBlockExtractionCustomDelimiters(): void
    {
        $html = '<html><head><title>T</title></head><body>'
            . '<div>до</div>'
            . '<---nav---><ul><li><a href="/a">Пункт А</a></li></ul><---end nav--->'
            . '<div>после</div>'
            . '</body></html>';

        $parser = $this->makeParserWithBlocks([
            'nav' => [
                'type'   => 'marker',
                'start'  => '<---nav--->',
                'end'    => '<---end nav--->',
                'target' => ['table' => 'menu_items', 'column' => 'html'],
            ],
        ]);

        $parsed = $parser->parse($html);

        self::assertTrue($parsed['blocks']['nav']['found']);
        self::assertStringContainsString('Пункт А', (string) $parsed['values']['html']);
        self::assertStringNotContainsString('после', (string) $parsed['values']['html']);
    }

    public function testMarkerCaseInsensitiveAndFlexibleWhitespace(): void
    {
        $html = '<html><body><!--   NAV   --><p>inner</p><!--  END  NAV  --><p>out</p></body></html>';

        $parser = $this->makeParserWithBlocks([
            'nav' => [
                'type'   => 'marker',
                'start'  => '<!-- nav -->',
                'end'    => '<!-- end nav -->',
                'target' => ['table' => 'pages', 'column' => 'content'],
            ],
        ]);

        $parsed = $parser->parse($html);
        self::assertTrue($parsed['blocks']['nav']['found']);
        self::assertStringContainsString('inner', (string) $parsed['values']['content']);
        self::assertStringNotContainsString('>out<', (string) $parsed['values']['content']);
    }

    public function testMissingEndMarkerTakesUntilFooterAndWarns(): void
    {
        $html = '<html><body><---sec---><p>keep</p><footer>tail</footer></body></html>';

        $parser = $this->makeParserWithBlocks([
            'sec' => [
                'type'   => 'marker',
                'start'  => '<---sec--->',
                'end'    => null,
                'target' => ['table' => 'pages', 'column' => 'content'],
            ],
        ]);

        $parsed = $parser->parse($html);
        self::assertTrue($parsed['blocks']['sec']['found']);
        self::assertStringContainsString('keep', (string) $parsed['values']['content']);
        self::assertStringNotContainsString('tail', (string) $parsed['values']['content']);
        self::assertNotEmpty($parsed['warnings']);
    }

    public function testFallbackXpathUsedWhenMarkersAbsent(): void
    {
        $html = '<html><body><main><h2>Заголовок секции</h2><p>текст</p></main></body></html>';

        $parser = $this->makeParserWithBlocks([
            'main_content' => [
                'type'           => 'marker',
                'start'          => '<!-- ОСНОВНОЙ КОНТЕНТ -->',
                'end'            => '<!-- FOOTER -->',
                'fallback_xpath' => '(//main | //article)[1]',
                'target'         => ['table' => 'pages', 'column' => 'content'],
            ],
        ]);

        $parsed = $parser->parse($html);
        self::assertTrue($parsed['blocks']['main_content']['found']);
        self::assertSame('fallback_xpath', $parsed['blocks']['main_content']['meta']['marker']);
        self::assertStringContainsString('Заголовок секции', (string) $parsed['values']['content']);
    }

    public function testRequiredBlockMissingProducesWarning(): void
    {
        $html = '<html><body><p>nothing here</p></body></html>';

        $parser = $this->makeParserWithBlocks([
            'missing' => [
                'type'     => 'marker',
                'start'    => '<---absent--->',
                'required' => true,
                'target'   => ['table' => 'pages', 'column' => 'content'],
            ],
        ]);

        $parsed = $parser->parse($html);
        self::assertFalse($parsed['blocks']['missing']['found']);
        $joined = implode("\n", $parsed['warnings']);
        self::assertStringContainsString('Обязательный блок «missing» не найден', $joined);
    }

    // ─────────────────────────── title / meta / xpath / script ───────────────────────────

    public function testTitleAndSlugGeneration(): void
    {
        $html = '<html><head><title>Услуги компании — Сайт</title></head><body><h1>Игнор</h1></body></html>';

        $parser = $this->makeParserWithBlocks([
            'title' => ['type' => 'title', 'target' => ['table' => 'pages', 'column' => 'title']],
            'slug'  => ['source' => 'slug_from_title', 'target' => ['table' => 'pages', 'column' => 'slug']],
        ]);

        $parsed = $parser->parse($html);
        self::assertSame('Услуги компании — Сайт', $parsed['title']);
        self::assertMatchesRegularExpression('/^[a-z0-9\-]+$/', $parsed['slug']);
        self::assertStringNotContainsString(' ', $parsed['slug']);
    }

    public function testMetaBlockWithFallbackMeta(): void
    {
        $html = '<html><head>'
            . '<meta property="og:description" content="OG описание">'
            . '</head><body></body></html>';

        $parser = $this->makeParserWithBlocks([
            'description' => [
                'type'          => 'meta',
                'expression'    => 'description',
                'fallback_meta' => [['property' => 'og:description']],
                'target'        => ['table' => 'pages', 'column' => 'description'],
            ],
        ]);

        $parsed = $parser->parse($html);
        self::assertSame('OG описание', $parsed['values']['description']);
    }

    public function testXpathBlockAndTransformStripTags(): void
    {
        $html = '<html><body><aside id="promo"><b>Акция!</b> <span>скидка</span></aside></body></html>';

        $parser = $this->makeParserWithBlocks([
            'promo' => [
                'type'       => 'xpath',
                'expression' => '//aside[@id="promo"]',
                'transform'  => 'strip_tags',
                'target'     => ['table' => 'pages', 'column' => 'description'],
            ],
        ]);

        $parsed = $parser->parse($html);
        self::assertTrue($parsed['blocks']['promo']['found']);
        self::assertStringNotContainsString('<b>', (string) $parsed['values']['description']);
        self::assertStringContainsString('Акция!', (string) $parsed['values']['description']);
    }

    public function testInvalidXpathExpressionWarnsAndSkips(): void
    {
        $html = '<html><body><p>x</p></body></html>';

        $parser = $this->makeParserWithBlocks([
            'broken' => [
                'type'       => 'xpath',
                'expression' => '///bad[[',
                'target'     => ['table' => 'pages', 'column' => 'content'],
            ],
        ]);

        $parsed = $parser->parse($html);
        self::assertFalse($parsed['blocks']['broken']['found']);
        self::assertStringContainsString('некорректное XPath-выражение', implode("\n", $parsed['warnings']));
    }

    public function testScriptBlockJsonArrayTarget(): void
    {
        $html = '<html><body><script src="/js/a.js"></script><script src="/js/b.js"></script></body></html>';

        $parser = $this->makeParserWithBlocks([
            'scripts' => [
                'type'   => 'script',
                'target' => ['table' => 'pages', 'column' => 'scripts', 'mode' => 'json_array'],
                'default' => ['/assets/js/main.js'],
            ],
        ]);

        $parsed = $parser->parse($html);
        $decoded = json_decode((string) $parsed['values']['scripts'], true);
        self::assertIsArray($decoded);
        self::assertContains('/js/a.js', $decoded);
        self::assertContains('/js/b.js', $decoded);
    }

    // ─────────────────────────── schema rows и совместимость контракта ───────────────────────────

    public function testSchemaRowsCollectedIntoRowSets(): void
    {
        $ld = json_encode(['@type' => 'Service', 'name' => 'Консультация']);
        $html = "<html><head><script type=\"application/ld+json\">{$ld}</script></head><body></body></html>";

        $parser = $this->makeParserWithBlocks([
            'schema' => [
                'type'   => 'schema',
                'target' => [
                    'table'  => 'schema_org',
                    'mode'   => 'rows',
                    'fields' => ['schema_type' => '{schema_type}', 'data' => '{json}'],
                ],
            ],
        ]);

        $parsed = $parser->parse($html);
        self::assertArrayHasKey('schema_org', $parsed['row_sets']);
        self::assertNotEmpty($parsed['row_sets']['schema_org']);
        self::assertSame('Service', $parsed['row_sets']['schema_org'][0]['schema_type']);
        // Обратная совместимость со старым контрактом
        self::assertSame($parsed['row_sets']['schema_org'], $parsed['schemas']);
    }

    public function testParseReturnsContractFields(): void
    {
        $html = '<html><head><title>Страница</title></head><body><p>тext</p></body></html>';
        $parser = new HtmlImportParser(Config::getInstance());

        $parsed = $parser->parse($html);

        foreach (['title', 'description', 'slug', 'content', 'schemas', 'scripts',
                  'styles', 'images_count', 'warnings', 'blocks', 'values', 'row_sets'] as $key) {
            self::assertArrayHasKey($key, $parsed, "Отсутствует поле контракта: {$key}");
        }
    }

    public function testGetActiveBlocksAndTargets(): void
    {
        $parser = $this->makeParserWithBlocks([
            'nav' => [
                'type'   => 'marker',
                'start'  => '<---nav--->',
                'target' => ['table' => 'menu_items', 'column' => 'html'],
            ],
            'broken' => ['type' => 'magic'],
        ]);

        $active = $parser->getActiveBlocks();
        self::assertArrayHasKey('nav', $active);
        self::assertArrayNotHasKey('broken', $active);

        $targets = $parser->getBlockTargets();
        self::assertSame('menu_items', $targets['nav']['table']);
    }

    public function testBlocksPlainTextReport(): void
    {
        $html = '<html><head><title>Отчёт</title></head><body>'
            . '<---nav---><a href="#">Меню</a><---end nav--->'
            . '</body></html>';

        $parser = $this->makeParserWithBlocks([
            'title' => ['type' => 'title', 'target' => ['table' => 'pages', 'column' => 'title']],
            'nav' => [
                'type'   => 'marker',
                'start'  => '<---nav--->',
                'end'    => '<---end nav--->',
                'target' => ['table' => 'menu_items', 'column' => 'html'],
            ],
        ]);

        $texts = $parser->blocksPlainText($html);
        self::assertSame('Отчёт', $texts['title']);
        self::assertStringContainsString('Меню', $texts['nav']);
    }

    public function testStylesCollectedButNotWrittenOnParse(): void
    {
        $html = '<html><head><style>.x{color:red}</style></head><body><p class="x">т</p></body></html>';
        $parser = new HtmlImportParser(Config::getInstance());

        $parsed = $parser->parse($html);

        self::assertStringContainsString('.x{color:red}', (string) $parsed['styles']);
        // На этапе разбора файл стилей НЕ создаётся — только после commitStyles()
        self::assertFileDoesNotExist($this->tmpRoot . '/storage/neutral-style.css');
    }
}
