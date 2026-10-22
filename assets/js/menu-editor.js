/**
 * VampqweEngineMedium — проприетарный PHP-движок
 * Редактор меню (drag-and-drop) для админ-панели.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const editor = document.getElementById('menu-editor');
        if (!editor) return;

        // ─── Данные ───
        let items = normalizeItems(readJson('menu-data', [])); // плоский список {id,parent_id,title,url,...,depth}
		const pages = readJson('pages-data', []); // [{id,title,canonical}]

		// Приведение данных от сервера к единому виду: числовые id/parent_id,
		// parent_id === null для верхнего уровня (0/'0'/'' тоже считаем корнем).
		function normalizeItems(list) {
			return list.map(function (it) {
				const p = it.parent_id;
				return Object.assign({}, it, {
					id: parseInt(it.id, 10),
					parent_id: (p === null || p === undefined || p === '' || parseInt(p, 10) === 0) ? null : parseInt(p, 10)
				});
			});
		}

        const treeEl = document.getElementById('menu-tree');
        const emptyEl = document.getElementById('menu-empty');
        const countEl = document.getElementById('menu-count');
        const statusEl = document.getElementById('menu-save-status');

        let dirty = false;
        let seq = 0;                 // временные ID для новых unsaved-элементов (-1, -2, ...)
        const collapsed = new Set(); // id свёрнутых узлов
        let dragId = null;

        // ─── Утилиты ───
        function readJson(elId, fallback) {
            const el = document.getElementById(elId);
            if (!el) return fallback;
            try { return JSON.parse(el.textContent || 'null') || fallback; }
            catch (e) { console.error('MenuEditor: bad JSON in #' + elId, e); return fallback; }
        }

        function byId(id) {
            for (let i = 0; i < items.length; i++) if (items[i].id === id) return items[i];
            return null;
        }

        function childrenOf(parentId) {
            return items.filter(function (it) { return it.parent_id === parentId; });
        }

        function descendantsIds(id) {
            const out = [];
            (function walk(pid) {
                childrenOf(pid).forEach(function (c) { out.push(c.id); walk(c.id); });
            })(id);
            return out;
        }

        function isUnsaved(it) { return it.id < 0; }

        function setDirty(v) {
            dirty = v;
            renderStatus();
        }

        function renderStatus(msg) {
            if (msg) { statusEl.textContent = msg; return; }
            statusEl.textContent = dirty ? '⚠ Есть несохранённые изменения структуры' : '✓ Все изменения сохранены';
            statusEl.classList.toggle('is-dirty', dirty);
        }

        function escapeHtml(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        async function postForm(url, fields) {
            const body = new URLSearchParams();
            Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });
            // CSRF: токен берётся из meta-тега, который рендерит layout админки.
            var csrfMeta = document.querySelector('meta[name="csrf-token"]');
            var headers = { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' };
            if (csrfMeta) {
                var name = csrfMeta.getAttribute('data-name') || '_csrf_token';
                body.append(name, csrfMeta.getAttribute('content') || '');
                headers['X-CSRF-Token'] = csrfMeta.getAttribute('content') || '';
            }
            const resp = await fetch(url, {
                method: 'POST',
                headers: headers,
                body: body.toString()
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

        // ─── Рендер дерева ───
        function render() {
            treeEl.innerHTML = '';
            const roots = childrenOf(null);
            emptyEl.hidden = items.length > 0;
            countEl.textContent = items.length + ' п.';

            roots.forEach(function (it) { treeEl.appendChild(buildNode(it, 0)); });

            if (dirty) setDirty(true); else renderStatus();
        }

        function buildNode(item, depth) {
            const li = document.createElement('li');
            li.className = 'menu-node' + (item.is_visible ? '' : ' is-hidden-item');
            li.dataset.id = item.id;
            li.draggable = true;

            const kids = childrenOf(item.id);
            const row = document.createElement('div');
            row.className = 'menu-row';
            row.style.marginLeft = (depth * 26) + 'px';

            const caret = document.createElement('button');
            caret.type = 'button';
            caret.className = 'menu-caret' + (kids.length ? '' : ' is-leaf');
            caret.textContent = kids.length ? (collapsed.has(item.id) ? '▸' : '▾') : '';
            if (kids.length) {
                caret.addEventListener('click', function (e) {
                    e.stopPropagation();
                    if (collapsed.has(item.id)) collapsed.delete(item.id); else collapsed.add(item.id);
                    render();
                });
            }

            const handle = document.createElement('span');
            handle.className = 'menu-handle';
            handle.textContent = '⠿';
            handle.title = 'Перетащите, чтобы изменить порядок/вложенность';

            const title = document.createElement('span');
            title.className = 'menu-title-text';
            title.textContent = (item.icon ? item.icon + ' ' : '') + (item.title || '(без названия)');
            title.title = 'Нажмите, чтобы редактировать';
            title.addEventListener('click', function () { openEditor(item.id); });

            const badges = document.createElement('span');
            badges.className = 'menu-badges';
            if (isUnsaved(item)) badges.insertAdjacentHTML('beforeend', '<span class="mbadge mbadge-new">черновик</span>');
            if (!item.is_visible) badges.insertAdjacentHTML('beforeend', '<span class="mbadge mbadge-off">скрыт</span>');
            if (item.is_external) badges.insertAdjacentHTML('beforeend', '<span class="mbadge mbadge-ext">↗ внешняя</span>');
            if (item.url) {
                const u = document.createElement('span');
                u.className = 'menu-url-hint';
                u.textContent = item.url;
                u.title = item.url;
                badges.appendChild(u);
            }

            const actions = document.createElement('span');
            actions.className = 'menu-actions';
            actions.appendChild(mkBtn('＋', 'Добавить подпункт', 'btn-mini', function (e) {
                e.stopPropagation(); addChildOf(item.id);
            }));
            actions.appendChild(mkBtn('✏️', 'Редактировать', 'btn-mini', function (e) {
                e.stopPropagation(); openEditor(item.id);
            }));
            actions.appendChild(mkBtn('✖', 'Удалить пункт и подпункты', 'btn-mini btn-danger-mini', function (e) {
                e.stopPropagation(); removeItem(item.id);
            }));

            row.appendChild(caret);
            row.appendChild(handle);
            row.appendChild(title);
            row.appendChild(badges);
            row.appendChild(actions);
            li.appendChild(row);

            if (kids.length && !collapsed.has(item.id)) {
                const ul = document.createElement('ul');
                ul.className = 'menu-subtree';
                kids.forEach(function (c) { ul.appendChild(buildNode(c, depth + 1)); });
                li.appendChild(ul);
            }

            attachDnd(li);
            return li;
        }

        function mkBtn(text, title, cls, onClick) {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = cls;
            b.textContent = text;
            b.title = title;
            b.addEventListener('click', onClick);
            return b;
        }

        // ─── Drag & Drop ───
        let dropMarker = null;

        function ensureMarker() {
            if (!dropMarker) {
                dropMarker = document.createElement('div');
                dropMarker.className = 'menu-drop-marker';
            }
            return dropMarker;
        }

        function clearDropUI() {
            if (dropMarker && dropMarker.parentNode) dropMarker.parentNode.removeChild(dropMarker);
            treeEl.querySelectorAll('.drag-over-inside').forEach(function (n) { n.classList.remove('drag-over-inside'); });
        }

        function attachDnd(li) {
            li.addEventListener('dragstart', function (e) {
                dragId = parseInt(li.dataset.id, 10);
                e.dataTransfer.effectAllowed = 'move';
                try { e.dataTransfer.setData('text/plain', String(dragId)); } catch (err) { /* IE */ }
                li.classList.add('dragging');
            });

            li.addEventListener('dragend', function () {
                dragId = null;
                li.classList.remove('dragging');
                clearDropUI();
            });

            li.addEventListener('dragover', function (e) {
                if (dragId === null) return;
                const overId = parseInt(li.dataset.id, 10);
                if (overId === dragId) return;
                // Нельзя уронить элемент внутрь собственного потомка
                if (descendantsIds(dragId).indexOf(overId) !== -1) return;

                e.preventDefault();
                e.stopPropagation();
                e.dataTransfer.dropEffect = 'move';

                const rect = li.getBoundingClientRect();
                const y = e.clientY - rect.top;
                const h = rect.height;
                clearDropUI();

                if (y < h * 0.25) {
                    // Вставить ПЕРЕД элементом (как сосед того же уровня)
                    const marker = ensureMarker();
                    li.parentNode.insertBefore(marker, li);
                } else if (y > h * 0.75) {
                    // Вставить ПОСЛЕ элемента
                    const marker = ensureMarker();
                    if (li.nextSibling) li.parentNode.insertBefore(marker, li.nextSibling);
                    else li.appendChild(marker);
                } else {
                    // Поместить ВНУТРЬ элемента (стать дочерним)
                    li.classList.add('drag-over-inside');
                }
                e._dropMode = y < h * 0.25 ? 'before' : (y > h * 0.75 ? 'after' : 'inside');
            });

            li.addEventListener('dragleave', function () {
                li.classList.remove('drag-over-inside');
            });

            li.addEventListener('drop', function (e) {
                if (dragId === null) return;
                e.preventDefault();
                e.stopPropagation();

                const overId = parseInt(li.dataset.id, 10);
                if (overId === dragId) { clearDropUI(); return; }
                if (descendantsIds(dragId).indexOf(overId) !== -1) { clearDropUI(); return; }

                const rect = li.getBoundingClientRect();
                const y = e.clientY - rect.top;
                const h = rect.height;
                const mode = y < h * 0.25 ? 'before' : (y > h * 0.75 ? 'after' : 'inside');

                moveNode(dragId, overId, mode);
                clearDropUI();
                dragId = null;
                setDirty(true);
                render();
            });
        }

        /**
         * Перемещение узла в модели (плоский массив = предобход в порядке sort)
         * mode: 'before' | 'after' — соседи узла overId; 'inside' — последний ребёнок overId
         */
        function moveNode(id, overId, mode) {
            const idx = items.findIndex(function (it) { return it.id === id; });
            if (idx === -1) return;

            // Собираем подсписок: перетаскиваемый узел + все его потомки (в текущем порядке)
            const block = [items[idx]];
            const rest = items.slice();
            rest.splice(idx, 1);

            const subIds = descendantsIdsAll(items[idx], rest);
            subIds.forEach(function (sid) {
                const node = rest.find(function (it) { return it.id === sid; });
                if (node) block.push(node);
            });
            const blockIds = block.map(function (b) { return b.id; });

            // Удаляем блок из «rest»
            const remaining = rest.filter(function (it) { return blockIds.indexOf(it.id) === -1; });

            // Ищем позицию вставки
            const overIdx = remaining.findIndex(function (it) { return it.id === overId; });
            if (overIdx === -1) return;

            let insertAt;
            let newParent;
            if (mode === 'inside') {
                // После всего поддерева overId
                const overSub = descendantsIdsAll(remaining[overIdx], remaining);
                let lastIdx = overIdx;
                remaining.forEach(function (it, i) {
                    if (i >= overIdx && (it.id === overId || overSub.indexOf(it.id) !== -1)) lastIdx = i;
                });
                insertAt = lastIdx + 1;
                newParent = overId;
            } else {
                const overNode = remaining[overIdx];
                // Поддерево over → позиция после него
                const overSub = descendantsIdsAll(overNode, remaining);
                let lastIdx = overIdx;
                remaining.forEach(function (it, i) {
                    if (i >= overIdx && (it.id === overId || overSub.indexOf(it.id) !== -1)) lastIdx = i;
                });
                if (mode === 'before') {
                    insertAt = overIdx;
                } else {
                    insertAt = lastIdx + 1;
                }
                newParent = overNode.parent_id;
            }

            block[0].parent_id = newParent;

            items = remaining.slice(0, insertAt)
                .concat(block)
                .concat(remaining.slice(insertAt));
        }

        // Потомки id с учётом произвольного родительского массива
        function descendantsIdsAll(node, pool) {
            const out = [];
            (function walk(pid) {
                pool.forEach(function (it) {
                    if (it.parent_id === pid && out.indexOf(it.id) === -1) { out.push(it.id); walk(it.id); }
                });
            })(node.id);
            return out;
        }

        // ─── CRUD ───
        function addChildOf(parentId) {
            const parent = byId(parentId);
            const tmp = --seq;
            const newItem = {
                id: tmp,
                parent_id: parentId,
                title: 'Новый подпункт',
                url: '',
                page_id: null,
                sort_order: 0,
                is_visible: 1,
                is_external: 0,
                css_class: '',
                icon: '',
                note: '',
                depth: parent ? parent.depth + 1 : 1
            };
            // Вставляем сразу после родителя (или после его последнего потомка)
            const pIdx = items.findIndex(function (it) { return it.id === parentId; });
            let insertAt = pIdx + 1;
            if (pIdx !== -1) {
                const subs = descendantsIdsAll(newItem, items); // пусто
                // найти конец поддерева родителя
                let lastIdx = pIdx;
                const parentSubs = descendantsIdsAll(parent, items);
                items.forEach(function (it, i) {
                    if (i >= pIdx && (it.id === parentId || parentSubs.indexOf(it.id) !== -1)) lastIdx = i;
                });
                insertAt = lastIdx + 1;
            }
            items.splice(insertAt, 0, newItem);
            if (parent) collapsed.delete(parent.id);
            setDirty(true);
            render();
            openEditor(tmp);
        }

        function removeItem(id) {
            const it = byId(id);
            if (!it) return;
            const subs = descendantsIds(id);
            const msg = subs.length
                ? 'Удалить «' + it.title + '» и ещё ' + subs.length + ' подпункт(ов)?'
                : 'Удалить пункт «' + it.title + '»?';
            if (!confirm(msg)) return;

            if (isUnsaved(it)) {
                const allIds = [id].concat(subs);
                items = items.filter(function (x) { return allIds.indexOf(x.id) === -1; });
                render();
                return;
            }

            postForm('/admin/menu/items/' + id + '/delete', {})
                .then(function () {
                    const allIds = [id].concat(subs);
                    items = items.filter(function (x) { return allIds.indexOf(x.id) === -1; });
                    renderStatus('✓ Пункт удалён');
                    setTimeout(renderStatus, 2500);
                })
                .catch(function (err) { alert('Ошибка удаления: ' + err.message); });
        }

        // ─── Панель редактирования пункта ───
        let panelOpenFor = null;

        function openEditor(id) {
            const it = byId(id);
            if (!it) return;
            panelOpenFor = id;

            let panel = document.getElementById('menu-edit-panel');
            if (!panel) {
                panel = document.createElement('div');
                panel.id = 'menu-edit-panel';
                panel.className = 'menu-edit-panel card card-compact';
                editor.appendChild(panel);
            }

            const parentOptions = buildParentOptions(id);
            const pageOptions = buildPageOptions(it.page_id);

            panel.innerHTML =
                '<div class="card-header card-header-toggle">' +
                '<h3 class="card-title">✏️ Пункт: ' + escapeHtml(it.title || '(новый)') + '</h3>' +
                '<button type="button" class="btn btn-secondary btn-sm" id="mep-close">Закрыть</button>' +
                '</div>' +
                '<div class="card-body">' +
                '<div class="form-grid-2col">' +
                '<label class="form-field"><span>Название *</span><input type="text" id="mep-title" maxlength="255" value="' + escapeHtml(it.title) + '"></label>' +
                '<label class="form-field"><span>URL ссылки</span><input type="text" id="mep-url" maxlength="500" list="mep-page-list" value="' + escapeHtml(it.url) + '" placeholder="/page или https://…"><datalist id="mep-page-list">' +
                pages.map(function (p) { return '<option value="' + escapeHtml(p.canonical) + '">' + escapeHtml(p.title) + '</option>'; }).join('') +
                '</datalist></label>' +
                '<label class="form-field"><span>Привязать к странице (авто-URL)</span><select id="mep-page">' + pageOptions + '</select></label>' +
                '<label class="form-field"><span>Родительский пункт</span><select id="mep-parent">' + parentOptions + '</select></label>' +
                '<label class="form-field"><span>Иконка (emoji)</span><input type="text" id="mep-icon" maxlength="50" value="' + escapeHtml(it.icon) + '" placeholder="🐶"></label>' +
                '<label class="form-field"><span>CSS-класс</span><input type="text" id="mep-class" maxlength="100" value="' + escapeHtml(it.css_class) + '" placeholder="has-dropdown"></label>' +
                '<label class="form-field field-wide"><span>Заметка (видна только вам)</span><input type="text" id="mep-note" maxlength="255" value="' + escapeHtml(it.note) + '"></label>' +
                '</div>' +
                '<div class="menu-edit-checks">' +
                '<label><input type="checkbox" id="mep-visible"' + (it.is_visible ? ' checked' : '') + '> Видим в меню</label>' +
                '<label><input type="checkbox" id="mep-external"' + (it.is_external ? ' checked' : '') + '> Внешняя ссылка (открывать в новой вкладке)</label>' +
                '</div>' +
                '<div class="menu-edit-actions">' +
                (isUnsaved(it)
                    ? '<button type="button" class="btn btn-success btn-sm" id="mep-create">💾 Создать пункт</button>'
                    : '<button type="button" class="btn btn-success btn-sm" id="mep-save">💾 Сохранить изменения</button>') +
                '<span class="menu-edit-msg" id="mep-msg"></span>' +
                '</div>' +
                '</div>';

            panel.hidden = false;

            document.getElementById('mep-close').addEventListener('click', closePanel);

            const urlInput = document.getElementById('mep-url');
            const pageSelect = document.getElementById('mep-page');
            pageSelect.addEventListener('change', function () {
                const pid = this.value;
                if (!pid) return;
                const pg = pages.find(function (p) { return String(p.id) === pid; });
                if (pg) {
                    urlInput.value = pg.canonical || '';
                    const t = document.getElementById('mep-title');
                    if (!t.value.trim()) t.value = pg.title || '';
                }
            });

            const collect = function () {
                return {
                    title: document.getElementById('mep-title').value.trim(),
                    url: urlInput.value.trim(),
                    page_id: pageSelect.value ? parseInt(pageSelect.value, 10) : null,
                    icon: document.getElementById('mep-icon').value.trim(),
                    css_class: document.getElementById('mep-class').value.trim(),
                    note: document.getElementById('mep-note').value.trim(),
                    is_visible: document.getElementById('mep-visible').checked ? 1 : 0,
                    is_external: document.getElementById('mep-external').checked ? 1 : 0
                };
            };

            const saveParent = function (newParentId) {
                const cur = byId(id);
                if (!cur) return Promise.resolve();
                if (cur.parent_id === newParentId) return Promise.resolve();
                cur.parent_id = newParentId;
                setDirty(true);
                return Promise.resolve();
            };

            const msgEl = function (text, ok) {
                const m = document.getElementById('mep-msg');
                if (m) { m.textContent = text; m.className = 'menu-edit-msg ' + (ok ? 'ok' : 'err'); }
            };

            if (isUnsaved(it)) {
                const tmpId = it.id; // временный отрицательный ID
                document.getElementById('mep-create').addEventListener('click', function () {
                    const data = collect();
                    if (!data.title) { msgEl('Укажите название.', false); return; }
                    postForm('/admin/menu/items', { item: JSON.stringify(data) })
                        .then(function (res) {
                            const realId = res.item.id;
                            // Заменяем временный id на реальный во всём дереве
                            it.id = realId;
                            it.sort_order = res.item.sort_order;
                            items.forEach(function (x) {
                                if (x.parent_id === tmpId) x.parent_id = realId;
                            });
                            // Родитель из панели (сервер создал пункт на верхнем уровне)
                            const parentSel = document.getElementById('mep-parent').value;
                            const newParent = parentSel ? parseInt(parentSel, 10) : null;
                            return saveParent(newParent).then(function () {
                                return persistStructure().then(function () {
                                    msgEl('Пункт создан ✓', true);
                                    panelOpenFor = null;
                                    render();
                                });
                            });
                        })
                        .catch(function (err) { msgEl('Ошибка: ' + err.message, false); });
                });
            } else {
                document.getElementById('mep-save').addEventListener('click', function () {
                    const data = collect();
                    const parentSel = document.getElementById('mep-parent').value;
                    const newParent = parentSel ? parseInt(parentSel, 10) : null;

                    postForm('/admin/menu/items/' + id, { item: JSON.stringify(data) })
                        .then(function () {
                            const cur = byId(id);
                            Object.keys(data).forEach(function (k) { if (cur) cur[k] = data[k]; });
                            return saveParent(newParent);
                        })
                        .then(function () {
                            if (dirty) return persistStructure();
                        })
                        .then(function () {
                            msgEl('Сохранено ✓', true);
                            render();
                        })
                        .catch(function (err) { msgEl('Ошибка: ' + err.message, false); });
                });
            }

            // focus
            setTimeout(function () { document.getElementById('mep-title').focus(); }, 30);
            panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        function closePanel() {
            const panel = document.getElementById('menu-edit-panel');
            if (panel) panel.hidden = true;
            panelOpenFor = null;
        }

        function buildParentOptions(excludeId) {
            const banned = [excludeId].concat(descendantsIds(excludeId));
            let html = '<option value="">— верхний уровень —</option>';
            items.forEach(function (it) {
                if (banned.indexOf(it.id) !== -1) return;
                const sel = (it.id === (byId(excludeId) || {}).parent_id) ? ' selected' : '';
                html += '<option value="' + it.id + '"' + sel + '>' +
                    '—'.repeat(Math.min(it.depth, 4)) + ' ' + escapeHtml(it.title || '(без названия)') + '</option>';
            });
            return html;
        }

        function buildPageOptions(selectedId) {
            let html = '<option value="">— без привязки —</option>';
            pages.forEach(function (p) {
                const sel = (selectedId && String(selectedId) === String(p.id)) ? ' selected' : '';
                html += '<option value="' + p.id + '"' + sel + '>' + escapeHtml(p.title) + '</option>';
            });
            return html;
        }

        // ─── Сохранение структуры ───
        // ─── Сохранение структуры ───
function persistStructure() {
    // Отправляем на сервер ТОЛЬКО сохранённые пункты (id > 0).
    // Временные «черновые» id (< 0) серверу неизвестны — их отправка
    // вызывала ошибку «Неизвестный ID пункта: -1».
    const structure = items
        .filter(function (it) { return it.id > 0; })
        .map(function (it) {
            let pid = it.parent_id === undefined ? null : it.parent_id;
            // Если родитель — несохранённый черновик, привязку к родителю
            // сервер сохранить не может: ставим пункт на верхний уровень.
            if (pid !== null && pid < 0) pid = null;
            return { id: it.id, parent_id: pid };
        });

    if (!structure.length) {
        setDirty(false);
        renderStatus('✓ Нет сохранённых пунктов для записи');
        setTimeout(renderStatus, 2500);
        return Promise.resolve();
    }

    return postForm('/admin/menu/structure', { structure: JSON.stringify(structure) })
        .then(function () { setDirty(false); renderStatus('✓ Структура сохранена'); setTimeout(renderStatus, 2500); })
        .catch(function (err) { alert('Не удалось сохранить структуру: ' + err.message); return Promise.reject(err); });
}

        document.getElementById('menu-save-structure').addEventListener('click', function () {
            if (!dirty) { renderStatus('Изменений нет'); setTimeout(renderStatus, 1500); return; }
            persistStructure().catch(function () { /* уже показали alert */ });
        });

        document.getElementById('menu-add-root').addEventListener('click', function () {
            const tmp = --seq;
            items.push({
                id: tmp, parent_id: null, title: 'Новый пункт', url: '', page_id: null,
                sort_order: 0, is_visible: 1, is_external: 0, css_class: '', icon: '', note: '', depth: 0
            });
            setDirty(true);
            render();
            openEditor(tmp);
        });

        document.getElementById('menu-expand-all').addEventListener('click', function () {
            collapsed.clear(); render();
        });
        document.getElementById('menu-collapse-all').addEventListener('click', function () {
            items.forEach(function (it) { if (childrenOf(it.id).length) collapsed.add(it.id); });
            render();
        });

        window.addEventListener('beforeunload', function (e) {
            if (dirty) { e.preventDefault(); e.returnValue = ''; }
        });

        // ─── Старт ───
        render();
        renderStatus();
    });
})();
