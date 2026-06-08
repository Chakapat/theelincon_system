/**
 * Convert navigation <a class="btn ..."> to <a class="tnc-link-nav|tnc-link-icon ...">
 * Keeps action anchors (delete, submit-like) unchanged.
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

const SKIP_DIRS = new Set(['vendor', 'node_modules', '.git', '.cursor', 'assets']);

const ACTION_MARKERS = [
    'tnc-delete-post',
    'btn-invoice-action-delete',
    'action=delete',
    'action-handler.php?action=delete',
    'data-bs-toggle',
    'data-bs-dismiss',
    'onclick',
    'href="#"',
    "href='#'",
    'window.print',
    'btn-outline-success',
    'download=',
];

function hasBootstrapBtnClass(cls) {
    return cls.split(/\s+/).some((c) => c === 'btn' || c.startsWith('btn-'));
}

const STRIP_CLASSES = new Set([
    'rounded-pill', 'rounded-3', 'rounded-circle', 'shadow-sm', 'shadow',
    'px-3', 'px-4', 'py-2', 'fw-semibold', 'fw-bold', 'border',
    'btn-back-home', 'btn-white', 'text-warning', 'text-white',
    'btn-lg', 'btn-sm', 'ms-2', 'me-3', 'd-none', 'no-print',
]);

function walk(dir, out = []) {
    for (const name of fs.readdirSync(dir)) {
        if (SKIP_DIRS.has(name)) continue;
        const full = path.join(dir, name);
        const st = fs.statSync(full);
        if (st.isDirectory()) {
            walk(full, out);
        } else if (name.endsWith('.php')) {
            out.push(full);
        }
    }
    return out;
}

function isActionAnchor(tag) {
    const lower = tag.toLowerCase();
    return ACTION_MARKERS.some((m) => lower.includes(m.toLowerCase()));
}

function pickLinkClass(tag) {
    const lower = tag.toLowerCase();
    if (lower.includes('btn-invoice-action-delete') || lower.includes('tnc-delete-post')) {
        return null;
    }
    if (lower.includes('btn-invoice-action-view') || (lower.includes('bi-eye') && lower.includes('invoice-action'))) {
        return 'tnc-link-icon tnc-link-icon--view';
    }
    if (lower.includes('btn-invoice-action-edit') || (lower.includes('bi-pencil') && lower.includes('invoice-action'))) {
        return 'tnc-link-icon tnc-link-icon--edit';
    }
    if (lower.includes('btn-invoice-action-tax') || lower.includes('file-earmark-check')) {
        return 'tnc-link-icon tnc-link-icon--tax';
    }
    if (lower.includes('btn-orange') || lower.includes('bi-plus-lg') || /เพิ่ม|สร้าง|ออกใบ|ออก po|ออก wo|ออกเอกสาร/.test(tag)) {
        return 'tnc-link-nav tnc-link-nav--primary';
    }
    if (/กลับ|ย้อน|ยกเลิก|cancel|arrow-left|chevron-left|ไปหน้า/.test(lower)) {
        return 'tnc-link-nav tnc-link-nav--back';
    }
    if (lower.includes('btn-sm') && lower.includes('pencil')) {
        return 'tnc-link-icon tnc-link-icon--edit';
    }
    if (lower.includes('btn-sm') && (lower.includes('eye') || lower.includes('เปิด'))) {
        return 'tnc-link-icon tnc-link-icon--view';
    }
    return 'tnc-link-nav';
}

function hasNestedHtmlTag(openTag) {
    const rest = openTag.slice(2);
    let i = 0;
    while (i < rest.length) {
        if (rest[i] === '<') {
            if (rest.slice(i, i + 2) === '<?') {
                const end = rest.indexOf('?>', i);
                i = end >= 0 ? end + 2 : rest.length;
                continue;
            }
            return true;
        }
        i += 1;
    }
    return false;
}

function mergeLinkClass(linkClass, cls) {
    const kept = cls
        .split(/\s+/)
        .filter((c) => c && !/^btn/.test(c) && !STRIP_CLASSES.has(c) && !/^px-/.test(c) && !/^py-/.test(c))
        .join(' ');
    return kept ? `${linkClass} ${kept}` : linkClass;
}

function processContent(content) {
    const ANCHOR_CLASS = /class=(["'])([^"']*\bbtn\b[^"']*)\1/gi;
    return content.replace(ANCHOR_CLASS, (fullMatch, quote, cls, offset, str) => {
        if (!hasBootstrapBtnClass(cls)) {
            return fullMatch;
        }
        if (/\btnc-link-(?:nav|icon)\b/.test(cls)) {
            return fullMatch;
        }

        const before = str.slice(0, offset);
        const aIdx = before.lastIndexOf('<a');
        if (aIdx < 0) {
            return fullMatch;
        }
        const between = before.slice(aIdx);
        if (/<\/a>/i.test(between)) {
            return fullMatch;
        }
        const afterClass = str.slice(offset + fullMatch.length);
        const tagEnd = afterClass.indexOf('>');
        if (tagEnd < 0) {
            return fullMatch;
        }

        const openTag = str.slice(aIdx, offset + fullMatch.length + tagEnd + 1);
        if (!/^<a[\s>]/i.test(openTag)) {
            return fullMatch;
        }
        if (hasNestedHtmlTag(openTag)) {
            return fullMatch;
        }
        if (isActionAnchor(openTag)) {
            return fullMatch;
        }

        const snippetEnd = Math.min(str.length, offset + fullMatch.length + tagEnd + 80);
        const linkClass = pickLinkClass(openTag + str.slice(offset + fullMatch.length, snippetEnd));
        if (!linkClass) {
            return fullMatch;
        }

        const merged = mergeLinkClass(linkClass, cls);
        return `class=${quote}${merged.trim()}${quote}`;
    });
}

const files = [
    path.join(root, 'index.php'),
    ...walk(path.join(root, 'pages')),
    ...walk(path.join(root, 'components')).filter((f) => f.endsWith('.php')),
];

let changed = 0;
for (const file of files) {
    const before = fs.readFileSync(file, 'utf8');
    const after = processContent(before);
    if (after !== before) {
        fs.writeFileSync(file, after, 'utf8');
        changed++;
        console.log('updated:', path.relative(root, file));
    }
}
console.log(`done: ${changed} files`);
