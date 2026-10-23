<?php $__template->setLayout('admin/layouts/admin'); ?>

<?php $__template->startSection('page_title', ); ?>
Страницы
<?php $__template->endSection('page_title'); ?>

<?php $__template->startSection('page_subtitle', ); ?>
Управление контентом сайта
<?php $__template->endSection('page_subtitle'); ?>

<?php $__template->startSection('content', ); ?>
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Все страницы (<?php echo htmlspecialchars((string) ($pagination['total']), ENT_QUOTES, 'UTF-8'); ?>)</h3>
        <a href="/admin/pages/new" class="btn btn-primary">➕ Новая страница</a>
    </div>
    <div class="card-body">
        <?php if (!empty($flash['error'])): ?>
        <div class="flash-message flash-error"><?php echo htmlspecialchars((string) ($flash['error']), ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if (!empty($flash['success'])): ?>
        <div class="flash-message flash-success"><?php echo htmlspecialchars((string) ($flash['success']), ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <table class="table">
            <thead>
                <tr>
                    <th>Заголовок</th>
                    <th>Slug</th>
                    <th>Автор</th>
                    <th>Статус</th>
                    <th>Дата изменения</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pages as $page): ?>
                <tr>
                    <td><?php echo htmlspecialchars((string) ($page['title']), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><code><?php echo htmlspecialchars((string) ($page['slug']), ENT_QUOTES, 'UTF-8'); ?></code></td>
                    <td><?php echo htmlspecialchars((string) ($page['author_name'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>
                        <?php if ($page['status'] == 'published'): ?>
                        <span class="badge badge-success">Опубликовано</span>
                        <?php elseif ($page['status'] == 'draft'): ?>
                        <span class="badge badge-warning">Черновик</span>
                        <?php else: ?>
                        <span class="badge badge-danger">Архив</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars((string) ($page['updated_at']), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>
                        <div class="action-buttons">
                            <a href="/admin/pages/<?php echo htmlspecialchars((string) ($page['id']), ENT_QUOTES, 'UTF-8'); ?>/edit" class="btn btn-sm btn-primary">✏️</a>
                            <form method="POST" action="/admin/pages/<?php echo htmlspecialchars((string) ($page['id']), ENT_QUOTES, 'UTF-8'); ?>/delete" style="display: inline;" onsubmit="return confirm('Удалить страницу?');">
                                <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars((string) ($csrf_token), ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="submit" class="btn btn-sm btn-danger">🗑️</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($pagination['totalPages'] > 1): ?>
        <div class="pagination">
            <?php if ($pagination['page'] > 1): ?>
            <a href="?page=<?php echo htmlspecialchars((string) ($pagination['page'] - 1), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-secondary">← Назад</a>
            <?php endif; ?>
            <span>Страница <?php echo htmlspecialchars((string) ($pagination['page']), ENT_QUOTES, 'UTF-8'); ?> из <?php echo htmlspecialchars((string) ($pagination['totalPages']), ENT_QUOTES, 'UTF-8'); ?></span>
            <?php if ($pagination['page'] < $pagination['totalPages']): ?>
            <a href="?page=<?php echo htmlspecialchars((string) ($pagination['page'] + 1), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-secondary">Вперёд →</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php $__template->endSection('content'); ?>