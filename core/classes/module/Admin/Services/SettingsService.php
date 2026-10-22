<?php
declare(strict_types=1);

namespace Core\Module\Admin\Services;

use Core\System\Config\Config;
use Core\System\Security\SecurityManager;
use Core\System\DataBase\DataBase;

/**
 * Сервис управления настройками движка через UI админ-панели.
 *
 * Безопасность:
 *  - Whitelist разрешённых ключей (критичные секреты НЕЛЬЗЯ менять через UI)
 *  - Валидация значений по типам и диапазонам
 *  - Аудит-лог при каждом изменении
 *  - Бэкап config.env перед записью
 */
class SettingsService
{
    private Config $config;
    private SecurityManager $security;
    private DataBase $db;
    private string $basePath;

    /**
     * Whitelist разрешённых ключей с метаданными.
     * Ключи вне этого списка игнорируются (защита от подмены через POST).
     *
     * @var array<string, array{type: string, label: string, description: string, options?: array, min?: int, max?: int}>
     */
    private const ALLOWED_KEYS = [
        // ─── Приложение ───
        'APP_NAME' => [
            'type' => 'text',
            'label' => 'Название сайта',
            'description' => 'Используется в title, Schema.org, оглавлении.',
            'section' => 'app',
        ],
        'APP_BASE_URL' => [
            'type' => 'url',
            'label' => 'Базовый URL',
            'description' => 'Полный адрес сайта без завершающего слэша (например, https://example.com).',
            'section' => 'app',
        ],
        'APP_ALLOWED_HOSTS' => [
            'type' => 'text',
            'label' => 'Разрешённые хосты',
            'description' => 'Список доменов через запятую (защита от Host-header атак).',
            'section' => 'app',
        ],
        'APP_DEBUG' => [
            'type' => 'bool',
            'label' => 'Режим отладки',
            'description' => 'Включает подробные ошибки и отключает кеш. ОТКЛЮЧИТЬ на продакшене!',
            'section' => 'app',
        ],
        'TIMEZONE' => [
            'type' => 'text',
            'label' => 'Часовой пояс',
            'description' => 'Например: Europe/Moscow, UTC, America/New_York.',
            'section' => 'app',
        ],
        'LOCALE' => [
            'type' => 'text',
            'label' => 'Локаль',
            'description' => 'Например: ru_RU, en_US.',
            'section' => 'app',
        ],

        // ─── Безопасность ───
        'SECURITY_CSRF_ENABLED' => [
            'type' => 'bool',
            'label' => 'CSRF-защита',
            'description' => 'Обязательная проверка токена для POST-запросов.',
            'section' => 'security',
        ],
        'SECURITY_RATE_LIMIT_ENABLED' => [
            'type' => 'bool',
            'label' => 'Rate limiting',
            'description' => 'Ограничение частоты запросов (защита от брутфорса).',
            'section' => 'security',
        ],
        'SECURITY_RATE_LIMIT_MAX' => [
            'type' => 'int',
            'label' => 'Максимум запросов',
            'description' => 'Количество запросов в окне времени.',
            'section' => 'security',
            'min' => 1,
            'max' => 10000,
        ],
        'SECURITY_RATE_LIMIT_WINDOW' => [
            'type' => 'int',
            'label' => 'Окно времени (сек)',
            'description' => 'Период для rate limiting.',
            'section' => 'security',
            'min' => 1,
            'max' => 86400,
        ],
        'SECURITY_HEADERS_ENABLED' => [
            'type' => 'bool',
            'label' => 'HTTP-заголовки безопасности',
            'description' => 'X-Frame-Options, X-Content-Type-Options, Referrer-Policy.',
            'section' => 'security',
        ],
        'SECURITY_CSP_ENABLED' => [
            'type' => 'bool',
            'label' => 'Content Security Policy',
            'description' => 'Защита от XSS через CSP-заголовки.',
            'section' => 'security',
        ],
        'SECURITY_CSP_POLICY' => [
            'type' => 'text',
            'label' => 'CSP-политика',
            'description' => 'Полная строка CSP (например: default-src \'self\'; script-src \'self\').',
            'section' => 'security',
        ],
        'SECURITY_AUDIT_ENABLED' => [
            'type' => 'bool',
            'label' => 'Аудит-лог',
            'description' => 'Запись security-событий в logs/audit.log.',
            'section' => 'security',
        ],
        'TRUSTED_PROXIES' => [
            'type' => 'text',
            'label' => 'Доверенные прокси',
            'description' => 'IP/CIDR через запятую (для X-Forwarded-For). Пусто = отключено.',
            'section' => 'security',
        ],

        // ─── Сессии ───
        'SESSION_COOKIE_SECURE' => [
            'type' => 'bool',
            'label' => 'Secure cookie',
            'description' => 'Передавать cookie только по HTTPS. Включить на продакшене!',
            'section' => 'session',
        ],
        'SESSION_COOKIE_HTTPONLY' => [
            'type' => 'bool',
            'label' => 'HttpOnly cookie',
            'description' => 'Запрет чтения cookie из JavaScript.',
            'section' => 'session',
        ],
        'SESSION_COOKIE_SAMESITE' => [
            'type' => 'select',
            'label' => 'SameSite',
            'description' => 'Защита от CSRF через cookie.',
            'section' => 'session',
            'options' => ['Lax', 'Strict', 'None'],
        ],

        // ─── Логирование ───
        'LOG_ENABLED' => [
            'type' => 'bool',
            'label' => 'Логирование',
            'description' => 'Запись логов в logs/app.log.',
            'section' => 'logging',
        ],
        'LOG_LEVEL' => [
            'type' => 'select',
            'label' => 'Уровень логирования',
            'description' => 'Минимальный уровень для записи в лог.',
            'section' => 'logging',
            'options' => ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'],
        ],
        'LOG_MASK_SENSITIVE' => [
            'type' => 'bool',
            'label' => 'Маскирование чувствительных данных',
            'description' => 'Замена паролей/токенов на ***REDACTED*** в логах.',
            'section' => 'logging',
        ],

        // ─── Шаблоны ───
        'TEMPLATE_CACHE' => [
            'type' => 'bool',
            'label' => 'Кеш шаблонов',
            'description' => 'Кешировать скомпилированные шаблоны в storage/cache/templates/.',
            'section' => 'template',
        ],
        'TEMPLATE_MAX_RENDER_DEPTH' => [
            'type' => 'int',
            'label' => 'Максимальная глубина вложенности',
            'description' => 'Защита от рекурсии в @extends/@include.',
            'section' => 'template',
            'min' => 1,
            'max' => 50,
        ],

        // ─── Админ-панель ───
        'ADMIN_PER_PAGE' => [
            'type' => 'int',
            'label' => 'Строк на странице',
            'description' => 'Размер пагинации в списках админки.',
            'section' => 'admin',
            'min' => 5,
            'max' => 100,
        ],
    ];

    public function __construct(Config $config, SecurityManager $security, DataBase $db)
    {
        $this->config = $config;
        $this->security = $security;
        $this->db = $db;
        $this->basePath = $config->getRootPath();
    }

    /**
     * Получить все настройки, сгруппированные по секциям.
     *
     * @return array<string, array{label: string, fields: array<string, array>}>
     */
    public function getSettingsGrouped(): array
    {
        $sections = [
            'app' => ['label' => 'Приложение', 'icon' => '🌐', 'fields' => []],
            'security' => ['label' => 'Безопасность', 'icon' => '🔒', 'fields' => []],
            'session' => ['label' => 'Сессии', 'icon' => '🍪', 'fields' => []],
            'logging' => ['label' => 'Логирование', 'icon' => '📝', 'fields' => []],
            'template' => ['label' => 'Шаблоны', 'icon' => '🎨', 'fields' => []],
            'admin' => ['label' => 'Админ-панель', 'icon' => '🛠️', 'fields' => []],
        ];

        foreach (self::ALLOWED_KEYS as $key => $meta) {
            $section = $meta['section'] ?? 'app';
            if (!isset($sections[$section])) {
                $sections[$section] = ['label' => $section, 'icon' => '⚙️', 'fields' => []];
            }

            $value = $this->config->get($key, '');
            $sections[$section]['fields'][$key] = array_merge($meta, [
                'key' => $key,
                'value' => $value,
            ]);
        }

        return $sections;
    }

    /**
     * Валидация и сохранение настроек.
     *
     * @param array<string, mixed> $data POST-данные
     * @return array{ok: bool, errors: string[], changed: int}
     */
    public function saveSettings(array $data): array
    {
        $errors = [];
        $changed = 0;
        $changes = [];

        foreach (self::ALLOWED_KEYS as $key => $meta) {
            if (!array_key_exists($key, $data)) {
                // Для bool-полей отсутствие в POST = false (чекбокс не отправляется)
                if ($meta['type'] === 'bool') {
                    $data[$key] = '0';
                } else {
                    continue;
                }
            }

            $value = $this->validateAndNormalize($key, $data[$key], $meta, $errors);
            if ($value === null && !empty($errors)) {
                continue; // Ошибка валидации
            }

            $currentValue = $this->config->get($key, '');
            if ((string) $currentValue !== (string) $value) {
                $changes[$key] = $value;
                $changed++;
            }
        }

        if (!empty($errors)) {
            return ['ok' => false, 'errors' => $errors, 'changed' => 0];
        }

        if ($changed === 0) {
            return ['ok' => true, 'errors' => [], 'changed' => 0];
        }

        // Бэкап config.env перед записью
        $this->backupConfig();

        // Запись в config.env
        $result = $this->writeConfigValues($changes);
        if (!$result['success']) {
            return ['ok' => false, 'errors' => [$result['message']], 'changed' => 0];
        }
        // Если изменился APP_NAME — обновить брендовые данные в БД
        if (isset($changes['APP_NAME'])) {
            $rebuild = $this->rebuildBrandData($changes['APP_NAME']);
            // Можно добавить в ответ информацию о пересчёте
            $changes['_rebuild_info'] = $rebuild;
        }
        // Аудит-лог
        $this->auditChanges($changes);

        return ['ok' => true, 'errors' => [], 'changed' => $changed];
    }

    /**
     * Валидация и нормализация значения по типу.
     */
    private function validateAndNormalize(string $key, mixed $value, array $meta, array &$errors): ?string
    {
        $value = is_string($value) ? trim($value) : (string) $value;

        switch ($meta['type']) {
            case 'bool':
                return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true) ? 'true' : 'false';

            case 'int':
                if (!ctype_digit($value) && $value !== '' && $value[0] !== '-') {
                    $errors[] = "{$meta['label']}: должно быть целым числом.";
                    return null;
                }
                $intVal = (int) $value;
                if (isset($meta['min']) && $intVal < $meta['min']) {
                    $errors[] = "{$meta['label']}: минимум {$meta['min']}.";
                    return null;
                }
                if (isset($meta['max']) && $intVal > $meta['max']) {
                    $errors[] = "{$meta['label']}: максимум {$meta['max']}.";
                    return null;
                }
                return (string) $intVal;

            case 'url':
                if ($value !== '' && !filter_var($value, FILTER_VALIDATE_URL)) {
                    $errors[] = "{$meta['label']}: некорректный URL.";
                    return null;
                }
                return rtrim($value, '/');

            case 'select':
                if (!in_array($value, $meta['options'] ?? [], true)) {
                    $errors[] = "{$meta['label']}: недопустимое значение.";
                    return null;
                }
                return $value;

            case 'text':
            default:
                // Защита от инъекций в config.env (символы переноса строки)
                if (preg_match('/[\r\n]/', $value)) {
                    $errors[] = "{$meta['label']}: содержит недопустимые символы.";
                    return null;
                }
                return $value;
        }
    }

    /**
     * Запись значений в config.env (аналог InstallService::writeConfigValues).
     *
     * @param array<string, string> $values
     */
    private function writeConfigValues(array $values): array
    {
        try {
            $configFile = $this->basePath . '/config.env';

            // Отладка: логируем путь и права
            error_log('SettingsService: config file = ' . $configFile);
            error_log('SettingsService: file exists = ' . (is_file($configFile) ? 'yes' : 'no'));
            error_log('SettingsService: file writable = ' . (is_writable($configFile) ? 'yes' : 'no'));

            if (!is_file($configFile)) {
                return ['success' => false, 'message' => 'Файл config.env не найден.'];
            }
            if (!is_writable($configFile)) {
                return ['success' => false, 'message' => 'Нет прав на запись в config.env.'];
            }

            $content = file_get_contents($configFile);
            if ($content === false) {
                return ['success' => false, 'message' => 'Не удалось прочитать config.env.'];
            }

            foreach ($values as $key => $value) {
                $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';
                $replacement = fn() => $key . '=' . $value;

                if (preg_match($pattern, $content)) {
                    $content = preg_replace_callback($pattern, $replacement, $content);
                    error_log('SettingsService: replaced key ' . $key);
                } else {
                    $content .= "\n" . $key . '=' . $value;
                    error_log('SettingsService: appended key ' . $key);
                }
            }

            if (file_put_contents($configFile, $content) === false) {
                return ['success' => false, 'message' => 'Не удалось записать config.env.'];
            }

            error_log('SettingsService: config saved successfully');
            return ['success' => true, 'message' => 'Настройки сохранены.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()];
        }
    }

    /**
     * Создать бэкап config.env перед записью.
     */
    private function backupConfig(): void
    {
        try {
            $configFile = $this->basePath . '/config.env';
            $backupDir = $this->config->getStoragePath('backups');
            if (!is_dir($backupDir)) {
                @mkdir($backupDir, 0750, true);
            }
            $backupFile = $backupDir . '/config.env.' . date('Y-m-d_H-i-s') . '.bak';
            @copy($configFile, $backupFile);
            @chmod($backupFile, 0640);
        } catch (\Throwable $e) {
            // Бэкап не критичен — логируем и продолжаем
            error_log('Settings backup failed: ' . $e->getMessage());
        }
    }

    /**
     * Запись изменений в аудит-лог.
     *
     * @param array<string, string> $changes
     */
    private function auditChanges(array $changes): void
    {
        try {
            $this->security->getAuditLogger()->logAction(
                'settings.update',
                'config',
                null,
                ['changed_keys' => array_keys($changes)]
            );
        } catch (\Throwable $e) {
            // Аудит не должен ломать сохранение
            error_log('Settings audit failed: ' . $e->getMessage());
        }
    }

    /**
 * Обновить брендовые данные в БД при смене APP_NAME.
 *
 * Вызывается автоматически после сохранения APP_NAME через настройки.
 * Обновляет:
 *  - schema_org.data для брендовых типов (Organization, WebSite, LocalBusiness, VeterinaryCare, MedicalOrganization)
 *  - pages.title для главной страницы (если содержит старое имя)
 *  - pages.og_title для главной страницы
 *
 * @return array{updated_schemas: int, updated_pages: int}
 */
public function rebuildBrandData(string $newAppName): array
{
    $updatedSchemas = 0;
    $updatedPages = 0;

    // Брендовые типы Schema.org, где name = название сайта
    $brandTypes = [
        'Organization', 'WebSite', 'LocalBusiness',
        'VeterinaryCare', 'MedicalOrganization', 'Physician', 'MedicalBusiness'
    ];

    try {
        // 1. Обновить schema_org
        $schemas = $this->db->fetchAll(
            "SELECT id, data FROM schema_org WHERE schema_type IN (" .
            implode(',', array_fill(0, count($brandTypes), '?')) . ")",
            $brandTypes
        );

        foreach ($schemas as $schema) {
            $data = json_decode($schema['data'], true);
            if (!is_array($data) || !isset($data['name'])) continue;

            // Заменяем name на новое имя
            $data['name'] = $newAppName;

            $this->db->execute(
                "UPDATE schema_org SET data = :data, updated_at = NOW() WHERE id = :id",
                ['data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'id' => $schema['id']]
            );
            $updatedSchemas++;
        }

        // 2. Обновить главную страницу (slug = '')
        $homePage = $this->db->fetchOne(
            "SELECT id, title, og_title FROM pages WHERE slug = '' LIMIT 1"
        );

        if ($homePage) {
            $needsUpdate = false;
            $params = ['id' => $homePage['id']];

            // Если title содержит старое имя — заменяем
            // (простая эвристика: если title не пустой и не совпадает с новым именем)
            $currentTitle = (string)($homePage['title'] ?? '');
            if ($currentTitle !== '' && !str_contains($currentTitle, $newAppName)) {
                // Предполагаем, что старое имя было в title — заменяем на новое
                // (это грубая эвристика, но работает для seed-данных)
                $params['title'] = $newAppName;
                $needsUpdate = true;
            }

            $currentOgTitle = (string)($homePage['og_title'] ?? '');
            if ($currentOgTitle !== '' && !str_contains($currentOgTitle, $newAppName)) {
                $params['og_title'] = $newAppName;
                $needsUpdate = true;
            }

            if ($needsUpdate) {
                $sql = "UPDATE pages SET updated_at = NOW()";
                if (isset($params['title'])) $sql .= ", title = :title";
                if (isset($params['og_title'])) $sql .= ", og_title = :og_title";
                $sql .= " WHERE id = :id";

                $this->db->execute($sql, $params);
                $updatedPages++;
            }
        }

        return ['updated_schemas' => $updatedSchemas, 'updated_pages' => $updatedPages];
        } catch (\Throwable $e) {
            error_log('rebuildBrandData failed: ' . $e->getMessage());
            return ['updated_schemas' => 0, 'updated_pages' => 0];
        }
    }

    /**
 * Пересоздать seed-данные с актуальным APP_NAME.
 *
 * Обновляет:
 *  - schema_org.data для брендовых типов (Organization, WebSite, LocalBusiness)
 *  - pages.title и og_title для главной страницы (slug='')
 *  - authors.name для автора по умолчанию
 *
 * @return array{ok: bool, errors: string[], updated: array{schemas: int, pages: int, authors: int}}
 */
public function rebuildSeedData(): array
{
    $appName = $this->config->get('APP_NAME', 'VampqweEngine');
    $baseUrl = rtrim($this->config->get('APP_BASE_URL', 'https://localhost'), '/');

    $updated = ['schemas' => 0, 'pages' => 0, 'authors' => 0];
    $errors = [];

    try {
        // 1. Обновить брендовые схемы
        $brandTypes = ['Organization', 'WebSite', 'LocalBusiness', 'VeterinaryCare', 'MedicalOrganization'];
        $schemas = $this->db->fetchAll(
            "SELECT id, data FROM schema_org WHERE schema_type IN (" .
            implode(',', array_fill(0, count($brandTypes), '?')) . ")",
            $brandTypes
        );

        foreach ($schemas as $schema) {
            $data = json_decode($schema['data'], true);
            if (!is_array($data)) continue;

            $changed = false;
            if (isset($data['name']) && $data['name'] !== $appName) {
                $data['name'] = $appName;
                $changed = true;
            }
            if (isset($data['url']) && $data['url'] !== $baseUrl) {
                $data['url'] = $baseUrl;
                $changed = true;
            }

            if ($changed) {
                $this->db->execute(
                    "UPDATE schema_org SET data = :data, updated_at = NOW() WHERE id = :id",
                    ['data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'id' => $schema['id']]
                );
                $updated['schemas']++;
            }
        }

        // 2. Обновить главную страницу
        $homePage = $this->db->fetchOne("SELECT id, title, og_title FROM pages WHERE slug = '' LIMIT 1");
        if ($homePage) {
            $needsUpdate = false;
            $params = ['id' => $homePage['id']];

            if (($homePage['title'] ?? '') !== $appName) {
                $params['title'] = $appName;
                $needsUpdate = true;
            }
            if (($homePage['og_title'] ?? '') !== $appName) {
                $params['og_title'] = $appName;
                $needsUpdate = true;
            }

            if ($needsUpdate) {
                $sql = "UPDATE pages SET updated_at = NOW()";
                if (isset($params['title'])) $sql .= ", title = :title";
                if (isset($params['og_title'])) $sql .= ", og_title = :og_title";
                $sql .= " WHERE id = :id";

                $this->db->execute($sql, $params);
                $updated['pages']++;
            }
        }

        // 3. Обновить автора по умолчанию (если есть)
        $defaultAuthor = $this->db->fetchOne("SELECT id, name FROM authors WHERE slug = 'komanda-103vet' LIMIT 1");
            if ($defaultAuthor) {
                $newAuthorName = 'Команда сайта ' . $appName;
                if ($defaultAuthor['name'] !== $newAuthorName) {
                    $this->db->execute(
                        "UPDATE authors SET name = :name, updated_at = NOW() WHERE id = :id",
                        ['name' => $newAuthorName, 'id' => $defaultAuthor['id']]
                    );
                    $updated['authors']++;
                }
            }

            // Аудит-лог
            $this->security->getAuditLogger()->logAction('settings.rebuild_seed', 'config', null, [
                'app_name' => $appName,
                'updated' => $updated,
            ]);

            return ['ok' => true, 'errors' => [], 'updated' => $updated];
        } catch (\Throwable $e) {
            return ['ok' => false, 'errors' => ['Ошибка пересоздания: ' . $e->getMessage()], 'updated' => $updated];
        }
    }
}