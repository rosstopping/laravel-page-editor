import { test } from 'node:test';
import assert from 'node:assert/strict';
import pageEditor from '../../resources/js/pageEditor.js';

globalThis.window = { location: { href: 'https://example.test/page?edit=1', assign() {} }, removeEventListener() {} };

const initial = () => ({ draft: { title: 'Original' }, version: 0, history: [{ version: 0, content: { title: 'Original' } }] });

test('draft save carries CSRF/version and publish is explicit', async () => {
    const editor = pageEditor(initial(), {}, '/_editor/make-reservation', 'token');
    editor.content.title = 'New text';
    assert.equal(editor.dirty, true);
    let submitted;
    globalThis.fetch = async (url, options) => {
        submitted = JSON.parse(options.body);
        assert.equal(options.headers['X-CSRF-TOKEN'], 'token');
        return { ok: true, json: async () => ({ version: 1, history: [] }) };
    };
    await editor.save('draft');
    assert.equal(submitted.action, 'draft');
    assert.equal(submitted.version, 0);
    assert.equal(editor.dirty, false);
    assert.equal(editor.version, 1);
    await editor.save('publish');
    assert.equal(submitted.action, 'publish');
});

test('conflict keeps unsaved text, history loading does not publish', async () => {
    const editor = pageEditor(initial(), {}, '/save', 'token');
    editor.content.title = 'My edits';
    globalThis.fetch = async () => ({ ok: false, status: 409 });
    await editor.save('publish');
    assert.match(editor.error, /Another editor/);
    assert.equal(editor.content.title, 'My edits');
    assert.equal(editor.dirty, true);
    assert.equal(editor.version, 0);
    globalThis.fetch = () => assert.fail('Loading a revision must not save');
    editor.restore(0);
    assert.equal(editor.content.title, 'Original');
});

test('plain text remains escaped when prepared for the rich editor', async () => {
    const { toHtml } = await import('../../resources/js/formattedText.js');
    assert.equal(toHtml('<img src=x onerror=alert(1)>'), '&lt;img src=x onerror=alert(1)&gt;');
});

test('successful save notification survives a reload and is dismissible', async () => {
    const values = new Map();
    globalThis.sessionStorage = {
        getItem: key => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, value),
        removeItem: key => values.delete(key),
    };
    globalThis.fetch = async () => ({ ok: true, json: async () => ({ version: 1, history: [] }) });
    const editor = pageEditor(initial(), {}, '/notification', 'token');
    await editor.save('draft');
    const reloaded = pageEditor({ ...initial(), version: 1 }, {}, '/notification', 'token');
    reloaded.restoreNotification();
    assert.equal(reloaded.message, 'Draft saved. The live page has not changed.');
    reloaded.dismissNotification();
    assert.equal(reloaded.message, '');
    assert.equal(values.size, 0);
    editor.dismissNotification();
    delete globalThis.sessionStorage;
});

test('publishing exits edit mode only after success; draft saving stays put', async () => {
    const navigations = [];
    globalThis.window = { location: { assign: url => navigations.push(url) } };
    const editor = pageEditor(initial(), {}, '/save', 'token', '/published-page');
    globalThis.fetch = async () => ({ ok: true, json: async () => ({ version: 1, history: [] }) });
    await editor.save('draft');
    assert.deepEqual(navigations, []);
    await editor.save('publish');
    assert.deepEqual(navigations, ['/published-page']);
    assert.equal(editor.dirty, false);
    editor.dismissNotification();
});

test('image replacement synchronises repeated previews and preserves alt text', async () => {
    const photo = JSON.stringify({ src: '/original.jpg', alt: 'Original alt' });
    const editor = pageEditor({ draft: { photo }, defaults: { photo }, manifest: 'manifest', version: 0, history: [] }, { photo: { format: 'image' } }, '/_editor/page', 'csrf');
    editor.selected = 'photo';
    editor.updateImage('alt', 'New alt');
    globalThis.fetch = async (url, options) => {
        assert.equal(url, '/_editor/page/images');
        assert.equal(options.headers['X-CSRF-TOKEN'], 'csrf');
        assert.equal(options.body.get('field'), 'photo');
        assert.equal(options.body.get('manifest'), 'manifest');
        return { ok: true, json: async () => ({ src: '/storage/cms-images/new.jpg' }) };
    };
    await editor.uploadImage(new Blob(['image'], { type: 'image/jpeg' }));
    assert.deepEqual(editor.imageValue(), { src: '/storage/cms-images/new.jpg', alt: 'New alt' });
    assert.equal(editor.dirty, true);
    globalThis.document = { querySelector: () => null };
    const image = () => ({ dataset: { cmsImage: 'photo' }, attributes: {}, getAttribute(name) { return this.attributes[name]; }, setAttribute(name, value) { this.attributes[name] = value; }, classList: { toggle() {} } });
    editor.imageElements = [image(), image()];
    editor.syncDocument();
    for (const element of editor.imageElements) {
        assert.equal(element.attributes.src, '/storage/cms-images/new.jpg');
        assert.equal(element.alt, 'New alt');
    }
    editor.content.photo = editor.defaults.photo;
    editor.syncDocument();
    assert.equal(editor.imageElements[0].attributes.src, '/original.jpg');
    editor.dismissNotification();
});

test('content browser searches copy and labels, filters images, and excludes metadata', () => {
    const editor = pageEditor({ draft: { heading: 'Welcome to Zante', image: JSON.stringify({ src: '/boat.jpg', alt: 'Sunset yacht party' }), seo_title: 'Search title', og_title: 'Social title' } }, {
        heading: { label: 'Home Hero Heading · home.blade.php', format: 'rich' },
        image: { label: 'Home Boat Image', format: 'image' },
        seo_title: { label: 'Page title', format: 'plain' },
        og_title: { label: 'og:title', format: 'plain', meta: {} },
    });
    editor.plainPreview = value => value;
    assert.equal(editor.fieldLabel('heading'), 'Hero Heading');
    assert.equal(editor.contentFields.length, 2);
    editor.search = 'zante';
    assert.deepEqual(editor.filteredFields.map(([key]) => key), ['heading']);
    editor.search = 'sunset';
    assert.deepEqual(editor.filteredFields.map(([key]) => key), ['image']);
    editor.contentFilter = 'text';
    assert.equal(editor.filteredFields.length, 0);
    editor.search = ''; editor.contentFilter = 'image';
    assert.deepEqual(editor.filteredFields.map(([key]) => key), ['image']);
    editor.selectField('image');
    assert.equal(editor.selected, 'image');
    assert.equal(editor.expanded, true);
});

test('publish follows the server exit URL before running notification code', async () => {
    const navigations = [];
    globalThis.window = { location: { assign: url => navigations.push(url) }, removeEventListener() {} };
    const editor = pageEditor(initial(), {}, '/save', 'token', '/page?edit=1');
    editor.notify = () => { throw new Error('Toast unavailable'); };
    globalThis.fetch = async () => ({ ok: true, json: async () => ({ version: 2, history: [], redirect_url: '/page?month=6' }) });
    await editor.save('publish');
    assert.deepEqual(navigations, ['/page?month=6']);
    assert.equal(editor.dirty, false);
    assert.equal(editor.busy, false);
});

test('edit mode starts minimised and inline text selection preserves tool visibility', () => {
    const editor = pageEditor(initial(), {}, '/save', 'token');
    assert.equal(editor.expanded, false);
    editor.selectField('title', false);
    assert.equal(editor.selected, 'title');
    assert.equal(editor.expanded, false);
    editor.expanded = true;
    editor.selectField('title', false);
    assert.equal(editor.expanded, true);
    editor.expanded = false;
    editor.selectField('image');
    assert.equal(editor.expanded, true);
});

test('local reset requires confirmation and clears only CMS browser keys after success', async () => {
    const storage = () => ({ values: new Map([['page-editor:notice:test', 'notice'], ['site-preference', 'keep']]), get length() { return this.values.size; }, key(i) { return [...this.values.keys()][i]; }, removeItem(key) { this.values.delete(key); } });
    const navigations = [];
    globalThis.window = { confirm: () => false, localStorage: storage(), sessionStorage: storage(), location: { assign: url => navigations.push(url) }, removeEventListener() {} };
    const editor = pageEditor(initial(), {}, '/save', 'token', '/page');
    globalThis.fetch = () => assert.fail('Cancelled reset must not send a request');
    await editor.resetCms('/reset');
    window.confirm = () => true;
    globalThis.fetch = async (url, options) => {
        assert.equal(url, '/reset');
        assert.equal(options.headers['X-CSRF-TOKEN'], 'token');
        assert.deepEqual(JSON.parse(options.body), { confirm: true });
        return { ok: true };
    };
    await editor.resetCms('/reset');
    assert.deepEqual(navigations, ['/page']);
    for (const store of [window.localStorage, window.sessionStorage]) assert.deepEqual([...store.values.keys()], ['site-preference']);
});

test('publishing strips every edit parameter from stale redirects and current-URL fallback', async () => {
    const navigations = [];
    globalThis.window = { location: { href: 'https://example.test/page?edit=1&month=6#details', assign: url => navigations.push(url) }, removeEventListener() {} };
    for (const redirect of ['/page?edit=1&month=6&edit=1#details', null]) {
        const editor = pageEditor(initial(), {}, '/save', 'token');
        globalThis.fetch = async () => ({ ok: true, json: async () => ({ version: 1, history: [], redirect_url: redirect }) });
        await editor.save('publish');
        assert.equal(editor.error, '');
        const destination = new URL(navigations.at(-1), window.location.href);
        assert.equal(destination.searchParams.has('edit'), false);
        assert.equal(destination.searchParams.get('month'), '6');
        assert.equal(destination.hash, '#details');
    }
    assert.equal(navigations.length, 2);
});

test('undo groups typing, covers metadata and images, and invalidates redo on new edits', () => {
    const editor = pageEditor(initial(), {}, '/save', 'token');
    editor.syncDocument = () => {};
    globalThis.document = { querySelectorAll: () => [] };
    editor.content.title = 'First';
    editor.content.title = 'Finished typing';
    editor.checkpoint();
    editor.replaceContent({ ...editor.content, seo_title: 'SEO', photo: '{"src":"/one.jpg","alt":"Keep"}' });
    editor.undo();
    assert.deepEqual(editor.content, { title: 'Finished typing' });
    editor.undo();
    assert.deepEqual(editor.content, { title: 'Original' });
    assert.equal(editor.dirty, false);
    editor.redo();
    assert.equal(editor.content.title, 'Finished typing');
    editor.content.title = 'Another direction';
    assert.equal(editor.canRedo, false);
    editor.undo();
    assert.equal(editor.content.title, 'Finished typing');
    editor.redo();
    assert.equal(editor.content.title, 'Another direction');
});

test('undo updates the active inline DOM and revision restore can itself be undone', () => {
    const element = { dataset: { editableField: 'title' }, innerHTML: 'Edited' };
    globalThis.document = { querySelectorAll: () => [element] };
    const editor = pageEditor(initial(), {}, '/save', 'token');
    editor.syncDocument = () => {};
    editor.notify = () => {};
    editor.content.title = 'Edited';
    editor.restore(0);
    editor.undo();
    assert.equal(editor.content.title, 'Edited');
    assert.equal(element.innerHTML, 'Edited');
    editor.undo();
    assert.equal(element.innerHTML, 'Original');
});

test('library preserves alt text, selection is undoable, and stale responses are ignored', async () => {
    const editor = pageEditor({ ...initial(), draft: { photo: '{"src":"/old.jpg","alt":"Keep me"}' } }, { photo: { format: 'image' } }, '/save', 'token');
    editor.syncDocument = () => {};
    editor.notify = () => {};
    globalThis.document = { querySelectorAll: () => [] };
    editor.selected = 'photo';
    let resolve;
    globalThis.fetch = () => new Promise(done => { resolve = done; });
    const request = editor.openLibrary();
    editor.selectField('elsewhere', false);
    resolve({ ok: true, json: async () => ({ images: [{ src: '/stale.jpg' }], has_more: false }) });
    await request;
    assert.deepEqual(editor.libraryItems, []);
    editor.selected = 'photo';
    editor.chooseLibraryImage({ src: '/new.jpg' });
    assert.deepEqual(editor.imageValue(), { src: '/new.jpg', alt: 'Keep me' });
    editor.undo();
    assert.equal(editor.imageValue().src, '/old.jpg');
});

test('editor status distinguishes unsaved fields, uploading and publishing', () => {
    const editor = pageEditor(initial(), { og_title: { meta: { name: 'og:title', index: 0 } } }, '/save', 'token');
    assert.equal(editor.statusLabel, 'No unsaved changes');
    editor.content.title = 'New';
    assert.equal(editor.statusLabel, '1 unsaved field');
    editor.content.og_title = 'Social';
    assert.equal(editor.statusLabel, '2 unsaved fields');
    editor.busy = true;
    editor.operation = 'upload';
    assert.equal(editor.statusLabel, 'Uploading image…');
    editor.operation = 'publish';
    assert.equal(editor.statusLabel, 'Publishing…');
    assert.equal(editor.metadataLabel('og_title'), 'Social title');
});

test('link editing previews labels and destinations, preserves decorations, and supports undo', () => {
    const initialLink = JSON.stringify({ href: '/reviews', text: 'Read reviews' });
    const editor = pageEditor({ ...initial(), draft: { cta: initialLink } }, { cta: { label: 'Reviews link', format: 'link' } }, '/save', 'token');
    const attributes = {};
    const label = { textContent: '' };
    const anchor = { dataset: { cmsLink: 'cta' }, setAttribute: (k,v) => attributes[k] = v, removeAttribute: k => delete attributes[k], querySelector: () => label, classList: { toggle() {} } };
    editor.linkElements = [anchor];
    globalThis.document = { querySelector: () => null, querySelectorAll: () => [] };
    editor.selected = 'cta';
    editor.updateLink('href', '/new');
    editor.updateLink('text', 'New label');
    editor.syncDocument();
    assert.equal(attributes.href, '/new');
    assert.equal(label.textContent, 'New label');
    editor.undo();
    assert.equal(attributes.href, '/reviews');
    assert.equal(label.textContent, 'Read reviews');
    editor.updateLink('href', 'javascript:alert(1)');
    editor.syncDocument();
    assert.equal(attributes.href, undefined);
    assert.match(editor.linkError(), /destination/);
    editor.contentFilter = 'link';
    assert.equal(editor.filteredFields.length, 1);
    editor.contentFilter = 'text';
    assert.equal(editor.filteredFields.length, 0);
});

test('link destinations allow navigation schemes without allowing executable URLs', async () => {
    const { safeLinkUrl } = await import('../../resources/js/linkValue.js');
    for (const href of ['https://example.com', '/reviews', '#guests', '?sort=latest', 'mailto:hello@example.com', 'tel:+441234567890']) assert.equal(safeLinkUrl(href), true, href);
    for (const href of ['', '//example.com', 'javascript:alert(1)', 'data:text/html,test', '/\\evil.com', 'https://example.com\n', 'mailto:hello@example.com?subject=x%0Abcc:test']) assert.equal(safeLinkUrl(href), false, href);
});

test('restoring a text-only link revision keeps its label with the markup destination', () => {
    const original = JSON.stringify({ href: '/reviews', text: 'Read reviews' });
    const editor = pageEditor({ draft: { cta: original }, defaults: { cta: original }, history: [{ content: { cta: 'Previous label' } }] }, { cta: { format: 'link' } }, '/save', 'token');
    editor.plainPreview = value => value;
    editor.notify = () => {};
    editor.restore(0);
    assert.deepEqual(editor.linkValue('cta'), { href: '/reviews', text: 'Previous label' });
    assert.equal(editor.linkError('cta'), '');
});

test('layout preferences persist without changing content and fall back on narrow screens', () => {
    const values = new Map();
    window.localStorage = { getItem: key => values.get(key), setItem: (key, value) => values.set(key, value) };
    const editor = pageEditor(initial(), {}, '/save', 'token');
    editor.content.title = 'Unsaved work';
    editor.chooseLayout('left');
    assert.equal(editor.dockLayout, 'left');
    assert.equal(editor.dirty, true);
    assert.equal(editor.content.title, 'Unsaved work');
    assert.equal(values.get('page-editor:layout'), 'left');
    editor.layoutWide = false;
    assert.equal(editor.dockLayout, 'bottom');
    assert.equal(editor.layout, 'left');
    editor.layoutWide = true;
    assert.equal(editor.dockLayout, 'left');
    editor.expanded = false;
    assert.equal(editor.dockLayout, 'left');
    editor.preview = true;
    assert.equal(editor.dockLayout, 'left');
    const nextPage = pageEditor(initial(), {}, '/other', 'token');
    nextPage.restoreLayout();
    assert.equal(nextPage.layout, 'left');
    assert.equal(nextPage.expanded, false);
    nextPage.chooseLayout('invalid');
    assert.equal(nextPage.layout, 'left');
});

test('layout selection works when browser storage is blocked', () => {
    window.localStorage = { getItem() { throw new Error('Blocked'); }, setItem() { throw new Error('Blocked'); } };
    const editor = pageEditor(initial(), {}, '/save', 'token');
    editor.restoreLayout();
    assert.equal(editor.layout, 'bottom');
    editor.chooseLayout('left');
    assert.equal(editor.dockLayout, 'left');
});


test('removed right preference falls back to bottom and left remains open until exit', () => {
    window.localStorage = { getItem: () => 'right' };
    const editor = pageEditor(initial(), {}, '/save', 'token');
    editor.restoreLayout();
    assert.equal(editor.dockLayout, 'bottom');
    editor.chooseLayout('right');
    assert.equal(editor.layout, 'bottom');
    editor.chooseLayout('left');
    editor.minimise();
    assert.equal(editor.dockLayout, 'left');
    assert.equal(editor.expanded, true);
    editor.preview = true;
    assert.equal(editor.dockLayout, 'left');
    editor.layoutWide = false;
    assert.equal(editor.selectedLayout, 'bottom');
    assert.equal(editor.dockLayout, 'bottom');
});

test('import requires confirmation, sends ZIP with CSRF, and reloads after success', async () => {
    const editor = pageEditor(initial(), {}, '/save', 'token', '/page');
    editor.importArchive = new Blob(['archive']);
    editor.content.title = 'Unsaved';
    window.confirm = () => false;
    globalThis.fetch = () => assert.fail('Cancelled import must not submit');
    await editor.importCms('/import');
    assert.equal(editor.dirty, true);
    window.confirm = () => true;
    let destination;
    window.location.assign = url => destination = url;
    globalThis.fetch = async (url, options) => {
        assert.equal(url, '/import');
        assert.equal(options.headers['X-CSRF-TOKEN'], 'token');
        assert.equal(options.body.get('confirm'), '1');
        assert.equal(await options.body.get('archive').text(), 'archive');
        return { ok: true };
    };
    await editor.importCms('/import');
    assert.equal(destination, '/page');
    assert.equal(editor.busy, false);
    assert.equal(editor.dirty, false);
});

test('failed import retains unsaved edits and shows validation errors', async () => {
    const editor = pageEditor(initial(), {}, '/save', 'token');
    editor.importArchive = new Blob(['bad']);
    editor.content.title = 'Unsaved';
    window.confirm = () => true;
    window.location.assign = () => assert.fail('Failed import must not navigate');
    globalThis.fetch = async () => ({ ok: false, status: 422, json: async () => ({ errors: { archive: ['Invalid archive.'] } }) });
    await editor.importCms('/import');
    assert.equal(editor.error, 'Invalid archive.');
    assert.equal(editor.dirty, true);
    assert.equal(editor.busy, false);
});

test('export blocks unsaved edits and reports server failures', async () => {
    const editor = pageEditor(initial(), {}, '/save', 'token');
    editor.content.title = 'Unsaved';
    globalThis.fetch = () => assert.fail('Save edits before exporting');
    await editor.exportCms('/export');
    editor.content.title = 'Original';
    globalThis.fetch = async (url, options) => {
        assert.equal(url, '/export');
        assert.equal(options.method, 'POST');
        assert.equal(options.headers['X-CSRF-TOKEN'], 'token');
        return { ok: false, status: 419 };
    };
    await editor.exportCms('/export');
    assert.match(editor.error, /session expired/);
    assert.equal(editor.busy, false);
});
