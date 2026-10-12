import { Controller } from '@hotwired/stimulus';

/**
 * Results while you type, in place: the page's own results region is fetched again (the server answers a request marked
 * `X-Requested-With: fetch` with just that region) and swapped in, the address bar follows with replaceState, and nothing
 * navigates, so the field keeps its focus and the page does not flash. Without JavaScript the form submits as always.
 * A response that arrives late is dropped (the newest request wins); a failed one leaves the old results in place.
 */
export default class extends Controller {
    static targets = ['input', 'form', 'region'];
    static values = { delay: { type: Number, default: 160 } };

    connect() {
        this.seq = 0;
    }

    update() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.load(), this.delayValue);
    }

    /** The form's own fields (and the hidden filters next to the text) are the request. */
    async load() {
        const params = new URLSearchParams(new FormData(this.formTarget));
        const text = (params.get('q') ?? '').trim();
        const url = `${this.formTarget.getAttribute('action') ?? location.pathname}?${params}`;
        const mine = ++this.seq;
        this.abort?.abort();
        this.abort = new AbortController();
        this.regionTarget.dataset.loadingMessage = text === '' ? 'Loading…' : `Searching for ${text}…`;
        this.regionTarget.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(url, { signal: this.abort.signal, headers: { 'X-Requested-With': 'fetch' } });
            if (!response.ok || mine !== this.seq) {
                return;
            }
            this.regionTarget.innerHTML = await response.text();
            history.replaceState(null, '', text === '' ? location.pathname : url);
        } catch (error) {
            if (error.name !== 'AbortError') {
                // keep the old results: a failed update is no reason to blank the page
            }
        } finally {
            if (mine === this.seq) {
                this.regionTarget.removeAttribute('aria-busy');
            }
        }
    }

    /** An example token fills the field and searches it in place. */
    example(event) {
        event.preventDefault();
        this.inputTarget.value = event.params.q;
        this.inputTarget.focus();
        this.load();
    }

    clear() {
        this.inputTarget.value = '';
        this.inputTarget.focus();
        this.load();
    }

    disconnect() {
        clearTimeout(this.timer);
        this.abort?.abort();
    }
}
