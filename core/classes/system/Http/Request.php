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
use Core\System\Security\SecurityException;
use Core\System\Security\TrustedProxy;

/**
 * Обёртка над HTTP-запросом.
 * Immutable — после создания не изменяется.
 */
class Request
{
    private Config $config;

    /** @var array<string, mixed> */
    private array $query;

    /** @var array<string, mixed> */
    private array $post;

    /** @var array<string, mixed> */
    private array $server;

    /** @var array<string, mixed> */
    private array $files;

    /** @var array<string, mixed> */
    private array $cookies;

    /** @var array<string, mixed> */
    private array $headers;

    private Method $method;
    private string $uri;
    private string $path;
    private string $queryString;

    /** @var mixed|null Распарсенное тело (JSON) */
    private mixed $parsedBody = null;
    private bool $bodyParsed = false;

    /** @var string|null Raw body */
    private ?string $rawBody = null;

    /** @var array<string, mixed> Атрибуты (для middleware/router) */
    private array $attributes = [];

    public function __construct(Config $config)
    {
        $this->config = $config;

        $this->query      = $_GET;
        $this->post       = $_POST;
        $this->server     = $_SERVER;
        $this->files      = $_FILES;
        $this->cookies    = $_COOKIE;
        $this->headers    = $this->parseHeaders();
        $this->method     = $this->detectMethod();
        $this->uri        = $this->server['REQUEST_URI'] ?? '/';
        $this->path       = parse_url($this->uri, PHP_URL_PATH) ?: '/';
        $this->queryString = $this->server['QUERY_STRING'] ?? '';
    }

    // ─── Метод ───

    public function getMethod(): Method
    {
        return $this->method;
    }

    public function isGet(): bool { return $this->method === Method::GET; }
    public function isPost(): bool { return $this->method === Method::POST; }
    public function isPut(): bool { return $this->method === Method::PUT; }
    public function isPatch(): bool { return $this->method === Method::PATCH; }
    public function isDelete(): bool { return $this->method === Method::DELETE; }
    public function isOptions(): bool { return $this->method === Method::OPTIONS; }
    public function isHead(): bool { return $this->method === Method::HEAD; }

    /**
     * Проверить, является ли метод безопасным
     */
    public function isSafe(): bool
    {
        return $this->method->isSafe();
    }

    /**
     * Проверить, является ли метод идемпотентным
     */
    public function isIdempotent(): bool
    {
        return $this->method->isIdempotent();
    }

    // ─── URL/URI ───

    public function getUri(): string
    {
        return $this->uri;
    }

	/**
	 * Получить путь URL (без query string и имени скрипта)
	 */
	public function getPath(): string
	{
		// 1. Берем оригинальный URI (приоритет у REDIRECT_URL, так как это внутренний редирект Apache)
		$uri = $_SERVER['REDIRECT_URL'] ?? $_SERVER['REQUEST_URI'] ?? '/';
		
		// 2. Отрезаем query string (?foo=bar)
		$path = parse_url($uri, PHP_URL_PATH) ?: '/';
		
		// 3. ЖЁСТКО убираем /index.php из начала пути, если он там случайно появился
		// Это частая особенность OSPanel при внутренних редиректах mod_rewrite
		$path = preg_replace('#^/index\.php#i', '', $path);
		
		// 4. Нормализуем слеши: всегда начинаем с /, убираем замыкающий (кроме корня)
		$path = '/' . ltrim($path, '/');
		if ($path !== '/' && str_ends_with($path, '/')) {
			$path = rtrim($path, '/');
		}
		
		return $path;
	}

    public function getQueryString(): string
    {
        return $this->queryString;
    }

    public function getBaseUrl(): string
    {
        $scheme = $this->getScheme();
        $host = $this->getHost();
        $port = $this->getPort();

        $url = "{$scheme}://{$host}";

        if (($scheme === 'http' && $port !== 80) || ($scheme === 'https' && $port !== 443)) {
            $url .= ":{$port}";
        }

        return $url;
    }

    public function getFullUrl(): string
    {
        return $this->getBaseUrl() . $this->uri;
    }

    public function getScheme(): string
    {
        $remoteAddress = $this->server['REMOTE_ADDR'] ?? '';
        $forwardedProto = $this->getHeader(Header::X_FORWARDED_PROTO);

        if ($forwardedProto !== null && $this->isTrustedProxy($remoteAddress)) {
            $scheme = strtolower(trim(explode(',', $forwardedProto)[0]));
            if (in_array($scheme, ['http', 'https'], true)) {
                return $scheme;
            }
        }

        return (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off') ? 'https' : 'http';
    }

    public function getHost(): string
    {
        $host = $this->getHeader(Header::HOST) ?? $this->server['SERVER_NAME'] ?? 'localhost';

        // Allowlist доменов из config.env (APP_ALLOWED_HOSTS, через запятую).
        // Host-заголовок контролируется клиентом: без проверки возможны
        // cache poisoning и фишинговые редиректы на чужой домен.
        $allowed = array_filter(array_map(
            'trim',
            explode(',', strtolower((string) $this->config->get('APP_ALLOWED_HOSTS', '')))
        ));

        // В production пустой allowlist недопустим: клиентский Host уходит
        // в генерацию URL/редиректы без проверки (cache poisoning, фишинг).
        if ($allowed === [] && !$this->config->getBool('APP_DEBUG', false)) {
            throw new SecurityException(
                'APP_ALLOWED_HOSTS пуст в production: добавьте разрешённые домены в config.env'
            );
        }

        if ($allowed !== []) {
            $normalized = strtolower(preg_replace('/:\d+$/', '', $host) ?? $host);

            if (!in_array($normalized, $allowed, true)) {
                // Чужой Host — возвращаем первый разрешённый домен,
                // чтобы генерация URL/редиректы никогда не использовали host атакующего.
                return $allowed[0];
            }
        }

        return $host;
    }

    public function getPort(): int
    {
        return (int) ($this->server['SERVER_PORT'] ?? 80);
    }

    public function isSecure(): bool
    {
        return $this->getScheme() === 'https';
    }

    // ─── Query parameters (GET) ───

    /**
     * Получить параметр из query string
     */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * Получить все query параметры
     *
     * @return array<string, mixed>
     */
    public function allQuery(): array
    {
        return $this->query;
    }

    public function hasQuery(string $key): bool
    {
        return array_key_exists($key, $this->query);
    }

    // ─── POST data ───

    /**
     * Получить параметр из POST
     */
    public function post(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    /**
     * Получить все POST параметры
     *
     * @return array<string, mixed>
     */
    public function allPost(): array
    {
        return $this->post;
    }

    public function hasPost(string $key): bool
    {
        return array_key_exists($key, $this->post);
    }

    // ─── Input (универсальный доступ: POST → Query → Attributes) ───

    /**
     * Получить параметр из любого источника
     */
    public function input(string $key, mixed $default = null): mixed
    {
        // Сначала POST
        if (array_key_exists($key, $this->post)) {
            return $this->post[$key];
        }

        // Потом query
        if (array_key_exists($key, $this->query)) {
            return $this->query[$key];
        }

        // Потом JSON body
        $body = $this->getParsedBody();
        if (is_array($body) && array_key_exists($key, $body)) {
            return $body[$key];
        }

        // Потом attributes
        if (array_key_exists($key, $this->attributes)) {
            return $this->attributes[$key];
        }

        return $default;
    }

    /**
     * Получить несколько параметров сразу
     *
     * @param string[] $keys
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->input($key);
        }

        return $result;
    }

    /**
     * Получить все параметры кроме указанных
     *
     * @param string[] $keys
     * @return array<string, mixed>
     */
    public function except(array $keys): array
    {
        $all = array_merge($this->query, $this->post, $this->getParsedBody() ?: []);

        foreach ($keys as $key) {
            unset($all[$key]);
        }

        return $all;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->query)
            || array_key_exists($key, $this->post)
            || array_key_exists($key, $this->attributes);
    }

    public function filled(string $key): bool
    {
        $value = $this->input($key);

        if ($value === null) {
            return false;
        }

        if (is_string($value) && trim($value) === '') {
            return false;
        }

        if (is_array($value) && empty($value)) {
            return false;
        }

        return true;
    }

    public function missing(string $key): bool
    {
        return !$this->has($key);
    }

    public function empty(string $key): bool
    {
        return !$this->filled($key);
    }

    // ─── Headers ───

    /**
     * Получить заголовок
     */
    public function getHeader(Header|string $header, ?string $default = null): ?string
    {
        $name = $header instanceof Header ? $header->value : $header;
        $key = strtolower(str_replace('_', '-', $name));

        return $this->headers[$key] ?? $default;
    }

    /**
     * Получить все заголовки
     *
     * @return array<string, string>
     */
    public function allHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader(Header|string $header): bool
    {
        $name = $header instanceof Header ? $header->value : $header;
        $key = strtolower(str_replace('_', '-', $name));

        return isset($this->headers[$key]);
    }

    // ─── Content-Type / Accept ───

    public function getContentType(): ?string
    {
        return $this->getHeader(Header::CONTENT_TYPE);
    }

    public function getAccept(): ?string
    {
        return $this->getHeader(Header::ACCEPT);
    }

    public function isJson(): bool
    {
        $contentType = $this->getContentType();
        return $contentType !== null && str_contains($contentType, 'json');
    }

    public function expectsJson(): bool
    {
        $accept = $this->getAccept();
        return $accept !== null && str_contains($accept, 'json');
    }

    public function isXml(): bool
    {
        $contentType = $this->getContentType();
        return $contentType !== null && str_contains($contentType, 'xml');
    }

    public function isHtml(): bool
    {
        $accept = $this->getAccept();
        return $accept !== null && str_contains($accept, 'html');
    }

    // ─── Body ───

    /**
     * Получить raw body
     */
    public function getRawBody(): string
    {
        if ($this->rawBody === null) {
            $this->rawBody = file_get_contents('php://input') ?: '';
        }

        return $this->rawBody;
    }

    /**
     * Получить распарсенное тело (JSON)
     *
     * @return array<string, mixed>|null
     */
    public function getParsedBody(): ?array
    {
        if (!$this->bodyParsed) {
            $this->bodyParsed = true;

            if ($this->isJson()) {
                $raw = $this->getRawBody();

                if ($raw !== '') {
                    $decoded = json_decode($raw, true);

                    if (is_array($decoded)) {
                        $this->parsedBody = $decoded;
                    }
                }
            }
        }

        return $this->parsedBody;
    }

    // ── Files ───

    /**
     * Получить загруженный файл
     */
    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    /**
     * Получить все файлы
     *
     * @return array<string, mixed>
     */
    public function allFiles(): array
    {
        return $this->files;
    }

    public function hasFile(string $key): bool
    {
        return isset($this->files[$key]) && $this->files[$key]['error'] === UPLOAD_ERR_OK;
    }

    /**
     * Проверить, есть ли ошибка загрузки
     */
    public function fileError(string $key): ?int
    {
        return $this->files[$key]['error'] ?? null;
    }

    // ─── Cookies ───

    public function cookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies[$key] ?? $default;
    }

    public function hasCookie(string $key): bool
    {
        return isset($this->cookies[$key]);
    }

    /**
     * Получить все куки
     *
     * @return array<string, mixed>
     */
    public function allCookies(): array
    {
        return $this->cookies;
    }

    // ─── Server / Environment ───

    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    public function allServer(): array
    {
        return $this->server;
    }

    // ─── IP / User Agent ───

    /**
     * Получить IP адрес клиента
     */
    public function getIp(): string
    {
        $remoteAddress = $this->server['REMOTE_ADDR'] ?? '127.0.0.1';
        $forwarded = $this->getHeader(Header::X_FORWARDED_FOR);

        if ($forwarded === null || !$this->isTrustedProxy($remoteAddress)) {
            return $remoteAddress;
        }

        $forwardedAddresses = array_values(array_filter(
            array_map('trim', explode(',', $forwarded)),
            static fn(string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false
        ));

        // Идём справа налево по XFF (от ближайшего к нам прокси к клиенту):
        // текущий кандидат признаётся клиентом только если он сам является
        // доверенным прокси — тогда переходим к следующему адресу левее.
        // Если самый левый незакрытый адрес тоже из TRUSTED_PROXIES (например,
        // allowlist задан широкой CIDR-сетью, включающей клиентские подсети),
        // дальнейшее доверие к XFF теряет смысл — возвращаем REMOTE_ADDR.
        $chain = array_reverse($forwardedAddresses);
        $candidate = $remoteAddress;
        while ($chain !== [] && $this->isTrustedProxy($candidate)) {
            $candidate = array_pop($chain);
        }

        if ($chain === [] && $candidate !== $remoteAddress && $this->isTrustedProxy($candidate)) {
            // Весь XFF-список состоит из доверенных адресов: последний элемент
            // не может быть «клиентом за прокси» — вероятна подмена цепочки.
            return $remoteAddress;
        }

        return $candidate;
    }

    /**
     * Получить User-Agent
     */
    public function getUserAgent(): ?string
    {
        return $this->getHeader(Header::USER_AGENT);
    }

    /**
     * Получить Referer
     */
    public function getReferer(): ?string
    {
        return $this->getHeader(Header::REFERER);
    }

    /**
     * Получить Origin
     */
    public function getOrigin(): ?string
    {
        return $this->getHeader(Header::ORIGIN);
    }

    // ─── AJAX / API detection ───

    /**
     * Является ли запрос AJAX
     */
    public function isAjax(): bool
    {
        return $this->getHeader(Header::X_REQUESTED_WITH) === 'XMLHttpRequest';
    }

    /**
     * Является ли запрос API (ожидает JSON)
     */
    public function isApi(): bool
    {
        return $this->expectsJson() || $this->isJson();
    }

    /**
     * Является ли запрос prefetch/preflight (CORS)
     */
    public function isPreflight(): bool
    {
        return $this->isOptions() && $this->hasHeader(Header::ORIGIN);
    }

    // ─── Language / Locale ───

    /**
     * Получить предпочтительный язык
     */
    public function getPreferredLanguage(array $supported = []): string
    {
        $acceptLanguage = $this->getHeader(Header::ACCEPT_LANGUAGE);

        if ($acceptLanguage === null) {
            return $supported[0] ?? 'en';
        }

        // Парсим Accept-Language: ru-RU,ru;q=0.9,en;q=0.8
        preg_match_all('/([a-zA-Z\-]+)(?:;q=([0-9.]+))?/', $acceptLanguage, $matches);

        $languages = [];
        foreach ($matches[1] as $i => $lang) {
            $quality = isset($matches[2][$i]) && $matches[2][$i] !== '' ? (float) $matches[2][$i] : 1.0;
            $languages[$lang] = $quality;
        }

        arsort($languages);

        foreach ($languages as $lang => $quality) {
            if (in_array($lang, $supported, true)) {
                return $lang;
            }

            // Проверяем базовый язык (ru-RU → ru)
            $base = explode('-', $lang)[0];
            if (in_array($base, $supported, true)) {
                return $base;
            }
        }

        return $supported[0] ?? 'en';
    }

    // ── Attributes (для middleware/router) ───

    /**
     * Установить атрибут
     */
    public function setAttribute(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->attributes[$key] = $value;
        return $clone;
    }

    /**
     * Получить атрибут
     */
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Получить все атрибуты
     *
     * @return array<string, mixed>
     */
    public function allAttributes(): array
    {
        return $this->attributes;
    }

    public function hasAttribute(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    // ── Content Length ───

    public function getContentLength(): int
    {
        return (int) ($this->server['CONTENT_LENGTH'] ?? 0);
    }

    // ─── Приватные методы ───

    /**
     * Распарсить заголовки из $_SERVER
     *
     * @return array<string, string>
     */
    private function parseHeaders(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[strtolower($name)] = $value;
            }
        }

        // Специальные заголовки
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }

        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = $_SERVER['CONTENT_LENGTH'];
        }

        return $headers;
    }

    private function isTrustedProxy(string $ip): bool
    {
        return TrustedProxy::contains(
            $ip,
            $this->config->get('TRUSTED_PROXIES', '') ?? ''
        );
    }

    /**
     * Определить HTTP-метод (с поддержкой method spoofing)
     */
    private function detectMethod(): Method
    {
        // Метод из $_SERVER
        $method = $this->server['REQUEST_METHOD'] ?? 'GET';

        // Method spoofing через _method поле (для PUT/PATCH/DELETE из форм)
        if ($method === 'POST' && isset($this->post['_method'])) {
            $method = strtoupper($this->post['_method']);
        }

        // Method spoofing через заголовок X-HTTP-Method-Override
        if ($method === 'POST') {
            $override = $this->server['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? null;
            if ($override !== null) {
                $method = strtoupper($override);
            }
        }

        return Method::fromString($method);
    }

    /**
     * Создать Request из суперглобальных (фабричный метод)
     */
    public static function fromGlobals(Config $config): self
    {
        return new self($config);
    }
}