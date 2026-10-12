/*
 * Release Note Manager — interactions de l'interface (vanilla JS, sans dépendance).
 * Amélioration progressive : toutes les actions restent possibles sans JavaScript.
 */
(function () {
    'use strict';

    const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

    /* ---------- Menu mobile ---------- */
    function initSidebar() {
        const toggle = document.querySelector('[data-toggle-sidebar]');
        const sidebar = document.getElementById('sidebar');
        if (!toggle || !sidebar) return;
        toggle.addEventListener('click', () => {
            const open = sidebar.classList.toggle('open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        document.addEventListener('click', (e) => {
            if (sidebar.classList.contains('open') && !sidebar.contains(e.target) && !toggle.contains(e.target)) {
                sidebar.classList.remove('open');
                toggle.setAttribute('aria-expanded', 'false');
            }
        });
    }

    /* ---------- Alertes refermables ---------- */
    function initDismiss() {
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-dismiss]');
            if (btn) btn.closest('.alert')?.remove();
        });
    }

    /* ---------- Confirmations et soumissions ---------- */
    function initForms() {
        document.addEventListener('submit', (e) => {
            const form = e.target;
            const message = form.dataset.confirm;
            if (message && !window.confirm(message)) {
                e.preventDefault();
                return;
            }
            if (form.dataset.busy) {
                $$('button[type=submit], button:not([type])', form).forEach((b) => {
                    b.classList.add('is-busy');
                    b.textContent = form.dataset.busy;
                });
            }
        });
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-confirm-click]');
            if (btn && !window.confirm(btn.dataset.confirmClick)) e.preventDefault();
        });
        document.addEventListener('change', (e) => {
            const el = e.target;
            if (el.matches('select[data-autosubmit]')) el.form?.requestSubmit ? el.form.requestSubmit() : el.form?.submit();
        });
    }

    /* ---------- Onglets génériques (data-tabs / data-tab-panel) ---------- */
    function initTabs() {
        $$('[data-tabs]').forEach((tabs) => {
            const container = tabs.parentElement;
            tabs.addEventListener('click', (e) => {
                const tab = e.target.closest('[data-tab]');
                if (!tab) return;
                $$('[data-tab]', tabs).forEach((t) => t.classList.toggle('active', t === tab));
                $$('[data-tab-panel]', container).forEach((p) => { p.hidden = p.dataset.tabPanel !== tab.dataset.tab; });
            });
            // Ouvre l'onglet contenant une erreur de validation.
            const panelWithError = $$('[data-tab-panel]', container).find((p) => p.querySelector('.has-error'));
            if (panelWithError) tabs.querySelector(`[data-tab="${panelWithError.dataset.tabPanel}"]`)?.click();
        });
    }

    /* ---------- Onglets de format de l'éditeur (IT / Fonctionnel / Communication) ---------- */
    function initFormatTabs() {
        const tabs = document.querySelector('[data-format-tabs]');
        if (!tabs) return;
        const apply = (format) => {
            $$('[data-format]', tabs).forEach((t) => {
                const active = t.dataset.format === format;
                t.classList.toggle('active', active);
                t.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            $$('[data-format-only]').forEach((el) => { el.hidden = el.dataset.formatOnly !== format; });
            try { sessionStorage.setItem('rnm.format', format); } catch (err) { /* stockage indisponible */ }
        };
        tabs.addEventListener('click', (e) => {
            const tab = e.target.closest('[data-format]');
            if (tab) apply(tab.dataset.format);
        });
        let saved = null;
        try { saved = sessionStorage.getItem('rnm.format'); } catch (err) { /* ignoré */ }
        if (saved && tabs.querySelector(`[data-format="${saved}"]`)) apply(saved);
    }

    /* ---------- Champ Epic Link visible uniquement pour Jira ---------- */
    function initProviderFields() {
        const block = document.querySelector('[data-show-for-provider]');
        if (!block) return;
        const radios = $$('input[name$="[provider]"]');
        const update = () => {
            const checked = radios.find((r) => r.checked);
            block.hidden = !!checked && checked.value !== block.dataset.showForProvider;
        };
        radios.forEach((r) => r.addEventListener('change', update));
        update();
    }

    /* ---------- Réordonnancement des sections par glisser-déposer ---------- */
    function initSortable() {
        const list = document.querySelector('[data-sortable]');
        if (!list) return;
        let dragged = null;
        $$('.section-card', list).forEach((card) => {
            const handle = card.querySelector('.drag-handle');
            if (!handle) return;
            handle.addEventListener('mousedown', () => { card.draggable = true; });
            handle.addEventListener('touchstart', () => { card.draggable = true; }, { passive: true });
            card.addEventListener('dragstart', (e) => {
                dragged = card;
                card.classList.add('is-dragging');
                e.dataTransfer.effectAllowed = 'move';
            });
            card.addEventListener('dragend', () => {
                card.classList.remove('is-dragging');
                card.draggable = false;
                $$('.drag-over', list).forEach((c) => c.classList.remove('drag-over'));
                save();
            });
            card.addEventListener('dragover', (e) => {
                if (!dragged || dragged === card) return;
                e.preventDefault();
                card.classList.add('drag-over');
                const rect = card.getBoundingClientRect();
                const after = e.clientY > rect.top + rect.height / 2;
                list.insertBefore(dragged, after ? card.nextSibling : card);
            });
            card.addEventListener('dragleave', () => card.classList.remove('drag-over'));
        });

        let lastOrder = order();
        function order() { return $$('.section-card', list).map((c) => c.dataset.id).join(','); }
        function save() {
            const current = order();
            if (current === lastOrder) return;
            const body = new FormData();
            body.append('_token', list.dataset.reorderToken);
            $$('.section-card', list).forEach((c) => body.append('order[]', c.dataset.id));
            fetch(list.dataset.reorderUrl, { method: 'POST', body, headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then((r) => {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    lastOrder = current;
                })
                .catch(() => {
                    window.alert('Le nouvel ordre n\'a pas pu être enregistré. La page va être rechargée.');
                    window.location.reload();
                });
        }
    }

    /* ---------- Aperçu immédiat du wording (même syntaxe que le serveur) ---------- */
    const escapeHtml = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&apos;');
    const URL_RE = /(https?:\/\/[^\s<>"'`]+[^\s<>"'`.,;:!?)\]])/g;

    function inline(text) {
        return text.split(/(`[^`]+`)/g).map((part) => {
            if (part.length > 2 && part.startsWith('`') && part.endsWith('`')) return '<code>' + escapeHtml(part.slice(1, -1)) + '</code>';
            return part.split(URL_RE).map((piece) => {
                if (/^https?:\/\//.test(piece)) { const u = escapeHtml(piece); return `<a href="${u}">${u}</a>`; }
                return escapeHtml(piece).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
            }).join('');
        }).join('');
    }

    function formatText(text) {
        if (!text || !text.trim()) return '';
        const blocks = text.replace(/\r\n?/g, '\n').trim().split(/\n\s*\n/);
        return blocks.map((block) => {
            let html = '', paragraph = [], listType = null, items = [];
            const flushP = () => { if (paragraph.length) { html += '<p>' + paragraph.map(inline).join('<br />') + '</p>'; paragraph = []; } };
            const flushL = () => { if (listType && items.length) { html += `<${listType}>` + items.map((i) => '<li>' + inline(i) + '</li>').join('') + `</${listType}>`; } listType = null; items = []; };
            block.split('\n').forEach((line) => {
                let m;
                if ((m = line.match(/^\s*[-*•]\s+(.*)$/))) { flushP(); if (listType !== 'ul') { flushL(); listType = 'ul'; } items.push(m[1]); }
                else if ((m = line.match(/^\s*\d+[.)]\s+(.*)$/))) { flushP(); if (listType !== 'ol') { flushL(); listType = 'ol'; } items.push(m[1]); }
                else if (listType && /^\s{2,}\S/.test(line)) { items[items.length - 1] += ' ' + line.trim(); }
                else { flushL(); if (line.trim()) paragraph.push(line.trim()); }
            });
            flushL(); flushP();
            return html;
        }).join('');
    }

    function initLivePreview() {
        const preview = document.querySelector('[data-live-preview]');
        if (!preview) return;
        const render = (field) => {
            const label = document.querySelector(`label[for="${field.id}"]`)?.textContent?.trim() || '';
            const html = formatText(field.value);
            preview.innerHTML = (label ? `<p class="small muted">${escapeHtml(label)}</p>` : '') + (html || '<p class="placeholder">Champ vide.</p>');
        };
        $$('textarea[data-preview-source]').forEach((field) => {
            field.addEventListener('focus', () => render(field));
            field.addEventListener('input', () => render(field));
        });
    }

    /* ---------- Copie dans le presse-papiers ---------- */
    function initCopy() {
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-copy]');
            if (!btn) return;
            const target = document.querySelector(btn.dataset.copy);
            if (!target || !navigator.clipboard) return;
            navigator.clipboard.writeText(target.textContent).then(() => {
                const old = btn.textContent;
                btn.textContent = 'Copié ✔';
                setTimeout(() => { btn.textContent = old; }, 1500);
            });
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        initSidebar();
        initDismiss();
        initForms();
        initTabs();
        initFormatTabs();
        initProviderFields();
        initSortable();
        initLivePreview();
        initCopy();
    });
})();
