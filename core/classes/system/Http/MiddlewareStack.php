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
 * Стек middleware — вызывает их по очереди
 */
class MiddlewareStack
{
    /** @var Middleware[] */
    private array $middlewares = [];

    /**
     * Добавить middleware
     */
    public function push(Middleware $middleware): self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    /**
     * Добавить несколько middleware
     *
     * @param Middleware[] $middlewares
     */
    public function merge(array $middlewares): self
    {
        foreach ($middlewares as $middleware) {
            $this->push($middleware);
        }
        return $this;
    }

    /**
     * Выполнить запрос через весь стек
     */
    public function process(Request $request, callable $finalHandler): Response
    {
        // Создаем цепочку: каждый middleware вызывает следующий
        $handler = $finalHandler;

        // Идем с конца, чтобы первый middleware был внешним
        for ($i = count($this->middlewares) - 1; $i >= 0; $i--) {
            $middleware = $this->middlewares[$i];
            $next = $handler;
            $handler = function (Request $req) use ($middleware, $next): Response {
                return $middleware->handle($req, $next);
            };
        }

        return $handler($request);
    }

    /**
     * Очистить стек
     */
    public function clear(): self
    {
        $this->middlewares = [];
        return $this;
    }

    /**
     * Получить количество middleware
     */
    public function count(): int
    {
        return count($this->middlewares);
    }

    /**
     * Получить все middleware как массив
     *
     * @return Middleware[]
     */
    public function all(): array
    {
        return $this->middlewares;
    }
}