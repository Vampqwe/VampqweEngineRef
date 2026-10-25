<?php
declare(strict_types=1);

namespace Core\Module\Admin\Services;

use Core\System\Config\Config;
use Core\System\Security\SecurityManager;

class ConfigFileParser
{
    private string $basePath;
    private SecurityManager $security;

    private const SENSITIVE_KEYS = [
        'DB_PASS', 'SUPER_ADMIN_PASSWORD', 'INSTALL_KEY', 'INSTALL_HMAC_KEY',
        'APP_SECRET', 'SESSION_SECRET', 'API_KEY', 'SMTP_PASSWORD',
    ];

    private const READONLY_KEYS = [
        'APP_ROOT_PATH', 'FS_BASE_PATH', 'DB_DRIVER', 'DB_PORT',
        'DB_CHARSET', 'DB_PDO_ERRMODE', 'DB_PDO_FETCH_MODE', 'DB_PDO_PERSISTENT',
        'FS_DEFAULT_FILE_PERMISSION', 'FS_DEFAULT_DIR_PERMISSION', 'FS_ENCODING',
    ];

    public function __construct(Config $config, SecurityManager $security)
    {
        $this->basePath = rtrim($config->getRootPath(), '/\\');
        $this->security = $security;
    }

    public function parse(): array
    {
        $file = $this->basePath . DIRECTORY_SEPARATOR . 'config.env';

        if (!is_file($file)) {
            return [
                'sections' => [],
                'meta' => ['error' => 'Файл не найден: ' . $file, 'lines' => 0, 'size' => 0, 'writable' => false, 'permissions' => '?']
            ];
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return ['sections' => [], 'meta' => ['error' => 'Не удалось прочитать файл', 'lines' => 0, 'size' => 0, 'writable' => false, 'permissions' => '?']];
        }

        // Нормализуем переносы строк
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $lines = explode("\n", $content);

        $sections = [];
        $currentSection = 'general';

        $sectionLabels = [
            'general' => ['label' => 'Общие', 'icon' => '⚙️'],
            'database' => ['label' => 'База данных', 'icon' => '🗄️'],
            'security' => ['label' => 'Безопасность', 'icon' => '🔒'],
            'session' => ['label' => 'Сессии', 'icon' => '🍪'],
            'logging' => ['label' => 'Логирование', 'icon' => '📝'],
            'filesystem' => ['label' => 'Файловая система', 'icon' => '📁'],
            'install' => ['label' => 'Установка', 'icon' => '🚀'],
            'superadmin' => ['label' => 'Супер-админ', 'icon' => ''],
            'app' => ['label' => 'Приложение', 'icon' => '🌐'],
            'template' => ['label' => 'Шаблоны', 'icon' => '🎨'],
            'admin' => ['label' => 'Админ-панель', 'icon' => '️'],
        ];

        $sections[$currentSection] = ['label' => 'Общие', 'icon' => '⚙️', 'keys' => [], 'commented_keys' => [], 'comments' => []];
        $lineIndex = 0;
        // Контекст для заголовков вида: "# =====" / "# НАЗВАНИЕ" / "# =====".
        $pendingDividerLine = null;   // строка чистого разделителя перед текстовым заголовком

        foreach ($lines as $line) {
            $lineIndex++;
            $trimmed = trim($line);

            // 1. Пустая строка (разделители сбрасываем: пустая строка разрывает блок)
            if ($trimmed === '') {
                $pendingDividerLine = null;
                continue;
            }

            // 2. Комментарий с #
            if (str_starts_with($trimmed, '#')) {
                $isPureDivider = (bool) preg_match('/^#+\s*=+\s*$/', $trimmed);

                // Заголовок из одной текстовой строки между двумя чистыми
                // разделителями: "# =====" / "# СУПЕР-АДМИНИСТРАТОР" / "# =====".
                // Чистый разделитель — только ASCII "=" после "#"; иначе кириллический
                // текст комментария ошибочно открывал бы секции у соседних строк.
                if (!$isPureDivider && $pendingDividerLine !== null && $this->isSectionHeader($trimmed, true)) {
                    $title = $this->extractTitle($trimmed);
                    $this->openSection($sections, $currentSection, $title, $lineIndex, $sectionLabels, $pendingDividerLine);
                    $pendingDividerLine = null;
                    continue;
                }

                // Разделитель секции: "# ===== НАЗВАНИЕ =====", "# ═══ НАЗВАНИЕ ═══"
                // или чистая линия "# =====".
                if ($this->isSectionHeader($trimmed)) {
                    $title = $this->extractTitle($trimmed);
                    if ($title !== '') {
                        $this->openSection($sections, $currentSection, $title, $lineIndex, $sectionLabels);
                        $pendingDividerLine = null;
                    } else {
                        // чистый разделитель без текста: запоминаем — возможно,
                        // следующая строка содержит название секции
                        $pendingDividerLine = $lineIndex;
                    }
                    continue;
                }
                // Закомментированная опция: "# KEY=VALUE" — выводим отдельным списком
                if (preg_match('/^#+\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$/', $trimmed, $m)) {
                    $row = $this->buildKeyRow(
                        strtoupper($m[1]), $this->unquoteValue(trim($m[2])), $lineIndex, true
                    );
                    $sections[$currentSection]['commented_keys'][] = $row;
                    $pendingDividerLine = null;
                    continue;
                }
                // Обычный комментарий
                $sections[$currentSection]['comments'][] = [
                    'type' => 'comment',
                    'text' => ltrim(substr($trimmed, 1)),
                    'line' => $lineIndex,
                ];
                $pendingDividerLine = null;
                continue;
            }

            // 3. Активная опция: KEY=VALUE (допускается "export " и пробелы вокруг "=")
            if (preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $trimmed, $m)) {
                $sections[$currentSection]['keys'][] = $this->buildKeyRow(
                    strtoupper($m[1]), $this->unquoteValue(trim($m[2])), $lineIndex, false
                );
                $pendingDividerLine = null;
                continue;
            }

            // 4. Всё остальное — не конфигурационная строка, игнорируем
            $pendingDividerLine = null;
        }

        // Удаляем пустые секции
        $sections = array_filter($sections, function ($s) {
            return !empty($s['keys']) || !empty($s['comments']) || !empty($s['commented_keys'] ?? []);
        });

        return [
            'sections' => $sections,
            'meta' => [
                'file' => $file,
                'lines' => $lineIndex,
                'size' => filesize($file),
                'writable' => is_writable($file),
                'permissions' => substr(sprintf('%o', fileperms($file)), -4),
            ],
        ];
    }

    /**
     * @param array<string,string> $changes   активные ключи => новые значения
     * @param string[]             $uncomment закомментированные ключи для раскомментирования
     */
    public function saveChanges(array $changes, array $uncomment = []): array
    {
        $file = $this->basePath . DIRECTORY_SEPARATOR . 'config.env';

        if (!is_file($file)) {
            return ['ok' => false, 'errors' => ['Файл config.env не найден'], 'changed' => 0];
        }
        if (!is_writable($file)) {
            return ['ok' => false, 'errors' => ['Нет прав на запись'], 'changed' => 0];
        }

        $errors = [];
        foreach ($changes as $key => $value) {
            if (in_array($key, self::READONLY_KEYS, true)) {
                $errors[] = "Ключ {$key} доступен только для чтения";
                unset($changes[$key]);
            }
            if (preg_match('/[\r\n]/', (string) $value)) {
                $errors[] = "Значение {$key} содержит недопустимые символы";
                unset($changes[$key]);
            }
            if (strlen((string) $value) > 10000) {
                $errors[] = "Значение {$key} слишком длинное";
                unset($changes[$key]);
            }
        }

        $uncomment = array_values(array_filter(array_map(
            static fn($k) => is_string($k) ? strtoupper(trim($k)) : '',
            $uncomment
        ), static fn($k) => $k !== '' && preg_match('/^[A-Z0-9_]+$/', $k)));

        if (empty($changes) && empty($uncomment)) {
            return ['ok' => false, 'errors' => $errors ?: ['Нет изменений для сохранения'], 'changed' => 0];
        }

        $keysSaved = [];
        $this->backupConfig($file);

        $content = file_get_contents($file);
        $changed = 0;

        // Раскомментирование: "# KEY=..." -> "KEY=..." (только если ключ ещё не активен)
        foreach ($uncomment as $key) {
            if (array_key_exists($key, $changes)) {
                continue; // значение и так будет обновлено в активной строке
            }
            $pattern = '/^([ \t]*)#+[ \t]*(' . preg_quote($key, '/') . '\s*=)/m';
            $count = 0;
            $content = preg_replace_callback($pattern, static function ($mm) {
                return $mm[1] . $mm[2];
            }, $content, 1, $count);
            if ($count > 0) {
                $changed++;
                $keysSaved[] = $key;
            }
        }

        foreach ($changes as $key => $value) {
            $pattern = '/^' . preg_quote($key, '/') . '=(.*)$/m';
            if (preg_match($pattern, $content)) {
                $content = preg_replace_callback($pattern, function () use ($key, $value) {
                    return $key . '=' . $value;
                }, $content);
                $changed++;
                $keysSaved[] = $key;
            }
        }

        if (file_put_contents($file, $content) === false) {
            return ['ok' => false, 'errors' => ['Не удалось записать файл'], 'changed' => 0];
        }

        @chmod($file, 0640);

        try {
            $this->security->getAuditLogger()->logAction('config.edit', 'config', null, [
                'changed_keys' => $keysSaved ?: array_keys($changes),
            ]);
        } catch (\Throwable $e) {
            error_log('Config audit failed: ' . $e->getMessage());
        }

        return ['ok' => true, 'errors' => $errors, 'changed' => $changed];
    }

    /**
     * Собирает строку опции для вывода в админке.
     */
    private function buildKeyRow(string $key, string $value, int $line, bool $commented): array
    {
        return [
            'key' => $key,
            'value' => $value,
            'type' => $this->detectType($key, $value),
            'sensitive' => in_array($key, self::SENSITIVE_KEYS, true),
            'readonly' => in_array($key, self::READONLY_KEYS, true),
            'commented' => $commented,
            'line' => $line,
            'changed' => false,
        ];
    }

    /**
     * Снимает обрамляющие кавычки ("..."/'...') со значения.
     */
    private function unquoteValue(string $value): string
    {
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $inner = substr($value, 1, -1);
                if ($first === '"') {
                    $inner = str_replace(['\\n', '\\t', '\\"', '\\\\'], ["\n", "\t", '"', '\\'], $inner);
                } else {
                    $inner = str_replace("\\'", "'", $inner);
                }
                return $inner;
            }
        }
        return $value;
    }

    /**
     * Определяет заголовок/разделитель секции. Поддерживаются варианты:
     *  - "# =====" (чистый разделитель без текста);
     *  - "# ═══ ТЕКСТ ═══" / "# ===== ТЕКСТ =====" (декоративные символы вокруг);
     *  - одиночная строка заглавного текста между двумя чистыми разделителями
     *    (например "# СУПЕР-АДМИНИСТРАТОР" между строками "# =====") —
     *    распознаётся в parse() по контексту и помечается флагом $bareText.
     */
    private function isSectionHeader(string $trimmed, bool $bareText = false): bool
    {
        if ($bareText) {
            // текстовая строка между разделителями: не должна быть опцией KEY=...
            return !preg_match('/^(?:export\s+)?[A-Za-z_][A-Za-z0-9_]*\s*=/', $trimmed);
        }
        if (!preg_match('/^#+[^A-Za-z0-9]*$/', $trimmed)) {
            // не чистый разделитель — проверяем вариант "# ═══ ТЕКСТ ═══"
            $body = preg_replace('/^#+\s*/', '', $trimmed) ?? '';
            if (!preg_match('/^[═=\-]+\s*\S.*$/', $body)) {
                return false;
            }
            // заголовок не должен быть закомментированной опцией KEY=...
            if (preg_match('/^\s*(?:export\s+)?[A-Za-z_][A-Za-z0-9_]*\s*=/', $body)) {
                return false;
            }
            $inner = preg_replace('/^\s*[═=\-]+\s*|\s*[═=\-]+\s*$/', '', $body) ?? '';
            return trim($inner) !== '' && !str_contains($inner, '=');
        }
        return true; // "# =====" без текста — тоже разделитель (title пустой)
    }

    /**
     * Извлекает текст заголовка из строки-разделителя любого вида.
     */
    private function extractTitle(string $trimmed): string
    {
        $title = preg_replace('/^#+\s*/', '', $trimmed) ?? '';
        $title = preg_replace('/[═=\-]+/u', ' ', $title) ?? '';
        return trim(preg_replace('/\s+/u', ' ', $title) ?? '');
    }

    private function detectType(string $key, string $value): string
    {
        if (in_array($key, self::SENSITIVE_KEYS, true)) return 'sensitive';
        if ($value === '') return 'empty';
        if (in_array(strtolower($value), ['true', 'false', '0', '1'], true)) return 'bool';
        if (ctype_digit($value) || (str_starts_with($value, '-') && ctype_digit(substr($value, 1)))) return 'int';
        if (filter_var($value, FILTER_VALIDATE_URL)) return 'url';
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) return 'email';
        if (preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}(\/\d+)?$/', $value)) return 'ip';
        if (preg_match('/^[0-9a-f]{32,}$/i', $value)) return 'hash';
        if (str_starts_with($value, '$2y$') || str_starts_with($value, '$2a$')) return 'password_hash';
        return 'text';
    }

    private function slugifySection(string $label): string
    {
        $map = [
            'база данных' => 'database', 'безопасность' => 'security',
            'сессии' => 'session', 'логирование' => 'logging',
            'файловая система' => 'filesystem', 'установка' => 'install',
            'супер-админ' => 'superadmin', 'приложение' => 'app',
            'шаблоны' => 'template', 'админ-панель' => 'admin',
            'общие' => 'general', 'email' => 'email', 'smtp' => 'email',
            'мастер установки' => 'install', 'локализация' => 'general',
        ];
        $lower = $this->lowercaseUtf8($label);
        foreach ($map as $k => $v) {
            if (str_contains($lower, $k)) return $v;
        }
        // Неизвестный заголовок: латиница/цифры в слаг, иначе общий раздел.
        $ascii = preg_replace('/[^a-z0-9]/', '_', strtolower($lower)) ?? '';
        $ascii = trim(preg_replace('/_+/', '_', $ascii) ?? '', '_');
        return $ascii !== '' ? $ascii : 'general';
    }

    /**
     * Безопасное приведение к нижнему регистру для UTF-8 без зависимости от
     * расширения mbstring (на серверах без mb_string mb_strtolower() — fatal).
     */
    private function lowercaseUtf8(string $text): string
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($text, 'UTF-8');
        }
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-8//TRANSLIT', $text);
            if ($converted !== false) {
                $text = $converted;
            }
        }
        return strtolower($text);
    }

    /**
     * Открывает новую секцию по заголовку: регистрирует её (если ещё нет),
     * делает текущей и добавляет запись-разделитель для отображения.
     *
     * @param array<string,array> $sections       ссылка на реестр секций
     * @param string              $currentSection ссылка на имя текущей секции
     * @param array<string,array{label:string,icon:string}> $sectionLabels
     */
    private function openSection(
        array &$sections,
        string &$currentSection,
        string $title,
        int $lineIndex,
        array $sectionLabels,
        ?int $dividerLine = null
    ): void {
        $sectionName = $this->slugifySection($title);
        // Повторный заголовок той же секции — не дублируем, только меняем текущую.
        $currentSection = $sectionName;
        if (!isset($sections[$sectionName])) {
            $sections[$sectionName] = [
                'label' => $sectionLabels[$sectionName]['label'] ?? $title,
                'icon' => $sectionLabels[$sectionName]['icon'] ?? '📦',
                'keys' => [],
                'commented_keys' => [],
                'comments' => [],
            ];
        }
        $sections[$sectionName]['comments'][] = [
            'type' => 'section-divider',
            'label' => $title,
            'line' => $dividerLine ?? $lineIndex,
        ];
    }

    private function backupConfig(string $file): void
    {
        try {
            $dir = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups';
            if (!is_dir($dir)) @mkdir($dir, 0750, true);
            $backup = $dir . DIRECTORY_SEPARATOR . 'config.env.' . date('Y-m-d_H-i-s') . '.bak';
            @copy($file, $backup);
            @chmod($backup, 0640);
        } catch (\Throwable $e) {
            error_log('Config backup failed: ' . $e->getMessage());
        }
    }
}