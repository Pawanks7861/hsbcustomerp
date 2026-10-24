const ALLOWED_TAGS = ['strong', 'br', 'em', 'code'];

const PURIFY_CONFIG = {
    ALLOWED_TAGS,
    ALLOWED_ATTR: [],
    ALLOW_DATA_ATTR: false,
    ALLOW_UNKNOWN_PROTOCOLS: false,
    FORBID_TAGS: ['script', 'iframe', 'object', 'embed', 'svg', 'math', 'link', 'style', 'form', 'img', 'video', 'audio'],
    FORBID_ATTR: ['style', 'src', 'href', 'onclick', 'onerror', 'onload', 'onmouseover'],
};

/**
 * Escape untrusted text so it cannot be interpreted as HTML.
 */
export function escapeHtml(text) {
    return String(text ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

/**
 * Apply the assistant's existing light markup after HTML escaping.
 * Supported: **bold** and newline → <br>.
 */
export function applyAssistantMarkup(escapedText) {
    return String(escapedText ?? '')
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/\n/g, '<br>');
}

/**
 * Restrict Inertia link hrefs to same-app relative paths.
 */
export function safeInternalHref(url) {
    if (typeof url !== 'string') {
        return null;
    }

    const trimmed = url.trim();
    if (trimmed === '' || trimmed.startsWith('//') || trimmed.includes('\\')) {
        return null;
    }

    const lower = trimmed.toLowerCase();
    if (
        lower.startsWith('javascript:')
        || lower.startsWith('data:')
        || lower.startsWith('vbscript:')
        || lower.startsWith('file:')
    ) {
        return null;
    }

    if (trimmed.startsWith('/') && !trimmed.startsWith('//')) {
        return trimmed;
    }

    return null;
}

export function sanitizeAssistantHtml(html, purify = null) {
    const source = String(html ?? '');
    if (purify && typeof purify.sanitize === 'function') {
        return purify.sanitize(source, PURIFY_CONFIG);
    }

    return source;
}

/**
 * Format an assistant message for v-html: escape, then markup, then sanitize.
 */
export function formatAssistantText(text, purify = null) {
    return sanitizeAssistantHtml(applyAssistantMarkup(escapeHtml(text)), purify);
}

export { ALLOWED_TAGS, PURIFY_CONFIG };
