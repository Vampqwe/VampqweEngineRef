<?php $__template->setLayout('admin/layouts/admin'); ?>

<?php $__template->startSection('page_title', ); ?>
<?= htmlspecialchars($title ?? '', ENT_QUOTES, 'UTF-8') ?>
<?php $__template->endSection('page_title'); ?>

<?php $__template->startSection('page_subtitle', ); ?>
Настройка JSON-LD разметки
<?php $__template->endSection('page_subtitle'); ?>

<?php $__template->startSection('content', ); ?>
<form method="POST" action="<?= $schema ? '/admin/schema/' . $schema['id'] . '/update' : '/admin/schema/store' ?>">
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">

    <?php if (!empty($flash['error'])): ?>
    <div class="flash-message flash-error"><?= htmlspecialchars($flash['error'], ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if (!empty($flash['success'])): ?>
    <div class="flash-message flash-success"><?= htmlspecialchars($flash['success'], ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <!-- ВЕРХНЯЯ ПАНЕЛЬ -->
    <div class="edit-topbar">
        <div class="edit-topbar-actions">
            <a href="/admin/schema" class="btn btn-secondary btn-sm">← Назад к списку</a>
            <button type="submit" class="btn btn-primary btn-sm">💾 Сохранить схему</button>
        </div>
    </div>

    <div class="form-grid">
        <!-- ЛЕВАЯ КОЛОНКА: Основные параметры -->
        <div>
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Основные параметры</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="schema_type">Тип схемы <span class="required">*</span></label>
                        <select id="schema_type" name="schema_type" required>
                            <option value="">— Выберите тип —</option>
                            <?php foreach ($types ?? [] as $type): ?>
                                <option value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>"
                                    <?= (($schema['schema_type'] ?? '') === $type) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Привязка <span class="required">*</span></label>
                        <div style="display: flex; gap: 1.5rem; margin-top: 0.5rem;">
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                <input type="radio" name="bind_type" value="global" <?= empty($schema['page_id']) ? 'checked' : '' ?> onchange="toggleBind(this.value)">
                                Глобальная / По маршруту
                            </label>
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                <input type="radio" name="bind_type" value="page" <?= !empty($schema['page_id']) ? 'checked' : '' ?> onchange="toggleBind(this.value)">
                                Конкретная страница
                            </label>
                        </div>
                    </div>

                    <div class="form-group" id="route-group" style="<?= !empty($schema['page_id']) ? 'display: none;' : '' ?>">
                        <label for="route">Маршрут (например, * или /contacts)</label>
                        <input type="text" id="route" name="route" value="<?= htmlspecialchars($schema['route'] ?? '*', ENT_QUOTES, 'UTF-8') ?>" placeholder="* означает весь сайт">
                        <small>Оставьте *, чтобы схема применялась ко всем страницам.</small>
                    </div>

                    <div class="form-group" id="page-group" style="<?= empty($schema['page_id']) ? 'display: none;' : '' ?>">
                        <label for="page_id">Выберите страницу <span class="required">*</span></label>
                        <select id="page_id" name="page_id">
                            <option value="">— Не выбрано —</option>
                            <?php foreach ($pages ?? [] as $p): ?>
                                <option value="<?= (int)$p['id'] ?>"
                                    <?= (($schema['page_id'] ?? 0) == $p['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($p['slug'] ?? '', ENT_QUOTES, 'UTF-8') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="priority">Приоритет (0–1000)</label>
                        <input type="number" id="priority" name="priority" min="0" max="1000" value="<?= (int)($schema['priority'] ?? 10) ?>">
                        <small>Чем выше число, тем выше схема в коде страницы.</small>
                    </div>

                    <div class="form-group checkbox">
                        <label>
                            <input type="checkbox" name="is_active" value="1" <?= (($schema['is_active'] ?? 1) == 1) ? 'checked' : '' ?>>
                            Активна (выводить на сайте)
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- ПРАВАЯ КОЛОНКА: Данные JSON -->
        <div>
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Данные JSON <span class="required">*</span></h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <?php
                            // Форматируем JSON для отображения в textarea
                            $jsonData = '';
                            if (!empty($schema['data']) && is_array($schema['data'])) {
                                $jsonData = json_encode($schema['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                            }
                        ?>
                        <textarea id="data" name="data" rows="20" style="font-family: 'Consolas', 'Monaco', monospace; font-size: 0.85rem;" placeholder='{
  "name": "Достойный уход",
  "description": "Ветеринарная клиника"
}' required><?= htmlspecialchars($jsonData, ENT_QUOTES, 'UTF-8') ?></textarea>
                        <small>Вводите только содержимое объекта. Поля <code>@context</code> и <code>@type</code> движок добавит автоматически.</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="form-actions">
        <a href="/admin/schema" class="btn btn-secondary">← Отмена</a>
        <button type="submit" class="btn btn-primary">💾 Сохранить схему</button>
    </div>
</form>

<script>
function toggleBind(type) {
    document.getElementById('route-group').style.display = type === 'global' ? 'block' : 'none';
    document.getElementById('page-group').style.display = type === 'page' ? 'block' : 'none';
}
</script>
<?php $__template->endSection('content'); ?>