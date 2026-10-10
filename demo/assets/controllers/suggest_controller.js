import { Controller } from '@hotwired/stimulus';

/**
 * Search-as-you-type for a search box. While the visitor types, /suggest answers with what the index knows.
 *
 * Basic (the default): the word being typed, completed from the index's vocabulary (Fuzzphony::suggest()), offered
 * in the input's <datalist>; the browser shows and filters the options.
 *
 *   <form data-controller="suggest" data-suggest-url-value="/suggest" data-suggest-index-value="catalog">
 *       <input data-suggest-target="input" data-action="input->suggest#update" list="suggestions">
 *       <datalist id="suggestions" data-suggest-target="list"></datalist>
 *   </form>
 *
 * Rich (`data-suggest-rich-value="true"`, with `data-suggest-page-value` = the results page): the grouped dropdown of a
 * shop, built from the three calls the endpoint makes: completions, the categories the text is found in (a facet),
 * and the first products. Arrow keys move, Enter follows, Escape closes. Everything is set with textContent / href,
 * never as HTML.
 */
export default class extends Controller {
    static targets = ['input', 'list'];
    static values = { url: String, index: String, rich: Boolean, page: String };

    connect() {
        this.items = [];
        this.active = -1;
        if (this.richValue) {
            this.panel = document.createElement('div');
            this.panel.className = 'suggest-panel';
            this.panel.setAttribute('role', 'listbox');
            this.panel.hidden = true;
            this.element.style.position = 'relative';
            this.element.append(this.panel);
            this.inputTarget.setAttribute('role', 'combobox');
            this.inputTarget.setAttribute('aria-expanded', 'false');
            this.inputTarget.setAttribute('aria-autocomplete', 'list');
            this.onKey = (event) => this.key(event);
            this.onOutside = (event) => {
                if (!this.element.contains(event.target)) {
                    this.close();
                }
            };
            this.inputTarget.addEventListener('keydown', this.onKey);
            document.addEventListener('click', this.onOutside);
        }
    }

    update() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.load(), 120);
    }

    async load() {
        const text = this.inputTarget.value;
        this.abort?.abort();
        if (text.trim() === '') {
            this.richValue ? this.close() : this.listTarget.replaceChildren();

            return;
        }
        this.abort = new AbortController();
        try {
            const params = { index: this.indexValue, q: text };
            if (this.richValue) {
                params.rich = '1';
            }
            const response = await fetch(`${this.urlValue}?${new URLSearchParams(params)}`, { signal: this.abort.signal, headers: { Accept: 'application/json' } });
            if (!response.ok) {
                return;
            }
            const data = await response.json();
            this.richValue ? this.render(text, data) : this.options(data);
        } catch (error) {
            if (error.name !== 'AbortError') {
                this.richValue ? this.close() : this.listTarget.replaceChildren(); // no suggestions is no reason to disturb the search
            }
        }
    }

    options(suggestions) {
        this.listTarget.replaceChildren(...suggestions.map((suggestion) => {
            const option = document.createElement('option');
            option.value = suggestion;

            return option;
        }));
    }

    render(text, data) {
        const sections = [];
        const link = (query, extra = {}) => `${this.pageValue}?${new URLSearchParams({ q: query, ...extra })}`;
        if (data.completions.length) {
            sections.push(['Searches', data.completions.map((c) => ({ label: c, href: link(c) }))]);
        }
        if (data.categories.length) {
            sections.push(['Categories', data.categories.map((c) => ({ label: c.name, note: String(c.count), href: link(text, { cat: c.name }) }))]);
        }
        if (data.products.length) {
            sections.push(['Products', data.products.map((p) => ({
                label: p.name,
                note: `${p.brand} · ${p.category} · ${new Intl.NumberFormat().format(p.price)} Ft`,
                href: link(p.name),
                product: true,
            }))]);
        }
        this.panel.replaceChildren();
        this.items = [];
        this.active = -1;
        for (const [title, entries] of sections) {
            const heading = document.createElement('div');
            heading.className = 'suggest-heading';
            heading.textContent = title;
            this.panel.append(heading);
            for (const entry of entries) {
                const item = document.createElement('a');
                item.className = 'suggest-item' + (entry.product ? ' is-product' : '');
                item.href = entry.href;
                item.setAttribute('role', 'option');
                const label = document.createElement('span');
                label.className = 'suggest-label';
                label.textContent = entry.label;
                item.append(label);
                if (entry.note) {
                    const note = document.createElement('span');
                    note.className = 'suggest-note muted';
                    note.textContent = entry.note;
                    item.append(note);
                }
                this.panel.append(item);
                this.items.push(item);
            }
        }
        this.panel.hidden = this.items.length === 0;
        this.inputTarget.setAttribute('aria-expanded', String(!this.panel.hidden));
    }

    key(event) {
        if (this.panel.hidden) {
            return;
        }
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const step = event.key === 'ArrowDown' ? 1 : -1;
            const last = this.items.length - 1; // -1 is the input itself
            this.active = step > 0 ? (this.active >= last ? -1 : this.active + 1) : (this.active <= -1 ? last : this.active - 1);
            this.mark();
        } else if (event.key === 'Enter' && this.active >= 0) {
            event.preventDefault();
            this.items[this.active].click();
        } else if (event.key === 'Escape') {
            this.close();
        }
    }

    mark() {
        this.items.forEach((item, i) => {
            item.classList.toggle('is-active', i === this.active);
            item.setAttribute('aria-selected', String(i === this.active));
        });
        this.items[this.active]?.scrollIntoView({ block: 'nearest' });
    }

    close() {
        if (this.panel) {
            this.panel.hidden = true;
            this.inputTarget.setAttribute('aria-expanded', 'false');
        }
        this.active = -1;
    }

    disconnect() {
        clearTimeout(this.timer);
        this.abort?.abort();
        if (this.richValue) {
            this.inputTarget.removeEventListener('keydown', this.onKey);
            document.removeEventListener('click', this.onOutside);
            this.panel?.remove();
        }
    }
}
