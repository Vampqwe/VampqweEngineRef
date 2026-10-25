<?php

declare(strict_types=1);

/**
 * VampqweEngineMedium — проприетарный PHP-движок
 *
 * @copyright  Copyright (c) 2024-2026. Все права защищены.
 * @author     [Дедюля Александр Иванович / VampqweEngine]
 * @license    Proprietary
 * @link       https://github.com/Vampqwe/VampqweEngine.git
 *
 * Данный код является интеллектуальной собственностью автора.
 * Любое копирование, распространение, модификация или использование
 * без письменного разрешения правообладателя строго запрещено.
 *
 * This software is proprietary and confidential.
 * Unauthorized copying, distribution, or use is strictly prohibited.
 */

namespace Core\System\Queue;

/**
 * Реестр обработчиков задач очереди.
 *
 * Имя задачи (job) — стабильная строка; payload хранит только данные,
 * а не сериализованные замыкания/объекты (безопасность + совместимость
 * версий). Обработчик получает массив данных и возвращает void; бросок
 * исключения трактуется как неудача (ретрай/failed решает Queue).
 */
class JobRegistry
{
    /** @var array<string, callable(array<string,mixed>): void> */
    private array $handlers = [];

    public function register(string $name, callable $handler): void
    {
        if (isset($this->handlers[$name])) {
            throw new \InvalidArgumentException("Обработчик задачи '{$name}' уже зарегистрирован.");
        }
        $this->handlers[$name] = $handler;
    }

    public function has(string $name): bool
    {
        return isset($this->handlers[$name]);
    }

    /**
     * @return string[]
     */
    public function names(): array
    {
        return array_keys($this->handlers);
    }

    public function dispatch(string $name, array $data): void
    {
        if (!isset($this->handlers[$name])) {
            throw new \RuntimeException("Нет обработчика для задачи '{$name}'. Доступны: " . implode(', ', $this->names()));
        }
        ($this->handlers[$name])($data);
    }
}
