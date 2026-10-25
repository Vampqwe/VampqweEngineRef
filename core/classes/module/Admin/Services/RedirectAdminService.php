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


namespace Core\Module\Admin\Services;

use Core\System\DataBase\DataBase;

/**
 * Сервис административного CRUD по таблице `redirects`.
 *
 * Рантайм-разыменование делает Core\Module\Page\RedirectService —
 * здесь только управление записями (список/поиск/пагинация/создание/
 * обновление/удаление) с серверной валидацией путей и лимитом записей.
 */
class RedirectAdminService
{
    /** Жёсткий потолок числа редиректов (защита от раздувания таблицы). */
    public const MAX_REDIRECTS = 5000;

    /** Допустимые HTTP-коды редиректа. */
    private const ALLOWED_STATUS_CODES = [301, 302];

    public function __construct(private DataBase $db)
    {
    }

    /**
     * Список редиректов с поиском и пагинацией.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(string $search = '', int $page = 1, int $perPage = 50): array
    {
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 200));
        $search = trim($search);

        $where = '';
        $params = [];
        if ($search !== '') {
            $where = "WHERE (source LIKE :s1 OR destination LIKE :s2)";
            $like = '%' . $this->escapeLike($search) . '%';
            $params = ['s1' => $like, 's2' => $like];
        }

        $total = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM redirects {$where}", $params);

        $offset = ($page - 1) * $perPage;
        // LIMIT/OFFSET — целые после clamp выше, конкатенация безопасна.
        $items = $this->db->fetchAll(
            "SELECT id, source, destination, status_code, is_active, hit_count, note, created_at, updated_at
             FROM redirects {$where}
             ORDER BY source ASC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT id, source, destination, status_code, is_active, hit_count, note
             FROM redirects WHERE id = :id",
            ['id' => $id]
        );
    }

    /**
     * Создать редирект.
     *
     * @return array{ok: bool, error?: string, id?: int}
     */
    public function create(
        string $source,
        string $destination,
        int $statusCode = 301,
        bool $isActive = true,
        ?string $note = null
    ): array {
        $count = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM redirects");
        if ($count >= self::MAX_REDIRECTS) {
            return ['ok' => false, 'error' => 'Достигнут лимит редиректов (' . self::MAX_REDIRECTS . ').'];
        }

        $validated = $this->validate($source, $destination, $statusCode);
        if (!$validated['ok']) {
            return $validated;
        }

        $source = $this->normalizePath($source);
        $destination = $this->normalizeDestination($destination);

        try {
            $id = $this->db->insert(
                "INSERT INTO redirects (source, destination, status_code, is_active, note)
                 VALUES (:source, :destination, :status_code, :is_active, :note)",
                [
                    'source' => $source,
                    'destination' => $destination,
                    'status_code' => $statusCode,
                    'is_active' => $isActive ? 1 : 0,
                    'note' => $this->sanitizeNote($note),
                ]
            );
        } catch (\Throwable $e) {
            if ($this->isDuplicate($e)) {
                return ['ok' => false, 'error' => "Редирект для «{$source}» уже существует."];
            }
            throw $e;
        }

        return ['ok' => true, 'id' => (int) $id];
    }

    /**
     * Обновить редирект (все поля перезаписываются).
     *
     * @return array{ok: bool, error?: string}
     */
    public function update(
        int $id,
        string $source,
        string $destination,
        int $statusCode,
        bool $isActive,
        ?string $note = null
    ): array {
        if ($this->find($id) === null) {
            return ['ok' => false, 'error' => 'Редирект не найден.'];
        }

        $validated = $this->validate($source, $destination, $statusCode);
        if (!$validated['ok']) {
            return $validated;
        }

        $source = $this->normalizePath($source);
        $destination = $this->normalizeDestination($destination);

        try {
            $this->db->execute(
                "UPDATE redirects
                 SET source = :source, destination = :destination, status_code = :status_code,
                     is_active = :is_active, note = :note
                 WHERE id = :id",
                [
                    'source' => $source,
                    'destination' => $destination,
                    'status_code' => $statusCode,
                    'is_active' => $isActive ? 1 : 0,
                    'note' => $this->sanitizeNote($note),
                    'id' => $id,
                ]
            );
        } catch (\Throwable $e) {
            if ($this->isDuplicate($e)) {
                return ['ok' => false, 'error' => "Редирект для «{$source}» уже существует."];
            }
            throw $e;
        }

        return ['ok' => true];
    }

    public function delete(int $id): bool
    {
        return $this->db->execute("DELETE FROM redirects WHERE id = :id", ['id' => $id]) > 0;
    }

    /**
     * Сброс счётчика срабатываний.
     */
    public function resetHits(int $id): bool
    {
        if ($this->find($id) === null) {
            return false;
        }
        $this->db->execute("UPDATE redirects SET hit_count = 0 WHERE id = :id", ['id' => $id]);
        return true;
    }

    // ─── Валидация ────────────────────────────────────────────────

    /**
     * @return array{ok: bool, error?: string}
     */
    private function validate(string $source, string $destination, int $statusCode): array
    {
        $source = trim($source);
        $destination = trim($destination);

        if ($source === '' || mb_strlen($source) > 500) {
            return ['ok' => false, 'error' => 'Источник: непустая строка до 500 символов.'];
        }
        if ($destination === '' || mb_strlen($destination) > 500) {
            return ['ok' => false, 'error' => 'Назначение: непустая строка до 500 символов.'];
        }
        if (!in_array($statusCode, self::ALLOWED_STATUS_CODES, true)) {
            return ['ok' => false, 'error' => 'Допустимые коды: 301 или 302.'];
        }

        // Источник — только относительный путь сайта (защита от open-redirect
        // на уровне данных и от схем вида javascript:/data:).
        if (!$this->isRelativePath($source)) {
            return ['ok' => false, 'error' => 'Источник должен быть относительным путём, начинающимся с «/».'];
        }

        // Назначение — относительный путь либо http(s)-URL.
        if (!$this->isRelativePath($destination) && !$this->isHttpUrl($destination)) {
            return ['ok' => false, 'error' => 'Назначение: относительный путь («/…») или URL http(s).'];
        }

        if ($this->normalizePath($source) === $this->normalizeDestination($destination)) {
            return ['ok' => false, 'error' => 'Источник и назначение совпадают — цикл редиректа.'];
        }

        return ['ok' => true];
    }

    private function isRelativePath(string $path): bool
    {
        // Одиночный слэш в начале, без протокола/хоста/управляющих символов.
        return preg_match('#^/[^\\\\\x00-\x1F\x7F]*$#D', $path) === 1
            && !str_starts_with($path, '//');
    }

    private function isHttpUrl(string $url): bool
    {
        if (!preg_match('#^https?://#i', $url)) {
            return false;
        }
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /** Нормализация: ведущий слэш, схлопывание двойных, без хвостового (кроме корня). */
    private function normalizePath(string $path): string
    {
        $path = '/' . ltrim(trim($path), '/');
        $path = (string) preg_replace('#/+#', '/', $path);
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }
        return $path;
    }

    private function normalizeDestination(string $dest): string
    {
        if ($this->isRelativePath($dest)) {
            return $this->normalizePath($dest);
        }
        return trim($dest);
    }

    private function sanitizeNote(?string $note): ?string
    {
        $note = trim((string) $note);
        if ($note === '') {
            return null;
        }
        return mb_substr($note, 0, 255);
    }

    /** Экранирование спецсимволов LIKE (% _ \) — поиск трактуется буквально. */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function isDuplicate(\Throwable $e): bool
    {
        return $e instanceof \PDOException
            && ($e->errorInfo[1] ?? null) === 1062; // ER_DUP_ENTRY
    }
}
