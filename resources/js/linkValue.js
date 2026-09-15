export function safeLinkUrl(href) {
    if (typeof href !== 'string' || !href || /[\x00-\x20\x7f\\]/.test(href) || href.length > 4096) return false;
    if (/^\/(?!\/)/.test(href) || /^[#?]/.test(href)) return true;
    if (/^mailto:/i.test(href)) return !/%0[ad]/i.test(href) && /^[^@?]+@[^@?]+\.[^@?]+(?:\?.*)?$/.test(href.slice(7));
    if (/^tel:/i.test(href)) return /^tel:\+?[0-9][0-9().-]*$/i.test(href);
    if (!/^https?:\/\//i.test(href)) return false;
    try { return Boolean(new URL(href).hostname); } catch { return false; }
}
