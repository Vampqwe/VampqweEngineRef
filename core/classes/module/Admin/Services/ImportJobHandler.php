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

namespace Core\Module\Admin\Services;

use Core\System\Application\Application;
use Core\System\Config\Config;
use Core\System\DataBase\DataBase;
use Core\System\Queue\Queue;

/**
 * Фоновое выполнение импорта HTML (job «import.html»).
 *
 * Тяжёлые шаги — парсинг чужого HTML, скачивание картинок, запись
 * блоков и стилей — вынесены из веб-процесса: контроллер лишь кладёт
 * путь к временному файлу в очередь и сразу отвечает администратору.
 *
 * Payload задачи хранит только примитивы (путь, slug, user id), а не
 * сериализованные объекты — безопасно и переживает релизы.
 */
class ImportJobHandler
{
    public const JOB_NAME = 'import.html';

    /** Ключ хранения результата парсинга в сессии администратора. */
    public const SESSION_KEY = 'import_preview';

    /** Срок жизни временного файла импорта (старые файлы удаляются воркером). */
    private const TMP_TTL_SECONDS = 86400;

    public function __construct(private Config $config, private ?Queue $queue = null)
    {
        $this->queue ??= new Queue($config);
    }

    /**
     * Поставить импорт в очередь (вызывается из веб-процесса).
     * Возвращает id задачи или null, если очередь выключена (QUEUE_ENABLED=false).
     */
    public function dispatch(string $file, int $userId): ?string
    {
        if (!$this->queue->isEnabled()) {
            return null;
        }

        return $this->queue->push(self::JOB_NAME, [
            'file' => $file,
            'user_id' => $userId,
        ]);
    }

    /**
     * Фоновый режим включён (QUEUE_ENABLED=true)?
     */
    public function isQueueEnabled(): bool
    {
        return $this->queue->isEnabled();
    }

    /**
     * Удалить временный файл job'а из сессии (если он там есть).
     */
    public function forgetStagedFile(): void
    {
        $file = (string) ($_SESSION[self::SESSION_KEY]['import_file'] ?? '');
        if ($file !== '' && is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * Каталог временных файлов загрузок: storage/import/tmp/.
     */
    public function getTmpDir(): string
    {
        return $this->config->getStoragePath('import') . DIRECTORY_SEPARATOR . 'tmp';
    }

    /**
     * Сохранить загруженный файл во временный хранилище очереди.
     * Возвращает абсолютный путь либо null при неудаче.
     */
    public function stageUpload(string $tmpName): ?string
    {
        $dir = $this->getTmpDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }

        $target = $dir . DIRECTORY_SEPARATOR . 'import-' . bin2hex(random_bytes(8)) . '.html';
        // move_uploaded_file не работает из CLI-контекста тестов — используем rename как fallback.
        $moved = @move_uploaded_file($tmpName, $target) || @rename($tmpName, $target);
        if (!$moved) {
            return null;
        }
        @chmod($target, 0660);

        return $target;
    }

    /**
     * Разрешить синглтон из DI-контейнера приложения, если оно
     * инициализировано (веб-контекст), иначе — создать напрямую
     * (CLI-воркер). Единый резолвер для всех сервисов импорта.
     */
    private static function resolveFromContainer(string $class, callable $factory)
    {
        try {
            return Application::getInstance()->getContainer()->get($class);
        } catch (\Throwable) {
            return $factory();
        }
    }

    /**
     * Разрешить парсер импорта (DI или прямое создание в CLI).
     */
    private static function resolveParser(Config $config): HtmlImportParser
    {
        /** @var HtmlImportParser */
        return self::resolveFromContainer(
            HtmlImportParser::class,
            static fn(): HtmlImportParser => new HtmlImportParser($config)
        );
    }

    /**
     * Разрешить сервис записи страниц (тот же DataBase, что уже создан
     * для проверки slug — единое соединение на обработку job'а).
     */
    private static function resolvePageService(DataBase $db): PageService
    {
        /** @var PageService */
        return self::resolveFromContainer(
            PageService::class,
            static fn(): PageService => new PageService($db)
        );
    }

    /**
     * Обработчик job «import.html». Бросок исключения трактуется очередью
     * как неудача (ретрай/failed решает Queue).
     *
     * @param array<string,mixed> $data
     */
    public function handle(array $data): void
    {
        $file = (string) ($data['file'] ?? '');
        $userId = (int) ($data['user_id'] ?? 0);

        try {
            if ($file === '' || !is_file($file)) {
                throw new \RuntimeException('Файл импорта не найден (возможно, уже обработан).');
            }

            $html = file_get_contents($file);
            if ($html === false || $html === '') {
                throw new \RuntimeException('Файл импорта пуст или не читается.');
            }

            $db = new DataBase($this->config);

            // Парсер получается через DI-контейнер (синглтон зарегистрирован
            // в AdminServiceProvider). В CLI-контексте воркера приложение может
            // быть не инициализировано — тогда создаём парсер напрямую.
            $parser = self::resolveParser($this->config);

            $parsed = $parser->parse($html);

            $pageService = self::resolvePageService($db);
            if ($pageService->pageExistsBySlug((string) $parsed['slug'])) {
                throw new \RuntimeException(
                    'Страница с slug «' . $parsed['slug'] . '» уже существует.'
                );
            }

            // Единая точка записи импорта (та же логика, что в
            // ImportController::confirm() — сборка записи pages, колоночные
            // блоки config/import-parser.php, rows-наборы schema_org и др.,
            // запись neutral-style.css). Все SQL-шаги — в транзакции;
            // ошибки блоков возвращаются в отчёте.
            $writer = new PageImportWriter(
                $parser,
                $pageService,
                $this->config,
                self::resolveFromContainer(
                    ImportService::class,
                    static fn(): ImportService => new ImportService($db)
                ),
                $db
            );
            $report = $writer->write($parsed);

            $this->notify($userId, 'success', 'Страница импортирована! ID: ' . $report['page_id'] . ' (фоновая обработка)');
        } catch (\Throwable $e) {
            $this->notify($userId, 'error', 'Импорт не выполнен: ' . $e->getMessage());
            throw $e; // Queue решит ретрай/failed
        } finally {
            if ($file !== '' && is_file($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * Удалить осиротевшие временные файлы старше TTL (запускается воркером).
     */
    public function pruneStaleUploads(): int
    {
        $dir = $this->getTmpDir();
        if (!is_dir($dir)) {
            return 0;
        }
        $removed = 0;
        $deadline = time() - self::TMP_TTL_SECONDS;
        foreach ((array) glob($dir . '/import-*.html') as $file) {
            if (is_string($file) && (int) @filemtime($file) < $deadline) {
                if (@unlink($file)) {
                    $removed++;
                }
            }
        }

        return $removed;
    }

    /**
     * Уведомление администратору о результате фоновой задачи.
     *
     * Воркер работает вне HTTP-сессии, а прямо писать в чужую сессию
     * небезопасно и несовместимо с драйвером redis. Поэтому результат
     * складывается в простой JSON-файл storage/import/notifications.json
     * (ключ — user id). Админ-панель может показать его при следующем
     * визите; до интеграции UI уведомления остаются в этом файле.
     */
    private function notify(int $userId, string $type, string $message): void
    {
        if ($userId <= 0) {
            return;
        }

        $dir = $this->config->getStoragePath('import');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $file = $dir . DIRECTORY_SEPARATOR . 'notifications.json';

        $handle = @fopen($file, 'c+b');
        if ($handle === false) {
            return;
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return;
        }

        $data = '';
        while (($chunk = fread($handle, 65536)) !== false) {
            $data .= $chunk;
        }
        $store = json_decode((string) $data, true);
        if (!is_array($store)) {
            $store = [];
        }

        $entry = [
            'type' => $type,
            'message' => $message,
            'at' => date('Y-m-d H:i:s'),
        ];
        $store[(string) $userId][] = $entry;
        // Не копить бесконечно: храним последние 20 уведомлений на пользователя.
        $store[(string) $userId] = array_slice($store[(string) $userId], -20);

        ftruncate($handle, 0);
        rewind($handle);
        fwrite(
            $handle,
            (string) json_encode($store, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
