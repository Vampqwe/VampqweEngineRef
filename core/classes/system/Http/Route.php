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
 * Класс одного маршрута
 */
class Route
{
    private string $path;
    private Method $method;
    /** @var Method[] */
    private array $methods;
    private Closure|string|array $handler;
    private string $name = '';
    private array $parameters = [];
    private MiddlewareStack $middlewareStack;
    private array $wheres = [];

    public function __construct(
        string $path,
        Method $method,
        Closure|string|array $handler
    ) {
        $this->path = $path;
        $this->method = $method;
        $this->methods = [$method];
        $this->handler = $handler;
        $this->middlewareStack = new MiddlewareStack();
    }

    /**
     * Установить имя маршрута
     */
    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Добавить middleware
     */
    public function middleware(Middleware ...$middlewares): self
    {
        $this->middlewareStack->merge($middlewares);
        return $this;
    }

    /**
     * Ограничение для параметров маршрута (regex)
     */
    public function where(string $param, string $pattern): self
    {
        $this->wheres[$param] = $pattern;
        return $this;
    }

    /**
     * Проверить, совпадает ли маршрут с запросом
     */
    public function matches(Request $request): bool
    {
        if (!$this->allowsMethod($request->getMethod())) {
            return false;
        }

        return $this->matchesPath($request);
    }

    public function matchesPath(Request $request): bool
    {
        $requestPath = $this->normalizePath($request->getPath());
        $routePath = $this->normalizePath($this->path);

        // Точное совпадение
        if ($requestPath === $routePath) {
            return true;
        }

        // Совпадение с параметрами {param}
        return $this->matchWithParameters($requestPath, $routePath);
    }

    public function allowMethods(Method ...$methods): self
    {
        foreach ($methods as $method) {
            if (!in_array($method, $this->methods, true)) {
                $this->methods[] = $method;
            }
        }

        return $this;
    }

    public function allowsMethod(Method $method): bool
    {
        return in_array($method, $this->methods, true)
            || ($method === Method::HEAD && in_array(Method::GET, $this->methods, true));
    }

    /** @return Method[] */
    public function getMethods(): array
    {
        $methods = $this->methods;
        if (in_array(Method::GET, $methods, true) && !in_array(Method::HEAD, $methods, true)) {
            $methods[] = Method::HEAD;
        }

        return $methods;
    }

    /**
     * Извлечь параметры из URL
     *
     * @return array<string, string>
     */
    public function extractParameters(Request $request): array
    {
        $requestPath = $this->normalizePath($request->getPath());
        $routePath = $this->normalizePath($this->path);

        $parameters = [];
        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $routePath, $matches);

        if (empty($matches[1])) {
            return $parameters;
        }

        // Создаем regex из пути маршрута
        $pattern = $this->buildPattern($routePath);

        if (preg_match($pattern, $requestPath, $values)) {
            foreach ($matches[1] as $i => $param) {
                $parameters[$param] = $values[$i + 1] ?? '';
            }
        }

        $this->parameters = $parameters;
        return $parameters;
    }

    /**
     * Выполнить маршрут
     */
    public function execute(Request $request): Response
    {
        // Извлекаем параметры
        $params = $this->extractParameters($request);

        // Добавляем параметры в атрибуты запроса
        foreach ($params as $key => $value) {
            $request = $request->setAttribute($key, $value);
        }

        // Финальный обработчик
        $finalHandler = function (Request $req) use ($params): Response {
            return $this->callHandler($req, $params);
        };

        // Пропускаем через middleware
        return $this->middlewareStack->process($request, $finalHandler);
    }

    /**
     * Вызвать handler (closure, controller@method, [controller, method])
     */
    private function callHandler(Request $request, array $params): Response
    {
        if ($this->handler instanceof Closure) {
            return ($this->handler)($request);
        }

        if (is_string($this->handler)) {
            // Формат "Controller@method" или просто "Controller"
            if (str_contains($this->handler, '@')) {
                [$class, $method] = explode('@', $this->handler, 2);
            } else {
                $class = $this->handler;
                $method = '__invoke';
            }

            if (!class_exists($class)) {
                throw new \RuntimeException("Controller class not found: {$class}");
            }

            $controller = new $class();

            if (!method_exists($controller, $method)) {
                throw new \RuntimeException("Method {$method} not found in {$class}");
            }

            return $controller->$method($request);
        }

        if (is_array($this->handler) && count($this->handler) === 2) {
            [$class, $method] = $this->handler;
            $controller = is_string($class) ? new $class() : $class;
            return $controller->$method($request);
        }

        throw new \RuntimeException('Invalid route handler');
    }

    // ─── Геттеры ───

    public function getPath(): string { return $this->path; }
    public function getMethod(): Method { return $this->method; }
    public function getName(): string { return $this->name; }
    public function getParameters(): array { return $this->parameters; }
    public function getMiddlewareStack(): MiddlewareStack { return $this->middlewareStack; }

    // ─── Приватные методы ───

    private function normalizePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private function matchWithParameters(string $requestPath, string $routePath): bool
    {
        $pattern = $this->buildPattern($routePath);
        return (bool) preg_match($pattern, $requestPath);
    }

    private function buildPattern(string $routePath): string
    {
        // Экранируем спецсимволы, кроме {param}
        $pattern = preg_quote($routePath, '#');

        // Восстанавливаем параметры {param}
        $pattern = preg_replace_callback(
            '/\\\{([a-zA-Z_][a-zA-Z0-9_]*)\\\}/',
            function ($matches) {
                $param = $matches[1];
                $regex = $this->wheres[$param] ?? '[^/]+';
                return "(?P<{$param}>{$regex})";
            },
            $pattern
        );

        return '#^' . $pattern . '$#u';
    }
}