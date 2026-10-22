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

use Closure;

/**
 * Группа маршрутов с общим префиксом и middleware
 */
class RouteGroup
{
    private string $prefix;
    private MiddlewareStack $middlewareStack;
    private Router $router;

    public function __construct(Router $router, string $prefix = '', Middleware ...$middlewares)
    {
        $this->router = $router;
        $this->prefix = $prefix;
        $this->middlewareStack = new MiddlewareStack();
        $this->middlewareStack->merge($middlewares);
    }

    /**
     * Добавить маршрут в группу
     */
	public function addRoute(string $path, Method $method, Closure|string|array $handler): Route
	{
		$fullPath = $this->prefix . $path;
		$route = $this->router->addRoute($fullPath, $method, $handler);
		
		// Добавляем middleware группы к маршруту
		$middlewares = $this->middlewareStack->all();
		if (!empty($middlewares)) {
			$route->middleware(...$middlewares);
		}
		
		return $route;
	}
    public function get(string $path, Closure|string|array $handler): Route
    {
        return $this->addRoute($path, Method::GET, $handler);
    }

    public function post(string $path, Closure|string|array $handler): Route
    {
        return $this->addRoute($path, Method::POST, $handler);
    }

    public function put(string $path, Closure|string|array $handler): Route
    {
        return $this->addRoute($path, Method::PUT, $handler);
    }

    public function patch(string $path, Closure|string|array $handler): Route
    {
        return $this->addRoute($path, Method::PATCH, $handler);
    }

    public function delete(string $path, Closure|string|array $handler): Route
    {
        return $this->addRoute($path, Method::DELETE, $handler);
    }
}