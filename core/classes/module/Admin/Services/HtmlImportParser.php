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

use Core\System\Config\Config;
use Core\System\Parser\HtmlParser;
use Core\System\Security\PathResolver;
use RuntimeException;

/**
 * Системный парсер HTML для импорта страниц (модуль «Админ-панель»).
 *
 * Наследует чистый разбор из Core\System\Parser\HtmlParser и добавляет
 * инфраструктуру приложения:
 *  - загрузку правил из config/import-parser.php (секция 'blocks' и др.);
 *  - подстановку значений окружения ('{APP_NAME}', '{APP_BASE_URL}') и
 *    псевдонимов маркеров ('marker_aliases') прямо в описания блоков;
 *  - валидацию загруженного файла (validateUpload());
 *  - скачивание внешних картинок из контента (processContentImages()).
 *
 * ⚠️ МЕХАНИКА КОПИРОВАНИЯ СТИЛЕЙ ИЗ СТРАНИЦ ПРИ ИМПОРТЕ ПОЛНОСТЬЮ УДАЛЕНА:
 * импорт не собирает <style>, не пишет neutral-style.css и никакие иные
 * файлы стилей. Контент страницы сохраняется со своими стилями как есть.
 *
 * Сам parse() остаётся свободным от побочных эффектов: картинки пишутся
 * только явными вызовами на этапе подтверждения импорта.
 */
class HtmlImportParser extends HtmlParser
{
    private Config $config;

    /** Каталог загрузки картинок импорта (абсолютный путь) */
    private string $uploadDir;

    public function __construct(Config $config)
    {
        $this->config = $config;

        // Правила из config/import-parser.php с подстановкой значений окружения
        $rules = $this->loadRules();

        parent::__construct($rules);

        $storage = $config->getStoragePath();
        $this->uploadDir = $storage . DIRECTORY_SEPARATOR
            . trim((string) ($this->rules['upload_dir'] ?? 'uploads/import'), '/\\');
    }

    /**
     * Загрузка правил парсера из config/import-parser.php + резолв
     * плейсхолдеров '{KEY}' по значениям config.env.
     *
     * @return array<string,mixed>
     */
    private function loadRules(): array
    {
        $file = PathResolver::resolveConfigFile('import-parser.php');
        if (!is_file($file)) {
            throw new RuntimeException('Отсутствует файл конфигурации парсера: config/import-parser.php');
        }

        $rules = require $file;
        if (!is_array($rules)) {
            throw new RuntimeException('config/import-parser.php должен возвращать массив');
        }

        return $this->resolvePlaceholders($rules);
    }

    /**
     * Рекурсивно заменяет плейсхолдеры вида '{APP_NAME}', '{APP_BASE_URL}'
     * в строковых значениях конфига на соответствующие значения config.env.
     * Неизвестный ключ оставлен как есть (валидатор его не сломает).
     *
     * @param array<mixed> $rules
     * @return array<mixed>
     */
    private function resolvePlaceholders(array $rules): array
    {
        foreach ($rules as $key => $value) {
            if (is_array($value)) {
                $rules[$key] = $this->resolvePlaceholders($value);
            } elseif (is_string($value) && str_contains($value, '{')) {
                $rules[$key] = preg_replace_callback(
                    '/\{([A-Z][A-Z0-9_]*)\}/',
                    fn(array $m): string => (string) $this->config->get($m[1], $m[0]),
                    $value
                ) ?? $value;
            }
        }

        return $rules;
    }

    /**
     * Правила импорта (секции config/import-parser.php), активные в парсере.
     *
     * @return array<string,mixed>
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    /**
     * Полная замена правил парсера с пересборкой профиля блоков.
     * Используется потребителями (и тестами) для переопределения секции
     * 'blocks'/'marker_aliases' без чтения config/import-parser.php.
     *
     * @param array<string,mixed> $rules
     */
    public function setRules(array $rules): void
    {
        $this->rules = $rules;
        $this->rebuildActiveBlocks();
    }

    /**
     * Валидация загруженного файла перед импортом.
     *
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     * @return string[] Список ошибок ([] — файл пригоден)
     */
    public function validateUpload(array $file): array
    {
        $errors = [];

        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = match ((int) $file['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Файл слишком большой (лимит upload_max_filesize)',
                UPLOAD_ERR_PARTIAL => 'Файл загружен частично',
                UPLOAD_ERR_NO_FILE => 'Файл не выбран',
                default => 'Ошибка загрузки файла (код ' . (int) $file['error'] . ')',
            };

            return $errors;
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            $errors[] = 'Некорректный временный файл загрузки';

            return $errors;
        }

        $maxSize = (int) ($this->rules['max_file_size'] ?? 0);
        if ($maxSize > 0 && (int) ($file['size'] ?? 0) > $maxSize) {
            $errors[] = 'Размер файла превышает лимит ' . round($maxSize / 1048576, 1) . ' МБ';
        }

        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $allowed = array_map('strval', (array) ($this->rules['allowed_extensions'] ?? ['html', 'htm']));
        if (!in_array($ext, $allowed, true)) {
            $errors[] = 'Допустимы только файлы: ' . implode(', ', $allowed);
        }

        return $errors;
    }

    /**
     * Скачивает внешние http(s)-картинки из HTML и заменяет src на локальные
     * пути /storage/uploads/import/... Побочный шаг подтверждения импорта
     * (и воркера очереди) — сам parse() файлов не трогает.
     *
     * @return array{html:string,images:array<int,array{original:string,local:string}>}
     */
    public function processContentImages(string $html): array
    {
        $downloaded = [];
        $cache = [];

        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $matches) === 0) {
            return ['html' => $html, 'images' => $downloaded];
        }

        foreach (array_unique($matches[1]) as $src) {
            if (str_starts_with($src, 'data:') || str_starts_with($src, '/storage/uploads/import/')) {
                continue;
            }
            if (!str_starts_with($src, 'http')) {
                continue;
            }

            // Кэш: одна и та же картинка скачивается один раз
            if (!array_key_exists($src, $cache)) {
                $cache[$src] = $this->downloadImage($src);
            }
            $localPath = $cache[$src];

            if ($localPath !== null) {
                $downloaded[] = ['original' => $src, 'local' => $localPath];
                $html = str_replace($src, $localPath, $html);
            }
        }

        return ['html' => $html, 'images' => $downloaded];
    }

    /**
     * Защита от SSRF: разрешаем только http/https на публичные хосты.
     */
    private function isSafeImageUrl(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return false;
        }
        if (!in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return false;
        }

        $ip = gethostbyname($parts['host']);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        // Блокируем приватные/петлбэк-диапазоны
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function downloadImage(string $url): ?string
    {
        try {
            if (!$this->isSafeImageUrl($url)) {
                $this->addWarning('Пропущен небезопасный URL картинки: ' . $url);
                return null;
            }

            $context = stream_context_create([
                'http' => [
                    'timeout' => (int) ($this->rules['image_timeout_s'] ?? 10),
                    'max_redirects' => (int) ($this->rules['image_max_redirects'] ?? 3),
                    'user_agent' => 'VampqweEngine Importer/1.0',
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ]);

            $imageData = @file_get_contents($url, false, $context);
            if ($imageData === false || strlen($imageData) < 100) {
                return null;
            }

            if (strlen($imageData) > (int) ($this->rules['max_image_size'] ?? 10 * 1024 * 1024)) {
                $this->addWarning('Картинка слишком большая, пропущена: ' . $url);
                return null;
            }

            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = (string) $finfo->buffer($imageData);
            $extMap = [
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                'image/svg+xml' => 'svg',
                'image/jpeg' => 'jpg',
            ];
            $extension = $extMap[$mimeType] ?? 'jpg';

            $filename = md5($url . microtime(true)) . '.' . $extension;

            if (!is_dir($this->uploadDir) && !@mkdir($this->uploadDir, 0775, true) && !is_dir($this->uploadDir)) {
                $this->addWarning('Не удалось создать каталог загрузок: ' . $this->uploadDir);
                return null;
            }

            $destPath = $this->uploadDir . DIRECTORY_SEPARATOR . $filename;
            if (file_put_contents($destPath, $imageData) === false) {
                return null;
            }

            return '/storage/uploads/import/' . $filename;
        } catch (\Throwable $e) {
            $this->addWarning('Ошибка загрузки картинки: ' . $e->getMessage());
            return null;
        }
    }
}
