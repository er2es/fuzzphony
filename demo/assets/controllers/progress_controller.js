import { Controller } from '@hotwired/stimulus';

/**
 * Global "something is loading" feedback, attached to <body>.
 *
 * Plain page navigations (nav links, example buttons, form submits) give no feedback while the server works,
 * and some pages (Compare, Wizard, Doctor) do real work per click. On the click we therefore set
 * `data-navigating` on <html>: CSS shows the slim top bar, dims <main> and reveals the status pill. The state is
 * cleared when the page is restored from the back-forward cache, and after a safety timeout in case the
 * navigation was cancelled (Esc / stop button), which the browser does not tell us about.
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
    }

    disconnect() {
        document.removeEventListener('click', this.onClick);
        document.removeEventListener('submit', this.onSubmit);
        window.removeEventListener('pageshow', this.onPageShow);
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
