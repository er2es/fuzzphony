import { Controller } from '@hotwired/stimulus';

/**
 * Fills placeholder rows one at a time: every `[data-src]` row is replaced by the HTML its URL returns.
 *
 * The benchmark takes several seconds; loading it row by row makes the page appear instantly, shows honest
 * progress, and keeps the measurements fair (one query batch at a time, never in parallel).
 */
export default class extends Controller {
    static targets = ['progress', 'label', 'done'];

    async connect() {
        this.abort = new AbortController();
        const rows = [...this.element.querySelectorAll('[data-src]')];
        this.progressTarget.max = rows.length;
        this.progressTarget.value = 0;
        try {
            for (const [index, row] of rows.entries()) {
                this.labelTarget.textContent = `Running ${index + 1} of ${rows.length}: ${row.dataset.label}…`;
                const response = await fetch(row.dataset.src, { signal: this.abort.signal, headers: { 'X-Requested-With': 'fetch' } });
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                const template = document.createElement('template');
                template.innerHTML = (await response.text()).trim();
                row.replaceWith(template.content);
                this.progressTarget.value = index + 1;
            }
            this.finish(`Done: ${rows.length} queries measured.`);
        } catch (error) {
            if (error.name !== 'AbortError') {
                this.finish(`Stopped: ${error.message}. Reload the page to retry.`, true);
            }
        }
    }

    disconnect() {
        this.abort?.abort();
    }

    finish(text, failed = false) {
        this.labelTarget.textContent = text;
        this.element.classList.toggle('is-failed', failed);
        this.element.classList.add('is-done');
        this.element.removeAttribute('aria-busy');
        this.doneTargets.forEach((element) => (element.hidden = false));
    }
}
