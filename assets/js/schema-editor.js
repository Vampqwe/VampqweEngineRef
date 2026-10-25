/**
 * VampqweEngineMedium — проприетарный PHP-движок
 * Редактор Schema.org разметок страницы.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const editor = document.getElementById('schema-editor');
        if (!editor) return;

        const initDataEl = document.getElementById('schema-data-init');
        if (!initDataEl) return;

        // ─── Данные ───
        let init = { schemas: [], allowed_types: [], taken_types: {}, page_id: 0 };
        try { init = JSON.parse(initDataEl.textContent || 'null') || init; } catch (e) {}

        let schemas = init.schemas;
        const allowedTypes = init.allowed_types;
        let takenTypes = init.taken_types;
        const pageId = init.page_id;

        // ─── DOM-элементы ───
        const listEl = document.getElementById('schema-list');
        const countEl = document.getElementById('schema-count');
        const statusEl = document.getElementById('schema-status');
        const formWrapper = document.getElementById('schema-form-wrapper');
        const formTitle = document.getElementById('schema-form-title');
        const formMsg = document.getElementById('schema-form-msg');

        const typeSelect = document.getElementById('schema-type');
        const priorityInput = document.getElementById('schema-priority');
        const dataTextarea = document.getElementById('schema-data');
        const activeCheckbox = document.getElementById('schema-active');
        const schemaIdInput = document.getElementById('schema-id');

        const btnAdd = document.getElementById('btn-add-schema');
        const btnCancel = document.getElementById('btn-cancel-schema');
        const btnSave = document.getElementById('btn-save-schema');

        // ─── Утилиты ───
        function escapeHtml(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        function setStatus(msg, isError) {
            statusEl.textContent = msg;
            statusEl.className = 'schema-status' + (isError ? ' is-error' : '');
            if (msg) setTimeout(function () { statusEl.textContent = ''; }, 4000);
        }

        function setFormMsg(msg, isError) {
            formMsg.textContent = msg;
            formMsg.className = 'schema-form-msg' + (isError ? ' err' : ' ok');
        }

        function getCsrfHeaders() {
            const csrfMeta = document.querySelector('meta[name="csrf-token"]');
            const headers = { 'Content-Type': 'application/json; charset=UTF-8' };
            if (csrfMeta) {
                headers['X-CSRF-Token'] = csrfMeta.getAttribute('content') || '';
            }
            return headers;
        }

        async function postJson(url, body) {
            const resp = await fetch(url, {
                method: 'POST',
                headers: getCsrfHeaders(),
                body: JSON.stringify(body)
            });
            let json;
            try { json = await resp.json(); }
            catch (e) { throw new Error('Сервер вернул некорректный ответ (' + resp.status + ')'); }
            if (!json.ok) {
                const errs = (json.errors && json.errors.length) ? json.errors.join(' ') : 'Неизвестная ошибка';
                throw new Error(errs);
            }
            return json;
        }

        // ─── Рендер списка схем ───
        function render() {
            listEl.innerHTML = '';
            countEl.textContent = schemas.length + ' шт.';

            if (schemas.length === 0) {
                listEl.innerHTML = '<div class="schema-empty" id="schema-empty"><p>📭 Схемы пока не добавлены.</p><p class="schema-hint">Нажмите «➕ Добавить схему», чтобы привязать разметку Schema.org к этой странице.</p></div>';
                return;
            }

            schemas.forEach(function (schema) {
                const card = document.createElement('div');
                card.className = 'schema-card';
                card.dataset.schemaId = schema.id;

                const jsonPreview = escapeHtml(JSON.stringify(schema.data, null, 2));
                const isActiveBadge = schema.is_active 
                    ? '<span class="badge badge-success">активна</span>' 
                    : '<span class="badge badge-warning">отключена</span>';

                card.innerHTML =
                    '<div class="schema-card-header">' +
                        '<div class="schema-card-title">' +
                            '<span class="schema-type-badge">' + escapeHtml(schema.schema_type) + '</span>' +
                            isActiveBadge +
                            ' <span class="schema-priority">приоритет: ' + schema.priority + '</span>' +
                        '</div>' +
                        '<div class="schema-card-actions">' +
                            '<button type="button" class="btn btn-secondary btn-sm btn-edit-schema" data-schema-id="' + schema.id + '" title="Редактировать">✏️</button>' +
                            '<button type="button" class="btn btn-danger btn-sm btn-delete-schema" data-schema-id="' + schema.id + '" title="Удалить">🗑️</button>' +
                        '</div>' +
                    '</div>' +
                    '<div class="schema-card-preview">' +
                        '<pre class="schema-json-preview">' + jsonPreview + '</pre>' +
                    '</div>';

                card.querySelector('.btn-edit-schema').addEventListener('click', function () { openEditor(schema.id); });
                card.querySelector('.btn-delete-schema').addEventListener('click', function () { deleteSchema(schema.id, schema.schema_type); });
                listEl.appendChild(card);
            });
        }

        // ─── Обновление select типов (блокировка занятых) ───
        function refreshTypeSelect(excludeSchemaId) {
            const current = typeSelect.value;
            typeSelect.innerHTML = '<option value="">— выберите тип —</option>';

            allowedTypes.forEach(function (type) {
                const opt = document.createElement('option');
                opt.value = type;
                opt.textContent = type;

                if (takenTypes[type] && takenTypes[type] !== excludeSchemaId) {
                    opt.disabled = true;
                    opt.textContent = type + ' (уже добавлена)';
                }
                typeSelect.appendChild(opt);
            });

            if (current && typeSelect.querySelector('option[value="' + current + '"]:not([disabled])')) {
                typeSelect.value = current;
            } else {
                typeSelect.value = '';
            }
        }

        // ─── Открытие/закрытие формы ───
        function openEditor(schemaId) {
            schemaIdInput.value = schemaId || '';
            formWrapper.style.display = 'block';

            if (schemaId) {
                const schema = schemas.find(function (s) { return s.id === schemaId; });
                if (!schema) { setFormMsg('Схема не найдена.', true); return; }
                formTitle.textContent = 'Редактирование: ' + schema.schema_type;
                typeSelect.value = schema.schema_type;
                priorityInput.value = schema.priority;
                dataTextarea.value = JSON.stringify(schema.data, null, 2);
                activeCheckbox.checked = !!schema.is_active;
                refreshTypeSelect(schemaId);
            } else {
                formTitle.textContent = 'Новая схема';
                typeSelect.value = '';
                priorityInput.value = 10;
                dataTextarea.value = '';
                activeCheckbox.checked = true;
                refreshTypeSelect(null);
            }
            setFormMsg('');
            formWrapper.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            setTimeout(function () { typeSelect.focus(); }, 100);
        }

        function closeEditor() {
            formWrapper.style.display = 'none';
            setFormMsg('');
        }

        // ─── Валидация JSON на клиенте ───
        function validateJson(str) {
            if (!str || !str.trim()) return { ok: false, error: 'Данные схемы не могут быть пустыми.' };
            try {
                const parsed = JSON.parse(str);
                if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
                    return { ok: false, error: 'Данные должны быть JSON-объектом (не массивом).' };
                }
                if (Object.keys(parsed).length === 0) return { ok: false, error: 'Объект не может быть пустым.' };
                return { ok: true, data: parsed };
            } catch (e) {
                return { ok: false, error: 'Невалидный JSON: ' + e.message };
            }
        }

        // ─── Сохранение (create/update) ───
        async function saveSchema() {
            const schemaId = schemaIdInput.value ? parseInt(schemaIdInput.value, 10) : null;
            const type = typeSelect.value.trim();
            const priority = parseInt(priorityInput.value, 10) || 10;
            const isActive = activeCheckbox.checked ? 1 : 0;
            const jsonValidation = validateJson(dataTextarea.value);

            if (!type) { setFormMsg('Выберите тип схемы.', true); return; }
            if (!jsonValidation.ok) { setFormMsg(jsonValidation.error, true); dataTextarea.focus(); return; }
            if (priority < 0 || priority > 1000) { setFormMsg('Приоритет должен быть в диапазоне 0–1000.', true); return; }

            const body = { schema_type: type, data: jsonValidation.data, priority: priority, is_active: isActive };
            const url = schemaId ? '/admin/pages/' + pageId + '/schemas/' + schemaId : '/admin/pages/' + pageId + '/schemas';

            btnSave.disabled = true;
            try {
                const res = await postJson(url, body);
                takenTypes = res.taken_types;

                if (schemaId) {
                    const idx = schemas.findIndex(function (s) { return s.id === schemaId; });
                    if (idx !== -1) schemas[idx] = res.schema;
                    setStatus('Схема обновлена ✓');
                } else {
                    schemas.push(res.schema);
                    setStatus('Схема добавлена ✓');
                }
                render();
                closeEditor();
            } catch (err) {
                setFormMsg('Ошибка: ' + err.message, true);
            } finally {
                btnSave.disabled = false;
            }
        }

        // ─── Удаление ───
        async function deleteSchema(schemaId, schemaType) {
            if (!confirm('Удалить схему типа «' + schemaType + '»?')) return;

            try {
                const res = await postJson('/admin/pages/' + pageId + '/schemas/' + schemaId + '/delete', {});
                takenTypes = res.taken_types;
                schemas = schemas.filter(function (s) { return s.id !== schemaId; });
                render();
                setStatus('Схема удалена ✓');
                if (schemaIdInput.value === String(schemaId)) closeEditor();
            } catch (err) {
                setStatus('Ошибка: ' + err.message, true);
            }
        }

        // ─── Обработчики событий ───
        btnAdd.addEventListener('click', function () {
            if (Object.keys(takenTypes).length >= allowedTypes.length) {
                setStatus('Все доступные типы схем уже добавлены к этой странице.', true);
                return;
            }
            openEditor(null);
        });
        btnCancel.addEventListener('click', closeEditor);
        btnSave.addEventListener('click', saveSchema);

        // ─── Старт ───
        render();
    });
})();