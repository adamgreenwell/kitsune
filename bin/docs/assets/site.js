'use strict';

const search = document.querySelector('#search');
const results = document.querySelector('#search-results');
const status = document.querySelector('#search-status');
let index;
let generation = 0;

document.querySelector('form[role="search"]')?.addEventListener('submit', event => event.preventDefault());

const mobile = window.matchMedia('(max-width: 700px)');
const browse = document.querySelector('.browse');
if (mobile.matches && browse) browse.open = false;

document.addEventListener('keydown', event => {
    if (event.key === '/' && !event.ctrlKey && !event.metaKey && !event.altKey &&
        !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName) &&
        !document.activeElement?.isContentEditable) {
        event.preventDefault();
        search?.focus();
    }
    if (event.key === 'Escape' && document.activeElement === search) {
        search.value = '';
        search.dispatchEvent(new Event('input'));
    }
});

async function loadIndex() {
    if (!index) {
        index = fetch('search-index.json').then(response => {
            if (!response.ok) throw new Error('index unavailable');
            return response.json();
        }).catch(error => {
            index = undefined;
            throw error;
        });
    }
    return index;
}

search?.addEventListener('input', async () => {
    const current = ++generation;
    const query = search.value.trim().toLocaleLowerCase();
    results.replaceChildren();
    if (!query) {
        status.textContent = '';
        return;
    }
    status.textContent = 'Searching this snapshot…';
    try {
        const records = await loadIndex();
        if (current !== generation) return;
        const terms = query.split(/\s+/).filter(Boolean);
        const matches = records.map(record => {
            const heading = `${record.title} ${record.section}`.toLocaleLowerCase();
            const body = record.body.toLocaleLowerCase();
            return {record, score: terms.every(term => heading.includes(term) || body.includes(term))
                ? terms.reduce((sum, term) => sum + (heading.includes(term) ? 3 : 1), 0) : 0};
        }).filter(match => match.score > 0).sort((a, b) => b.score - a.score);
        status.textContent = matches.length ? `${matches.length} matching sections. Showing ${Math.min(matches.length, 20)}.`
            : 'No matching sections. Try a command, task or ADR number, or browse the guides.';
        for (const {record} of matches.slice(0, 20)) {
            const item = document.createElement('li');
            const link = document.createElement('a');
            link.href = record.href;
            link.textContent = record.section === record.title ? record.title : `${record.title} — ${record.section}`;
            const excerpt = document.createElement('p');
            const text = record.body.replace(/\s+/g, ' ').trim();
            const at = text.toLocaleLowerCase().indexOf(terms[0]);
            const start = Math.max(0, at - 35);
            excerpt.textContent = `${start ? '…' : ''}${text.slice(start, start + 160)}${start + 160 < text.length ? '…' : ''}`;
            item.append(link, excerpt);
            results.append(item);
        }
    } catch {
        if (current === generation) status.textContent = 'Search could not load. Serve this artifact over local HTTP, or browse the guides.';
    }
});

// Older immutable pages discover new snapshots without being rewritten.
fetch('../versions.json').then(response => {
    if (!response.ok) throw new Error('catalogue unavailable');
    return response.json();
}).then(versions => {
    const list = document.querySelector('#versions');
    const page = document.documentElement.dataset.page;
    for (const version of versions) {
        if (!/^[a-z0-9][a-z0-9.-]*-[a-f0-9]{12}$/.test(version.id) || version.id === document.documentElement.dataset.snapshot) continue;
        const target = version.pages.includes(page) ? page : version.home;
        if (!/^[a-z0-9_-]+$/.test(target)) continue;
        const item = document.createElement('li');
        const link = document.createElement('a');
        link.href = `../${version.id}/${target}.html`;
        link.textContent = `${version.label} · ${version.commit.slice(0, 12)}`;
        item.append(link);
        list.append(item);
    }
}).catch(() => {}); // The ordinary All built snapshots link remains usable.
