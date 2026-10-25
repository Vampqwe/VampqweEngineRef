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
use Core\System\Config\Config;

/**
 * Главный роутер приложения
 */
class Router
{
    private Request $request;
    private Config $config;

    /** @var Route[] */
    private array $routes = [];

    /** @var array<string, Route> Именованные маршруты */
    private array $namedRoutes = [];

    /** Флаг ленивой синхронизации карты имён (см. url()) */
    private bool $namedRoutesSynced = false;

    private MiddlewareStack $globalMiddleware;
    private ?Route $currentRoute = null;

    /** @var callable|null Fallback если маршрут не найден */
    private $fallback = null;

    public function __construct(Request $request, Config $config)
    {
        $this->request = $request;
        $this->config = $config;
        $this->globalMiddleware = new MiddlewareStack();
    }

    // ─── Регистрация маршрутов ──

    public function add(string $path, Method $method, Closure|string|array $handler): Route
    {
        return $this->addRoute($path, $method, $handler);
    }

    public function addRoute(string $path, Method $method, Closure|string|array $handler): Route
    {
        $route = new Route($path, $method, $handler);
        $this->routes[] = $route;

        if ($route->getName() !== '') {
            $this->namedRoutes[$route->getName()] = $route;
        }

        return $route;
    }

    public function get(string $path, Closure|string|array $handler): Route
    {
        return $this->add($path, Method::GET, $handler);
    }

    public function post(string $path, Closure|string|array $handler): Route
    {
        return $this->add($path, Method::POST, $handler);
    }

    public function put(string $path, Closure|string|array $handler): Route
    {
        return $this->add($path, Method::PUT, $handler);
    }

    public function patch(string $path, Closure|string|array $handler): Route
    {
        return $this->add($path, Method::PATCH, $handler);
    }

    public function delete(string $path, Closure|string|array $handler): Route
    {
        return $this->add($path, Method::DELETE, $handler);
    }

    public function any(string $path, Closure|string|array $handler): Route
    {
        $route = $this->add($path, Method::GET, $handler);
        return $route->allowMethods(...Method::cases());
    }

    // ─── Группы маршрутов ───

    public function group(string $prefix, Middleware ...$middlewares): RouteGroup
    {
        return new RouteGroup($this, $prefix, ...$middlewares);
    }

    // ─── Middleware ───

    /**
     * Добавить глобальный middleware (применяется ко всем маршрутам)
     */
    public function use(Middleware $middleware): self
    {
        $this->globalMiddleware->push($middleware);
        return $this;
    }

    // ─── Fallback ───

    /**
     * Установить fallback-обработчик (если маршрут не найден)
     */
    public function fallback(Closure $handler): self
    {
        $this->fallback = $handler;
        return $this;
    }

    // ─── Диспетчеризация ───

    /**
     * Обработать текущий запрос
     */
    public function dispatch(): Response
    {
        // Ищем совпадающий маршрут. Проход в два этапа: сначала статические
        // пути (без {param}) — они имеют приоритет над шаблонными независимо
        // от порядка регистрации (пример: '/menu/items' должен выигрывать у
        // '/menu/items/{id}', даже если последний зарегистрирован раньше).
        $matched = [];
        $allowedMethods = [];

        foreach ([false, true] as $staticOnly) {
            foreach ($this->routes as $route) {
                if ($staticOnly && $this->routeIsDynamic($route)) {
                    continue;
                }

                if (!$route->matchesPath($this->request)) {
                    continue;
                }

                foreach ($route->getMethods() as $method) {
                    $allowedMethods[$method->value] = $method->value;
                }

                if ($route->matches($this->request)) {
                    $matched[] = $route;
                }
            }

            if ($matched !== []) {
                break;
            }
        }

        foreach ($matched as $route) {
            $this->currentRoute = $route;

            // Пропускаем через глобальный middleware + middleware маршрута
            return $this->globalMiddleware->process(
                $this->request,
                fn(Request $req) => $route->execute($req)
            );
        }

        if ($allowedMethods !== []) {
            $allow = implode(', ', array_values($allowedMethods));
            return Response::text('Method Not Allowed', StatusCode::METHOD_NOT_ALLOWED)
                ->setHeader('Allow', $allow);
        }

        // Если ничего не нашли — fallback (тоже через глобальный middleware) или 404.
        // Без шлюза установки fallback-обработчик (PageController → RedirectService)
        // выполнял SQL-запросы на незанятой базе и ронял сайт с DataBaseException.
        if ($this->fallback !== null) {
            $fallback = $this->fallback;
            return $this->globalMiddleware->process(
                $this->request,
                fn(Request $req) => $fallback($req)
            );
        }

        return Response::html('<h1>404 Not Found</h1>', StatusCode::NOT_FOUND);
    }

    /**
     * Маршрут содержит хотя бы один параметр вида {param}.
     */
    private function routeIsDynamic(Route $route): bool
    {
        return (bool) preg_match('/\{[a-zA-Z_][a-zA-Z0-9_]*\}/', $route->getPath());
    }

    // ─── URL генерация ───

    /**
     * @param array<string, string> $parameters
     */
    public function url(string $name, array $parameters = []): string
    {
        if (!isset($this->namedRoutes[$name]) && !$this->namedRoutesSynced) {
            // Лень-синхронизация: имена проставляются fluent-методом
            // Route::name() ПОСЛЕ addRoute(), поэтому карта на момент
            // регистрации пуста. Пересобираем один раз при первом запросе
            // URL (маршруты после boot уже не меняются), а не на каждый вызов.
            $this->syncNamedRoutes();
            $this->namedRoutesSynced = true;
        }

        if (!isset($this->namedRoutes[$name])) {
            throw new \RuntimeException("Named route '{$name}' not found");
        }

        $route = $this->namedRoutes[$name];
        $path = $route->getPath();

        foreach ($parameters as $key => $value) {
            // Параметры подставляются в URL — экранируем спецсимволы и срезаем
            // слэши, чтобы значение не ломало структуру пути (SSRF/открытый redirect).
            $safe = rawurlencode((string) $value);
            $safe = str_replace(['/', '\\', '?', '#'], '', $safe);
            $path = str_replace('{' . $key . '}', $safe, $path);
        }

        $path = preg_replace('/\{[a-zA-Z_][a-zA-Z0-9_]*\}/', '', $path);

        $baseUrl = rtrim($this->config->get('APP_BASE_URL', ''), '/');
        return $baseUrl . $path;
    }

    public function path(string $name, array $parameters = []): string
    {
        $url = $this->url($name, $parameters);
        $baseUrl = rtrim($this->config->get('APP_BASE_URL', ''), '/');

        // Срезаем только префикс, а не все вхождения подстроки: иначе путь
        // вида '/blog/base-url-post' при APP_BASE_URL='/base-url' терял бы
        // часть имени.
        if ($baseUrl !== '' && str_starts_with($url, $baseUrl)) {
            return substr($url, strlen($baseUrl));
        }

        return $url;
    }

    // ─── Геттеры ───

    public function getCurrentRoute(): ?Route
    {
        return $this->currentRoute;
    }

    /** @return Route[] */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /** @return array<string, Route> */
    public function getNamedRoutes(): array
    {
        $this->ensureNamedRoutesSynced();
        return $this->namedRoutes;
    }

    public function hasRoute(string $name): bool
    {
        if (!isset($this->namedRoutes[$name])) {
            $this->ensureNamedRoutesSynced();
        }
        return isset($this->namedRoutes[$name]);
    }

    /**
     * Пересобрать карту имён один раз (маршруты после boot не меняются).
     * addRoute() кладёт имя сразу, но Route::name() вызывается fluent-методом
     * ПОСЛЕ регистрации — поэтому нужна ленивая дозаполняющая синхронизация.
     */
    private function ensureNamedRoutesSynced(): void
    {
        if (!$this->namedRoutesSynced) {
            $this->syncNamedRoutes();
            $this->namedRoutesSynced = true;
        }
    }

    private function syncNamedRoutes(): void
    {
        $this->namedRoutes = [];
        foreach ($this->routes as $route) {
            if ($route->getName() !== '') {
                // Первый wins: при дублировании имени не даём поздней
                // регистрации молча подменить маршрут целевого URL.
                if (!isset($this->namedRoutes[$route->getName()])) {
                    $this->namedRoutes[$route->getName()] = $route;
                }
            }
        }
    }

    public function getRequest(): Request
    {
        return $this->request;
    }
}