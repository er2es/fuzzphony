import { Controller } from '@hotwired/stimulus';

/**
 * Global "something is loading" feedback, attached to <body>.
 *
 * Plain page navigations (nav links, example buttons, form submits) give no feedback while the server works,
 * and some pages (Compare, Wizard, Doctor) do real work per click. On the click we therefore set
 * `data-navigating` on <html>: CSS shows the slim top bar, dims <main> and reveals the status pill. The state is
 * cleared when the page is restored from the back-forward cache, and after a safety timeout in case the
 * navigation was cancelled (Esc / stop button), which the browser does not tell us about.
 *
 * An update in place (the live search, Tune, the speed bars) is the same state without a navigation: whatever sets
 * `aria-busy` on a region of the page (not the benchmark, which shows its own progress) sets `data-busy` on <html>,
 * with the region's `data-loading-message` in the status pill, so waiting looks the same everywhere.
 */
export default class extends Controller {
    static targets = ['message'];

    connect() {
        this.onClick = (event) => this.handleClick(event);
        this.onSubmit = (event) => this.handleSubmit(event);
        this.onPageShow = () => this.stop();
        document.addEventListener('click', this.onClick);
        document.addEventListener('submit', this.onSubmit);
        window.addEventListener('pageshow', this.onPageShow);
        this.observer = new MutationObserver(() => this.sync());
        this.observer.observe(document.body, { attributes: true, attributeFilter: ['aria-busy'], subtree: true });
        this.sync();
    }

    /** The in-place state follows the first busy region; a navigation has its own (start / stop). */
    sync() {
        if ('navigating' in document.documentElement.dataset) {
            return;
        }
        const region = document.querySelector('main [aria-busy="true"]:not([data-controller~="sequence"])');
        if (region === null) {
            delete document.documentElement.dataset.busy;
            if (this.hasMessageTarget) {
                this.messageTarget.textContent = '';
            }

            return;
        }
        document.documentElement.dataset.busy = '';
        if (this.hasMessageTarget) {
            this.messageTarget.textContent = region.dataset.loadingMessage || 'Loading…';
        }
    }

    disconnect() {
        document.removeEventListener('click', this.onClick);
        document.removeEventListener('submit', this.onSubmit);
        window.removeEventListener('pageshow', this.onPageShow);
        this.observer?.disconnect();
        this.stop();
    }

    handleClick(event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
        if (!link || (link.target && link.target !== '_self') || link.hasAttribute('download')) {
            return;
        }
        const url = new URL(link.href, window.location.href);
        if (url.origin !== window.location.origin) {
            return;
        }
        if (url.hash && url.pathname === window.location.pathname && url.search === window.location.search) {
            return; // same-page anchor: no request
        }
        link.classList.add('is-pending');
        this.start(link.dataset.loadingMessage || 'Loading…');
    }

    handleSubmit(event) {
        if (event.defaultPrevented || !(event.target instanceof HTMLFormElement)) {
            return;
        }
        // A form inside a Live Component is handled by Live (fetch), not by a page load.
        if (event.target.closest('[data-controller~=live]')) {
            return;
        }
        this.start(event.target.dataset.loadingMessage || 'Loading…');
    }

    start(message) {
        clearTimeout(this.timeout);
        document.documentElement.dataset.navigating = '';
        document.querySelector('main')?.setAttribute('aria-busy', 'true');
        if (this.hasMessageTarget) {
            this.messageTarget.textContent = message; // role="status": announced politely
        }
        this.timeout = setTimeout(() => this.stop(), 60000);
    }

    stop() {
        clearTimeout(this.timeout);
        delete document.documentElement.dataset.navigating;
        document.querySelector('main')?.removeAttribute('aria-busy');
        document.querySelectorAll('a.is-pending').forEach((link) => link.classList.remove('is-pending'));
        if (this.hasMessageTarget) {
            this.messageTarget.textContent = '';
        }
    }
}
