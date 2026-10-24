<?php

declare(strict_types=1);

namespace Tests\Unit\Module\Auth\Fakes;

use Core\System\Session\SessionManager;

/**
 * Тестовая заглушка SessionManager: хранилище — локальный массив,
 * а не суперглобал $_SESSION. Регенерация ID в CLI недоступна,
 * поэтому она засчитывается счётчиком (можно проверять вызовы).
 */
final class FakeSessionManager extends SessionManager
{
    /** @var array<string, mixed> */
    private array $data = [];

    public int $regenerateCount = 0;

    public function isActive(): bool
    {
        return true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function regenerate(bool $deleteOldSession = true): void
    {
        $this->regenerateCount++;
    }

    public function destroy(): void
    {
        $this->data = [];
    }
}
