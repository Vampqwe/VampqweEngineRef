<?php $__template->setLayout('admin/layouts/admin'); ?>

<?php $__template->startSection('page_title', ); ?>
Управление Schema.org
<?php $__template->endSection('page_title'); ?>

<?php $__template->startSection('page_subtitle', ); ?>
Глобальные и привязанные к страницам разметки
<?php $__template->endSection('page_subtitle'); ?>

<?php $__template->startSection('content', ); ?>
<div class="header-actions" style="margin-bottom: 1.5rem;">
    <a href="/admin/schema/new" class="btn btn-primary">➕ Добавить схему</a>
    <a href="/admin/pages" class="btn btn-secondary">← К страницам</a>
</div>

<?php if (!empty($flash['error'])): ?>
    <div class="flash-message flash-error"><?php echo htmlspecialchars((string) ($flash['error']), ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>
<?php if (!empty($flash['success'])): ?>
    <div class="flash-message flash-success"><?php echo htmlspecialchars((string) ($flash['success']), ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-body" style="padding: 0;">
        <table class="table">
            <thead>
                <tr>
                    <th>Тип схемы</th>
                    <th>Привязка</th>
                    <th>Приоритет</th>
                    <th>Статус</th>
                    <th style="width: 150px;">Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($schemas as $schema): ?>
                <tr>
                    <td>
                        <span class="badge badge-info" style="background: var(--teal-dark); color: var(--teal-light);">
                            <?php echo htmlspecialchars((string) ($schema['schema_type']), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($schema['page_id']): ?>
                            📄 <a href="/admin/pages/<?php echo htmlspecialchars((string) ($schema['page_id']), ENT_QUOTES, 'UTF-8'); ?>/edit" style="color: var(--teal-light);">
                                <?php echo htmlspecialchars((string) ($schema['page_title']), ENT_QUOTES, 'UTF-8'); ?> #<?php echo htmlspecialchars((string) ($schema['page_id']), ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        <?php elseif ($schema['route']): ?>
                            🌐 Маршрут: <code><?php echo htmlspecialchars((string) ($schema['route']), ENT_QUOTES, 'UTF-8'); ?></code>
                        <?php else: ?>
                            🌍 Глобальная (везде)
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars((string) ($schema['priority']), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>
                        <?php if ($schema['is_active']): ?>
                            <span class="badge badge-success">Активна</span>
                        <?php else: ?>
                            <span class="badge badge-warning">Отключена</span>
                        <?php endif; ?>
                    </td>
                    <td class="action-buttons">
                        <a href="/admin/schema/<?php echo htmlspecialchars((string) ($schema['id']), ENT_QUOTES, 'UTF-8'); ?>/edit" class="btn btn-sm btn-secondary">✏️</a>
                        <form method="POST" action="/admin/schema/<?php echo htmlspecialchars((string) ($schema['id']), ENT_QUOTES, 'UTF-8'); ?>/delete" style="display:inline;" onsubmit="return confirm('Удалить эту схему?');">
                            <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars((string) ($csrf_token), ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" class="btn btn-sm btn-danger">🗑️</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($schemas)): ?>
                <tr>
                    <td colspan="5" style="text-align: center; padding: 2rem; color: var(--silver);">
                        Схемы пока не добавлены.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($pagination['totalPages'] > 1): ?>
<div class="pagination">
    <?php if ($pagination['page'] > 1): ?>
        <a href="?page=<?php echo htmlspecialchars((string) ($pagination['page'] - 1), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-secondary">← Назад</a>
    <?php endif; ?>
    <span>Страница <?php echo htmlspecialchars((string) ($pagination['page']), ENT_QUOTES, 'UTF-8'); ?> из <?php echo htmlspecialchars((string) ($pagination['totalPages']), ENT_QUOTES, 'UTF-8'); ?></span>
    <?php if ($pagination['page'] < $pagination['totalPages']): ?>
        <a href="?page=<?php echo htmlspecialchars((string) ($pagination['page'] + 1), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-secondary">Вперёд →</a>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php $__template->endSection('content'); ?>