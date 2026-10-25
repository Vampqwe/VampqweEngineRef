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


namespace Core\System\Security;

use Core\System\Config\Config;
use Core\System\Logger\Logger;

/**
 * Фасад для всех security-компонентов
 */
class SecurityManager
{
    private Config $config;
    private PathSanitizer $pathSanitizer;
    private SensitiveDataMasker $sensitiveDataMasker;
    private CsrfToken $csrfToken;
    private RateLimiter $rateLimiter;
    private SecurityHeaders $securityHeaders;
    private AuditLogger $auditLogger;
    private SqlSanitizer $sqlSanitizer;
    private string $cspNonce;

    public function __construct(
        Config $config,
        Logger $logger,
        PathSanitizer $pathSanitizer,
        SensitiveDataMasker $sensitiveDataMasker,
        CsrfToken $csrfToken,
        RateLimiter $rateLimiter,
        SecurityHeaders $securityHeaders,
        AuditLogger $auditLogger,
        SqlSanitizer $sqlSanitizer
    ) {
        $this->config = $config;
        $this->pathSanitizer = $pathSanitizer;
        $this->sensitiveDataMasker = $sensitiveDataMasker;
        $this->csrfToken = $csrfToken;
        $this->rateLimiter = $rateLimiter;
        $this->securityHeaders = $securityHeaders;
        $this->auditLogger = $auditLogger;
        $this->sqlSanitizer = $sqlSanitizer;
        $this->cspNonce = bin2hex(random_bytes(16));
    }

    /**
     * Нормализация корня проекта.
     *
     * @deprecated Используйте \Core\System\Security\PathResolver::normalizeRootPath().
     *             Метод сохранён как делегат для обратной совместимости:
     *             $basePath трактуется как канонический APP_ROOT_PATH.
     */
    public static function normalizeRootPath(string $basePath = ''): string
    {
        return PathResolver::normalizeRootPath($basePath);
    }

    /**
     * Каталог storage/ внутри корня проекта.
     *
     * @deprecated Используйте \Core\System\Security\PathResolver::resolveStorageDir().
     */
    public static function resolveStorageDir(string $rootPath): string
    {
        return PathResolver::resolveStorageDir($rootPath);
    }

    /**
     * Создать SecurityManager из конфига
     */
    public static function fromConfig(Config $config, Logger $logger): self
    {
        // Корень проекта — единый резолвер (канон — APP_ROOT_PATH,
        // см. Config::getRootPath() → PathResolver/ProjectRoot).
        $basePath = $config->getRootPath();
        $enforceBasePath = $config->getBool('FS_ENFORCE_BASE_PATH', true);

        $sensitiveKeys = $config->get('SECURITY_SENSITIVE_KEYS', '');
        $masker = SensitiveDataMasker::fromString($sensitiveKeys);

        $csrfToken = new CsrfToken(
            $config->get('SECURITY_CSRF_TOKEN_NAME', '_csrf_token'),
            $config->getInt('SECURITY_CSRF_EXPIRE', 3600),
            $config->getBool('SECURITY_CSRF_ENABLED', true)
        );

        $rateLimiter = new RateLimiter(
            // Хранилище rate-limit — внутри storage/ (закрыт .htaccess,
            // лежит в .gitignore), а не в корне проекта.
            PathResolver::resolveStorageDir($basePath) . DIRECTORY_SEPARATOR . '.rate_limits',
            $config->getInt('SECURITY_RATE_LIMIT_MAX', 60),
            $config->getInt('SECURITY_RATE_LIMIT_WINDOW', 60),
            $config->getBool('SECURITY_RATE_LIMIT_ENABLED', true)
        );

        $securityHeaders = new SecurityHeaders(
            $config->getBool('SECURITY_HEADERS_ENABLED', true),
            $config->get('SECURITY_X_FRAME_OPTIONS', 'DENY'),
            $config->get('SECURITY_X_CONTENT_TYPE_OPTIONS', 'nosniff'),
            $config->get('SECURITY_REFERRER_POLICY', 'strict-origin-when-cross-origin'),
            $config->getBool('SECURITY_HSTS_ENABLED', true),
            $config->getInt('SECURITY_HSTS_MAX_AGE', 31536000),
            $config->getBool('SECURITY_CSP_ENABLED', true),
            $config->get('SECURITY_CSP_POLICY', "default-src 'self'"),
            $config->get('TRUSTED_PROXIES', '') ?? ''
        );

        // Аудит пишется в отдельный файл; SECURITY_AUDIT_FILE задаётся
        // ОТНОСИТЕЛЬНО корня проекта (APP_ROOT_PATH), напр.: /log/audit.log
        $auditLogger = new AuditLogger(
            $logger->forFile(
                $config->resolvePath(
                    (string) $config->get('SECURITY_AUDIT_FILE', ''),
                    'logs/audit.log'
                )
            ),
            $masker,
            $config->getBool('SECURITY_AUDIT_ENABLED', true)
        );

        $sqlSanitizer = new SqlSanitizer(
            $config->getBool('DB_ENFORCE_PREPARED', true)
        );

        $pathSanitizer = new PathSanitizer($basePath, $enforceBasePath);

        return new self(
            $config,
            $logger,
            $pathSanitizer,
            $masker,
            $csrfToken,
            $rateLimiter,
            $securityHeaders,
            $auditLogger,
            $sqlSanitizer
        );
    }

    // ─── Геттеры для компонентов ───

    public function getPathSanitizer(): PathSanitizer
    {
        return $this->pathSanitizer;
    }

    public function getSensitiveDataMasker(): SensitiveDataMasker
    {
        return $this->sensitiveDataMasker;
    }

    public function getCsrfToken(): CsrfToken
    {
        return $this->csrfToken;
    }

    public function getRateLimiter(): RateLimiter
    {
        return $this->rateLimiter;
    }

    public function getSecurityHeaders(): SecurityHeaders
    {
        return $this->securityHeaders;
    }

    public function getAuditLogger(): AuditLogger
    {
        return $this->auditLogger;
    }

    public function getSqlSanitizer(): SqlSanitizer
    {
        return $this->sqlSanitizer;
    }

    // ─── Быстрые методы-делегаты ───

    /**
     * Санитизировать путь
     */
    public function sanitizePath(string $path): string
    {
        return $this->pathSanitizer->sanitize($path);
    }

    /**
     * Маскировать чувствительные данные
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public function maskSensitive(array $data): array
    {
        return $this->sensitiveDataMasker->mask($data);
    }

    /**
     * Валидировать CSRF токен
     */
    public function validateCsrf(?string $token): bool
    {
        return $this->csrfToken->validate($token);
    }

    /**
     * Попытаться выполнить действие с учётом rate limit
     *
     * @return array{allowed: bool, remaining: int, retry_after: int}
     */
    public function attempt(string $key): array
    {
        return $this->rateLimiter->attempt($key);
    }

    /**
     * Отправить security-заголовки
     */
    public function sendHeaders(): void
    {
        $this->securityHeaders->sendWithNonce($this->cspNonce);
    }

    public function getCspNonce(): string
    {
        return $this->cspNonce;
    }

    /**
     * Проверить SQL-запрос
     *
     * @param array<mixed> $params
     */
    public function validateSql(string $sql, array $params = []): void
    {
        $this->sqlSanitizer->validate($sql, $params);
    }

    /**
     * Проверить права config.env
     *
     * @throws SecurityException
     */
    public function checkConfigPermissions(string $configPath): void
    {
        // Флаг SECURITY_ENFORCE_CONFIG_PERMS=true означает «политика 0640
        // включена ЕДИНООБРАЗНО»: он проверяется и здесь, и в конструкторе
        // Config (там — минимальная защита от world-access, необходимая,
        // потому что Config читается до инициализации SecurityManager).
        // Раньше флаг применялся только к этой проверке, из-за чего файл
        // с правами 0644 проходил Config::init(), но ронял bootstrap здесь.
        if (!$this->config->getBool('SECURITY_ENFORCE_CONFIG_PERMS', true)) {
            return;
        }

        if (!file_exists($configPath)) {
            return;
        }

        // На Windows проверка прав не имеет смысла
        if (DIRECTORY_SEPARATOR === '\\') {
            return;
        }

        $perms = fileperms($configPath) & 0777;

        // Допускаются только биты владельца rw и группы r.
        if (($perms & ~0640) !== 0) {
            throw SecurityException::configPermissionsInvalid($configPath, $perms, 0640);
        }
    }
}