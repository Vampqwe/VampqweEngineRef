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
 */

namespace Core\System\Session;

/**
 * Контрат хранилища данных сессии.
 *
 * Реализации: FileSessionStorage (режим «files», поведение по умолчанию) и
 * RedisSessionStorage (режим «redis»). Интерфейс намеренно минимален:
 * сериализацию/десериализуацию выполняет вызывающий код, хранилище оперирует
 * только строковыми значениями с TTL.
 */
interface SessionStorageInterface
{
    /** Прочитать сырое (сериализованное) значение сессии или null, если записи нет. */
    public function read(string $id): ?string;

    /** Записать значение сессии с истечением через $maxLifetime секунд. */
    public function write(string $id, string $data, int $maxLifetime): void;

    /** Полностью удалить запись сессии. */
    public function destroy(string $id): void;

    /** Существовала ли запись на момент проверки. */
    public function exists(string $id): bool;

    /** Символьное имя драйвера («files» / «redis»). */
    public function getDriverName(): string;
}
