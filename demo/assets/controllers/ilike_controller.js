import { Controller } from '@hotwired/stimulus';

/**
 * Fills the ILIKE column after the page (with Fuzzphony's column already rendered) has loaded: ILIKE is a
 * full sequential scan on this catalogue (~400 ms on 500 000 rows) while Fuzzphony answers in ~10 ms, so
 * making the whole page wait for both would throw away Fuzzphony's speed. This fetches the column's HTML
 * from its own endpoint (`data-src`, same Twig partial the response is rendered from) and swaps it in,
 * leaving the loading placeholder in place until it arrives.
 */
export default class extends Controller {
    static targets = ['region'];

    async connect() {
        this.abort = new AbortController();
        try {
            const response = await fetch(this.element.dataset.src, { signal: this.abort.signal, headers: { 'X-Requested-With': 'fetch' } });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            this.regionTarget.innerHTML = await response.text();
        } catch (error) {
            if (error.name !== 'AbortError') {
                this.regionTarget.innerHTML = `<h2>With ILIKE <code>'%…%'</code></h2><p class="muted s-error">Could not load ILIKE's results (${this.escape(error.message)}). <a href="${this.escape(this.element.dataset.src)}">Try again</a>.</p>`;
            }
        } finally {
            this.regionTarget.removeAttribute('aria-busy');
        }
    }

    disconnect() {
        this.abort?.abort();
    }

    escape(text) {
        return text.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    }
}
