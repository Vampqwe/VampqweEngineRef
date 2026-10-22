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

/**
 * Обёртка над HTTP-ответом.
 * Fluent API — поддерживает chain-вызовы.
 */
class Response
{
    private StatusCode $statusCode;
    private string $reasonPhrase;

    /** @var array<string, string|array<string>> */
    private array $headers = [];

    private string $content = '';

    private ?string $filePath = null;

    /** @var Cookie[] */
    private array $cookies = [];

    private bool $sent = false;

    public function __construct(
        string $content = '',
        StatusCode $statusCode = StatusCode::OK,
        array $headers = []
    ) {
        $this->content = $content;
        $this->statusCode = $statusCode;
        $this->reasonPhrase = $statusCode->reasonPhrase();

        foreach ($headers as $name => $value) {
            $this->headers[strtolower($name)] = $value;
        }
    }

    // ─── Статус ───

    public function getStatusCode(): StatusCode
    {
        return $this->statusCode;
    }

    public function setStatusCode(StatusCode $statusCode, string $reasonPhrase = ''): self
    {
        $this->statusCode = $statusCode;
        $this->reasonPhrase = $reasonPhrase ?: $statusCode->reasonPhrase();
        return $this;
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    // ─── Content ───

    public function getContent(): string
    {
        if ($this->filePath !== null) {
            return file_get_contents($this->filePath) ?: '';
        }

        return $this->content;
    }

    public function setContent(string $content): self
    {
        $this->filePath = null;
        $this->content = $content;
        return $this;
    }

    // ─── Headers ───

    /**
     * Установить заголовок
     */
    public function setHeader(Header|string $name, string|array $value): self
    {
        // Защита от HTTP response splitting: значения заголовков не должны
        // содержать CR/LF. Массивные значения фильтруются рекурсивно.
        $this->assertHeaderValueSafe($value);

        $key = strtolower($name instanceof Header ? $name->value : $name);
        $this->headers[$key] = $value;
        return $this;
    }

    /**
     * Проверить значение заголовка на отсутствие переносов строки.
     *
     * @throws HttpException при обнаружении CR/LF (header injection)
     */
    private function assertHeaderValueSafe(string|array $value): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->assertHeaderValueSafe((string) $item);
            }
            return;
        }

        if (str_contains($value, "\r") || str_contains($value, "\n")) {
            throw new HttpException(
                StatusCode::INTERNAL_SERVER_ERROR,
                'Заголовок ответа не может содержать символы переноса строки (CR/LF).'
            );
        }
    }

    /**
     * Добавить заголовок (не перезаписывает существующий)
     */
    public function addHeader(Header|string $name, string $value): self
    {
        $key = strtolower($name instanceof Header ? $name->value : $name);

        if (!isset($this->headers[$key])) {
            $this->headers[$key] = $value;
        } elseif (is_array($this->headers[$key])) {
            $this->headers[$key][] = $value;
        } else {
            $this->headers[$key] = [$this->headers[$key], $value];
        }

        return $this;
    }

    /**
     * Получить заголовок
     */
    public function getHeader(Header|string $name): string|array|null
    {
        $key = strtolower($name instanceof Header ? $name->value : $name);
        return $this->headers[$key] ?? null;
    }

    /**
     * Проверить наличие заголовка
     */
    public function hasHeader(Header|string $name): bool
    {
        $key = strtolower($name instanceof Header ? $name->value : $name);
        return isset($this->headers[$key]);
    }

    /**
     * Удалить заголовок
     */
    public function removeHeader(Header|string $name): self
    {
        $key = strtolower($name instanceof Header ? $name->value : $name);
        unset($this->headers[$key]);
        return $this;
    }

    /**
     * Получить все заголовки
     *
     * @return array<string, string|array<string>>
     */
    public function allHeaders(): array
    {
        return $this->headers;
    }

    // ─── Content-Type ───

    public function setContentType(string $contentType, string $charset = 'UTF-8'): self
    {
        return $this->setHeader(Header::CONTENT_TYPE, "{$contentType}; charset={$charset}");
    }

    public function setJsonContentType(): self
    {
        return $this->setContentType('application/json');
    }

    public function setHtmlContentType(): self
    {
        return $this->setContentType('text/html');
    }

    public function setXmlContentType(): self
    {
        return $this->setContentType('application/xml');
    }

    public function setPlainTextContentType(): self
    {
        return $this->setContentType('text/plain');
    }

    // ─── JSON ───

    /**
     * Установить JSON-содержимое
     */
    public function setJson(mixed $data, int $options = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES): self
    {
        $this->setJsonContentType();
        $this->content = json_encode($data, $options | JSON_THROW_ON_ERROR);
        return $this;
    }

    /**
     * Создать JSON-ответ
     */
    public static function json(mixed $data, StatusCode $statusCode = StatusCode::OK, int $options = JSON_UNESCAPED_UNICODE): self
    {
        $response = new self('', $statusCode);
        return $response->setJson($data, $options);
    }

    // ─── HTML ───

    /**
     * Создать HTML-ответ
     */
    public static function html(string $content, StatusCode $statusCode = StatusCode::OK): self
    {
        $response = new self($content, $statusCode);
        return $response->setHtmlContentType();
    }

    // ─── Plain Text ───

    public static function text(string $content, StatusCode $statusCode = StatusCode::OK): self
    {
        $response = new self($content, $statusCode);
        return $response->setPlainTextContentType();
    }
    /**
     * XML-ответ (Content-Type: application/xml).
     */
    public static function xml(string $content, StatusCode $statusCode = StatusCode::OK): self
    {
        $response = new self($content, $statusCode);
        return $response->setXmlContentType();
    }
    // ─── Redirect ───

    /**
     * Создать редирект
     */
    public static function redirect(string $url, StatusCode $statusCode = StatusCode::FOUND): self
    {
        $response = new self('', $statusCode);
        $response->setHeader(Header::LOCATION, $url);
        return $response;
    }

    /**
     * Permanent redirect (301)
     */
    public static function permanentRedirect(string $url): self
    {
        return self::redirect($url, StatusCode::MOVED_PERMANENTLY);
    }

    /**
     * See Other redirect (303) — после POST
     */
    public static function seeOther(string $url): self
    {
        return self::redirect($url, StatusCode::SEE_OTHER);
    }

    // ─── No Content ───

    public static function noContent(): self
    {
        return new self('', StatusCode::NO_CONTENT);
    }

    // ─── Created ───

    public static function created(string $location = '', mixed $data = null): self
    {
        $response = new self('', StatusCode::CREATED);

        if ($location !== '') {
            $response->setHeader(Header::LOCATION, $location);
        }

        if ($data !== null) {
            $response->setJson($data);
        }

        return $response;
    }

    // ─── File Download ───

    /**
     * Отправить файл на скачивание
     */
    public static function download(string $filePath, ?string $fileName = null): self
    {
        if (!file_exists($filePath)) {
            throw HttpException::notFound("File not found: {$filePath}");
        }

        $fileName = $fileName ?? basename($filePath);
        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
        $fileSize = filesize($filePath);

        $response = new self('', StatusCode::OK);
        $response->setHeader(Header::CONTENT_TYPE, $mimeType);
        $response->setHeader(Header::CONTENT_LENGTH, (string) $fileSize);
        $response->setHeader(Header::CONTENT_DISPOSITION, 'attachment; filename="' . self::sanitizeDownloadFilename($fileName) . '"');
        $response->filePath = $filePath;

        return $response;
    }

    /**
     * Очистить имя файла для заголовка Content-Disposition.
     *
     * Удаляет управляющие символы (включая CR/LF — защита от HTTP header
     * injection), кавычки и обратные слеши, ограничивает длину.
     */
    private static function sanitizeDownloadFilename(string $fileName): string
    {
        // Только basename — исключаем пути/переходы на другие директории
        $fileName = basename($fileName);
        // Убираем управляющие символы (\r, \n, \0 и т.п.), кавычки и слеши
        $fileName = preg_replace('/[\x00-\x1F\x7F"\\\\\/]/u', '', $fileName) ?? '';
        // Ограничиваем разумную длину имени
        if (strlen($fileName) > 200) {
            $ext = pathinfo($fileName, PATHINFO_EXTENSION);
            $fileName = substr(pathinfo($fileName, PATHINFO_FILENAME), 0, 180) . ($ext !== '' ? '.' . substr($ext, 0, 16) : '');
        }

        return $fileName !== '' ? $fileName : 'download';
    }

    /**
     * Отправить файл inline (в браузере)
     */
    public static function file(string $filePath, ?string $contentType = null): self
    {
        if (!file_exists($filePath)) {
            throw HttpException::notFound("File not found: {$filePath}");
        }

        $mimeType = $contentType ?? mime_content_type($filePath) ?: 'application/octet-stream';
        $fileSize = filesize($filePath);

        $response = new self('', StatusCode::OK);
        $response->setHeader(Header::CONTENT_TYPE, $mimeType);
        $response->setHeader(Header::CONTENT_LENGTH, (string) $fileSize);
        $response->filePath = $filePath;

        return $response;
    }

    // ── Cookies ───

    /**
     * Добавить куку к ответу
     */
    public function withCookie(Cookie $cookie): self
    {
        $this->cookies[$cookie->getName()] = $cookie;
        return $this;
    }

    /**
     * Удалить куку
     */
    public function withoutCookie(string $name): self
    {
        $cookie = new Cookie($name, '', time() - 3600);
        $this->cookies[$name] = $cookie;
        return $this;
    }

    /**
     * Получить все куки
     *
     * @return Cookie[]
     */
    public function getCookies(): array
    {
        return $this->cookies;
    }

    // ─── Cache ──

    /**
     * Установить Cache-Control
     */
    public function setCacheControl(string $directive, int $maxAge = 0): self
    {
        $value = $directive;

        if ($maxAge > 0) {
            $value .= ", max-age={$maxAge}";
        }

        return $this->setHeader(Header::CACHE_CONTROL, $value);
    }

    /**
     * Запретить кеширование
     */
    public function noCache(): self
    {
        return $this->setCacheControl('no-store, no-cache, must-revalidate, max-age=0')
            ->setHeader(Header::PRAGMA, 'no-cache')
            ->setHeader(Header::EXPIRES, '0');
    }

    /**
     * Установить ETag
     */
    public function setEtag(string $etag, bool $weak = false): self
    {
        $value = $weak ? "W/\"{$etag}\"" : "\"{$etag}\"";
        return $this->setHeader(Header::ETAG, $value);
    }

    /**
     * Установить Last-Modified
     */
    public function setLastModified(int $timestamp): self
    {
        return $this->setHeader(Header::LAST_MODIFIED, gmdate('D, d M Y H:i:s', $timestamp) . ' GMT');
    }

    // ─── CORS ───

    /**
     * Установить CORS заголовки
     */
    public function setCors(
        string $origin = '*',
        string $methods = 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
        string $headers = 'Content-Type, Authorization, X-CSRF-Token',
        int $maxAge = 86400,
        bool $allowCredentials = false
    ): self {
        $this->setHeader('Access-Control-Allow-Origin', $origin);
        $this->setHeader('Access-Control-Allow-Methods', $methods);
        $this->setHeader('Access-Control-Allow-Headers', $headers);
        $this->setHeader('Access-Control-Max-Age', (string) $maxAge);

        if ($allowCredentials) {
            $this->setHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $this;
    }

    // ── Отправка ───

    /**
     * Отправить ответ клиенту
     */
    public function send(): void
    {
        if ($this->sent) {
            return;
        }

        if (headers_sent($file, $line)) {
            error_log("Headers already sent in {$file}:{$line}");
            // Выводим только тело
            if (!$this->isHeadRequest()) {
                $this->sendBody();
            }
            $this->sent = true;
            return;
        }

        // Статус
        header(
            sprintf(
                '%s %d %s',
                $this->getProtocolVersion(),
                $this->statusCode->value,
                $this->reasonPhrase
            ),
            true,
            $this->statusCode->value
        );

        // Заголовки
        foreach ($this->headers as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $v) {
                    header("{$name}: {$v}", false);
                }
            } else {
                header("{$name}: {$value}", true);
            }
        }

        // Куки
        foreach ($this->cookies as $cookie) {
            $cookie->send();
        }

        // HEAD возвращает те же заголовки, что GET, но никогда не отправляет тело.
        if (!$this->isHeadRequest()) {
            $this->sendBody();
        }

        $this->sent = true;
    }

    /**
     * Проверить, отправлен ли ответ
     */
    public function isSent(): bool
    {
        return $this->sent;
    }

    // ─── Магические методы ───

    public function __toString(): string
    {
        return $this->getContent();
    }

    // ─── Приватные ───

    private function getProtocolVersion(): string
    {
        return $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1';
    }

    private function isHeadRequest(): bool
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD';
    }

    private function sendBody(): void
    {
        if ($this->filePath === null) {
            echo $this->content;
            return;
        }

        $stream = fopen($this->filePath, 'rb');
        if ($stream === false) {
            error_log("Unable to open response file: {$this->filePath}");
            return;
        }

        fpassthru($stream);
        fclose($stream);
    }
}