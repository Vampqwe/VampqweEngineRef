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

namespace Core\System\Parser;

use Core\System\FileSystem\FileSystemException;
use Core\System\FileSystem\PathProbe;

/**
 * Базовый класс всех парсеров модуля Core\System\Parser.
 *
 * Иерархия: ParserInterface -> AbstractParser -> HtmlParser -> SvgParser ...
 * Наследованием решаются только общие инфраструктурные вещи: накопление
 * предупреждений, чтение файла и нормализация входного содержимого. Вся
 * бизнес-логика конкретного формата живёт в дочернем классе.
 */
abstract class AbstractParser implements ParserInterface
{
    /** @var string[] Предупреждения текущего прогона parse() */
    protected array $warnings = [];

    public function __construct(protected array $rules = [])
    {
    }

    /**
     * {@inheritdoc}
     */
    abstract public function parse(string|array $content): array;

    /**
     * Прочитать файл и разобрать его содержимое этим парсером.
     *
     * @return array<string,mixed>
     */
    public function parseFile(string $path): array
    {
        if (!PathProbe::exists($path) || !PathProbe::isReadable($path)) {
            throw new FileSystemException("Парсер: файл не найден или недоступен для чтения: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new FileSystemException("Парсер: не удалось прочитать файл: {$path}");
        }

        return $this->parse($content);
    }

    /**
     * Предупреждения, собранные за последний вызов parse().
     *
     * @return string[]
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    protected function addWarning(string $message): void
    {
        $this->warnings[] = $message;
    }

    /**
     * Сброс состояния прогона (дочерние классы вызывают в начале parse()).
     */
    protected function resetRunState(): void
    {
        $this->warnings = [];
    }

    /**
     * Нормализация исходного содержимого: переносы строк к \n, снятие BOM.
     */
    protected function normalizeInput(string $content): string
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        return ltrim($content, "\xEF\xBB\xBF");
    }
}
