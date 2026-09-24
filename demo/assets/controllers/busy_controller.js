import { Controller } from '@hotwired/stimulus';

/**
 * Live Components put a `busy` attribute on their root element while a request is in flight (CSS styles the
 * dimming and the spinner from it). This mirrors that state to `aria-busy` on the region that is being
 * replaced, so assistive tech waits for the finished update instead of reading half-updated content.
 */
export default class extends Controller {
    static targets = ['region'];

    connect() {
        this.observer = new MutationObserver(() => this.sync());
        this.observer.observe(this.element, { attributes: true, attributeFilter: ['busy'] });
        this.sync();
    }

    disconnect() {
        this.observer?.disconnect();
    }

    regionTargetConnected() {
        this.sync();
    }

    sync() {
        const busy = this.element.hasAttribute('busy');
        this.regionTargets.forEach((region) => region.setAttribute('aria-busy', busy ? 'true' : 'false'));
    }
}
