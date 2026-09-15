export const prefix = '__cms_html__:';
const escape = text => text.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');

export function cleanHtml(html) {
    const template = document.createElement('template');
    template.innerHTML = html;
    const walk = node => {
        if (node.nodeType === 3) return escape(node.textContent);
        if (node.nodeType !== 1) return '';
        const tag = { B: 'strong', STRONG: 'strong', I: 'em', EM: 'em', U: 'u', BR: 'br' }[node.tagName];
        const children = Array.from(node.childNodes).map(walk).join('');
        if (tag === 'br') return '<br>';
        if (tag) return `<${tag}>${children}</${tag}>`;
        return children + (['DIV', 'P'].includes(node.tagName) ? '\n' : '');
    };
    return Array.from(template.content.childNodes).map(walk).join('');
}

export function toHtml(value) {
    return value.startsWith(prefix) ? cleanHtml(value.slice(prefix.length)) : escape(value);
}

export function fromHtml(html) {
    return prefix + cleanHtml(html);
}
