<?php
declare(strict_types=1);

namespace Core\Module\Admin\Controllers;

use Core\Module\Admin\Services\SchemaService;
use Core\Module\Admin\Services\AdminService;
use Core\System\Http\Request;
use Core\System\Http\Response;
use Core\System\Http\StatusCode;
use Core\System\Security\SecurityManager;
use Core\System\Template\Template;

class SchemaController
{
    public function __construct(
        private SchemaService $schemaService,
        private AdminService $adminService,
        private Template $template,
        private SecurityManager $security
    ) {}

    public function index(Request $request): Response
    {
        $page = max(1, (int) $request->input('page', 1));
        $data = $this->schemaService->getAllSchemas($page, 20);
        
        return Response::html($this->template->render('admin/schema/index', [
            'title' => 'Управление Schema.org',
            'schemas' => $data['schemas'],
            'pagination' => [
                'page' => $data['page'],
                'totalPages' => $data['totalPages'],
                'total' => $data['total'],
            ],
            'flash' => $this->adminService->getFlash(),
            'csrf_token' => $this->security->getCsrfToken()->getToken(),
        ]));
    }

    public function create(Request $request): Response
    {
        return Response::html($this->template->render('admin/schema/edit', [
            'title' => 'Добавить схему',
            'schema' => null,
            'pages' => $this->schemaService->getPagesForSelect(),
            'types' => $this->schemaService->getAllowedTypes(),
            'flash' => $this->adminService->getFlash(),
            'csrf_token' => $this->security->getCsrfToken()->getToken(),
        ]));
    }

    public function store(Request $request): Response
    {
        $result = $this->schemaService->saveSchema(null, $request->allPost());
        if (!$result['ok']) {
            $this->adminService->setFlash('error', implode(' ', $result['errors']));
            return Response::redirect('/admin/schema/new');
        }
        $this->adminService->setFlash('success', 'Схема успешно создана');
        return Response::redirect('/admin/schema');
    }

    public function edit(Request $request): Response
    {
        $id = (int) $request->getAttribute('id');
        $schema = $this->schemaService->getSchema($id);
        
        if (!$schema) {
            $this->adminService->setFlash('error', 'Схема не найдена');
            return Response::redirect('/admin/schema');
        }

        return Response::html($this->template->render('admin/schema/edit', [
            'title' => 'Редактирование схемы',
            'schema' => $schema,
            'pages' => $this->schemaService->getPagesForSelect(),
            'types' => $this->schemaService->getAllowedTypes(),
            'flash' => $this->adminService->getFlash(),
            'csrf_token' => $this->security->getCsrfToken()->getToken(),
        ]));
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->getAttribute('id');
        $result = $this->schemaService->saveSchema($id, $request->allPost());
        
        if (!$result['ok']) {
            $this->adminService->setFlash('error', implode(' ', $result['errors']));
            return Response::redirect('/admin/schema/' . $id . '/edit');
        }
        $this->adminService->setFlash('success', 'Схема успешно обновлена');
        return Response::redirect('/admin/schema');
    }

    public function destroy(Request $request): Response
    {
        $id = (int) $request->getAttribute('id');
        $result = $this->schemaService->deleteSchema($id);
        
        if (!$result['ok']) {
            $this->adminService->setFlash('error', implode(' ', $result['errors']));
        } else {
            $this->adminService->setFlash('success', 'Схема удалена');
        }
        return Response::redirect('/admin/schema');
    }
}