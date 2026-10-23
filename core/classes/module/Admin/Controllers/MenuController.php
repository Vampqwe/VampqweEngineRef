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


namespace Core\Module\Admin\Controllers;

use Core\Module\Admin\Services\MenuAdminService;
use Core\System\Http\Request;
use Core\System\Http\Response;
use Core\System\Http\StatusCode;
use Core\System\Security\SecurityManager;
use Core\System\Template\Template;

/**
 * Контроллер drag-and-drop редактора меню в админ-панели.
 *
 * GET  /admin/menu            — страница редактора
 * POST /admin/menu/items      — создать пункт
 * POST /admin/menu/items/{id} — обновить поля пункта
 * POST /admin/menu/items/{id}/delete — удалить пункт (с потомками)
 * POST /admin/menu/structure  — пакетно сохранить структуру (порядок/вложенность)
 */
class MenuController
{
    private MenuAdminService $menuService;
    private Template $template;

    public function __construct(
        MenuAdminService $menuService,
        Template $template,
        private SecurityManager $security
    ) {
        $this->menuService = $menuService;
        $this->template = $template;
    }

    public function index(Request $request): Response
    {
        $html = $this->template->render('admin/menu/index', [
            'title'      => 'Меню сайта',
            // CSRF-токен для fetch-запросов menu-editor.js (meta-тег + заголовок X-CSRF-Token)
            'csrf_meta'  => $this->security->getCsrfToken()->getMetaTag(),
            'menu_json'  => json_encode(
                $this->menuService->getTreeForUi(),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'pages_json' => json_encode(
                $this->menuService->getPageSuggestions(),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
        ]);

        return Response::html($html);
    }

    public function store(Request $request): Response
    {
        $result = $this->menuService->createItem($this->itemPayload($request));
        return $this->jsonResult($result, $result['ok'] ? StatusCode::CREATED : StatusCode::BAD_REQUEST);
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->getAttribute('id');
        $result = $this->menuService->updateItem($id, $this->itemPayload($request));
        return $this->jsonResult($result, $result['ok'] ? StatusCode::OK : StatusCode::BAD_REQUEST);
    }

    public function destroy(Request $request): Response
    {
        $id = (int) $request->getAttribute('id');
        $result = $this->menuService->deleteItem($id);
        return $this->jsonResult($result, $result['ok'] ? StatusCode::OK : StatusCode::NOT_FOUND);
    }

    public function structure(Request $request): Response
    {
        $raw = $request->post('structure', '');
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        if (!is_array($decoded)) {
            return Response::json([
                'ok'     => false,
                'errors' => ['Некорректный JSON структуры меню.'],
            ], StatusCode::BAD_REQUEST);
        }

        $result = $this->menuService->saveStructure($decoded);
        return $this->jsonResult($result, $result['ok'] ? StatusCode::OK : StatusCode::BAD_REQUEST);
    }

    /**
     * Собрать данные пункта из запроса (JSON-тело или обычные поля формы)
     *
     * @return array<string, mixed>
     */
    private function itemPayload(Request $request): array
    {
        $body = $request->allPost();

        // AJAX может прислать одним полем item={...}
        if (isset($body['item']) && is_string($body['item'])) {
            $decoded = json_decode($body['item'], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $flags = [];
        foreach (['is_visible', 'is_external'] as $flag) {
            $val = $body[$flag] ?? null;
            $flags[$flag] = in_array($val, ['1', 'on', 'true', 1, true], true);
        }

        return array_merge($body, $flags);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function jsonResult(array $result, StatusCode $status): Response
    {
        return Response::json($result, $status, JSON_UNESCAPED_UNICODE);
    }
}
