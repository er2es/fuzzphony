import { Controller } from '@hotwired/stimulus';

/**
 * The search-as-you-type dropdown, the same on every search box of the demo. While the visitor types, /suggest answers
 * with what the index knows, and this shows it in groups: the completions of the word being typed (Fuzzphony::suggest()),
 * the categories the text is found in (a facet, catalogue only, when `categories` is set) and the first hits
 * (matched as you type). Arrow keys move, Enter follows the highlighted line (or submits the form), Escape closes the panel and
 * leaves the text in the field. It is the ARIA combobox with a listbox popup: the focus stays in the input, `aria-activedescendant`
 * names the marked option, `aria-controls` the panel, the groups are `role=group` named by their heading.
 * Everything is set with textContent / href, never as HTML.
 *
 *   <form data-controller="suggest" data-suggest-url-value="/suggest" data-suggest-index-value="catalog"
 *         data-suggest-page-value="/" data-suggest-params-value='{"lang":"hu"}'>
 *       <input data-suggest-target="input" data-action="input->suggest#update" autocomplete="off">
 *       <div class="suggest-panel" data-suggest-target="panel" data-live-ignore hidden></div>
 *   </form>
 *
 * `param` is the name of the query parameter of the results page (`q`; the Playground's is `query`), `params` the
 * fixed parameters of that page. The panel is rendered by the server, so a Live Component's re-render leaves it alone
 * (`data-live-ignore`).
 */
let instances = 0;

export default class extends Controller {
    static targets = ['input', 'panel'];
    static values = {
        url: String,
        index: String,
        page: String,
        param: { type: String, default: 'q' },
        params: { type: Object, default: {} },
        categories: Boolean,
        products: { type: Boolean, default: true },
    };

    connect() {
        this.items = [];
        this.active = -1;
        // the panel is positioned inside the input's container, under the input
        const anchor = this.panelTarget.parentElement;
        if (anchor && getComputedStyle(anchor).position === 'static') {
            anchor.style.position = 'relative';
        }
        this.uid = `suggest-${++instances}`;
        this.panelTarget.id = `${this.uid}-panel`;
        this.inputTarget.setAttribute('role', 'combobox');
        this.inputTarget.setAttribute('aria-expanded', 'false');
        this.inputTarget.setAttribute('aria-autocomplete', 'list');
        this.inputTarget.setAttribute('aria-controls', this.panelTarget.id);
        this.inputTarget.setAttribute('aria-haspopup', 'listbox');
        this.panelTarget.setAttribute('role', 'listbox');
        this.onKey = (event) => this.key(event);
        this.onOutside = (event) => {
            if (!this.element.contains(event.target)) {
                this.close();
            }
        };
        this.inputTarget.addEventListener('keydown', this.onKey);
        document.addEventListener('click', this.onOutside);
    }

    update() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.load(), 120);
    }

    async load() {
        const text = this.inputTarget.value;
        this.abort?.abort();
        if (text.trim() === '') {
            this.close();

            return;
        }
        this.abort = new AbortController();
        try {
            const url = `${this.urlValue}?${new URLSearchParams({ index: this.indexValue, q: text })}`;
            const response = await fetch(url, { signal: this.abort.signal, headers: { Accept: 'application/json' } });
            if (!response.ok) {
                return;
            }
            this.render(text, await response.json());
        } catch (error) {
            if (error.name !== 'AbortError') {
                this.close(); // no suggestions is no reason to disturb the search
            }
        }
    }

    link(query, extra = {}) {
        return `${this.pageValue}?${new URLSearchParams({ ...this.paramsValue, [this.paramValue]: query, ...extra })}`;
    }

    render(text, data) {
        const sections = [];
        if (data.completions.length) {
            sections.push(['Searches', data.completions.map((c) => ({ label: c, href: this.link(c) }))]);
        }
        if (this.categoriesValue && data.categories.length) {
            sections.push(['Categories', data.categories.map((c) => ({ label: c.name, note: String(c.count), href: this.link(text, { cat: c.name }), inline: true }))]);
        }
        if (this.productsValue && data.products.length) {
            sections.push(['Products', data.products.map((p) => ({ label: p.name, note: p.note, href: this.link(p.name), icon: p.icon, hue: p.hue }))]);
        }
        this.panelTarget.replaceChildren();
        this.items = [];
        this.active = -1;
        this.inputTarget.removeAttribute('aria-activedescendant');
        for (const [group, [title, entries]] of sections.entries()) {
            // a group is named by its heading; its options are the listbox's options
            const section = document.createElement('div');
            section.setAttribute('role', 'group');
            const heading = document.createElement('div');
            heading.className = 'suggest-heading';
            heading.id = `${this.uid}-group-${group}`;
            heading.textContent = title;
            section.setAttribute('aria-labelledby', heading.id);
            section.append(heading);
            this.panelTarget.append(section);
            for (const entry of entries) {
                const item = document.createElement('a');
                item.className = 'suggest-item';
                item.href = entry.href;
                item.id = `${this.uid}-option-${this.items.length}`;
                item.tabIndex = -1; // the focus stays in the field (aria-activedescendant); Tab leaves it
                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', 'false');
                if (entry.icon) {
                    // the category's drawing on its tint, from the sprite in the page
                    const tile = document.createElement('span');
                    tile.className = 'tile' + (entry.hue < 0 ? ' is-neutral' : '');
                    tile.style.setProperty('--h', String(entry.hue));
                    tile.setAttribute('aria-hidden', 'true');
                    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                    svg.setAttribute('class', 'icon');
                    const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
                    use.setAttribute('href', `#${entry.icon}`);
                    svg.append(use);
                    tile.append(svg);
                    item.append(tile);
                }
                const body = document.createElement('span');
                body.className = 'suggest-body';
                const label = document.createElement('span');
                label.className = 'suggest-label';
                label.textContent = entry.label;
                body.append(label);
                if (entry.note && !entry.inline) {
                    const note = document.createElement('span');
                    note.className = 'suggest-note';
                    note.textContent = entry.note;
                    body.append(note);
                }
                item.append(body);
                if (entry.inline && entry.note) {
                    const count = document.createElement('span');
                    count.className = 'suggest-note';
                    count.textContent = entry.note;
                    item.classList.add('is-text');
                    item.append(count);
                }
                section.append(item);
                this.items.push(item);
            }
        }
        this.place();
        this.panelTarget.hidden = this.items.length === 0;
        this.inputTarget.setAttribute('aria-expanded', String(!this.panelTarget.hidden));
    }

    /** Under the field (the rounded box around the input, else the input), as wide as it: whatever the page lays out around it. */
    place() {
        const field = this.inputTarget.closest('.search-field') ?? this.inputTarget;
        const panel = this.panelTarget;
        panel.style.left = `${field.offsetLeft}px`;
        panel.style.top = `${field.offsetTop + field.offsetHeight + 6}px`;
        panel.style.width = `${field.offsetWidth}px`;
    }

    /** The clear button of the search field: empties it, closes the panel and puts the cursor back. */
    clear() {
        this.inputTarget.value = '';
        this.close();
        this.inputTarget.focus();
        // the pages that submit leave the old search (see clearable_controller.js); on the Tune page the Live Component follows the field
        this.inputTarget.dispatchEvent(new Event('input', { bubbles: true }));
    }

    key(event) {
        if (event.key === 'Escape') {
            // closes the panel; a search field would otherwise also empty itself and leave the results of a text that is gone
            event.preventDefault();
            this.close();

            return;
        }
        if (this.panelTarget.hidden) {
            return;
        }
        const last = this.items.length - 1; // -1 is the input itself
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            this.active = this.active >= last ? -1 : this.active + 1;
            this.mark();
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            this.active = this.active <= -1 ? last : this.active - 1;
            this.mark();
        } else if (event.key === 'Enter' && this.active >= 0) {
            event.preventDefault();
            this.items[this.active].click();
        }
    }

    mark() {
        this.items.forEach((item, i) => {
            item.classList.toggle('is-active', i === this.active);
            item.setAttribute('aria-selected', String(i === this.active));
        });
        if (this.items[this.active]) {
            this.inputTarget.setAttribute('aria-activedescendant', this.items[this.active].id);
        } else {
            this.inputTarget.removeAttribute('aria-activedescendant');
        }
        this.items[this.active]?.scrollIntoView({ block: 'nearest' });
    }

    close() {
        this.panelTarget.hidden = true;
        this.inputTarget.setAttribute('aria-expanded', 'false');
        this.inputTarget.removeAttribute('aria-activedescendant');
        this.active = -1;
    }

    disconnect() {
        clearTimeout(this.timer);
        this.abort?.abort();
        this.inputTarget.removeEventListener('keydown', this.onKey);
        document.removeEventListener('click', this.onOutside);
    }
}
