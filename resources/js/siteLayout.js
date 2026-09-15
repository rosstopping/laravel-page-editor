// Reserve space beside the page while preserving its normal window scrolling.
export function createSiteLayout() {
    let active = false;
    let frame;
    const marked = new Set();
    const observer = new MutationObserver(records => {
        if (records.some(record => !record.target.closest?.('.cms-tool, [data-editable-field], .cms-rich-input'))) schedule();
    });
    function clearMarks() {
        for (const element of marked) {
            element.removeAttribute('data-cms-fixed-position');
            element.style.removeProperty('--cms-original-left');
        }
        marked.clear();
    }
    function schedule() {
        if (active && !frame) frame = requestAnimationFrame(refresh);
    }
    function refresh() {
        frame = null;
        if (!active) return;
        observer.disconnect();
        clearMarks();
        const viewport = document.documentElement.clientWidth;
        const elements = [];
        for (const element of document.body.querySelectorAll('*')) {
            if (element.closest('.cms-tool') || getComputedStyle(element).position !== 'fixed') continue;
            // A transformed ancestor already gives fixed descendants a local containing block.
            let local = false;
            for (let parent = element.parentElement; parent && parent !== document.body; parent = parent.parentElement) {
                const style = getComputedStyle(parent);
                if (style.transform !== 'none' || style.filter !== 'none' || style.perspective !== 'none' || /paint|layout|strict|content/.test(style.contain) || /transform|filter|perspective/.test(style.willChange)) { local = true; break; }
            }
            if (local) continue;
            const style = getComputedStyle(element);
            const rect = element.getBoundingClientRect();
            const left = parseFloat(style.left);
            if (!Number.isFinite(left)) continue;
            const mode = rect.width >= viewport - 32 ? 'full'
                : Math.abs(rect.left + rect.width / 2 - viewport / 2) < 24 ? 'center'
                : rect.left < viewport - rect.right ? 'left' : 'right';
            elements.push({ element, left, mode });
        }
        for (const { element, left, mode } of elements) {
            element.style.setProperty('--cms-original-left', `${left}px`);
            element.setAttribute('data-cms-fixed-position', mode);
            marked.add(element);
        }
        observer.observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['class', 'style'] });
    }
    return {
        setActive(value) {
            if (active === value) return;
            active = value;
            observer.disconnect();
            document.body.classList.toggle('cms-editor-left', active);
            if (active) { window.addEventListener('resize', schedule); refresh(); }
            else { window.removeEventListener('resize', schedule); cancelAnimationFrame(frame); frame = null; clearMarks(); }
        },
        destroy() { this.setActive(false); },
    };
}
