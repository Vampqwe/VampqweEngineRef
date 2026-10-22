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


namespace Core\System\Template;

use Core\System\Config\Config;

/**
 * Главный класс шаблонизатора
 */
class Template
{
    private Config $config;
    private TemplateCompiler $compiler;
    private TemplateCache $cache;

    private string $templatePath;
    private string $extension = '.html';

    /** @var array<string, mixed> Глобальные переменные */
    private array $shared = [];

    /** @var array<string, Section> Секции */
    private array $sections = [];

    /** @var int Глубина вложенности */
    private int $renderDepth = 0;

    private int $maxRenderDepth = 10;

    /** @var string Nonce для CSP */
    private string $cspNonce;

    /** @var string|null Имя layout, который нужно применить после рендера */
    private ?string $pendingLayout = null;

    public function __construct(Config $config, ?string $cspNonce = null)
    {
        $this->config = $config;
        $this->compiler = new TemplateCompiler();
        $this->cspNonce = $cspNonce ?? bin2hex(random_bytes(16));

        // Корень проекта — единый резолвер (канон — APP_ROOT_PATH).
        $basePath = $config->getRootPath();
        $this->templatePath = $basePath . DIRECTORY_SEPARATOR . 'views';
        $cachePath = \Core\System\Security\PathResolver::resolveStorageDir($basePath)
            . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'templates';

        $this->cache = new TemplateCache(
            $cachePath,
            $config->getBool('TEMPLATE_CACHE', true)
        );

        // Максимальная глубина вложенности рендера — из конфига (защита от рекурсии @extends/@include)
        $maxDepth = $config->getInt('TEMPLATE_MAX_RENDER_DEPTH', 10);
        if ($maxDepth > 0) {
            $this->maxRenderDepth = $maxDepth;
        }
    }

    // ─── Рендеринг ───

    /**
     * Установить layout для текущего шаблона (вызывается из @extends)
     */
    public function setLayout(string $layout): void
    {
        $this->pendingLayout = $layout;
    }

    /**
     * Рендер шаблона с данными
     */
    public function render(string $template, array $data = []): string
    {
        $this->renderDepth++;

        try {
            if ($this->renderDepth > $this->maxRenderDepth) {
                throw TemplateException::nestedTooDeep($this->renderDepth, $this->maxRenderDepth);
            }

            $templatePath = $this->resolveTemplatePath($template);

            if (!file_exists($templatePath)) {
                throw TemplateException::notFound($template);
            }

            // Проверяем кеш
            $compiled = $this->cache->get($templatePath);

            if ($compiled === null) {
                $source = file_get_contents($templatePath);
                if ($source === false) {
                    throw TemplateException::compilationFailed($template, 'Не удалось прочитать файл');
                }

                $this->compiler->validate($source, $template);
                $compiled = $this->compiler->compile($source);
                $this->cache->put($templatePath, $compiled);
            }

            // Сливаем данные: локальные > глобальные
            $allData = array_merge($this->shared, $data);

            // Сохраняем текущие секции перед рендером дочернего шаблона
            $savedSections = $this->sections;

            // Сбрасываем pendingLayout перед рендером
            $this->pendingLayout = null;

            // Рендерим через eval в изолированной области
            $result = $this->evaluate($compiled, $allData, $templatePath);

            // Если шаблон использовал @extends — оборачиваем в layout
            if ($this->pendingLayout !== null) {
                $layoutName = $this->pendingLayout;
                $this->pendingLayout = null;

                // Передаём контент дочернего шаблона как переменную $__content
                $allData['__content'] = $result;

                // Восстанавливаем секции ДО рендера layout (секции из дочернего шаблона должны быть доступны)
                // Но сначала сохраняем секции дочернего шаблона
                $childSections = $this->sections;

                // Восстанавливаем родительские секции
                $this->sections = $savedSections;

                // Теперь объединяем: секции дочернего шаблона имеют приоритет
                foreach ($childSections as $name => $section) {
                    $this->sections[$name] = $section;
                }

                // Рендерим layout (рекурсивно — layout тоже может использовать @extends)
                $result = $this->render($layoutName, $allData);
            } else {
                // Нет layout — восстанавливаем секции
                $this->sections = $savedSections;
            }

            return $result;
        } finally {
            // Всегда уменьшаем глубину при выходе (включая исключения),
            // иначе счётчик «залипает» и защита от рекурсии ломается/ложно срабатывает.
            $this->renderDepth--;
        }
    }

    /**
     * Рендер строки (не файла)
     */
    public function renderString(string $content, array $data = []): string
    {
        $compiled = $this->compiler->compile($content);
        $allData = array_merge($this->shared, $data);

        return $this->evaluate($compiled, $allData, 'string');
    }

    /**
     * Подготовить доверенный HTML из БД для CSP, не экранируя его и не удаляя теги.
     * Nonce добавляется только inline script/style-элементам; внешние скрипты
     * по-прежнему должны проходить CSP allowlist. Inline event-атрибуты не разрешаются.
     */
    public function trustedHtml(string $content): string
    {
        if ($content === '') {
            return '';
        }

        // Defense-in-depth: контент из БД может быть сохранён в режиме
        // content_mode='raw' (или до появления санитизации) — прогоняем
        // через HtmlSanitizer перед выводом посетителям. Правила —
        // config/html-sanitizer.php. Nonce-обработка ниже остаётся для
        // легитимных inline-скриптов шаблонов.
        $content = \Core\System\Security\HtmlSanitizer::clean($content);

        $wrapperId = 'trusted-content-' . bin2hex(random_bytes(8));
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previousLibxmlMode = libxml_use_internal_errors(true);

        try {
            $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>'
                . '<div id="' . $wrapperId . '">' . $content . '</div></body></html>';

            if (!$document->loadHTML($html, LIBXML_NONET)) {
                throw new \RuntimeException('Не удалось разобрать доверенный HTML-контент');
            }

            $wrapper = null;
            foreach ($document->getElementsByTagName('div') as $element) {
                if ($element->getAttribute('id') === $wrapperId) {
                    $wrapper = $element;
                    break;
                }
            }

            if ($wrapper === null) {
                throw new \RuntimeException('Не удалось извлечь доверенный HTML-контент');
            }

            foreach ($wrapper->getElementsByTagName('script') as $script) {
                if (!$script->hasAttribute('src')) {
                    $script->setAttribute('nonce', $this->cspNonce);
                }
            }

            foreach ($wrapper->getElementsByTagName('style') as $style) {
                $style->setAttribute('nonce', $this->cspNonce);
            }

            $rendered = '';
            foreach ($wrapper->childNodes as $child) {
                $rendered .= $document->saveHTML($child);
            }

            return $rendered;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlMode);
        }
    }

    /**
     * Рендер partial (без layout)
     */
    public function renderPartial(string $template, array $data = []): string
    {
        $templatePath = $this->resolveTemplatePath($template);

        if (!file_exists($templatePath)) {
            throw TemplateException::notFound($template);
        }

        $compiled = $this->cache->get($templatePath);

        if ($compiled === null) {
            $source = file_get_contents($templatePath);
            if ($source === false) {
                return '';
            }

            $compiled = $this->compiler->compile($source);
            $this->cache->put($templatePath, $compiled);
        }

        $allData = array_merge($this->shared, $data);
        return $this->evaluate($compiled, $allData, $templatePath);
    }

    /**
     * Проверить существование шаблона
     */
    public function exists(string $template): bool
    {
        $path = $this->resolveTemplatePath($template);
        return file_exists($path);
    }

    // ── Секции ───

    /**
     * Начать захват секции
     */
    public function startSection(string $name, bool $overwrite = true): string
    {
        $section = new Section($name, $overwrite);

        if (isset($this->sections[$name]) && !$overwrite) {
            $section->setParent($this->sections[$name]);
        }

        $this->sections[$name] = $section;
        $section->start();

        return '';
    }

    /**
     * Завершить захват секции
     */
    public function endSection(string $name): string
    {
        if (!isset($this->sections[$name])) {
            return '';
        }

        $this->sections[$name]->end();
        return '';
    }

	/**
	 * Получить содержимое секции (для @yield)
	 */
	public function yieldSection(string $name, string|null $default = ''): string
	{
		if (!isset($this->sections[$name]) || $this->sections[$name]->isEmpty()) {
			return $default ?? '';
		}

		return $this->sections[$name]->getWithParent();
	}

    // ─── Глобальные переменные ───

    /**
     * Поделиться переменной со всеми шаблонами
     */
    public function share(string $key, mixed $value): self
    {
        $this->shared[$key] = $value;
        return $this;
    }

    /**
     * Поделиться несколькими переменными
     */
    public function shareMany(array $data): self
    {
        $this->shared = array_merge($this->shared, $data);
        return $this;
    }

    /**
     * Получить глобальную переменную
     */
    public function getShared(string $key, mixed $default = null): mixed
    {
        return $this->shared[$key] ?? $default;
    }

    /**
     * Получить все глобальные переменные
     */
    public function getAllShared(): array
    {
        return $this->shared;
    }

    // ─── Кеширование ───

    /**
     * Очистить кеш
     */
    public function clearCache(?string $template = null): int
    {
        if ($template !== null) {
            $path = $this->resolveTemplatePath($template);
            return $this->cache->forget($path) ? 1 : 0;
        }

        return $this->cache->flush();
    }

    // ─── Безопасный вывод скриптов ───

    /**
     * Безопасный вывод внешних скриптов из JSON-массива
     */
    public function scripts(string|array|null $scripts): string
    {
        if ($scripts === null || $scripts === '') {
            return '';
        }

        if (is_string($scripts)) {
            $scripts = json_decode($scripts, true);
            if (!is_array($scripts)) {
                return '';
            }
        }

        // Whitelist разрешённых путей
        $allowedPrefixes = [
            '/assets/js/',
            '/assets/vendor/',
            'https://cdn.jsdelivr.net/npm/',
            'https://unpkg.com/',
        ];

        $html = '';
        foreach ($scripts as $src) {
            if (!is_string($src) || $src === '') {
                continue;
            }

            $isAllowed = false;
            foreach ($allowedPrefixes as $prefix) {
                if (str_starts_with($src, $prefix)) {
                    $isAllowed = true;
                    break;
                }
            }

            if (!$isAllowed) {
                error_log("Template: blocked suspicious script: {$src}");
                continue;
            }

            $safeSrc = htmlspecialchars($src, ENT_QUOTES, 'UTF-8');
            $html .= "<script src=\"{$safeSrc}\" nonce=\"{$this->cspNonce}\" defer></script>\n";
        }

        return $html;
    }

    /**
     * Безопасный вывод Schema.org JSON-LD
     */
    public function schemaJson(array|string $data, ?string $type = null): string
    {
        if (is_string($data)) {
            $data = json_decode($data, true);
            if (!is_array($data)) {
                return '';
            }
        }

        $schema = array_merge(['@context' => 'https://schema.org'], $data);

        if ($type !== null) {
            $schema['@type'] = $type;
        }

        $json = json_encode(
            $schema,
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRETTY_PRINT
                | JSON_HEX_TAG
                | JSON_HEX_AMP
                | JSON_HEX_APOS
                | JSON_HEX_QUOT
                | JSON_THROW_ON_ERROR
        );

        return "<script nonce=\"{$this->cspNonce}\" type=\"application/ld+json\">\n{$json}\n</script>\n";
    }

    /**
     * Получить CSP nonce
     */
    public function getCspNonce(): string
    {
        return $this->cspNonce;
    }

    /**
     * Meta-тег с nonce
     */
    public function cspNonceMeta(): string
    {
        return '<meta name="csp-nonce" content="' . $this->cspNonce . '">';
    }

    // ─── Конфигурация ───

    public function setTemplatePath(string $path): self
    {
        $this->templatePath = rtrim($path, DIRECTORY_SEPARATOR);
        return $this;
    }

    public function setExtension(string $ext): self
    {
        $this->extension = $ext;
        return $this;
    }

    public function setMaxRenderDepth(int $depth): self
    {
        $this->maxRenderDepth = $depth;
        return $this;
    }

    public function getTemplatePath(): string
    {
        return $this->templatePath;
    }

    public function getCache(): TemplateCache
    {
        return $this->cache;
    }

    public function getCompiler(): TemplateCompiler
    {
        return $this->compiler;
    }

    // ─── Приватные методы ───

    /**
     * Разрешить путь к шаблону
     */
    private function resolveTemplatePath(string $template): string
    {
        // Защита от Path Traversal
        if (str_contains($template, '..')) {
            throw TemplateException::pathTraversal($template);
        }

        // Нормализуем разделители
        $template = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $template);

        // Добавляем расширение если нет
        if (!str_ends_with($template, $this->extension)) {
            $template .= $this->extension;
        }

        return $this->templatePath . DIRECTORY_SEPARATOR . ltrim($template, DIRECTORY_SEPARATOR);
    }

    /**
     * Выполнить скомпилированный PHP-код
     */
    private function evaluate(string $compiledCode, array $data, string $templateName): string
    {
        // Гарантируем наличие глобальных переменных, чтобы избежать Warning 
        // при вычислении аргументов в @yield('title', app_name)
        $data = array_merge([
            'app_name' => $this->getShared('app_name', 'Достойный уход'),
            'base_url' => $this->getShared('base_url', ''),
        ], $data);

        extract($data, EXTR_SKIP);
        
        $__template = $this;
        
        ob_start();
        try {
            eval('?>' . $compiledCode);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw TemplateException::compilationFailed($templateName, $e->getMessage());
        }
        
        return ob_get_clean() ?: '';
    }
	
		/**
	 * Безопасный вывод HTML-контента (с санитизацией)
	 */
	public function safeHtml(string $html): string
	{
		return HtmlSanitizer::clean($html);
	}
}