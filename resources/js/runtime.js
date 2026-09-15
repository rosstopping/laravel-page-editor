import pageEditor from './pageEditor.js';

export async function boot() {
    const notice = document.getElementById('cms-published-notice');
    if (notice && !notice.dataset.ready) {
        notice.dataset.ready = 'true';
        try { sessionStorage.removeItem(`page-editor:notice:${notice.dataset.endpoint}`); } catch {}
        const timer = setTimeout(() => notice.remove(), 6000);
        notice.querySelector('button').addEventListener('click', () => { clearTimeout(timer); notice.remove(); });
    }
    const script = document.getElementById('cms-bootstrap');
    const template = document.getElementById('cms-toolbar-template');
    if (!script || !template || window.__cmsEditorMounted) return;
    window.__cmsEditorMounted = true;
    const config = JSON.parse(script.textContent);
    const existing = window.Alpine;
    const Alpine = existing || (await import(/* @vite-ignore */ config.alpine)).default;
    if (!existing) window.Alpine = Alpine;
    Alpine.data('cmsPageEditor', () => pageEditor(config.state, config.fields, config.endpoint, config.csrf, config.exitUrl));
    const root = template.content.firstElementChild.cloneNode(true);
    if (existing) {
        Alpine.mutateDom(() => document.body.append(root));
        Alpine.initTree(root);
    } else {
        document.body.append(root);
        Alpine.start();
    }
}

// Wait for the host's deferred/module scripts before deciding whether Alpine is absent.
if (document.readyState === 'complete') boot();
else window.addEventListener('load', boot, { once: true });
