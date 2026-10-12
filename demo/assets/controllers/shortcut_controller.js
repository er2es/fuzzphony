import { Controller } from '@hotwired/stimulus';

/** "/" focuses the search field of the page, as on the sites people know; Escape in the field leaves it. */
export default class extends Controller {
    connect() {
        this.onKey = (event) => {
            if (event.key !== '/' || event.metaKey || event.ctrlKey || event.altKey) {
                return;
            }
            const target = event.target;
            if (target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))) {
                return;
            }
            const field = document.querySelector('input[type=search]');
            if (field) {
                event.preventDefault();
                field.focus();
                field.select();
            }
        };
        document.addEventListener('keydown', this.onKey);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKey);
    }
}
