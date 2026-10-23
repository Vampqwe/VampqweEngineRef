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

/**
 * Отправка HTTP-заголовков безопасности
 */
class SecurityHeaders
{
    private bool $enabled;
    private string $xFrameOptions;
    private string $xContentTypeOptions;
    private string $referrerPolicy;
    private bool $hstsEnabled;
    private int $hstsMaxAge;
    private bool $cspEnabled;
    private string $cspPolicy;
    private string $trustedProxies;

    public function __construct(
        bool $enabled = true,
        string $xFrameOptions = 'DENY',
        string $xContentTypeOptions = 'nosniff',
        string $referrerPolicy = 'strict-origin-when-cross-origin',
        bool $hstsEnabled = true,
        int $hstsMaxAge = 31536000,
        bool $cspEnabled = true,
        string $cspPolicy = "default-src 'self'",
        string $trustedProxies = ''
    ) {
        $this->enabled = $enabled;
        $this->xFrameOptions = $xFrameOptions;
        $this->xContentTypeOptions = $xContentTypeOptions;
        $this->referrerPolicy = $referrerPolicy;
        $this->hstsEnabled = $hstsEnabled;
        $this->hstsMaxAge = $hstsMaxAge;
        $this->cspEnabled = $cspEnabled;
        $this->cspPolicy = $cspPolicy;
        $this->trustedProxies = $trustedProxies;
    }

    /**
     * Отправить все заголовки безопасности
     */
    public function send(): void
    {
        $this->sendHeaders(null);
    }

    /**
     * Отправить обычные security-заголовки и CSP с nonce текущего запроса.
     */
    public function sendWithNonce(string $nonce): void
    {
        $this->sendHeaders($nonce);
    }

    private function sendHeaders(?string $nonce): void
    {
        if (!$this->enabled) {
            return;
        }

        if (headers_sent()) {
            return;
        }

        $this->sendHeader('X-Frame-Options', $this->xFrameOptions);
        $this->sendHeader('X-Content-Type-Options', $this->xContentTypeOptions);
        $this->sendHeader('Referrer-Policy', $this->referrerPolicy);
        $this->sendHeader('X-XSS-Protection', '1; mode=block');
        $this->sendHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if ($this->hstsEnabled && $this->isHttps()) {
            $this->sendHeader(
                'Strict-Transport-Security',
                "max-age={$this->hstsMaxAge}; includeSubDomains; preload"
            );
        }

        if ($this->cspEnabled) {
            $policy = $nonce === null ? $this->cspPolicy : $this->policyWithNonce($nonce);
            $this->sendHeader('Content-Security-Policy', $policy);
        }
    }

    private function policyWithNonce(string $nonce): string
    {
        $nonceSource = "'nonce-{$nonce}'";
        $directives = array_values(array_filter(array_map('trim', explode(';', $this->cspPolicy))));
        $directives = $this->ensureImgSource($directives);
        $hasScriptSource = false;
        $hasStyleSource = false;

        foreach ($directives as &$directive) {
            $parts = preg_split('/\s+/', $directive) ?: [];
            $name = strtolower(array_shift($parts) ?? '');

            if ($name === 'script-src' || $name === 'style-src') {
                // unsafe-inline не должен разрешать произвольный исполняемый JavaScript
                // или обходить nonce у style-блоков. style-src-attr настраивается отдельно.
                $parts = array_values(array_filter(
                    $parts,
                    static fn(string $source): bool => $source !== "'unsafe-inline'"
                ));

                if (!in_array($nonceSource, $parts, true)) {
                    $parts[] = $nonceSource;
                }

                $directive = $name . ' ' . implode(' ', $parts);
                $hasScriptSource = $hasScriptSource || $name === 'script-src';
                $hasStyleSource = $hasStyleSource || $name === 'style-src';
            }
        }
        unset($directive);

        if (!$hasScriptSource) {
            $directives[] = "script-src 'self' {$nonceSource}";
        }
        if (!$hasStyleSource) {
            $directives[] = "style-src 'self' {$nonceSource}";
        }

        return implode('; ', $directives);
    }

    /**
     * Гарантировать наличие директивы img-src.
     *
     * Без явного img-src браузер применяет default-src ('self'), из-за чего
     * блокируются внешние изображения (например, images.unsplash.com) и
     * data: URI (inline-SVG-плейсхолдеры). Если политика CSP не содержит
     * img-src, добавляем разумный дефолт. Уже настроенная администратором
     * директива никогда не переопределяется.
     *
     * @param string[] $directives
     * @return string[]
     */
    private function ensureImgSource(array $directives): array
    {
        foreach ($directives as $directive) {
            $name = strtolower(trim(preg_split('/\s+/', $directive)[0] ?? ''));
            if ($name === 'img-src') {
                return $directives;
            }
        }

        $directives[] = "img-src 'self' data: https://images.unsplash.com";

        return $directives;
    }

    /**
     * Сгенерировать nonce для inline-скриптов
     */
    public function generateNonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Установить политику CSP
     */
    public function setCspPolicy(string $policy): self
    {
        $this->cspPolicy = $policy;
        return $this;
    }

    /**
     * Установить X-Frame-Options
     */
    public function setXFrameOptions(string $value): self
    {
        $allowed = ['DENY', 'SAMEORIGIN', 'ALLOW-FROM'];

        if (!in_array(strtoupper($value), $allowed, true)) {
            throw new \InvalidArgumentException("Недопустимое значение X-Frame-Options: {$value}");
        }

        $this->xFrameOptions = strtoupper($value);
        return $this;
    }

    /**
     * Включить/выключить заголовки
     */
    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    /**
     * Проверить, используется ли HTTPS
     */
    private function isHttps(): bool
    {
        if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }

        $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!TrustedProxy::contains($remoteAddress, $this->trustedProxies)) {
            return false;
        }

        $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        $scheme = strtolower(trim(explode(',', $forwardedProto)[0]));
        return $scheme === 'https';
    }

    /**
     * Отправить один заголовок
     */
    private function sendHeader(string $name, string $value): void
    {
        header("{$name}: {$value}", true);
    }
}