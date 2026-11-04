import { Controller } from '@hotwired/stimulus';

/**
 * The search box of the header: one text searched in every index of the demo at once (GET /magic, a federated search), the hits grouped
 * by index with the index's hit count and a link to the index's own page. It is a modal <dialog> (the browser traps the focus inside
 * and closes it on Escape, then returns the focus to what had it) holding the ARIA combobox pattern of the suggestion panels: the focus stays
 * in the input, `aria-activedescendant` names the marked option, the groups are `role=group` named by their heading. Arrow keys move
 * through the rows and the "all N in ..." links, Enter follows the marked one, Home/End jump.
 *
 * Opened by the header button, Ctrl/Cmd+K anywhere, and "/" on a page that has no search field of its own (shortcut_controller.js hands
 * it over; on the others "/" focuses their field). As you type it asks after 150 ms of quiet and cancels the request it replaces.
 * Everything is set with textContent / href, never as HTML.
 */
export default class extends Controller {
    static targets = ['dialog', 'input', 'list', 'state', 'lede', 'status', 'note', 'spinner', 'opener', 'hint'];
    static values = { url: String, lang: { type: String, default: 'en' } };

    connect() {
        this.items = [];
        this.active = -1;
        if (/Mac|iPhone|iPad/.test(navigator.platform)) {
            this.hintTarget.textContent = '⌘K';
        }
        this.ledeText = this.ledeTarget.textContent;
        this.onKey = (event) => {
            if ((event.ctrlKey || event.metaKey) && !event.altKey && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                this.open();
            }
        };
        this.onOpen = () => this.open();
        document.addEventListener('keydown', this.onKey);
        document.addEventListener('magic:open', this.onOpen);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKey);
        document.removeEventListener('magic:open', this.onOpen);
        clearTimeout(this.timer);
        this.abort?.abort();
    }

    open() {
        if (!this.dialogTarget.open) {
            this.dialogTarget.showModal();
        }
        this.inputTarget.focus();
        this.inputTarget.select();
        if (this.inputTarget.value.trim() !== '' && this.items.length === 0 && !this.failed) {
            this.load();
        }
    }

    close() {
        this.dialogTarget.close();
    }

    closed() {
        clearTimeout(this.timer);
        this.abort?.abort();
        this.spinnerTarget.classList.remove('is-on');
    }

    /** A click on the backdrop (the dialog element itself, outside its content box) closes it. */
    backdrop(event) {
        if (event.target === this.dialogTarget) {
            this.close();
        }
    }

    update() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.load(), 150);
    }

    example(event) {
        this.inputTarget.value = event.params.example;
        this.inputTarget.focus();
        this.load();
    }

    clear() {
        this.inputTarget.value = '';
        this.inputTarget.focus();
        this.load();
    }

    async load() {
        clearTimeout(this.timer);
        this.abort?.abort();
        const text = this.inputTarget.value;
        if (text.trim() === '') {
            this.spinnerTarget.classList.remove('is-on');
            this.empty('', this.ledeText);

            return;
        }
        const controller = new AbortController();
        this.abort = controller;
        this.spinnerTarget.classList.add('is-on');
        try {
            const response = await fetch(`${this.urlValue}?${new URLSearchParams({ q: text, lang: this.langValue })}`, { signal: controller.signal, headers: { Accept: 'application/json' } });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                this.failed = true;
                this.empty('error', data.error ?? `The search failed (HTTP ${response.status}).`);
            } else {
                this.failed = false;
                this.render(text, data);
            }
        } catch (error) {
            if (error.name === 'AbortError') {
                return; // a newer request replaced this one: it says the spinner off
            }
            this.failed = true;
            this.empty('error', 'The search could not be reached. Check the connection and type again.');
        }
        this.spinnerTarget.classList.remove('is-on');
    }

    /** The message state: the help (no text), nothing found, or an error; the example chips stay under it. */
    empty(kind, message) {
        this.listTarget.replaceChildren();
        this.items = [];
        this.active = -1;
        this.stateTarget.hidden = false;
        this.stateTarget.classList.toggle('is-error', kind === 'error');
        this.ledeTarget.textContent = message;
        if (kind === 'error') {
            this.ledeTarget.setAttribute('role', 'alert');
        } else {
            this.ledeTarget.removeAttribute('role');
        }
        this.inputTarget.setAttribute('aria-expanded', 'false');
        this.inputTarget.removeAttribute('aria-activedescendant');
        this.statusTarget.textContent = kind === '' ? '' : message;
        this.noteTarget.textContent = '';
    }

    render(text, data) {
        if (data.groups.length === 0) {
            this.empty('none', `Nothing found for “${text}” in any index.`);
            this.noteTarget.textContent = this.noteText(data);

            return;
        }
        this.stateTarget.hidden = true;
        this.ledeTarget.removeAttribute('role');
        this.listTarget.replaceChildren();
        this.items = [];
        this.active = -1;
        this.inputTarget.removeAttribute('aria-activedescendant');
        const hits = (n, lower) => `${n.toLocaleString('en-US')}${lower ? '+' : ''} ${n === 1 ? 'hit' : 'hits'}`;
        for (const [g, group] of data.groups.entries()) {
            const section = document.createElement('div');
            section.className = 'magic-group';
            section.setAttribute('role', 'group');
            section.dataset.index = group.index;
            const heading = document.createElement('div');
            heading.className = 'magic-heading';
            heading.id = `magic-group-${g}`;
            const title = document.createElement('span');
            title.textContent = group.title;
            const count = document.createElement('span');
            count.className = 'magic-count';
            count.textContent = hits(group.total, group.lowerBound);
            heading.append(title, count);
            section.setAttribute('aria-labelledby', heading.id);
            section.append(heading);
            for (const row of group.rows) {
                const item = this.option(row.url);
                const body = document.createElement('span');
                body.className = 'magic-main';
                const name = document.createElement('span');
                name.className = 'magic-title';
                name.textContent = row.title;
                body.append(name);
                if (row.sub) {
                    const sub = document.createElement('span');
                    sub.className = 'magic-sub';
                    sub.textContent = row.sub;
                    body.append(sub);
                }
                item.append(body);
                if (row.kind !== group.index) {
                    const kind = document.createElement('span');
                    kind.className = 'flag is-ok';
                    kind.title = 'kind';
                    kind.textContent = row.kind;
                    item.append(kind);
                }
                section.append(item);
            }
            const all = this.option(group.url);
            all.classList.add('magic-all');
            all.textContent = `All ${group.total.toLocaleString('en-US')}${group.lowerBound ? '+' : ''} in ${group.title}`;
            section.append(all);
            this.listTarget.append(section);
        }
        this.inputTarget.setAttribute('aria-expanded', 'true');
        this.noteTarget.textContent = this.noteText(data);
        const total = data.groups.reduce((sum, g) => sum + g.total, 0);
        this.statusTarget.textContent = `${total.toLocaleString('en-US')} hits in ${data.groups.length} ${data.groups.length === 1 ? 'index' : 'indexes'}.`;
    }

    noteText(data) {
        const viewer = data.viewer ? `Intranet notes as ${data.viewer}` : 'Intranet notes: nobody is signed in';

        return `${viewer} · ${Number(data.tookMs).toLocaleString('en-US', { maximumFractionDigits: 1 })} ms`;
    }

    option(href) {
        const item = document.createElement('a');
        item.className = 'magic-item';
        item.href = href;
        item.id = `magic-option-${this.items.length}`;
        item.tabIndex = -1; // the focus stays in the input (aria-activedescendant)
        item.setAttribute('role', 'option');
        item.setAttribute('aria-selected', 'false');
        item.addEventListener('mousemove', () => {
            const i = this.items.indexOf(item);
            if (i !== this.active) {
                this.active = i;
                this.mark(false);
            }
        });
        this.items.push(item);

        return item;
    }

    key(event) {
        const last = this.items.length - 1;
        if (event.key === 'ArrowDown' && last >= 0) {
            event.preventDefault();
            this.active = this.active >= last ? 0 : this.active + 1;
            this.mark();
        } else if (event.key === 'ArrowUp' && last >= 0) {
            event.preventDefault();
            this.active = this.active <= 0 ? last : this.active - 1;
            this.mark();
        } else if (event.key === 'Home' && this.active >= 0) {
            event.preventDefault();
            this.active = 0;
            this.mark();
        } else if (event.key === 'End' && this.active >= 0) {
            event.preventDefault();
            this.active = last;
            this.mark();
        } else if (event.key === 'Enter') {
            // a marked row is followed; with none marked, the first row (what the box found best) is
            event.preventDefault();
            clearTimeout(this.timer);
            (this.items[this.active] ?? this.items[0])?.click();
        }
    }

    mark(scroll = true) {
        this.items.forEach((item, i) => {
            item.classList.toggle('is-active', i === this.active);
            item.setAttribute('aria-selected', String(i === this.active));
        });
        const current = this.items[this.active];
        if (current) {
            this.inputTarget.setAttribute('aria-activedescendant', current.id);
            if (scroll) {
                current.scrollIntoView({ block: 'nearest' });
            }
        } else {
            this.inputTarget.removeAttribute('aria-activedescendant');
        }
    }
}
