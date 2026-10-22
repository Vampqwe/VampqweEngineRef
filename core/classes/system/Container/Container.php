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


namespace Core\System\Container;

use Core\System\FileSystem\FileSystemException;
use ReflectionClass;
use ReflectionNamedType;

/**
 * DI-контейнер с автовнедрением через Reflection
 * Поддерживает singleton (один экземпляр) и bind (новый экземпляр каждый раз)
 */
final class Container
{
    /** @var array<string, object> Готовые экземпляры (синглтоны) */
    private array $instances = [];

    /** @var array<string, callable> Фабрики для создания объектов */
    private array $factories = [];

    /** @var array<string, true> Список классов, зарегистрированных как синглтоны */
    private array $singletons = [];

    /**
     * Зарегистрировать синглтон (один экземпляр на всё приложение)
     */
    public function singleton(string $abstract, callable|object $concrete): void
    {
        // Если передан готовый объект — сохраняем сразу
        if (is_object($concrete) && !$concrete instanceof \Closure) {
            $this->instances[$abstract] = $concrete;
            $this->singletons[$abstract] = true;
            return;
        }

        // Если передан Closure — сохраняем как фабрику, но помечаем как синглтон
        $this->factories[$abstract] = $concrete;
        $this->singletons[$abstract] = true;
    }

    /**
     * Зарегистрировать фабрику (новый экземпляр при каждом вызове)
     */
    public function bind(string $abstract, callable $concrete): void
    {
        $this->factories[$abstract] = $concrete;
        // НЕ добавляем в $singletons — значит, будет создаваться заново
    }

    /**
     * Получить экземпляр
     */
    public function get(string $abstract): object
    {
        // 1. Если уже есть готовый экземпляр — возвращаем его
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        // 2. Если есть фабрика — создаём объект
        if (isset($this->factories[$abstract])) {
            $instance = ($this->factories[$abstract])($this);

            // 3. Если это синглтон — кешируем экземпляр
            if (isset($this->singletons[$abstract])) {
                $this->instances[$abstract] = $instance;
            }

            return $instance;
        }

        // 4. Если ничего не зарегистрировано — пытаемся автовнедрение
        return $this->autowire($abstract);
    }

    /**
     * Проверить, зарегистрирован ли класс
     */
    public function has(string $abstract): bool
    {
        return isset($this->instances[$abstract]) || isset($this->factories[$abstract]);
    }

    /**
     * Автоматическое разрешение зависимостей
     */
    private function autowire(string $class): object
    {
        if (!class_exists($class)) {
            throw new FileSystemException("Класс не найден: {$class}");
        }

        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new FileSystemException("Класс не может быть создан: {$class}");
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return new $class();
        }

        $dependencies = [];

        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $dependencies[] = $this->get($type->getName());
            } elseif ($param->isDefaultValueAvailable()) {
                $dependencies[] = $param->getDefaultValue();
            } else {
                throw new FileSystemException(
                    "Невозможно разрешить зависимость \${$param->getName()} в {$class}"
                );
            }
        }

        return $reflection->newInstanceArgs($dependencies);
    }
}