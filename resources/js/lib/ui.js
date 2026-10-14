// Small shared helpers for the page scripts. Everything user-supplied goes through esc()
// before it is placed in HTML.

const ENTITIES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

export const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ENTITIES[c]);

export const money = (value) => {
    if (value === null || value === undefined || value === '') return '';
    return Number(value).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};

/** "YYYY-MM-DD" to a readable date without a timezone shift. */
export const fmtDate = (iso) => {
    if (!iso) return '';
    const [y, m, d] = iso.split('-').map(Number);
    return new Date(y, m - 1, d).toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });
};

export const fmtDateTime = (iso) => {
    if (!iso) return '';
    return new Date(iso).toLocaleString('en-PH', { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
};

export const fmtSize = (bytes) => {
    if (!bytes) return '';
    return bytes > 1048576 ? (bytes / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(bytes / 1024)) + ' KB';
};

/** Text shown for a failed AJAX call; 422 bodies are flattened to their first messages. */
export const errorText = (xhr, fallback = 'Something went wrong. Please try again.') => {
    const body = xhr.responseJSON || {};
    if (xhr.status === 422 && body.errors) {
        return Object.values(body.errors).flat().slice(0, 3).join(' ');
    }
    if (xhr.status === 429) return 'Too many requests. Please wait a moment.';
    if (xhr.status === 403) return 'You are not allowed to do that.';
    return body.message || fallback;
};
