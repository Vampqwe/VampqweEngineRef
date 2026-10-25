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

/**
 * Контракт модуля парсеров: любой формат (HTML, SVG, XML, .env)
 * приводится к строковому содержимому и возвращает массив значений.
 */
interface ParserInterface
{
    /**
     * Разобрать содержимое формата и вернуть ассоциативный массив
     * извлечённых значений (структура определяется конкретным парсером).
     * Аргумент — строка либо результат parseFile() (ключ 'content').
     *
     * @param string|array<string,mixed> $content
     * @return array<string,mixed>
     */
    public function parse(string|array $content): array;

    /**
     * Прочитать файл и разобрать его содержимое.
     *
     * @return array<string,mixed>
     */
    public function parseFile(string $path): array;

    /**
     * Каноническое имя формата ('html', 'svg', 'env').
     */
    public function format(): string;
}
