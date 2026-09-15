import { toHtml, fromHtml } from './formattedText.js';
import { safeLinkUrl } from './linkValue.js';
import { createSiteLayout } from './siteLayout.js';

export default (initial, fields, endpoint, csrf, exitUrl) => ({
    content: { ...initial.draft },
    defaults: initial.defaults || {},
    baseline: JSON.stringify(initial.draft),
    version: initial.version,
    history: initial.history,
    fields,
    undoStack: [],
    redoStack: [],
    observed: JSON.stringify(initial.draft),
    checkpointTimer: null,
    get canUndo() { return this.undoStack.length > 0 || JSON.stringify(this.content) !== this.observed; },
    get canRedo() { return this.redoStack.length > 0 && JSON.stringify(this.content) === this.observed; },
    trackChange() {
        if (JSON.stringify(this.content) === this.observed) return;
        this.redoStack = [];
        clearTimeout(this.checkpointTimer);
        this.checkpointTimer = setTimeout(() => this.checkpoint(), 500);
    },
    checkpoint() {
        clearTimeout(this.checkpointTimer);
        const snapshot = JSON.stringify(this.content);
        if (snapshot === this.observed) return;
        this.undoStack = [...this.undoStack, this.observed].slice(-50);
        this.redoStack = [];
        this.observed = snapshot;
    },
    replaceContent(content) {
        this.checkpoint();
        this.content = { ...content };
        this.checkpoint();
    },
    undo() {
        if (this.busy) return;
        this.checkpoint();
        if (!this.undoStack.length) return;
        this.redoStack.push(this.observed);
        this.applySnapshot(this.undoStack.pop());
    },
    redo() {
        if (this.busy || !this.canRedo) return;
        this.undoStack.push(this.observed);
        this.applySnapshot(this.redoStack.pop());
    },
    applySnapshot(snapshot) {
        this.observed = snapshot;
        this.content = JSON.parse(snapshot);
        this.savedRange = null;
        this.syncDocument();
        // An active contenteditable normally owns its DOM; history must update it too.
        for (const element of document.querySelectorAll('[data-editable-field], .cms-rich-input')) {
            const key = element.dataset.editableField || this.selected;
            if (key in this.content) {
                element.innerHTML = toHtml(this.content[key]);
                if (element === document.activeElement) {
                    const range = document.createRange();
                    range.selectNodeContents(element);
                    range.collapse(false);
                    const selection = window.getSelection();
                    selection?.removeAllRanges();
                    selection?.addRange(range);
                    this.rememberSelection(element, key);
                }
            }
        }
    },
    historyShortcut(event) {
        if (!(event.ctrlKey || event.metaKey) || event.altKey || event.isComposing || this.busy) return;
        const target = event.target;
        if (!target?.closest('[data-editable-field], [data-cms-link], .cms-tool') || target.closest('[type="search"], [type="file"]')) return;
        const key = event.key.toLowerCase();
        if (key !== 'z' && !(key === 'y' && event.ctrlKey)) return;
        event.preventDefault();
        if (key === 'y' || event.shiftKey) this.redo(); else this.undo();
    },
    libraryOpen: false,
    libraryLoading: false,
    libraryItems: [],
    libraryError: '',
    libraryPage: 1,
    libraryMore: false,
    libraryRequest: 0,
    async openLibrary(page = 1) {
        if (this.busy || this.fields[this.selected]?.format !== 'image') return;
        const key = this.selected;
        const request = ++this.libraryRequest;
        const opening = !this.libraryOpen;
        this.libraryOpen = true;
        if (opening) this.$nextTick?.(() => this.$refs.libraryHeading?.focus());
        this.libraryLoading = true;
        this.libraryError = '';
        this.libraryItems = [];
        try {
            const query = new URLSearchParams({ manifest: initial.manifest, field: key, batch: page });
            const response = await fetch(endpoint + '/images?' + query, { headers: { Accept: 'application/json' } });
            const result = await response.json();
            if (!response.ok) throw new Error(result.message || 'Unable to load images. Please try again.');
            if (request !== this.libraryRequest || key !== this.selected) return;
            this.libraryItems = result.images;
            this.libraryMore = result.has_more;
            this.libraryPage = page;
        } catch (error) {
            if (request === this.libraryRequest) this.libraryError = error.message;
        } finally {
            if (request === this.libraryRequest) this.libraryLoading = false;
        }
    },
    chooseLibraryImage(image) {
        if (this.busy || this.fields[this.selected]?.format !== 'image') return;
        this.replaceContent({ ...this.content, [this.selected]: JSON.stringify({ ...this.imageValue(), src: image.src }) });
        this.closeLibrary();
        this.notify('Image selected. Save draft or publish to apply it.');
    },
    activeField: '',
    savedRange: null,
    selected: '',
    search: '',
    contentFilter: 'all',
    panel: 'content',
    plainPreview(value) {
        const holder = document.createElement('div');
        holder.innerHTML = toHtml(value || '');
        return (holder.textContent || '').replace(/\s+/g, ' ').trim();
    },
    fieldLabel(key) {
        return (this.fields[key]?.label || 'Content').split(' · ')[0].replace(/^Home /i, '').replace(/\b[a-f0-9]{12,}\b/gi, '').trim();
    },
    fieldPreview(key) {
        if (this.fields[key]?.format === 'link') { const link = this.linkValue(key); return `${link.text} · ${link.href}`; }
        if (this.fields[key]?.format === 'image') return this.imageValue(key).alt || this.fieldLabel(key);
        return this.plainPreview(this.content[key]) || 'Empty text';
    },
    get contentFields() {
        return Object.entries(this.fields).filter(([key, field]) => key !== 'seo_title' && key !== 'seo_description' && !field.meta);
    },
    get filteredFields() {
        const query = this.search.trim().toLowerCase();
        return this.contentFields.filter(([key, field]) => {
            const type = ['image', 'link'].includes(field.format) ? field.format : 'text';
            return (this.contentFilter === 'all' || type === this.contentFilter)
                && (!query || (this.fieldLabel(key) + ' ' + this.fieldPreview(key)).toLowerCase().includes(query));
        });
    },
    locateField() {
        const elements = [...(this.inlineElements || []), ...(this.imageElements || []), ...(this.linkElements || [])];
        const element = elements.find(el => (el.dataset.editableField || el.dataset.cmsImage || el.dataset.cmsLink) === this.selected && el.getClientRects().length && !el.closest('[inert]'));
        element?.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth', block: 'center' });
    },
    get metadataFields() { return Object.entries(this.fields).filter(([key, field]) => key === 'seo_title' || key === 'seo_description' || field.meta); },
    layout: 'bottom',
    layoutWide: true,
    layoutMenuOpen: false,
    get selectedLayout() { return this.layoutWide ? this.layout : 'bottom'; },
    get dockLayout() { return this.selectedLayout; },
    restoreLayout() {
        try {
            const saved = window.localStorage.getItem('page-editor:layout');
            if (['bottom', 'left'].includes(saved)) this.layout = saved;
        } catch { /* The editor works when browser storage is disabled. */ }
    },
    chooseLayout(layout) {
        if (!['bottom', 'left'].includes(layout) || (!this.layoutWide && layout !== 'bottom')) return;
        this.layout = layout;
        this.preview = false;
        this.expanded = true;
        try { window.localStorage.setItem('page-editor:layout', layout); } catch { /* Keep this session's choice. */ }
        this.closeLayoutMenu();
    },
    toggleLayoutMenu() {
        if (this.layoutMenuOpen) { this.closeLayoutMenu(); return; }
        this.layoutMenuOpen = true;
        this.$nextTick(() => this.$refs.layoutChoices?.querySelector(`[data-layout-option="${this.layoutWide ? this.layout : 'bottom'}"]`)?.focus());
    },
    closeLayoutMenu() {
        this.layoutMenuOpen = false;
        this.$nextTick?.(() => this.$refs.layoutToggle?.focus());
    },
    updateDockSpace() {
        this.siteLayout?.setActive(this.dockLayout === 'left');
        const height = this.dockLayout === 'bottom' ? this.$refs.dock.getBoundingClientRect().height : 0;
        document.body.style.setProperty('--cms-dock-height', `${height}px`);
    },
    preview: false,
    expanded: false,
    busy: false,
    operation: '',
    get changedCount() {
        const saved = JSON.parse(this.baseline);
        return Object.keys(this.content).filter(key => this.content[key] !== saved[key]).length;
    },
    get statusLabel() {
        if (this.busy) return { upload: 'Uploading image…', publish: 'Publishing…', reset: 'Resetting CMS…', draft: 'Saving draft…' }[this.operation] || 'Working…';
        if (this.dirty) return `${this.changedCount} unsaved ${this.changedCount === 1 ? 'field' : 'fields'}`;
        return 'No unsaved changes';
    },
    metadataLabel(key) {
        const labels = { seo_title: 'Page title', seo_description: 'Search description', 'og:title': 'Social title', 'og:description': 'Social description', 'og:image': 'Social image URL', 'og:image:alt': 'Social image alt text', 'og:url': 'Social page URL', 'og:type': 'Social content type', 'og:site_name': 'Site name' };
        const meta = this.fields[key]?.meta;
        return (labels[key] || labels[meta?.name] || this.fieldLabel(key)) + (meta?.index ? ` (${meta.index + 1})` : '');
    },
    minimise() {
        if (this.dockLayout === 'left') return;
        this.expanded = false;
        this.$nextTick(() => this.$refs.toolsToggle?.focus());
    },
    backToContent() {
        const previous = this.selected;
        this.selected = '';
        this.$nextTick(() => {
            const button = Array.from(this.$refs.contentList?.querySelectorAll('button') || []).find(el => el.dataset.field === previous);
            (button || this.$refs.contentSearch)?.focus();
        });
    },
    closeLibrary() {
        this.libraryOpen = false;
        this.libraryRequest++;
        this.$nextTick?.(() => this.$refs.libraryToggle?.focus());
    },
    message: '',
    error: '',
    notificationTimer: null,
    get dirty() { return JSON.stringify(this.content) !== this.baseline; },
    init() {
        this.warn = event => {
            if (this.dirty) { event.preventDefault(); event.returnValue = ''; }
        };
        window.addEventListener('beforeunload', this.warn);
        this.restoreNotification();
        this.restoreLayout();
        this.siteLayout = createSiteLayout();
        this.layoutMedia = window.matchMedia('(min-width: 768px)');
        this.layoutWide = this.layoutMedia.matches;
        this.onLayoutMedia = event => { this.layoutWide = event.matches; if (this.layoutMenuOpen) this.closeLayoutMenu(); };
        this.layoutMedia.addEventListener('change', this.onLayoutMedia);
        this.$watch('dockLayout', () => this.$nextTick(() => this.updateDockSpace()));
        this.bindDocument();
        this.$watch('content', () => { this.syncDocument(); this.trackChange(); });
        this.onHistoryKey = event => this.historyShortcut(event);
        window.addEventListener('keydown', this.onHistoryKey, true);
        this.$watch('preview', () => this.syncDocument());
        this.$watch('busy', () => this.syncDocument());
        this.escape = event => {
            if (event.key === 'Escape' && !event.isComposing && !event.defaultPrevented && !this.busy) {
                event.preventDefault();
                if (this.layoutMenuOpen) { this.closeLayoutMenu(); return; }
                window.location.assign(exitUrl);
            }
        };
        window.addEventListener('keydown', this.escape);
        document.body.classList.add('cms-editor-open');
        this.$nextTick(() => {
            this.dockObserver = new ResizeObserver(() => this.updateDockSpace());
            this.dockObserver.observe(this.$refs.dock);
        });
    },
    destroy() {
        window.removeEventListener('beforeunload', this.warn);
        clearTimeout(this.notificationTimer);
        this.dockObserver?.disconnect();
        this.siteLayout?.destroy();
        this.layoutMedia?.removeEventListener('change', this.onLayoutMedia);
        window.removeEventListener('keydown', this.escape);
        this.inlineAbort?.abort();
        clearTimeout(this.checkpointTimer);
        this.libraryRequest++;
        window.removeEventListener('keydown', this.onHistoryKey, true);
        document.body.classList.remove('cms-editor-open');
    },
    linkValue(key = this.selected) {
        try {
            const value = JSON.parse(this.content[key]);
            if (value && typeof value.href === 'string' && typeof value.text === 'string') return value;
        } catch { /* A revision may predate link editing. */ }
        return { href: '', text: '' };
    },
    updateLink(property, value) {
        this.content[this.selected] = JSON.stringify({ ...this.linkValue(), [property]: value });
    },
    linkError(key = this.selected) {
        const link = this.linkValue(key);
        if (typeof link?.text !== 'string' || !link.text.trim()) return 'Add text that describes where this link goes.';
        if (link.text.length > 2000) return 'Link text must not exceed 2,000 characters.';
        if (!safeLinkUrl(link.href)) return 'Use https://, /page, #section, ?query, mailto: or tel: for the destination.';
        return '';
    },
    imageValue(key = this.selected) {
        try { return JSON.parse(this.content[key]); } catch { return { src: '', alt: '' }; }
    },
    updateImage(property, value) {
        this.content[this.selected] = JSON.stringify({ ...this.imageValue(), [property]: value });
    },
    async uploadImage(file) {
        if (!file || this.busy) return;
        const key = this.selected;
        this.operation = 'upload';
        this.busy = true;
        try {
            const body = new FormData();
            body.append('image', file); body.append('field', key); body.append('manifest', initial.manifest);
            const response = await fetch(endpoint + '/images', { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body });
            const result = await response.json();
            if (!response.ok) throw new Error(Object.values(result.errors || {}).flat()[0] || result.message || 'Upload failed. Please try again.');
            this.replaceContent({ ...this.content, [key]: JSON.stringify({ ...this.imageValue(key), src: result.src }) });
            this.notify('Image uploaded. Save draft or publish to apply it.');
        } catch (error) { this.notify(error.message, true); }
        finally { this.busy = false; }
    },
    bindDocument() {
        this.inlineAbort = new AbortController();
        this.inlineElements = Array.from(document.querySelectorAll('[data-editable-field]'));
        for (const element of this.inlineElements) {
            const field = element.dataset.editableField;
            if (!this.fields[field]) continue;
            const on = (event, handler) => element.addEventListener(event, handler, { signal: this.inlineAbort.signal });
            on('focus', () => { if (!this.preview) this.selectField(field, false); });
            on('click', event => { if (!this.preview) { event.preventDefault(); event.stopPropagation(); } });
            on('input', () => this.updateInline(element, field));
            on('mouseup', () => this.rememberSelection(element, field));
            on('keyup', () => this.rememberSelection(element, field));
            on('keydown', event => {
                this.formatShortcut(event, element, field);
                if (event.key === 'Enter' && !event.isComposing && !this.preview) {
                    event.preventDefault(); this.insertInline(element, field, '\n');
                }
            });
            on('paste', event => { event.preventDefault(); this.insertInline(element, field, event.clipboardData.getData('text/plain')); });
            on('drop', event => event.preventDefault());
        }
        this.linkElements = Array.from(document.querySelectorAll('[data-cms-link]'));
        for (const element of this.linkElements) {
            const select = event => {
                if (this.preview) return;
                event.preventDefault(); event.stopImmediatePropagation();
                if (!this.busy) this.selectField(element.dataset.cmsLink);
            };
            element.addEventListener('click', select, { capture: true, signal: this.inlineAbort.signal });
            element.addEventListener('auxclick', select, { capture: true, signal: this.inlineAbort.signal });
        }
        this.imageElements = Array.from(document.querySelectorAll('[data-cms-image]'));
        for (const element of this.imageElements) {
            element.addEventListener('click', event => {
                if (this.preview || this.busy) return;
                event.preventDefault(); event.stopPropagation(); this.selectField(element.dataset.cmsImage);
            }, { signal: this.inlineAbort.signal });
        }
        this.syncDocument();
    },
    syncDocument() {
        for (const element of this.linkElements || []) {
            const value = this.linkValue(element.dataset.cmsLink);
            if (safeLinkUrl(value?.href)) element.setAttribute('href', value.href);
            else element.removeAttribute('href');
            const label = element.querySelector('[data-cms-link-label]');
            if (label) label.textContent = value?.text || '';
            element.classList.toggle('cms-link-editable', !this.preview);
        }
        for (const element of this.imageElements || []) {
            const value = this.imageValue(element.dataset.cmsImage);
            if (typeof value.src === 'string' && /^(https?:\/\/|\/(?![\/\\]))/i.test(value.src)) {
                if (element.getAttribute('src') !== value.src) element.setAttribute('src', value.src);
            }
            element.alt = value.alt || '';
            element.classList.toggle('cms-image-editable', !this.preview);
        }
        for (const element of this.inlineElements || []) {
            const field = element.dataset.editableField;
            if (!this.fields[field]) continue;
            this.syncInline(element, field);
            element.contentEditable = !this.preview && !this.busy ? 'true' : 'false';
            element.classList.toggle('cms-editable', !this.preview);
            element.tabIndex = this.preview ? -1 : 0;
            if (!this.preview) {
                element.setAttribute('role', 'textbox');
                element.setAttribute('aria-label', `Edit ${this.fields[field].label}`);
                element.setAttribute('aria-multiline', 'true');
            } else {
                element.removeAttribute('role'); element.removeAttribute('aria-label'); element.removeAttribute('aria-multiline');
            }
        }
        if ('seo_title' in this.content) document.title = this.content.seo_title;
        const description = document.querySelector('meta[name="description" i]');
        if (description && 'seo_description' in this.content) description.content = this.content.seo_description;
        for (const [key, field] of this.metadataFields) {
            if (!field.meta) continue;
            const { attribute, name, index } = field.meta;
            const matches = Array.from(document.head.querySelectorAll('meta')).filter(meta => (meta.getAttribute('property') || meta.getAttribute('name') || '').toLowerCase() === name);
            let meta = matches[index];
            if (!meta && this.content[key]) {
                meta = document.createElement('meta');
                meta.setAttribute(attribute, name);
                document.head.append(meta);
            }
            if (meta) meta.content = this.content[key] || '';
        }
    },
    syncInline(element, field) {
        const value = toHtml(this.content[field] ?? '');
        if (element.innerHTML !== value && document.activeElement !== element) element.innerHTML = value;
    },
    updateInline(element, field) {
        this.content[field] = fromHtml(element.innerHTML);
        this.rememberSelection(element, field);
    },
    rememberSelection(element, field) {
        const selection = window.getSelection();
        if (!selection?.rangeCount || !element.contains(selection.getRangeAt(0).commonAncestorContainer)) return;
        this.activeEditor = element;
        this.activeField = field;
        this.savedRange = selection.getRangeAt(0).cloneRange();
    },
    formatShortcut(event, element, field) {
        if (!(event.ctrlKey || event.metaKey) || !['b', 'i', 'u'].includes(event.key.toLowerCase())) return;
        event.preventDefault();
        this.rememberSelection(element, field);
        this.format({ b: 'bold', i: 'italic', u: 'underline' }[event.key.toLowerCase()]);
    },
    format(command) {
        if (this.preview || this.busy || !this.activeEditor || !this.savedRange || !['bold', 'italic', 'underline'].includes(command)) return;
        this.activeEditor.focus();
        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(this.savedRange);
        // Native formatting preserves the browser selection; CMS history records the result.
        document.execCommand('styleWithCSS', false, false);
        document.execCommand(command, false);
        this.updateInline(this.activeEditor, this.activeField);
    },
    insertInline(element, field, text) {
        if (this.preview || this.busy) return;
        const selection = window.getSelection();
        if (!selection?.rangeCount) return;
        const range = selection.getRangeAt(0);
        if (!element.contains(range.commonAncestorContainer)) return;
        const available = 2000 - (element.textContent.length - range.toString().length);
        range.deleteContents();
        const node = document.createTextNode(text.slice(0, Math.max(0, available)));
        range.insertNode(node);
        range.setStartAfter(node);
        range.collapse(true);
        selection.removeAllRanges();
        selection.addRange(range);
        this.updateInline(element, field);
    },
    notificationKey() { return `page-editor:notice:${endpoint}`; },
    restoreNotification() {
        try {
            const pending = JSON.parse(sessionStorage.getItem(this.notificationKey()) || 'null');
            if (pending && Date.now() - pending.at < 30000 && initial.version >= pending.version) {
                this.notify(pending.text);
            } else {
                sessionStorage.removeItem(this.notificationKey());
            }
        } catch { /* Storage may be unavailable; inline feedback still works. */ }
    },
    selectField(field, showTools = true) {
        this.panel = 'content';
        if (this.selected !== field) { this.checkpoint(); this.libraryOpen = false; this.libraryRequest++; this.activeEditor = null; this.savedRange = null; }
        this.selected = field;
        if (showTools) {
            this.expanded = true;
            this.$nextTick?.(() => this.$refs.detailHeading?.focus());
        }
    },
    dismissNotification() {
        clearTimeout(this.notificationTimer);
        this.message = '';
        this.error = '';
        try { sessionStorage.removeItem(this.notificationKey()); } catch {}
    },
    notify(text, isError = false) {
        this.dismissNotification();
        if (isError) this.error = text;
        else {
            this.message = text;
            this.notificationTimer = setTimeout(() => { this.dismissNotification(); }, 6000);
        }
    },
    restore(index) {
        const restored = { ...this.defaults, ...this.history[index].content };
        for (const [key, field] of Object.entries(this.fields)) {
            if (field.format !== 'link' || !(key in restored)) continue;
            let value;
            try { value = JSON.parse(restored[key]); } catch { /* Older text-only revision. */ }
            if (value && typeof value.href === 'string' && typeof value.text === 'string') continue;
            const original = JSON.parse(this.defaults[key]);
            restored[key] = JSON.stringify({ ...original, text: this.plainPreview(restored[key]) || original.text });
        }
        this.replaceContent(restored);
        this.notify('Revision loaded for preview. Save draft or publish to apply it.');
    },
    async resetCms(resetUrl) {
        if (this.busy || !window.confirm('Reset all local CMS content? This deletes drafts, published overrides, revision history and CMS image uploads across every page. This cannot be undone.')) return;
        this.operation = 'reset';
        this.busy = true;
        this.dismissNotification();
        try {
            const response = await fetch(resetUrl, {
                method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ confirm: true }),
            });
            if (!response.ok) {
                const result = await response.json().catch(() => ({}));
                throw new Error(result.message || 'Could not reset CMS content.');
            }
            for (const name of ['localStorage', 'sessionStorage']) {
                try {
                    const storage = window[name];
                    for (let index = storage.length - 1; index >= 0; index--) {
                        const key = storage.key(index);
                        if (key?.startsWith('page-editor:')) storage.removeItem(key);
                    }
                } catch { /* Browser storage may be disabled. */ }
            }
            this.baseline = JSON.stringify(this.content);
            window.removeEventListener?.('beforeunload', this.warn);
            window.location.assign(exitUrl || window.location.pathname);
        } catch (error) { this.notify(error.message, true); }
        finally { this.busy = false; }
    },
    async save(action) {
        if (this.busy) return;
        const invalidLink = Object.keys(this.fields).find(key => this.fields[key].format === 'link' && this.linkError(key));
        if (invalidLink) { this.selectField(invalidLink); this.notify(this.linkError(invalidLink), true); return; }
        this.checkpoint();
        this.operation = action;
        this.busy = true;
        this.dismissNotification();
        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ version: this.version, action, content: this.content, manifest: initial.manifest }),
            });
            if (!response.ok) {
                if (response.status === 409) throw new Error('Another editor saved this page. Copy your changes, then reload to get the latest version.');
                if ([403, 419].includes(response.status)) throw new Error('Your editing session expired or access was removed. Copy your changes and sign in again.');
                const failure = await response.json().catch(() => ({}));
                throw new Error(response.status === 422 ? (Object.values(failure.errors || {}).flat()[0] || 'Check your text and try again.') : 'Unable to save. Your edits are still here; please try again.');
            }
            const state = await response.json();
            this.version = state.version;
            this.history = state.history;
            for (const field of Object.values(this.fields)) field.updated_in_code = false;
            this.baseline = JSON.stringify(this.content);
            // Leave immediately after a confirmed publish. The server flashes the
            // success notice for the next page; toast/storage work must not block exit.
            if (action === 'publish') {
                const source = state.redirect_url || exitUrl || window.location.href;
                const target = new URL(source, window.location.href || 'http://localhost/');
                target.searchParams.delete('edit');
                window.removeEventListener?.('beforeunload', this.warn);
                window.location.assign(source.startsWith('/') ? target.pathname + target.search + target.hash : target.href);
                return;
            }
            this.notify(action === 'publish' ? 'Published. Visitors now see this version.' : 'Draft saved. The live page has not changed.');
            try { sessionStorage.setItem(this.notificationKey(), JSON.stringify({ text: this.message, version: state.version, at: Date.now() })); } catch {}

        } catch (error) {
            this.notify(error.message, true);
        } finally {
            this.busy = false;
        }
    },
});
