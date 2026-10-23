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

namespace Core\System\Http;

use Core\System\Config\Config;

/**
 * Full-page cache: хранилище полностью отрендеренных HTML-страниц.
 *
 * Ключ кеша — вариант-хэш (method + host + path + query + Accept-Language),
 * значение — сериализованный снапшот ответа (статус, заголовки, тело, TTL).
 * Запись выполняется атомарно (temp + rename), чтение — по одному stat+file
 * get contents на попадание. Инвалидация: точечная purge($path) (удаляет все
 * варианты данного пути) и полная clear().
 */
class PageCache
{
    private const INDEX_PATTERN = '/^[0-9a-f]{64}\.html\.meta$/';

    public function __construct(
        private readonly Config $config
    ) {
    }

    /**
     * Включён ли full-page cache (конфиг PAGE_CACHE_ENABLED, по умолчанию выкл).
     * В dev-режиме (APP_DEBUG=true) кеширование принудительно выключено:
     * правки в БД/шаблонах должны быть видны сразу.
     */
    public function isEnabled(): bool
    {
        if ($this->config->getBool('APP_DEBUG', false)) {
            return false;
        }

        return $this->config->getBool('PAGE_CACHE_ENABLED', false);
    }

    /**
     * TTL свежего кеша, сек.
     */
    public function getTtl(): int
    {
        return max(1, $this->config->getInt('PAGE_CACHE_TTL', 3600));
    }

    /**
     * Максимальный размер тела страницы, подлежащий кешированию, байт.
     */
    public function getMaxSize(): int
    {
        return max(1024, $this->config->getInt('PAGE_CACHE_MAX_SIZE', 1048576));
    }

    /**
     * Каталог хранилища кеша страниц.
     */
    public function getDir(): string
    {
        $override = trim((string) $this->config->get('PAGE_CACHE_DIR', ''));

        // Абсолютный путь используется как есть (важно для тестов с изолированным
        // кешем); относительный — разрешается от корня проекта, как и прочие
        // путевые настройки config.env.
        if ($override !== '') {
            return $this->isAbsolutePath($override)
                ? rtrim($override, '/\\')
                : $this->config->resolvePath($override);
        }

        return $this->config->getStoragePath('cache/pages');
    }

    private function isAbsolutePath(string $path): bool
    {
        return $path !== ''
            && ($path[0] === '/' || $path[0] === '\\' || preg_match('#^[A-Za-z]:[/\\\\]#', $path) === 1);
    }

    /**
     * Сформировать ключ кеша для запроса. Возвращает null, если запрос
     * кешировать нельзя (не GET/HEAD, есть сессионная кука — персонализированный
     * визититель).
     */
    public function buildKey(Request $request): ?string
    {
        if (!$request->isGet() && !$request->isHead()) {
            return null;
        }

        // Персональные визитители (авторизованные в админке, пользователи с
        // активной сессией) не должны получать чужой кеш и не должны писать его.
        $sessionCookie = $this->config->get('SESSION_COOKIE_NAME', 'PHPSESSID');
        if ((string) $request->cookie((string) $sessionCookie, '') !== '') {
            return null;
        }

        return $this->keyForMaterial($request);
    }

    /**
     * Ключ кеша без учёта сессионной куки — используется middleware для
     * инвалидации (purge) пути после изменений из админки, где у запроса
     * гарантированно есть сессия.
     */
    public function buildPathKeys(Request $request): string
    {
        return $this->keyForMaterial($request);
    }

    private function keyForMaterial(Request $request): string
    {
        $acceptLanguage = $request->getHeader(Header::ACCEPT_LANGUAGE, '') ?? '';
        $userAgent = $request->getHeader(Header::USER_AGENT, '') ?? '';

        // Боты и превью-сервисы: отдельный вариант кеша, чтобы их вывод
        // не «отравлял» обычный кеш живых посетителей (и наоборот).
        $bucket = $this->isBotUserAgent($userAgent) ? 'bot' : 'std';

        $material = implode("\x00", [
            $bucket,
            $request->getMethod()->value,
            strtolower($request->getHost()),
            $request->getPath(),
            $request->getQueryString(),
            strtolower(explode(',', $acceptLanguage)[0]),
        ]);

        return hash('sha256', $material);
    }

    /**
     * Прочитать снапшот ответа по ключу. Null — промах или просрочено.
     *
     * @return array{status:int,headers:array,content:string,expires_at:int}|null
     */
    public function read(string $key): ?array
    {
        $file = $this->entryFile($key);

        if (!is_readable($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }

        $data = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($data) || !isset($data['expires_at'], $data['content'])) {
            return null;
        }

        if ($data['expires_at'] < time()) {
            @unlink($file);
            return null;
        }

        return $data;
    }

    /**
     * Сохранить ответ в кеш (атомарно: temp + chmod + rename).
     * $path — путь запроса, сохраняется в метаданных для точечной инвалидации.
     */
    public function write(string $key, Response $response, string $path = ''): bool
    {
        $body = $response->getContent();

        if (strlen($body) > $this->getMaxSize()) {
            return false;
        }

        $snapshot = [
            'status'     => $response->getStatusCode()->value,
            'headers'    => $response->allHeaders(),
            'content'    => $body,
            'path'       => '/' . ltrim($path, '/'),
            'created_at' => time(),
            'expires_at' => time() + $this->resolveFreshTtl($response),
        ];

        $dir = $this->getDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        $file = $this->entryFile($key);
        $tmp  = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';

        $written = @file_put_contents($tmp, serialize($snapshot));
        if ($written === false) {
            return false;
        }

        @chmod($tmp, 0664);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }

        return true;
    }

    /**
     * Есть ли хоть один (пусть и просроченный) свежий или stale-записи для пути.
     * Используется для стратегии stale-if-error.
     */
    public function hasStaleForPath(string $path): bool
    {
        foreach ($this->indexPaths() as [$hash]) {
            $data = @$this->readRawMeta($hash);
            if ($data !== null && $data['path'] === '/' . ltrim($path, '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Точечная инвалидация: удалить все варианты кеша указанного пути.
     *
     * @return int Количество удалённых записей
     */
    public function purge(string $path): int
    {
        $path = '/' . ltrim($path, '/');
        $removed = 0;

        foreach ($this->indexPaths() as [$hash, $file]) {
            $data = @$this->readRawMeta($hash);
            if ($data !== null && $data['path'] === $path) {
                if (@unlink($file)) {
                    $removed++;
                }
            }
        }

        return $removed;
    }

    /**
     * Полная очистка кеша страниц.
     *
     * @return int Количество удалённых записей
     */
    public function clear(): int
    {
        $removed = 0;

        foreach ($this->indexPaths() as [, $file]) {
            if (@unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * TTL из ответа: если страница явно отдаёт Cache-Control с max-age —
     * уважаем его (берём минимум с конфигом), иначе — конфиг-TTL.
     */
    private function resolveFreshTtl(Response $response): int
    {
        $ttl = $this->getTtl();

        $cc = $response->getHeader('Cache-Control');
        if (is_string($cc) && preg_match('/max-age=(\d+)/', $cc, $m)) {
            $ttl = min($ttl, max(1, (int) $m[1]));
        }

        return $ttl;
    }

    private function entryFile(string $key): string
    {
        return $this->getDir() . DIRECTORY_SEPARATOR . $key . '.html.meta';
    }

    /**
     * @return array<int, array{0:string,1:string}> [hash, filepath]
     */
    private function indexPaths(): array
    {
        $result = [];
        $entries = @scandir($this->getDir());

        if ($entries === false) {
            return $result;
        }

        foreach ($entries as $entry) {
            if (preg_match(self::INDEX_PATTERN, $entry) === 1) {
                $result[] = [substr($entry, 0, 64), $this->getDir() . DIRECTORY_SEPARATOR . $entry];
            }
        }

        return $result;
    }

    /**
     * Метаданные записи без проверки срока годности (для purge/hasStale).
     *
     * @return array{path:string,status:int,expires_at:int}|null
     */
    private function readRawMeta(string $hash): ?array
    {
        $raw = @file_get_contents($this->entryFile($hash));
        if ($raw === false) {
            return null;
        }

        $data = @unserialize($raw, ['allowed_classes' => false]);

        return is_array($data) && isset($data['path']) ? $data : null;
    }

    private function isBotUserAgent(string $ua): bool
    {
        if ($ua === '') {
            return false;
        }

        return preg_match(
            '/bot|crawler|spider|slurp|curl|wget|python-requests|facebookexternalhit|telegrambot|whatsapp|preview/i',
            $ua
        ) === 1;
    }
}
