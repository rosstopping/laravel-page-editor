import { toHtml } from './formattedText.js';

const escape = value => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
const tokens = value => String(value).match(/\s+|[\p{L}\p{N}_]+|[^\s\p{L}\p{N}_]/gu) || [];

// Bound the work for long fields; every path escapes content before adding markup.
export function wordDiff(before, after) {
    const a = tokens(before), b = tokens(after);
    if (before === after) return { before: escape(before), after: escape(after) };
    const mark = (text, kind) => text ? `<mark class="cms-diff-${kind}">${escape(text)}</mark>` : '';
    if (a.length * b.length > 250000) return { before: mark(before, 'removed'), after: mark(after, 'added') };
    const lengths = Array.from({ length: a.length + 1 }, () => new Uint16Array(b.length + 1));
    for (let i = a.length - 1; i >= 0; i--) {
        for (let j = b.length - 1; j >= 0; j--) {
            lengths[i][j] = a[i] === b[j] ? lengths[i + 1][j + 1] + 1 : Math.max(lengths[i + 1][j], lengths[i][j + 1]);
        }
    }
    let i = 0, j = 0, oldHtml = '', newHtml = '', removed = '', added = '';
    const flush = () => { oldHtml += mark(removed, 'removed'); newHtml += mark(added, 'added'); removed = ''; added = ''; };
    while (i < a.length || j < b.length) {
        if (i < a.length && j < b.length && a[i] === b[j]) {
            flush(); oldHtml += escape(a[i]); newHtml += escape(b[j]); i++; j++;
        } else if (i < a.length && (j === b.length || lengths[i + 1][j] >= lengths[i][j + 1])) removed += a[i++];
        else added += b[j++];
    }
    flush();
    return { before: oldHtml, after: newHtml };
}

function structured(value, keys) {
    try {
        const parsed = JSON.parse(value);
        if (parsed && keys.every(key => typeof parsed[key] === 'string')) return Object.fromEntries(keys.map(key => [key, parsed[key]]));
    } catch { /* Legacy values are still shown as text. */ }
    return null;
}

export function equivalent(before, after, format) {
    if (before === after) return true;
    if (['image', 'link'].includes(format)) {
        const keys = format === 'image' ? ['src', 'alt'] : ['text', 'href'];
        const a = structured(before, keys), b = structured(after, keys);
        return a !== null && b !== null && JSON.stringify(a) === JSON.stringify(b);
    }
    if (format === 'rich') return toHtml(String(before ?? '')) === toHtml(String(after ?? ''));
    return false;
}

const plainRich = value => {
    const holder = document.createElement('div');
    holder.innerHTML = toHtml(String(value ?? '')).replace(/<br\s*\/?\s*>/gi, '\n');
    return holder.textContent || '';
};

export function comparison(before, after, format) {
    const row = (label, a, b) => ({ label, ...wordDiff(a, b), changed: a !== b, emptyBefore: a === '', emptyAfter: b === '' });
    if (format === 'image') {
        const a = structured(before, ['src', 'alt']), b = structured(after, ['src', 'alt']);
        if (a && b) return { rows: [row('Image URL', a.src, b.src), row('Alt text', a.alt, b.alt)], images: { before: a, after: b } };
    }
    if (format === 'link') {
        const a = structured(before, ['text', 'href']), b = structured(after, ['text', 'href']);
        if (a && b) return { rows: [row('Link text', a.text, b.text), row('Destination', a.href, b.href)] };
    }
    if (format === 'rich') {
        const a = plainRich(before), b = plainRich(after);
        return { rows: [row('Text', a, b)], rich: { before: toHtml(String(before ?? '')), after: toHtml(String(after ?? '')) }, formattingOnly: a === b };
    }
    return { rows: [row('Text', String(before ?? ''), String(after ?? ''))] };
}

export function safePreviewImage(src) {
    return typeof src === 'string' && !/[\x00-\x20\x7f\\]/.test(src) && /^(https?:\/\/|\/(?!\/))/i.test(src) ? src : '';
}
