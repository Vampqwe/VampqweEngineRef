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

        // ─── Логгер (параметры Core\System\Logger\Logger) ───
        'LOG_FILE' => [
            'type' => 'text',
            'label' => 'Файл лога',
            'description' => 'Путь относительно корня проекта (например, logs/app.log). Пусто = значение по умолчанию.',
            'section' => 'logging',
        ],
        'LOG_MAX_SIZE' => [
            'type' => 'int',
            'label' => 'Максимальный размер лога (байт)',
            'description' => 'При превышении выполняется ротация. По умолчанию 10485760 (10 МБ).',
            'section' => 'logging',
            'min' => 10240,
            'max' => 1073741824,
        ],
        'LOG_ROTATION' => [
            'type' => 'bool',
            'label' => 'Ротация логов',
            'description' => 'Автоповорот файла лога при достижении LOG_MAX_SIZE.',
            'section' => 'logging',
        ],
        'LOG_DATE_FORMAT' => [
            'type' => 'text',
            'label' => 'Формат даты в логах',
            'description' => 'Формат PHP date(), например Y-m-d H:i:s.u.',
            'section' => 'logging',
        ],

        // ─── Импортер страниц (управляют config/import-parser.php;
        //     детальная настройка блоков — на странице «Настройки импортера») ───
        'IMPORT_CANONICAL_MODE' => [
            'type' => 'select',
            'label' => 'Режим canonical при импорте',
            'description' => 'derived: canonical = APP_BASE_URL + slug (slug из title). source: canonical берётся из <link rel="canonical"> целиком, slug вырезается из его пути.',
            'section' => 'importer',
            'options' => ['source', 'derived'],
        ],
        'IMPORT_CONTENT_RAW' => [
            'type' => 'bool',
            'label' => 'Побитный импорт контента',
            'description' => '⚠️ Правило доверенного импорта: контент ложится в БД байт-в-байт без санитайзера. Только для доверенных источников!',
            'section' => 'importer',
        ],
        'IMPORT_MAX_FILE_SIZE' => [
            'type' => 'int',
            'label' => 'Максимум размера HTML-файла (байт)',
            'description' => 'Ограничение загружаемого файла импорта. По умолчанию 20971520 (20 МБ).',
            'section' => 'importer',
            'min' => 10240,
            'max' => 268435456,
        ],
        'IMPORT_MAX_IMAGE_SIZE' => [
            'type' => 'int',
            'label' => 'Максимум размера изображения (байт)',
            'description' => 'Ограничение скачиваемых картинок. По умолчанию 10485760 (10 МБ).',
            'section' => 'importer',
            'min' => 10240,
            'max' => 104857600,
        ],
    ];

    /**
     * Ключи формы → имена параметров в config/import-parser.php.
     * Эти настройки хранятся НЕ в config.env, а в PHP-конфиге импортера;
     * запись выполняется round-trip перезаписью файла (ConfigFileParser).
     */
    private const IMPORTER_CONFIG_KEYS = [
        'IMPORT_CANONICAL_MODE' => 'canonical_mode',
        'IMPORT_CONTENT_RAW' => 'content_raw',
        'IMPORT_MAX_FILE_SIZE' => 'max_file_size',
        'IMPORT_MAX_IMAGE_SIZE' => 'max_image_size',
    ];

    /**
     * Значения по умолчанию для ключей импортера (совпадают с дефолтами
     * движка; используются если параметр ещё не записан в конфиг-файл).
     */
    private const IMPORTER_DEFAULTS = [
        'IMPORT_CANONICAL_MODE' => 'source',
        'IMPORT_CONTENT_RAW' => 'true',
        'IMPORT_MAX_FILE_SIZE' => '20971520',
        'IMPORT_MAX_IMAGE_SIZE' => '10485760',
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
            'app' => ['label' => 'Настройки сайта', 'icon' => '🌐', 'fields' => []],
            'security' => ['label' => 'Безопасность', 'icon' => '🔒', 'fields' => []],
            'session' => ['label' => 'Сессии', 'icon' => '🍪', 'fields' => []],
            'logging' => ['label' => 'Логгер', 'icon' => '📝', 'fields' => []],
            'template' => ['label' => 'Шаблоны', 'icon' => '🎨', 'fields' => []],
            'admin' => ['label' => 'Админ-панель', 'icon' => '🛠️', 'fields' => []],
            'importer' => ['label' => 'Настройки импортера', 'icon' => '📥', 'fields' => []],
        ];

        // Текущие значения параметров импортера из config/import-parser.php.
        $importerValues = $this->readImporterConfigValues();

        foreach (self::ALLOWED_KEYS as $key => $meta) {
            $section = $meta['section'] ?? 'app';
            if (!isset($sections[$section])) {
                $sections[$section] = ['label' => $section, 'icon' => '⚙️', 'fields' => []];
            }

            if ($section === 'importer') {
                // Значение берётся из import-parser.php (дефолт — если параметра там нет).
                $cfgKey = self::IMPORTER_CONFIG_KEYS[$key];
                $value = $importerValues[$cfgKey] ?? self::IMPORTER_DEFAULTS[$cfgKey] ?? '';
                if (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                }
                $value = (string) $value;
            } else {
                $value = $this->config->get($key, '');
            }
            $sections[$section]['fields'][$key] = array_merge($meta, [
                'key' => $key,
                'value' => $value,
            ]);
        }

        return $sections;
    }

    /**
     * Текущее (фактическое) значение ключа формы: для настроек импортера —
     * из config/import-parser.php, для остальных — из config.env.
     */
    private function getCurrentValue(string $key): string
    {
        if (isset(self::IMPORTER_CONFIG_KEYS[$key])) {
            $cfgKey = self::IMPORTER_CONFIG_KEYS[$key];
            $values = $this->readImporterConfigValues();
            $value = $values[$cfgKey] ?? self::IMPORTER_DEFAULTS[$cfgKey] ?? '';
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }
            return (string) $value;
        }
        return (string) $this->config->get($key, '');
    }

    /**
     * Прочитать управляемые параметры из config/import-parser.php.
     *
     * @return array<string, mixed>
     */
    private function readImporterConfigValues(): array
    {
        $out = [];
        try {
            $cfg = require $this->basePath . '/config/import-parser.php';
            if (is_array($cfg)) {
                foreach (self::IMPORTER_CONFIG_KEYS as $cfgVal) {
                    if (array_key_exists($cfgVal, $cfg)) {
                        $out[$cfgVal] = $cfg[$cfgVal];
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('SettingsService: cannot read import-parser config: ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * Round-trip запись scalars-параметров в config/import-parser.php
     * (через токенный парсер ConfigFileParser — комментарии сохраняются).
     *
     * @param array<string, string> $changes ключ формы => нормализованное значение
     * @return array{success: bool, message: string}
     */
    private function writeImporterConfigValues(array $changes): array
    {
        $file = $this->basePath . '/config/import-parser.php';
        if (!is_file($file)) {
            return ['success' => false, 'message' => 'Файл config/import-parser.php не найден.'];
        }
        if (!is_writable($file)) {
            return ['success' => false, 'message' => 'Нет прав на запись в config/import-parser.php.'];
        }

        $php = file_get_contents($file);
        if ($php === false) {
            return ['success' => false, 'message' => 'Не удалось прочитать config/import-parser.php.'];
        }

        $newContent = $php;
        foreach ($changes as $formKey => $value) {
            $cfgKey = self::IMPORTER_CONFIG_KEYS[$formKey];
            // PHP-литерал значения: строка в одинарных кавычках либо число/bool.
            if (preg_match('/^-?\d+$/', $value)) {
                $literal = $value;
            } elseif (in_array($value, ['true', 'false'], true)) {
                $literal = $value;
            } else {
                $literal = "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
            }
            $pattern = "/^(\s*'" . preg_quote($cfgKey, '/') . "'\s*=>\s*)([^,;]+)(,)/m";
            if (preg_match($pattern, $newContent)) {
                $newContent = preg_replace_callback(
                    $pattern,
                    fn(array $m): string => $m[1] . $literal . $m[3],
                    $newContent
                );
            } else {
                // Параметр отсутствует — вставим в начало массива после "return [".
                $newContent = preg_replace(
                    '/return\s*\[\n/',
                    "return [\n    '" . $cfgKey . "' => " . $literal . ",\n",
                    $newContent,
                    1
                );
            }
        }

        // Валидация синтаксиса до записи: разбираем результат как PHP-массив.
        // Безопасно: eval выполняется на временном содержимом, значения строго
        // из whitelist и прошли validateAndNormalize (без переносов строк).
        $tmp = @tempnam(sys_get_temp_dir(), 'imp-cfg-') . '.php';
        file_put_contents($tmp, $newContent);
        $check = @include $tmp;
        @unlink($tmp);
        if (!is_array($check)) {
            return ['success' => false, 'message' => 'После изменений config/import-parser.php стал невалидным — запись отменена.'];
        }

        if (file_put_contents($file, $newContent) === false) {
            return ['success' => false, 'message' => 'Не удалось записать config/import-parser.php.'];
        }
        return ['success' => true, 'message' => 'Настройки импортера сохранены.'];
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

            $currentValue = $this->getCurrentValue($key);
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

        // Разделяем изменения: config.env и config/import-parser.php.
        $envChanges = [];
        $importerChanges = [];
        foreach ($changes as $key => $value) {
            if (isset(self::IMPORTER_CONFIG_KEYS[$key])) {
                $importerChanges[$key] = $value;
            } else {
                $envChanges[$key] = $value;
            }
        }

        // Бэкап config.env перед записью
        if ($envChanges !== []) {
            $this->backupConfig();
        }

        // Запись в config.env
        if ($envChanges !== []) {
            $result = $this->writeConfigValues($envChanges);
            if (!$result['success']) {
                return ['ok' => false, 'errors' => [$result['message']], 'changed' => 0];
            }
        }

        // Запись параметров импортера в config/import-parser.php
        if ($importerChanges !== []) {
            $result = $this->writeImporterConfigValues($importerChanges);
            if (!$result['success']) {
                return ['ok' => false, 'errors' => [$result['message']], 'changed' => count($envChanges)];
            }
        }
        // Если изменился APP_NAME — обновить брендовые данные в БД
        if (isset($envChanges['APP_NAME'])) {
            $rebuild = $this->rebuildBrandData($envChanges['APP_NAME']);
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