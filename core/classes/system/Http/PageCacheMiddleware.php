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

use Throwable;

/**
 * Full-page cache middleware.
 *
 * Работает в двух режимах:
 *
 * 1. Публичный GET/HEAD-запрос без сессии (анонимный посетитель):
 *    - попадание в кеш → ответ отдаётся мгновенно, роутер и БД не трогаются;
 *    - промах → запрос проходит дальше, успешный HTML-ответ (200) сохраняется
 *      в кеш; при фатальной ошибке бэкенда отдаётся stale-копия, если она есть
 *      (stale-if-error).
 *
 * 2. Немедиабельный запрос (POST/PUT/PATCH/DELETE, авторизованный визит в
 *    админку, не-HTML ответ): после выполнения инвалидируются все варианты
 *    кеша затронутого пути + корень сайта (шапка/меню/списки зависят от
 *    контента страниц).
 *
 * Кешируются только ответы 200 c Content-Type text/html. Редиректы, 404/500,
 * JSON/API — проходят насквозь и не пишутся в кеш.
 */
class PageCacheMiddleware implements Middleware
{
    private const PURGE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        private readonly PageCache $cache
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        // Админ-панель никогда не отдаётся из кеша и не кешируется:
        // там персонализированный контент, CSRF-токены и формы.
        if ($this->isAdminPath($request->getPath())) {
            $response = $next($request);
            $this->invalidateAfterMutation($request, $response);

            return $response;
        }

        $key = $this->cache->buildKey($request);

        // Персональный визититель (есть сессионная кука) — мимо кеша,
        // но его мутации должны сбрасывать кеш публичных страниц.
        if ($key === null) {
            $response = $next($request);
            $this->invalidateAfterMutation($request, $response);

            return $response;
        }

        if (!$this->cache->isEnabled()) {
            return $next($request);
        }

        try {
            $hit = $this->cache->read($key);
        } catch (Throwable) {
            $hit = null;
        }

        if ($hit !== null) {
            return $this->replay($hit, $request);
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            // stale-if-error: БД легла / упал шаблон — отдаём последнюю
            // сохранённую копию страницы вместо «белого экрана» 500.
            $stale = $this->serveStaleOnError($key, $request);
            if ($stale !== null) {
                return $stale;
            }

            throw $e;
        }

        if ($this->isCacheable($response)) {
            $this->cache->write($key, $response, $request->getPath());
            $response = $response->setHeader('X-Page-Cache', 'MISS');
        }

        return $response;
    }

    /**
     * Можно ли сохранить ответ в кеш: только свежий HTML со статусом 200,
     * без Set-Cookie и без приватного Cache-Control.
     */
    private function isCacheable(Response $response): bool
    {
        if ($response->getStatusCode() !== StatusCode::OK) {
            return false;
        }

        if ($response->getCookies() !== []) {
            return false;
        }

        $contentType = (string) $response->getHeader('Content-Type', '');
        if (!str_contains(strtolower($contentType), 'text/html')) {
            return false;
        }

        $cacheControl = strtolower((string) $response->getHeader('Cache-Control', ''));
        foreach (['no-store', 'private', 'no-cache'] as $blocking) {
            if (str_contains($cacheControl, $blocking)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Собрать Response из снапшота кеша.
     */
    private function replay(array $hit, Request $request): Response
    {
        $status = StatusCode::tryFrom((int) $hit['status']) ?? StatusCode::OK;

        $response = new Response((string) $hit['content'], $status);

        foreach ((array) ($hit['headers'] ?? []) as $name => $value) {
            // Динамические/пересекающиеся заголовки не воспроизводим:
            // статус-лайн и Content-Length ставит send(), Date — сервер.
            $lower = strtolower((string) $name);
            if (in_array($lower, ['status', 'content-length', 'date', 'x-page-cache'], true)) {
                continue;
            }
            $response->setHeader($lower, $value);
        }

        // Etag/Last-Modified из снапшота уже проставлены выше; добавляем
        // маркер попадания и сокращаем max-age: TTL кеша сервера мог истечь
        // частично с момента записи.
        $remaining = max(0, (int) ($hit['expires_at'] ?? time()) - time());
        $cc = (string) $response->getHeader('Cache-Control', '');
        if (str_contains($cc, 'max-age=')) {
            $cc = preg_replace('/max-age=\d+/', 'max-age=' . min($remaining, $this->cache->getTtl()), $cc);
            $response->setHeader('Cache-Control', $cc);
        }

        $response->setHeader('X-Page-Cache', 'HIT');

        return $response;
    }

    /**
     * stale-if-error: читается запись кеша независимо от срока годности.
     */
    private function serveStaleOnError(string $key, Request $request): ?Response
    {
        $file = $this->staleFile($key);

        if ($file === null) {
            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        $data = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($data) || !isset($data['content'])) {
            return null;
        }

        $response = $this->replay($data, $request);
        $response->setHeader('X-Page-Cache', 'STALE');

        return $response;
    }

    private function staleFile(string $key): ?string
    {
        $dir = $this->cache->getDir();
        $entries = @scandir($dir);

        if ($entries === false) {
            return null;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            // Запись лежит под тем же ключом, просто просрочена: read() её
            // отвергает, а для stale-if-error годится любая копия этого ключа.
            if (str_starts_with($entry, $key)) {
                return $dir . DIRECTORY_SEPARATOR . $entry;
            }
        }

        return null;
    }

    /**
     * После изменяющих запросов (мутации) и любых запросов из админки —
     * сбрасываем кеш затронутого пути и корня сайта.
     */
    private function invalidateAfterMutation(Request $request, Response $response): void
    {
        $isMutation = in_array($request->getMethod()->value, self::PURGE_METHODS, true);
        $saved = $response->getStatusCode()->value >= 200 && $response->getStatusCode()->value < 400;

        if (!$isMutation || !$saved) {
            return;
        }

        $path = $request->getPath();

        // Изменения в админке влияют и на списки/меню на публичных страницах:
        // дополнительно чистим домашнюю.
        $this->cache->purge($path);
        if ($path !== '/') {
            $this->cache->purge('/');
        }
    }

    private function isAdminPath(string $path): bool
    {
        return str_starts_with($path, '/admin');
    }
}
