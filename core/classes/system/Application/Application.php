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


namespace Core\System\Application;

use Core\System\Application\InstalledGuard;
use Core\System\Config\Config;
use Core\System\Container\Container;
use Core\System\Http\InstallGateMiddleware;
use Core\System\Http\Router;
use Core\System\Logger\Logger;
use Core\System\Security\SecurityManager;
use Core\System\Error\ErrorHandler;
use Core\System\ServiceProvider\ConfigServiceProvider;
use Core\System\ServiceProvider\DataBaseServiceProvider;
use Core\System\ServiceProvider\FileSystemServiceProvider;
use Core\System\ServiceProvider\LoggerServiceProvider;
use Core\System\ServiceProvider\PageServiceProvider; 
use Core\System\ServiceProvider\RouterServiceProvider;  
use Core\System\ServiceProvider\SecurityServiceProvider;
use Core\System\ServiceProvider\ServiceProvider;
use Core\System\ServiceProvider\TimeDateServiceProvider;
use Core\System\ServiceProvider\ValidatorServiceProvider;
use Core\System\ServiceProvider\HttpServiceProvider;
use Core\System\ServiceProvider\TemplateServiceProvider;
use Core\System\ServiceProvider\AdminServiceProvider;
use Core\System\ServiceProvider\HtmlSanitizerServiceProvider;
use Throwable;

/**
 * Главный класс приложения.
 */
class Application
{
    private static ?self $instance = null;

    private Container $container;
    private Config $config;
    private string $basePath;

    /** @var ServiceProvider[] */
    private array $providers = [];

    private bool $booted = false;

    private function __construct(string $basePath)
    {
        if (!is_dir($basePath)) {
            throw ApplicationException::basePathNotFound($basePath);
        }

        $this->basePath  = rtrim($basePath, DIRECTORY_SEPARATOR);
        $this->container = new Container();
    }

    /**
     * Инициализация приложения.
     *
     * Корень проекта определяется единым резолвером ProjectRoot
     * (иерархия: явный параметр → VCORE_ROOT → APP_ROOT_PATH из
     * config.env → fallback от расположения файлов движка).
     *
     * @param string|null $basePath Явный корень (например, __DIR__ из
     *        index.php). Null/пусто — разрешить через ProjectRoot.
     */
    public static function init(?string $basePath = null): self
    {
        if (self::$instance !== null) {
            throw ApplicationException::alreadyInitialized();
        }

        // Фиксируем корень ДО создания Config: все потребители базового
        // пути (провайдеры, Template, InstallService, CLI) читают его
        // только из ProjectRoot / getBasePath().
        ProjectRoot::set($basePath);

        $app = new self(ProjectRoot::get());
        self::$instance = $app;

        try {
            $app->bootstrap();
        } catch (Throwable $e) {
            self::reset();
            throw $e;
        }

        return $app;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw ApplicationException::notInitialized();
        }

        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
        Config::reset();
        ProjectRoot::reset();
    }

    private function bootstrap(): void
    {
        // Экономный фолбэк: если bootstrap упадёт ДО регистрации полноценного
        // ErrorHandler (кривой config.env, нет прав на storage/ и т.п.),
        // пользователь увидит внятную 500-страницу, а не белый экран.
        $this->registerEmergencyErrorHandler();

        // Флаг: bootstrap дошёл до bootProviders() без фатала.
        // Если нет — сработает аварийный шлюз установки (см. ниже).
        $providersBooted = false;

        try {
            $this->container->singleton(self::class, $this);
            $this->registerConfig();
            $this->registerDefaultProviders();
            $this->bootProviders();
            $providersBooted = true;
            $this->registerInstallGate();
            $this->registerErrorHandler();

            $this->booted = true;
        } catch (Throwable $e) {
            // АВАРИЙНЫЙ ШЛЮЗ УСТАНОВКИ (замыкает остаточный дефект «HTTP 500
            // вместо страницы установки/заглушки»).
            //
            // Если bootstrap упал ПОСЛЕ загрузки конфига и провайдеров
            // (например, кривые маршруты в Router/Admin-провайдерах), но
            // ДО регистрации InstallGateMiddleware — отдаём честное
            // состояние движка, а не 500. Проверка состояния не трогает БД:
            // только метку storage/.installed.
            if ($providersBooted && $e instanceof ApplicationException) {
                $this->serveEmergencyInstallState($e);
            }

            if ($e instanceof ApplicationException) {
                throw $e;
            }

            throw ApplicationException::serviceProviderFailed('bootstrap', $e->getMessage());
        }
    }

    /**
     * Аварийный шлюз установки: отдаёт честное состояние движка, если
     * bootstrap упал после загрузки провайдеров, но до регистрации
     * InstallGateMiddleware. Работает без БД и без роутера — только по
     * метке storage/.installed. Завершает запрос и не возвращает контроль.
     */
    private function serveEmergencyInstallState(Throwable $e): never
    {
        error_log('[emergency-gate] bootstrap failed after providers boot: ' . $e->getMessage());

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $isApi = str_starts_with(
            parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
            '/api/'
        );

        $hasMarker = file_exists($this->storagePath('.installed'));

        if ($isApi) {
            http_response_code(503);
            header('Content-Type: application/json; charset=UTF-8');
            header('Retry-After: 30');
            header('X-Engine-State', $hasMarker ? 'db_unreachable' : 'not_installed');
            echo json_encode([
                'status'  => 'error',
                'code'    => $hasMarker ? 'database_unavailable' : 'engine_not_installed',
                'message' => $hasMarker
                    ? 'Сервис временно недоступен: движок не смог корректно запуститься.'
                    : 'Движок не установлен. Выполните установку через /admin/install.',
                'install_url' => '/admin/install',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!$hasMarker) {
            \Core\System\Http\NotInstalledPage::response()->send();
        } else {
            // Метка есть → установка цела, проблема в запуске движка:
            // служебная страница «БД/движок временно недоступен», 503.
            \Core\System\Http\DbUnavailablePage::response()->send();
        }
        exit;
    }

    private function registerErrorHandler(): void
{
    $logger = null;
    
    try {
        $logger = $this->container->get(Logger::class);
    } catch (Throwable) {
        // Логгер ещё не доступен
    }
    
    $security = $this->container->get(SecurityManager::class);
    $errorHandler = new ErrorHandler($this->config, $logger, $security->getCspNonce());
    $errorHandler->register();
    
    $this->container->singleton(ErrorHandler::class, $errorHandler);
}

    /**
     * Глобальный шлюз установки: если движок не установлен (нет метки
     * storage/.installed или нет таблиц в БД), любой запрос — кроме
     * маршрутов мастера установки и health-проб — получает аккуратную
     * страницу «Движок не установлен», а не фатальную SQL-ошибку.
     */
    private function registerInstallGate(): void
	{
		$guard = new InstalledGuard($this->basePath);
		$this->container->singleton(InstalledGuard::class, $guard);

		/** @var Router $router */
		$router = $this->container->get(Router::class);
		$router->use(new InstallGateMiddleware($guard));
	}
	
    /**
     * Фаталити-safe обработчик ошибок на случай, когда bootstrap()
     * всё же не завершился (например, отсутствует config.env).
     * Без него любое исключение из bootstrap давало бы белый экран
     * с неопределённым 500 вместо понятного ответа.
     */
    private function registerEmergencyErrorHandler(): void
    {
        set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(static function (Throwable $e): void {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/plain; charset=UTF-8');
            }

            error_log('[emergency] ' . $e->getMessage());
            echo "Внутренняя ошибка сервера. Движок не готов к работе: проверьте config.env и права на storage/.\n";
        });
    }

    private function registerConfig(): void
    {
        $configPath = $this->basePath . DIRECTORY_SEPARATOR . 'config.env';
        $config     = Config::init($configPath);

        $this->config = $config;
        $this->container->singleton(Config::class, $config);
    }

    private function registerDefaultProviders(): void
    {
        $providerClasses = [
            ConfigServiceProvider::class,
            FileSystemServiceProvider::class,
            DataBaseServiceProvider::class,
            LoggerServiceProvider::class,
            TimeDateServiceProvider::class,
            ValidatorServiceProvider::class,
            SecurityServiceProvider::class,
			HttpServiceProvider::class,
			PageServiceProvider::class,
			RouterServiceProvider::class,
			TemplateServiceProvider::class,
			AdminServiceProvider::class,
			HtmlSanitizerServiceProvider::class,
        ];

        foreach ($providerClasses as $providerClass) {
            /** @var ServiceProvider $provider */
            $provider = new $providerClass($this);
            $provider->register();
            $this->providers[] = $provider;
        }
    }

    private function bootProviders(): void
    {
        foreach ($this->providers as $provider) {
            try {
                $provider->boot();
            } catch (Throwable $e) {
                throw ApplicationException::serviceProviderFailed(
                    get_class($provider),
                    $e->getMessage(),
                    $e
                );
            }
        }
    }

    public function registerProvider(string $providerClass): self
    {
        /** @var ServiceProvider $provider */
        $provider = new $providerClass($this);
        $provider->register();
        $this->providers[] = $provider;

        if ($this->booted) {
            $provider->boot();
        }

        return $this;
    }

    /**
     * @template T of object
     * @param class-string<T> $abstract
     * @return T
     */
    public function make(string $abstract): object
    {
        return $this->container->get($abstract);
    }

    public function getConfig(): Config
    {
        return $this->config;
    }

    public function getContainer(): Container
    {
        return $this->container;
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    public function path(string $subpath = ''): string
    {
        return $this->basePath . ($subpath ? DIRECTORY_SEPARATOR . ltrim($subpath, DIRECTORY_SEPARATOR) : '');
    }

    public function corePath(string $subpath = ''): string
    {
        return $this->path('core' . ($subpath ? DIRECTORY_SEPARATOR . $subpath : ''));
    }

    public function storagePath(string $subpath = ''): string
    {
        return $this->path('storage' . ($subpath ? DIRECTORY_SEPARATOR . $subpath : ''));
    }

    public function logsPath(string $subpath = ''): string
    {
        return $this->path('logs' . ($subpath ? DIRECTORY_SEPARATOR . $subpath : ''));
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }

    /**
     * @return string[]
     */
    public function getProviders(): array
    {
        return array_map(fn(ServiceProvider $p) => get_class($p), $this->providers);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function run(callable $callback): mixed
    {
        try {
            return $callback($this);
        } catch (Throwable $e) {
            $this->handleException($e);
            throw $e;
        }
    }

    public function handleException(Throwable $e): void
    {
        try {
            if ($this->booted) {
                $logger = $this->make(Logger::class);
                if ($logger->isEnabled()) {
                    $logger->exception($e);
                }

                // Логируем в аудит, если доступен
                if ($this->container->has(SecurityManager::class)) {
                    $security = $this->make(SecurityManager::class);
                    $security->getAuditLogger()->logException($e);
                }
            }
        } catch (Throwable) {
            error_log("Application error: {$e->getMessage()}");
        }
    }
}