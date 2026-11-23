import { Controller } from '@hotwired/stimulus';

/**
 * Back and Forward for pages that change their address without navigating (the Tune page's Live Component, the live search).
 * A discrete action (a facet, a chip, a token, "Clear all", an example) first pushes a copy of the current entry (`push`), and the
 * update that follows rewrites the new entry's address; typing only rewrites the current entry, so it never floods the history.
 * Going back to an entry the page changed in place has nothing to restore from, so the page is loaded again from that address.
 */
export default class extends Controller {
    connect() {
        this.onPop = () => location.reload();
        window.addEventListener('popstate', this.onPop);
    }

    disconnect() {
        window.removeEventListener('popstate', this.onPop);
    }

    /** The current address becomes a history entry of its own; the next in-place update changes the entry after it. */
    push() {
        history.pushState(history.state, '', location.href);
    }

    /** The Results / SQL switch (browser only) writes its value into the address; the default, results, leaves it out. */
    param(event) {
        const url = new URL(location.href);
        const { name, value } = event.target;
        value === 'results' ? url.searchParams.delete(name) : url.searchParams.set(name, value);
        history.replaceState(history.state, '', url);
    }
}
