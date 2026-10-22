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


namespace Core\System\Map;

use ArrayAccess;
use ArrayIterator;
use Closure;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * Обёртка над массивами с богатым функционалом.
 *
 * @template TKey of string|int
 * @template TValue
 * @implements ArrayAccess<TKey, TValue>
 * @implements IteratorAggregate<TKey, TValue>
 */
class Map implements ArrayAccess, IteratorAggregate, Countable, JsonSerializable
{
    /** @var array<TKey, TValue> */
    protected array $items = [];

    /**
     * @param array<TKey, TValue> $items
     */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    // ─── Статические конструкторы ───

    /**
     * @template K of string|int
     * @template V
     * @param array<K, V> $items
     * @return self<K, V>
     */
    public static function make(array $items = []): self
    {
        return new self($items);
    }

    /**
     * @template K of string|int
     * @template V
     * @param array<K> $keys
     * @return self<K, V>
     */
    public static function fromKeys(array $keys, mixed $value = null): self
    {
        return new self(array_fill_keys($keys, $value));
    }

    /**
     * @template K of string|int
     * @template V
     * @param array<K> $keys
     * @param array<V> $values
     * @return self<K, V>
     */
    public static function fromPairs(array $keys, array $values): self
    {
        return new self(array_combine($keys, $values));
    }

    /**
     * @return self<int, int>
     */
    public static function range(int $start, int $end, int $step = 1): self
    {
        return new self(range($start, $end, $step));
    }

    // ─── Базовые операции ───

    public function get(string|int $key, mixed $default = null): mixed
    {
        return $this->items[$key] ?? $default;
    }

    public function getOrFail(string|int $key): mixed
    {
        if (!$this->has($key)) {
            throw MapException::keyNotFound($key);
        }

        return $this->items[$key];
    }

    public function set(string|int $key, mixed $value): self
    {
        $this->items[$key] = $value;
        return $this;
    }

    public function has(string|int $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    public function remove(string|int $key): self
    {
        unset($this->items[$key]);
        return $this;
    }

    public function pull(string|int $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->remove($key);
        return $value;
    }

    public function push(mixed ...$values): self
    {
        foreach ($values as $value) {
            $this->items[] = $value;
        }
        return $this;
    }

    public function unshift(mixed $value): self
    {
        array_unshift($this->items, $value);
        return $this;
    }

    public function pop(): mixed
    {
        if ($this->isEmpty()) {
            throw MapException::emptyCollection('pop');
        }

        return array_pop($this->items);
    }

    public function shift(): mixed
    {
        if ($this->isEmpty()) {
            throw MapException::emptyCollection('shift');
        }

        return array_shift($this->items);
    }

    // ─── Получение данных ───

    /**
     * @return array<TKey, TValue>
     */
    public function all(): array
    {
        return $this->items;
    }

    public function toArray(): array
    {
        return $this->items;
    }

    /**
     * @return self<int, TKey>
     */
    public function keys(): self
    {
        return new self(array_keys($this->items));
    }

    /**
     * @return self<int, TValue>
     */
	public function values(): self
	{
		$values = array_values($this->items);
    
    // Гарантируем, что массив индексирован с 0
		$result = [];
		foreach ($values as $value) {
			$result[] = $value;
    }
    
		return new self($result);
	}
	
	/**
 * Получить массив с гарантированной переиндексацией
 *
 * @return array<int, TValue>
 */
	public function toIndexedArray(): array
	{
		$result = [];
		foreach ($this->items as $value) {
			$result[] = $value;
		}
		return $result;
	}
	
    public function first(?Closure $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            if ($this->isEmpty()) {
                return $default;
            }
            $first = reset($this->items);
            return $first === false ? $default : $first;
        }

        foreach ($this->items as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return $default;
    }

    public function last(?Closure $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            if ($this->isEmpty()) {
                return $default;
            }
            $last = end($this->items);
            return $last === false ? $default : $last;
        }

        return $this->reverse()->first($callback, $default);
    }

    public function nth(int $index, mixed $default = null): mixed
    {
        $keys = array_keys($this->items);

        if ($index < 0) {
            $index = count($keys) + $index;
        }

        return isset($keys[$index]) ? $this->items[$keys[$index]] : $default;
    }

    // ─── Поиск и проверка ───

    public function contains(mixed $value, bool $strict = false): bool
    {
        return in_array($value, $this->items, $strict);
    }

    public function containsWhere(Closure $callback): bool
    {
        foreach ($this->items as $key => $value) {
            if ($callback($value, $key)) {
                return true;
            }
        }

        return false;
    }

    public function search(mixed $value, bool $strict = false): string|int|false
    {
        return array_search($value, $this->items, $strict);
    }

    // ─── Преобразования ───

    /**
     * @template TNewValue
     * @param Closure(TValue, TKey): TNewValue $callback
     * @return self<TKey, TNewValue>
     */
    public function map(Closure $callback): self
    {
        $result = [];

        foreach ($this->items as $key => $value) {
            $result[$key] = $callback($value, $key);
        }

        return new self($result);
    }

    /**
     * @param Closure(TValue, TKey): bool $callback
     * @return self<TKey, TValue>
     */
    public function filter(?Closure $callback = null): self
    {
        if ($callback === null) {
            return new self(array_filter($this->items));
        }

        $result = [];

        foreach ($this->items as $key => $value) {
            if ($callback($value, $key)) {
                $result[$key] = $value;
            }
        }

        return new self($result);
    }

    /**
     * @param Closure(TValue, TKey): bool $callback
     * @return self<TKey, TValue>
     */
    public function reject(Closure $callback): self
    {
        return $this->filter(fn($value, $key) => !$callback($value, $key));
    }

    /**
     * @template TCarry
     * @param Closure(TCarry, TValue, TKey): TCarry $callback
     * @param TCarry $initial
     * @return TCarry
     */
    public function reduce(Closure $callback, mixed $initial = null): mixed
    {
        $carry = $initial;

        foreach ($this->items as $key => $value) {
            $carry = $callback($carry, $value, $key);
        }

        return $carry;
    }

    /**
     * @param Closure(TValue, TKey): void $callback
     */
    public function each(Closure $callback): self
    {
        foreach ($this->items as $key => $value) {
            if ($callback($value, $key) === false) {
                break;
            }
        }

        return $this;
    }

    /**
     * @return self<int, mixed>
     */
    public function flatten(int $depth = PHP_INT_MAX): self
    {
        $result = [];

        $flatten = function (array $items, int $currentDepth) use (&$result, &$flatten, $depth): void {
            foreach ($items as $item) {
                if (is_array($item) && $currentDepth < $depth) {
                    $flatten($item, $currentDepth + 1);
                } else {
                    $result[] = $item;
                }
            }
        };

        $flatten($this->items, 0);

        return new self($result);
    }

    // ─── Сортировка ───

    /**
     * @return self<TKey, TValue>
     */
    public function sort(SortOrder $order = SortOrder::Asc): self
    {
        $items = $this->items;

        $order === SortOrder::Asc
            ? asort($items)
            : arsort($items);

        return new self($items);
    }

    /**
     * @return self<TKey, TValue>
     */
    public function sortByKey(SortOrder $order = SortOrder::Asc): self
    {
        $items = $this->items;

        $order === SortOrder::Asc
            ? ksort($items)
            : krsort($items);

        return new self($items);
    }

    /**
     * @param Closure|string $callback
     * @return self<TKey, TValue>
     */
    public function sortBy(Closure|string $callback, SortOrder $order = SortOrder::Asc): self
    {
        $items = $this->items;

        if (is_string($callback)) {
            $key = $callback;
            $callback = fn($item) => is_array($item) ? ($item[$key] ?? null) : (is_object($item) ? ($item->$key ?? null) : null);
        }

        uasort($items, function ($a, $b) use ($callback, $order) {
            $valA = $callback($a);
            $valB = $callback($b);

            $cmp = $valA <=> $valB;

            return $cmp * $order->multiplier();
        });

        return new self($items);
    }

    /**
     * @return self<TKey, TValue>
     */
    public function shuffle(): self
    {
        $items = $this->items;
        shuffle($items);
        return new self($items);
    }

    /**
     * @return self<TKey, TValue>
     */
    public function reverse(): self
    {
        return new self(array_reverse($this->items, preserve_keys: true));
    }

    // ─── Операции над коллекциями ───

    /**
     * @param self|array<TKey, TValue> $items
     * @return self<TKey, TValue>
     */
    public function merge(self|array $items): self
    {
        $other = $items instanceof self ? $items->all() : $items;
        return new self(array_merge($this->items, $other));
    }

    /**
     * @return self<TKey, TValue>
     */
    public function combine(self|array $values): self
    {
        $vals = $values instanceof self ? $values->all() : $values;
        return new self(array_combine(array_keys($this->items), array_values($vals)));
    }

    /**
     * @return self<TKey, TValue>
     */
    public function intersect(self|array $items): self
    {
        $other = $items instanceof self ? $items->all() : $items;
        return new self(array_intersect($this->items, $other));
    }

    /**
     * @return self<TKey, TValue>
     */
    public function diff(self|array $items): self
    {
        $other = $items instanceof self ? $items->all() : $items;
        return new self(array_diff($this->items, $other));
    }

    /**
     * @return self<TKey, TValue>
     */
    public function unique(?Closure $callback = null): self
    {
        if ($callback === null) {
            return new self(array_unique($this->items));
        }

        $seen = [];
        $result = [];

        foreach ($this->items as $key => $value) {
            $id = $callback($value, $key);

            if (!in_array($id, $seen, true)) {
                $seen[] = $id;
                $result[$key] = $value;
            }
        }

        return new self($result);
    }

    /**
     * @return self<int, self<TKey, TValue>>
     */
    public function chunk(int $size): self
    {
        if ($size <= 0) {
            throw new MapException("Размер чанка должен быть больше нуля");
        }

        $chunks = [];

        foreach (array_chunk($this->items, $size, preserve_keys: true) as $chunk) {
            $chunks[] = new self($chunk);
        }

        return new self($chunks);
    }

    /**
     * @return self<TKey, TValue>
     */
    public function take(int $limit): self
    {
        if ($limit < 0) {
            return $this->slice($limit, abs($limit));
        }

        return $this->slice(0, $limit);
    }

    /**
     * @return self<TKey, TValue>
     */
    public function skip(int $count): self
    {
        return $this->slice($count);
    }

    /**
     * @return self<TKey, TValue>
     */
    public function slice(int $offset, ?int $length = null): self
    {
        return new self(array_slice($this->items, $offset, $length, preserve_keys: true));
    }

    /**
     * @param Closure|string $callback
     * @return self<mixed, self<TKey, TValue>>
     */
    public function groupBy(Closure|string $callback): self
    {
        if (is_string($callback)) {
            $key = $callback;
            $callback = fn($item) => is_array($item) ? ($item[$key] ?? null) : (is_object($item) ? ($item->$key ?? null) : null);
        }

        $result = [];

        foreach ($this->items as $k => $value) {
            $groupKey = $callback($value, $k);
            $result[$groupKey][$k] = $value;
        }

        foreach ($result as $k => $group) {
            $result[$k] = new self($group);
        }

        return new self($result);
    }

    /**
     * @param Closure|string $keyCallback
     * @return self<mixed, TValue>
     */
    public function keyBy(Closure|string $keyCallback): self
    {
        if (is_string($keyCallback)) {
            $key = $keyCallback;
            $keyCallback = fn($item) => is_array($item) ? ($item[$key] ?? null) : (is_object($item) ? ($item->$key ?? null) : null);
        }

        $result = [];

        foreach ($this->items as $value) {
            $newKey = $keyCallback($value);
            $result[$newKey] = $value;
        }

        return new self($result);
    }

    public function random(int $count = 1): mixed
    {
        if ($this->isEmpty()) {
            throw MapException::emptyCollection('random');
        }

        $keys = array_rand($this->items, min($count, $this->count()));

        if ($count === 1) {
            return $this->items[$keys];
        }

        $result = [];
        foreach ((array) $keys as $key) {
            $result[$key] = $this->items[$key];
        }

        return new self($result);
    }

    // ─── Математические операции ───

    public function min(?Closure $callback = null): mixed
    {
        if ($this->isEmpty()) {
            throw MapException::emptyCollection('min');
        }

        if ($callback !== null) {
            return $this->map($callback)->min();
        }

        return min($this->items);
    }

    public function max(?Closure $callback = null): mixed
    {
        if ($this->isEmpty()) {
            throw MapException::emptyCollection('max');
        }

        if ($callback !== null) {
            return $this->map($callback)->max();
        }

        return max($this->items);
    }

    public function sum(Closure|string|null $callback = null): int|float
    {
        if ($callback !== null) {
            return $this->reduce(
                fn($carry, $item) => $carry + (is_string($callback) ? ($item[$callback] ?? 0) : $callback($item)),
                0
            );
        }

        return array_sum($this->items);
    }

    public function avg(Closure|string|null $callback = null): float
    {
        if ($this->isEmpty()) {
            throw MapException::emptyCollection('avg');
        }

        return $this->sum($callback) / $this->count();
    }

    public function median(): float|int
    {
        if ($this->isEmpty()) {
            throw MapException::emptyCollection('median');
        }

        $values = array_values($this->items);
        sort($values);

        $count = count($values);
        $middle = (int) floor($count / 2);

        if ($count % 2 === 0) {
            return ($values[$middle - 1] + $values[$middle]) / 2;
        }

        return $values[$middle];
    }

    // ─── Проверки ───

    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }

    public function count(): int
    {
        return count($this->items);
    }

    // ─── Сериализация ───

	public function toJson(int $options = JSON_UNESCAPED_UNICODE): string
	{
    // Если ключи не последовательные — переиндексируем
		$keys = array_keys($this->items);
		$isSequential = $keys === range(0, count($keys) - 1);
    
		$data = $isSequential ? $this->items : array_values($this->items);
    
		return json_encode($data, $options | JSON_THROW_ON_ERROR);
	}

    public function toString(): string
    {
        return $this->toJson();
    }

    public function jsonSerialize(): array
    {
        return $this->items;
    }

    public function __debugInfo(): array
    {
        return $this->items;
    }

    public function copy(): self
    {
        return new self($this->items);
    }

    // ─── Реализация интерфейсов ───

    public function offsetExists(mixed $offset): bool
    {
        return $this->has($offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->remove($offset);
    }

    /**
     * @return Traversable<TKey, TValue>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}