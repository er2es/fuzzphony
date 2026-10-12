import { Controller } from '@hotwired/stimulus';

/** A <details> menu that closes on Escape, on a click outside it and when a link in it is followed. */
export default class extends Controller {
    connect() {
        this.onOutside = (event) => {
            if (!this.element.contains(event.target)) {
                this.element.open = false;
            }
        };
        this.onKey = (event) => {
            if (event.key === 'Escape' && this.element.open) {
                this.element.open = false;
                this.element.querySelector('summary')?.focus();
            }
        };
        document.addEventListener('click', this.onOutside);
        document.addEventListener('keydown', this.onKey);
    }

    disconnect() {
        document.removeEventListener('click', this.onOutside);
        document.removeEventListener('keydown', this.onKey);
    }
}
