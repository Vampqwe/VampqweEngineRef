<?php $__template->setLayout('admin/layouts/admin'); ?>

<?php $__template->startSection('page_title', ); ?>
Дашборд
<?php $__template->endSection('page_title'); ?>

<?php $__template->startSection('page_subtitle', ); ?>
Добро пожаловать в панель управления
<?php $__template->endSection('page_subtitle'); ?>

<?php $__template->startSection('content', ); ?>
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-header">
            <div>
                <div class="stat-value"><?php echo htmlspecialchars((string) ($stats['total_pages']), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="stat-label">Всего страниц</div>
            </div>
            <div class="stat-icon blue">📄</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-header">
            <div>
                <div class="stat-value"><?php echo htmlspecialchars((string) ($stats['published_pages']), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="stat-label">Опубликовано</div>
            </div>
            <div class="stat-icon green">✅</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-header">
            <div>
                <div class="stat-value"><?php echo htmlspecialchars((string) ($stats['draft_pages']), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="stat-label">Черновики</div>
            </div>
            <div class="stat-icon silver">📝</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-header">
            <div>
                <div class="stat-value"><?php echo htmlspecialchars((string) ($stats['total_views']), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="stat-label">Просмотров за месяц</div>
            </div>
            <div class="stat-icon blue">👁️</div>
        </div>
    </div>
</div>

<div class="content-grid">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Последние страницы</h3>
            <a href="/admin/pages" class="btn btn-primary">Все страницы</a>
        </div>
        <div class="card-body">
            <table class="table">
                <thead>
                    <tr>
                        <th>Заголовок</th>
                        <th>Статус</th>
                        <th>Дата</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentPages as $page): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string) ($page['title']), ENT_QUOTES, 'UTF-8'); ?></td>
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
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Последняя активность</h3>
        </div>
        <div class="card-body">
            <ul class="activity-list">
                <?php foreach ($recentActivity as $activity): ?>
                <li class="activity-item">
                    <div class="activity-icon" style="background: rgba(26, 107, 107, 0.2); color: var(--teal-light);">
                        📝
                    </div>
                    <div class="activity-content">
                        <div class="activity-title"><?php echo htmlspecialchars((string) ($activity['title']), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="activity-time">Обновлено: <?php echo htmlspecialchars((string) ($activity['updated_at']), ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Быстрые действия</h3>
    </div>
    <div class="card-body">
        <div class="quick-actions">
            <a href="/admin/pages/new" class="btn btn-primary">➕ Новая страница</a>
            <a href="/admin/import" class="btn btn-primary">📥 Импорт страниц</a>
            <a href="/admin/menu" class="btn btn-primary">📋 Редактировать меню</a>
            <a href="/admin/settings" class="btn btn-primary">⚙️ Настройки сайта</a>
        </div>
    </div>
</div>


<?php $__template->endSection('content'); ?>