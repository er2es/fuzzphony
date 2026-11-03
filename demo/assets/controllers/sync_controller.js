import { Controller } from '@hotwired/stimulus';

/**
 * The sync demonstration's panel: after a move the worker needs a moment, so the panel (a fragment from the server) is fetched again
 * every 700 ms until it no longer says `data-syncing`, for at most 30 seconds. A slow worker is not an error: the server's fragment says
 * "still syncing" and the page stays usable; after the timeout the polling stops and the text tells the visitor to reload later.
 */
const EVERY_MS = 700;
const GIVE_UP_MS = 30000;

export default class extends Controller {
    static targets = ['panel', 'status'];
    static values = { url: String, active: Boolean };

    connect() {
        this.stopped = false;
        if (this.activeValue) {
            this.poll();
        }
    }

    disconnect() {
        this.stopped = true;
    }

    async poll() {
        const deadline = Date.now() + GIVE_UP_MS;
        while (!this.stopped && Date.now() < deadline) {
            await new Promise((resolve) => setTimeout(resolve, EVERY_MS));
            try {
                const response = await fetch(this.urlValue, { headers: { 'X-Requested-With': 'fetch' } });
                if (!response.ok) {
                    continue;
                }
                this.panelTarget.innerHTML = await response.text();
                this.statusTarget.textContent = this.panelTarget.querySelector('[data-status]')?.textContent.trim() ?? '';
                if (this.panelTarget.querySelector('[data-syncing]') === null) {
                    return;
                }
            } catch {
                // a failed poll is tried again until the deadline
            }
        }
    }
}
