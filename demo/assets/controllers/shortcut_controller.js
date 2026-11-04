import { Controller } from '@hotwired/stimulus';

/** "/" focuses the search field of the page, as on the sites people know (on a page without one it opens the header's search box); Escape in the field leaves it. */
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
            event.preventDefault();
            if (field) {
                field.focus();
                field.select();
            } else {
                document.dispatchEvent(new Event('magic:open')); // a page without a field of its own: the header's search box (magic_controller.js)
            }
        };
        document.addEventListener('keydown', this.onKey);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKey);
    }
}
