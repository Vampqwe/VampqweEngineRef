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

        $sections[$currentSection] = ['label' => 'Общие', 'icon' => '⚙️', 'keys' => [], 'comments' => []];
        $lineIndex = 0;

        foreach ($lines as $line) {
            $lineIndex++;
            $trimmed = trim($line);

            // 1. Пустая строка
            if ($trimmed === '') {
                continue;
            }

            // 2. Комментарий с #
            if (str_starts_with($trimmed, '#')) {
                $text = substr($trimmed, 1);

                // Проверяем, является ли это разделителем секции: # ═══ НАЗВАНИЕ ═══
                if (preg_match('/^\s*[═\-=]{2,}\s*(.+?)\s*[═\-=]{2,}\s*$/u', $text, $m)) {
                    $title = trim($m[1]);
                    $sectionName = $this->slugifySection($title);
                    $currentSection = $sectionName;
                    if (!isset($sections[$currentSection])) {
                        $sections[$currentSection] = [
                            'label' => $sectionLabels[$sectionName]['label'] ?? $title,
                            'icon' => $sectionLabels[$sectionName]['icon'] ?? '📦',
                            'keys' => [],
                            'comments' => [],
                        ];
                    }
                    $sections[$currentSection]['comments'][] = [
                        'type' => 'section-divider',
                        'label' => $title,
                        'line' => $lineIndex,
                    ];
                } else {
                    // Обычный комментарий
                    $sections[$currentSection]['comments'][] = [
                        'type' => 'comment',
                        'text' => ltrim($text),
                        'line' => $lineIndex,
                    ];
                }
                continue;
            }

            // 3. Ключ=значение
            if (preg_match('/^([A-Z0-9_]+)=(.*)$/', $trimmed, $m)) {
                $key = $m[1];
                $value = $m[2];
                $type = $this->detectType($key, $value);
                $isSensitive = in_array($key, self::SENSITIVE_KEYS, true);
                $isReadonly = in_array($key, self::READONLY_KEYS, true);

                $sections[$currentSection]['keys'][] = [
                    'key' => $key,
                    'value' => $value,
                    'type' => $type,
                    'sensitive' => $isSensitive,
                    'readonly' => $isReadonly,
                    'line' => $lineIndex,
                    'changed' => false,
                ];
                continue;
            }

            // 4. Всё остальное — игнорируем (или можно считать комментарием без #)
        }

        // Удаляем пустые секции
        $sections = array_filter($sections, function ($s) {
            return !empty($s['keys']) || !empty($s['comments']);
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

    public function saveChanges(array $changes): array
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

        if (empty($changes)) {
            return ['ok' => false, 'errors' => $errors ?: ['Нет изменений для сохранения'], 'changed' => 0];
        }

        $this->backupConfig($file);

        $content = file_get_contents($file);
        $changed = 0;

        foreach ($changes as $key => $value) {
            $pattern = '/^' . preg_quote($key, '/') . '=(.*)$/m';
            if (preg_match($pattern, $content)) {
                $content = preg_replace_callback($pattern, function () use ($key, $value) {
                    return $key . '=' . $value;
                }, $content);
                $changed++;
            }
        }

        if (file_put_contents($file, $content) === false) {
            return ['ok' => false, 'errors' => ['Не удалось записать файл'], 'changed' => 0];
        }

        @chmod($file, 0640);

        try {
            $this->security->getAuditLogger()->logAction('config.edit', 'config', null, [
                'changed_keys' => array_keys($changes),
            ]);
        } catch (\Throwable $e) {
            error_log('Config audit failed: ' . $e->getMessage());
        }

        return ['ok' => true, 'errors' => $errors, 'changed' => $changed];
    }

    private function detectType(string $key, string $value): string
    {
        if (in_array($key, self::SENSITIVE_KEYS, true)) return 'sensitive';
        if (in_array($value, ['true', 'false', '0', '1'], true)) return 'bool';
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
        $lower = mb_strtolower($label, 'UTF-8');
        foreach ($map as $k => $v) {
            if (str_contains($lower, $k)) return $v;
        }
        return preg_replace('/[^a-z0-9]/u', '_', $lower) ?: 'general';
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