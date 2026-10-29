import { Controller } from '@hotwired/stimulus';

/**
 * A search box of a page that submits (everything but the home page, which searches in place): emptying the field, with the clear
 * button or with the keyboard, leaves the page of a text that is gone. The results and the address bar would otherwise keep the old
 * search, and a reload or a shared link would bring it back. So the page is opened again without the text (`q`, and the one-shot
 * `reset`); the other parameters (the language, the viewer, the index) stay. The field's own name is the parameter's name.
 *
 *   <form data-controller="clearable"><input type="search" name="q"></form>
 */
export default class extends Controller {
    connect() {
        this.onInput = (event) => {
            if (event.target.matches('input[type=search]') && event.target.value.trim() === '') {
                this.leave(event.target.name || 'q');
            }
        };
        this.element.addEventListener('input', this.onInput);
    }

    leave(name) {
        const url = new URL(location.href);
        if (!url.searchParams.has(name)) {
            return;
        }
        url.searchParams.delete(name);
        url.searchParams.delete('reset');
        location.assign(url);
    }

    disconnect() {
        this.element.removeEventListener('input', this.onInput);
    }
}
