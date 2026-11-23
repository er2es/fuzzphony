import { Controller } from '@hotwired/stimulus';

/**
 * Results while you type, in place: the page's own results region is fetched again (the server answers a request marked
 * `X-Requested-With: fetch` with just that region) and swapped in, the address bar follows with replaceState, and nothing
 * navigates, so the field keeps its focus and the page does not flash. Without JavaScript the form submits as always.
 * A response that arrives late is dropped (the newest request wins); a failed one leaves the old results in place and says so in one quiet line.
 * The live requests are unlogged (the half-typed words would fill the search log), except where the visitor means the search: a click
 * on an example is logged on its own request, and typed text is logged once it settles (1.5 s without typing, or at once when the
 * page is left earlier) by one more silent request whose answer is ignored.
 * A text that finds nothing, even as the beginning of a word, is answered with "Searching for a correction..." (the server marks it
 * `data-rescue-pending`) and this asks at once for the rescue ("Showing results for ..."): there is nothing to wait for, and nothing
 * on the page that could jump. A word that finds results is never rescued while it is typed.
 */

/** How long the text stands before the search counts as settled (and is logged). */
const SETTLE_MS = 1500;

export default class extends Controller {
    static targets = ['input', 'form', 'region', 'status', 'notice'];
    static values = { delay: { type: Number, default: 160 } };

    connect() {
        this.seq = 0;
        this.markExample();
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
        this.markExample();
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
     * The settled text is requested once more, marked `X-Settled`, so the server logs that one search. Nothing of the page is touched (no
     * history, no aria-busy: the busy bar and pill stay quiet) and the answer is ignored: whatever the visitor needed (the results, the
     * rescue) has been shown by the live request.
     */
    settle(url, text) {
        this.cancelSettle();
        if (text.length < 2) {
            return;
        }
        this.pending = url;
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
        fetch(url, { signal: this.settleAbort.signal, keepalive: true, headers: { 'X-Requested-With': 'fetch', 'X-Settled': '1' } }).catch(() => {});
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
            const html = await this.fetchRegion(url, headers, logged);
            if (mine !== this.seq) {
                return;
            }
            if (html === null) {
                this.failed();

                return;
            }
            this.show(html);
            history.replaceState(null, '', this.address(params, text));
            if (html.includes('data-rescue-pending')) {
                // nothing matched while typing: the rescue is asked for at once (the busy state stays until it is answered)
                const rescued = await this.fetchRegion(url, { ...headers, 'X-Rescue': '1' }, false);
                if (mine === this.seq) {
                    rescued === null ? this.failed() : this.show(rescued);
                }
            }
        } catch (error) {
            if (error.name !== 'AbortError' && mine === this.seq) {
                this.failed();
            }
        } finally {
            if (mine === this.seq) {
                this.regionTarget.removeAttribute('aria-busy');
            }
        }
    }

    /**
     * The address of the settled search: the form's own fields, but only what differs from the page's defaults, so it is the same address
     * the links of the page (facets, tokens, examples) build: no empty value, no `q` for an empty field (a reload shows the empty page, not
     * the old search), no `best` while the box is ticked (the form sends a hidden 0 and the box's 1: the 1 is the default), no `mode=both`,
     * no `tab=people`.
     */
    address(params, text) {
        const kept = new URLSearchParams(params);
        for (const [name, value] of [...kept]) {
            if (value === '') {
                kept.delete(name);
            }
        }
        if (kept.getAll('best').includes('1')) {
            kept.delete('best');
        }
        if (kept.get('mode') === 'both') {
            kept.delete('mode');
        }
        if (kept.get('tab') === 'people') {
            kept.delete('tab');
        }
        const query = kept.toString();

        return (this.formTarget.getAttribute('action') ?? location.pathname) + (query === '' ? '' : `?${query}`);
    }

    /** The old results stay (a failed update is no reason to blank the page); a quiet line says they may be out of date. */
    failed() {
        if (this.hasNoticeTarget) {
            this.noticeTarget.hidden = false;
        }
    }

    /** Swaps the fragment in and announces its one short sentence (the region itself is not a live region). */
    show(html) {
        this.regionTarget.innerHTML = html;
        if (this.hasNoticeTarget) {
            this.noticeTarget.hidden = true;
        }
        if (this.hasStatusTarget) {
            this.statusTarget.textContent = this.regionTarget.querySelector('[data-status]')?.textContent.trim() ?? '';
        }
    }

    /** The region's HTML for the url, or null when the server did not answer well. */
    async fetchRegion(url, headers, keepalive) {
        const response = await fetch(url, { signal: this.abort?.signal, keepalive, headers });

        return response.ok ? response.text() : null;
    }

    /** The example whose text is in the field is marked as the current one (a link: aria-current, and its selected style). */
    markExample() {
        const text = this.hasInputTarget ? this.inputTarget.value.trim() : '';
        for (const chip of this.element.querySelectorAll('.examples-row a.chip')) {
            const on = text !== '' && chip.dataset.liveSearchQParam === text;
            chip.classList.toggle('is-current', on);
            on ? chip.setAttribute('aria-current', 'true') : chip.removeAttribute('aria-current');
        }
    }

    /** An example token fills the field and searches it in place; it is a step of its own in the history (Back returns to the search before). */
    example(event) {
        event.preventDefault();
        history.pushState(history.state, '', location.href);
        this.inputTarget.value = event.params.q;
        this.markExample();
        this.inputTarget.focus();
        this.load(true);
    }

    clear() {
        if (this.inputTarget.value !== '') {
            history.pushState(history.state, '', location.href);
        }
        this.inputTarget.value = '';
        this.markExample();
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
