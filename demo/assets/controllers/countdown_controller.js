import { Controller } from '@hotwired/stimulus';

/**
 * The seconds until a rate-limited search may be repeated, counting down in the page (the server renders the first number
 * from RateLimited::$retryAfter). The ticking number is hidden from assistive technology (the card announces the wait once,
 * when it appears) and the end is announced once, through a polite status element. At zero the card says the visitor can
 * search again and offers the link that repeats the search.
 *
 * Targets: seconds (the ticking number), title, reset (the call to action before the end), again (the call to action after
 * it, hidden at first), done (an sr-only status that receives the end message). Value: seconds.
 */
export default class extends Controller {
    static targets = ['seconds', 'title', 'reset', 'again', 'done'];
    static values = { seconds: Number, finished: { type: String, default: 'You can search again.' } };

    connect() {
        this.deadline = Date.now() + this.secondsValue * 1000;
        this.tick();
        if (!this.ended) {
            // a few times a second, so the number never lags more than a fraction of a second behind (and a tab that was asleep catches up)
            this.timer = setInterval(() => this.tick(), 250);
        }
    }

    disconnect() {
        clearInterval(this.timer);
    }

    tick() {
        const left = Math.max(0, Math.ceil((this.deadline - Date.now()) / 1000));
        if (left > 0) {
            this.secondsTarget.textContent = `${left} s`;

            return;
        }
        this.finish();
    }

    finish() {
        clearInterval(this.timer);
        this.ended = true;
        // not a live region any more: the end is announced once, by the status element, not twice
        this.element.removeAttribute('role');
        this.titleTarget.textContent = this.finishedValue;
        this.element.classList.replace('is-warn', 'is-ok');
        this.resetTarget.hidden = true;
        this.againTarget.hidden = false;
        this.doneTarget.textContent = this.finishedValue;
        this.element.dispatchEvent(new CustomEvent('countdown:finished', { bubbles: true }));
    }
}
