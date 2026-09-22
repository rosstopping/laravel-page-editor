import { test } from 'node:test';
import assert from 'node:assert/strict';
import { wordDiff, comparison, equivalent, safePreviewImage } from '../../resources/js/changes.js';
import pageEditor from '../../resources/js/pageEditor.js';

test('word comparisons mark only replacements and escape executable markup', () => {
    assert.deepEqual(wordDiff('Hello old world', 'Hello new world'), {
        before: 'Hello <mark class="cms-diff-removed">old</mark> world',
        after: 'Hello <mark class="cms-diff-added">new</mark> world',
    });
    const diff = wordDiff('Safe', '<img src=x onerror=alert(1)>');
    assert.ok(!diff.after.includes('<img'));
    assert.match(diff.after, /&lt;/);
    assert.equal(wordDiff('', '').after, '');
    assert.equal(wordDiff('Same', 'Same').before, 'Same');
    assert.match(wordDiff('', '新しい').after, /cms-diff-added/);
    assert.match(wordDiff('Deleted', '').before, /cms-diff-removed/);
});

test('long comparisons fall back to bounded whole-value highlighting', () => {
    const before = 'old '.repeat(1000), after = 'new '.repeat(1000);
    const diff = wordDiff(before, after);
    assert.equal(diff.before, `<mark class="cms-diff-removed">${before}</mark>`);
    assert.equal(diff.after, `<mark class="cms-diff-added">${after}</mark>`);
});

test('structured values compare semantics and describe individual image and link changes', () => {
    assert.equal(equivalent('{"alt":"Hero","src":"/a.png"}', '{"src":"/a.png", "alt":"Hero"}', 'image'), true);
    const image = comparison('{"src":"/a.png","alt":"Old"}', '{"src":"/a.png","alt":"New"}', 'image');
    assert.equal(image.rows[0].changed, false);
    assert.equal(image.rows[1].changed, true);
    assert.equal(image.images.after.src, '/a.png');
    const link = comparison('{"href":"/old","text":"Read"}', '{"href":"/new","text":"Read"}', 'link');
    assert.equal(link.rows[0].changed, false);
    assert.match(link.rows[1].after, /cms-diff-added/);
    assert.equal(comparison('Legacy text', 'New text', 'link').rows[0].label, 'Text');
    for (const value of ['javascript:alert(1)', '//external.test/x', '/\\evil.test/x', 'https://example.test/\nx']) assert.equal(safePreviewImage(value), '');
    assert.equal(safePreviewImage('/storage/hero.png'), '/storage/hero.png');
});

const makeEditor = () => pageEditor({
    defaults: { title: 'Default', seo_title: 'Default SEO', unchanged: 'Same' },
    draft: { title: 'Draft', seo_title: 'Default SEO', unchanged: 'Same' },
    published: { title: 'Published' }, version: 1, history: [],
}, { title: { format: 'plain', label: 'Heading', shared: true }, seo_title: { format: 'plain' }, unchanged: { format: 'plain' } }, '/save', 'token');

test('comparisons separate unpublished content from published overrides and preserve empty values', () => {
    const editor = makeEditor();
    assert.deepEqual(editor.changedFields.map(entry => entry.key), ['title']);
    assert.equal(editor.changeEntries[0].before, 'Published');
    assert.equal(editor.changeEntries[0].after, 'Draft');
    assert.equal(editor.changeEntries[0].field.shared, true);
    editor.changeMode = 'overrides';
    assert.equal(editor.changeEntries[0].before, 'Default');
    assert.equal(editor.changeEntries[0].after, 'Published');
    editor.content.title = '';
    assert.equal(editor.changeEntries[0].after, 'Published');
    editor.changeMode = 'unpublished';
    assert.equal(editor.changeEntries[0].rows[0].emptyAfter, true);
    editor.content.title = 'Published';
    assert.equal(editor.changedFields.length, 0);
    editor.content.seo_title = 'New SEO';
    assert.equal(editor.changeEntries[0].label, 'Page title');
});

test('saving a draft keeps unpublished comparisons until published', async () => {
    const editor = makeEditor();
    editor.notify = () => {};
    editor.dismissNotification = () => {};
    globalThis.fetch = async () => ({ ok: true, json: async () => ({ version: 2, history: [], published: { title: 'Published' } }) });
    await editor.save('draft');
    assert.equal(editor.dirty, false);
    assert.equal(editor.changedFields.length, 1);
    assert.equal(editor.changedFields[0].after, 'Draft');
    editor.published = { ...editor.defaults, title: 'Draft' };
    assert.equal(editor.changedFields.length, 0);
    editor.changeMode = 'overrides';
    assert.equal(editor.changedFields.length, 1);
});

test('revision restore and undo update the change list without publishing', () => {
    const editor = makeEditor();
    editor.history = [{ content: { title: 'Earlier' } }];
    editor.notify = () => {};
    editor.syncDocument = () => {};
    globalThis.document = { querySelectorAll: () => [] };
    editor.restore(0);
    assert.equal(editor.changedFields[0].after, 'Earlier');
    editor.undo();
    assert.equal(editor.changedFields[0].after, 'Draft');
    assert.equal(editor.published.title, 'Published');
});

test('page highlights track comparison mode, repeated fields, and preview without changing content', () => {
    const editor = makeEditor();
    const node = key => ({ dataset: { editableField: key }, classList: { toggle(name, on) { this[name] = on; } } });
    editor.inlineElements = [node('title'), node('title'), node('unchanged')];
    editor.highlightChanges = true;
    editor.syncChangeHighlights();
    assert.deepEqual(editor.inlineElements.map(el => el.classList['cms-field-changed']), [true, true, false]);
    editor.preview = true;
    editor.syncChangeHighlights();
    assert.equal(editor.inlineElements[0].classList['cms-field-changed'], false);
    editor.preview = false;
    editor.content.title = 'Published';
    editor.syncChangeHighlights();
    assert.equal(editor.inlineElements[0].classList['cms-field-changed'], false);
    editor.changeMode = 'overrides';
    editor.syncChangeHighlights();
    assert.equal(editor.inlineElements[0].classList['cms-field-changed'], true);
    editor.highlightChanges = false;
    editor.syncChangeHighlights();
    assert.equal(editor.inlineElements[0].classList['cms-field-changed'], false);
});
