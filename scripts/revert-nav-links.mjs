/**
 * Restore navigation <a class="tnc-link-*"> back to original <a class="btn ...">
 * using git HEAD as source of truth for class attributes.
 */
import fs from 'fs';
import path from 'path';
import { execSync } from 'child_process';
import { fileURLToPath } from 'url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

const SKIP_DIRS = new Set(['vendor', 'node_modules', '.git', '.cursor', 'assets']);

function walk(dir, out = []) {
    for (const name of fs.readdirSync(dir)) {
        if (SKIP_DIRS.has(name)) continue;
        const full = path.join(dir, name);
        const st = fs.statSync(full);
        if (st.isDirectory()) walk(full, out);
        else if (name.endsWith('.php')) out.push(full);
    }
    return out;
}

function gitShow(rel) {
    try {
        return execSync(`git show HEAD:${rel.replace(/\\/g, '/')}`, { cwd: root, encoding: 'utf8' });
    } catch {
        return null;
    }
}

function normalizeHref(href) {
    return href.replace(/\s+/g, ' ').trim();
}

function extractAnchorClasses(content) {
    const map = new Map();
    const re = /<a\s([^>]*?)>/gi;
    let m;
    while ((m = re.exec(content)) !== null) {
        const attrs = m[1];
        const classM = attrs.match(/\bclass=(["'])([^"']*)\1/i);
        const hrefM = attrs.match(/\bhref=(["'])([\s\S]*?)\1/i);
        if (!classM || !hrefM) continue;
        if (!/\bbtn\b/.test(classM[2]) && !/\bbtn-/.test(classM[2])) continue;
        const key = normalizeHref(hrefM[2]);
        if (!map.has(key)) map.set(key, classM[2]);
    }
    return map;
}

function restoreFromGit(file) {
    const rel = path.relative(root, file);
    const current = fs.readFileSync(file, 'utf8');
    if (!/\btnc-link-(?:nav|icon)\b/.test(current)) return false;

    const original = gitShow(rel);
    if (!original) return false;

    const classByHref = extractAnchorClasses(original);
    if (classByHref.size === 0) return false;

    let changed = false;
    const updated = current.replace(/<a\s([^>]*?)>/gi, (full, attrs) => {
        if (!/\btnc-link-(?:nav|icon)\b/.test(attrs)) return full;
        const hrefM = attrs.match(/\bhref=(["'])([\s\S]*?)\1/i);
        if (!hrefM) return full;
        const origClass = classByHref.get(normalizeHref(hrefM[2]));
        if (!origClass) return full;
        const newAttrs = attrs.replace(/\bclass=(["'])[^"']*\1/i, `class="${origClass}"`);
        changed = true;
        return `<a ${newAttrs}>`;
    });

    if (changed && updated !== current) {
        fs.writeFileSync(file, updated, 'utf8');
        return true;
    }
    return false;
}

const files = [
    path.join(root, 'index.php'),
    ...walk(path.join(root, 'pages')),
];

let changed = 0;
for (const file of files) {
    if (restoreFromGit(file)) {
        changed++;
        console.log('reverted:', path.relative(root, file));
    }
}
console.log(`done: ${changed} files`);
