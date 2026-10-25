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


namespace Core\System\Diagnostics;

use Core\System\Application\Application;
use Core\System\DataBase\DataBase;
use Core\System\Http\Router;
use Core\System\Security\SecurityManager;
use Core\System\Security\TrustedProxy;
use Core\System\Template\Template;
use Throwable;

/**
 * Fixed, read-only health checks exposed by the local development preview.
 */
final class ProjectChecks
{
    private const DEFINITIONS = [
        'runtime' => [
            'title' => 'PHP runtime',
            'description' => 'Версия PHP, PDO и расширение DOM.',
        ],
        'application' => [
            'title' => 'Application bootstrap',
            'description' => 'Состояние приложения и регистрация health route.',
        ],
        'database' => [
            'title' => 'Database schema',
            'description' => 'Соединение и таблицы страниц, Schema.org, меню и редиректов.',
        ],
        'templates' => [
            'title' => 'Template engine',
            'description' => 'HTML escaping, доверенный HTML, JSON-LD и CSP nonce.',
        ],
        'security' => [
            'title' => 'Security configuration',
            'description' => 'Права config.env, CSRF round-trip и trusted proxy allowlist.',
        ],
        'rate_limit' => [
            'title' => 'Rate limiter',
            'description' => 'Лимит попыток и безопасное файловое хранилище.',
        ],
    ];

    public function __construct(private Application $app)
    {
    }

    /** @return array<string, array{title: string, description: string}> */
    public static function definitions(): array
    {
        return self::DEFINITIONS;
    }

    /** @param string[] $selected */
    public function run(array $selected): array
    {
        $results = [];

        foreach (array_values(array_unique($selected)) as $id) {
            if (!isset(self::DEFINITIONS[$id])) {
                continue;
            }

            $startedAt = hrtime(true);
            try {
                $this->{'check' . str_replace('_', '', ucwords($id, '_'))}();
                $results[] = [
                    'id' => $id,
                    'title' => self::DEFINITIONS[$id]['title'],
                    'status' => 'passed',
                    'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
                    'message' => 'Проверка пройдена.',
                ];
            } catch (Throwable $e) {
                error_log('Preview check ' . $id . ' failed: ' . get_class($e));
                $results[] = [
                    'id' => $id,
                    'title' => self::DEFINITIONS[$id]['title'],
                    'status' => 'failed',
                    'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
                    'message' => 'Проверка не пройдена: ' . get_class($e),
                ];
            }
        }

        return $results;
    }

    private function checkRuntime(): void
    {
        if (PHP_VERSION_ID < 80100 || !extension_loaded('dom') || !extension_loaded('pdo')) {
            throw new \RuntimeException('Required PHP runtime features are unavailable');
        }
    }

    private function checkApplication(): void
    {
        if (!$this->app->isBooted()) {
            throw new \RuntimeException('Application has not completed bootstrap');
        }

        $router = $this->app->make(Router::class);
        foreach ($router->getRoutes() as $route) {
            if ($route->getPath() === '/api/health') {
                return;
            }
        }

        throw new \RuntimeException('Health route is missing');
    }

    private function checkDatabase(): void
    {
        $database = $this->app->make(DataBase::class);
        if ((int) $database->getConnection()->query('SELECT 1')->fetchColumn() !== 1) {
            throw new \RuntimeException('Database probe did not return 1');
        }

        // LIMIT 0 validates required tables without reading or changing CMS content.
        $database->fetchAll('SELECT p.id FROM pages p LEFT JOIN authors a ON p.author_id = a.id LIMIT 0');
        $database->fetchAll('SELECT * FROM schema_org LIMIT 0');
        $database->fetchAll('SELECT * FROM menu_items LIMIT 0');
        $database->fetchAll('SELECT * FROM redirects LIMIT 0');
    }

    private function checkTemplates(): void
    {
        $template = $this->app->make(Template::class);
        $escaped = $template->renderString('{{ preview_value }}', [
            'preview_value' => '<b>escaped</b>',
        ]);
        if (!str_contains($escaped, '&lt;b&gt;escaped&lt;/b&gt;')) {
            throw new \RuntimeException('Escaped template output failed');
        }

        $nonce = $template->getCspNonce();
        $trusted = $template->trustedHtml('<h2>Trusted HTML</h2><script>window.previewCheck = true;</script>');
        if (!str_contains($trusted, '<h2>Trusted HTML</h2>')
            || !str_contains($trusted, 'nonce="' . $nonce . '"')) {
            throw new \RuntimeException('Trusted HTML/CSP nonce output failed');
        }

        $jsonLd = $template->schemaJson(['name' => '</script><script>blocked</script>']);
        if (str_contains($jsonLd, '</script><script>blocked')) {
            throw new \RuntimeException('JSON-LD script boundary is not escaped');
        }
    }

    private function checkSecurity(): void
    {
        $security = $this->app->make(SecurityManager::class);
        $security->checkConfigPermissions($this->app->path('config.env'));

        $csrf = $security->getCsrfToken();
        $token = $csrf->getToken();
        if (!$csrf->validate($token)) {
            throw new \RuntimeException('CSRF validation failed');
        }

        if (!TrustedProxy::contains('127.0.0.1', '127.0.0.0/8')
            || TrustedProxy::contains('192.0.2.1', '127.0.0.0/8')) {
            throw new \RuntimeException('Trusted proxy allowlist check failed');
        }
    }

    private function checkRateLimit(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vampqwe-preview-' . bin2hex(random_bytes(8));
        $limiter = new \Core\System\Security\RateLimiter($directory, 2, 60);
        $key = 'preview:' . bin2hex(random_bytes(8));

        try {
            if (!$limiter->attempt($key)['allowed'] || !$limiter->attempt($key)['allowed']) {
                throw new \RuntimeException('Rate limiter rejected an allowed attempt');
            }
            if ($limiter->attempt($key)['allowed']) {
                throw new \RuntimeException('Rate limiter allowed an attempt beyond its limit');
            }
        } finally {
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @unlink($directory . DIRECTORY_SEPARATOR . '.rate-limiter.lock');
            @rmdir($directory);
        }
    }
}