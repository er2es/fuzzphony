import { Controller } from '@hotwired/stimulus';

/**
 * Results while you type, in place: the page's own results region is fetched again (the server answers a request marked
 * `X-Requested-With: fetch` with just that region) and swapped in, the address bar follows with replaceState, and nothing
 * navigates, so the field keeps its focus and the page does not flash. Without JavaScript the form submits as always.
 * A response that arrives late is dropped (the newest request wins); a failed one leaves the old results in place.
 * The live requests are unlogged (the half-typed words would fill the search log), except where the visitor means the search: a click
 * on an example is logged on its own request, and typed text is logged once it settles (1.5 s without typing, or at once when the
 * page is left earlier) by one more silent request.
 * Only a search the visitor means is rescued when it finds nothing ("Showing results for ..."): not the live requests while typing, but the
 * settled one: its answer is swapped in when it carries the rescue bar (and is still the newest), and nothing else of the page is touched.
 */

/** How long the text stands before the search counts as settled (and is logged). */
const SETTLE_MS = 1500;

export default class extends Controller {
    static targets = ['input', 'form', 'region'];
    static values = { delay: { type: Number, default: 160 } };

    connect() {
        this.seq = 0;
        this.onLeave = () => {
            if (document.visibilityState === 'hidden') {
                this.flushSettle();
            }
        };
        // the page is being left (a link, the back button, a closed tab): a pending settle is sent now, not lost
        window.addEventListener('pagehide', this.onLeave);
        document.addEventListener('visibilitychange', this.onLeave);
    }

    update() {
        this.cancelSettle();
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.load(), this.delayValue);
    }

    /** A pending or running settle request is dropped: the visitor is still typing. */
    cancelSettle() {
        clearTimeout(this.settleTimer);
        this.pending = null;
        this.settleAbort?.abort();
    }

    /**
     * The settled text is requested once more, marked `X-Settled`, so the server logs that one search (and rescues it when it found
     * nothing). Nothing of the page is touched (no history, no aria-busy: the busy bar and pill stay quiet), and the answer is ignored
     * unless it is a rescued one, which replaces the empty results (see flushSettle()).
     */
    settle(url, text) {
        this.cancelSettle();
        if (text.length < 2) {
            return;
        }
        this.pending = url;
        this.settleSeq = this.seq;
        this.settleTimer = setTimeout(() => this.flushSettle(), SETTLE_MS);
    }

    /** Sends the pending settle now; `keepalive` lets the request outlive a page that is being left. */
    flushSettle() {
        clearTimeout(this.settleTimer);
        const url = this.pending;
        this.pending = null;
        if (url === null || url === undefined) {
            return;
        }
        this.settleAbort = new AbortController();
        const seq = this.settleSeq;
        fetch(url, { signal: this.settleAbort.signal, keepalive: true, headers: { 'X-Requested-With': 'fetch', 'X-Settled': '1' } })
            .then((response) => (response.ok ? response.text() : ''))
            .then((html) => {
                // the zero-result rescue: the typed text found nothing, and the settled search found its correction. Nothing newer was asked.
                if (seq === this.seq && html.includes('class="corrected"') && this.hasRegionTarget) {
                    this.regionTarget.innerHTML = html;
                }
            })
            .catch(() => {});
    }

    /** The form's own fields (and the hidden filters next to the text) are the request. */
    async load(logged = false) {
        const params = new URLSearchParams(new FormData(this.formTarget));
        const text = (params.get('q') ?? '').trim();
        const url = `${this.formTarget.getAttribute('action') ?? location.pathname}?${params}`;
        const mine = ++this.seq;
        // an explicit search (an example) is logged by its own request: no settle for it, and it is never aborted (a newer
        // search only makes its answer stale), because an aborted request may never reach the log
        if (logged) {
            this.cancelSettle();
        } else {
            this.settle(url, text);
        }
        this.abort?.abort();
        this.abort = logged ? null : new AbortController();
        const headers = logged ? { 'X-Requested-With': 'fetch', 'X-Settled': '1' } : { 'X-Requested-With': 'fetch' };
        this.regionTarget.dataset.loadingMessage = text === '' ? 'Loading…' : `Searching for ${text}…`;
        this.regionTarget.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(url, { signal: this.abort?.signal, keepalive: logged, headers });
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
        this.load(true);
    }

    clear() {
        this.inputTarget.value = '';
        this.inputTarget.focus();
        this.load();
    }

    disconnect() {
        clearTimeout(this.timer);
        window.removeEventListener('pagehide', this.onLeave);
        document.removeEventListener('visibilitychange', this.onLeave);
        this.cancelSettle();
        this.abort?.abort();
    }
}
