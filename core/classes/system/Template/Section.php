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


namespace Core\System\Template;

/**
 * Класс для управления секциями (@section/@yield)
 */
class Section
{
    private string $name;
    private string $content = '';
    private bool $started = false;
    private bool $overwrite = true;
    private ?Section $parent = null;

    public function __construct(string $name, bool $overwrite = true)
    {
        $this->name = $name;
        $this->overwrite = $overwrite;
    }

    /**
     * Начать захват секции
     */
    public function start(): void
    {
        $this->started = true;
        ob_start();
    }

    /**
     * Завершить захват секции
     */
    public function end(): string
    {
        if (!$this->started) {
            return '';
        }

        $this->content = ob_get_clean();
        $this->started = false;

        return $this->content;
    }

    /**
     * Установить содержимое напрямую
     */
    public function setContent(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    /**
     * Установить родительскую секцию (для @parent)
     */
    public function setParent(?Section $parent): self
    {
        $this->parent = $parent;
        return $this;
    }

    /**
     * Получить содержимое с учетом @parent
     */
    public function getWithParent(): string
    {
        if ($this->parent !== null && str_contains($this->content, '@parent')) {
            return str_replace('@parent', $this->parent->getContent(), $this->content);
        }

        return $this->content;
    }

    public function isEmpty(): bool
    {
        return trim($this->content) === '';
    }
}