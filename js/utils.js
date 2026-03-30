export function countWords(str) {
    if (!str.trim()) return 0;
    if (typeof Intl !== 'undefined' && Intl.Segmenter) {
        const segmenter = new Intl.Segmenter('th', { granularity: 'word' });
        const segments = segmenter.segment(str);
        let wordCount = 0;
        for (const segment of segments) {
            if (segment.isWordLike) wordCount++;
        }
        return wordCount;
    }
    return str.trim().split(/\s+/).length;
}

export function formatTime(totalSeconds) {
    const m = Math.floor(totalSeconds / 60).toString().padStart(2, '0');
    const s = Math.floor(totalSeconds % 60).toString().padStart(2, '0');
    return `${m}:${s}`;
}

export function escapeHTML(str) {
    if (!str) return '';
    return str.toString().replace(/[&<>'"]/g, tag => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        "'": '&#39;',
        '"': '&quot;'
    }[tag] || tag));
}
