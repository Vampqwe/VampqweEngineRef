<?php

declare(strict_types=1);

namespace Tests\Unit\System\Security;

use Core\System\Security\CsrfToken;
use Core\System\Security\SecurityException;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты CSRF-токена (CLI-режим SessionManager).
 * Покрывает регрессии задачи №3: токены в админке.
 */
final class CsrfTokenTest extends TestCase
{
    public function testGenerateReturnsNonEmptyUniqueToken(): void
    {
        $csrf = new CsrfToken();
        $a = $csrf->generate();
        $b = $csrf->generate();

        self::assertNotSame('', $a);
        self::assertGreaterThanOrEqual(32, strlen($a));
        self::assertNotSame($a, $b, 'Каждая генерация должна давать уникальный токен');
    }

    public function testValidateAcceptsJustGeneratedToken(): void
    {
        $csrf = new CsrfToken();
        $token = $csrf->getToken();

        self::assertTrue($csrf->validate($token));
    }

    public function testValidateRejectsWrongToken(): void
    {
        $csrf = new CsrfToken();
        $csrf->getToken(); // инициализировать хранилище

        $this->expectException(SecurityException::class);
        $csrf->validate('wrong-token-value');
    }

    /**
     * @dataProvider emptyTokenProvider
     */
    public function testValidateRejectsNullOrEmpty(?string $token): void
    {
        $csrf = new CsrfToken();

        $this->expectException(SecurityException::class);
        $csrf->validate($token);
    }

    public static function emptyTokenProvider(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
        ];
    }

    public function testValidateWithoutStoredTokenThrows(): void
    {
        $csrf = new CsrfToken();
        $csrf->clear();

        $this->expectException(SecurityException::class);
        $csrf->validate('anything');
    }

    public function testDisabledCsrfAlwaysValidates(): void
    {
        $csrf = new CsrfToken('_csrf_token', 3600, false);

        self::assertTrue($csrf->validate(null));
        self::assertTrue($csrf->validate('garbage'));
    }

    public function testGetFieldContainsTokenNameAndValue(): void
    {
        $csrf = new CsrfToken();
        $token = $csrf->getToken();
        $field = $csrf->getField();

        self::assertStringContainsString('hidden', $field);
        self::assertStringContainsString('_csrf_token', $field);
        self::assertStringContainsString($token, $field);
    }

    public function testGetMetaTagContainsToken(): void
    {
        $csrf = new CsrfToken();
        $token = $csrf->getToken();
        $meta = $csrf->getMetaTag();

        self::assertStringContainsString('csrf-token', $meta);
        self::assertStringContainsString($token, $meta);
    }

    public function testClearRemovesTokenAndNextGetGeneratesFreshOne(): void
    {
        $csrf = new CsrfToken();
        $first = $csrf->getToken();
        $csrf->clear();
        $second = $csrf->getToken();

        self::assertNotSame($first, $second);

        $this->expectException(SecurityException::class);
        $csrf->validate($first);
    }

    public function testExpiredTokenIsRejected(): void
    {
        $csrf = new CsrfToken('_csrf_token', -1); // срок действия уже истёк
        $token = $csrf->getToken();

        $this->expectException(SecurityException::class);
        $csrf->validate($token);
    }

    public function testIsExpiredFalseForFreshToken(): void
    {
        $csrf = new CsrfToken('_csrf_token', 3600);
        $csrf->getToken();

        self::assertFalse($csrf->isExpired());
    }
}
