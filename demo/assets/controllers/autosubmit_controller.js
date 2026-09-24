import { Controller } from '@hotwired/stimulus';

/** Submits the form when a control changes. requestSubmit() fires the `submit` event, so the page loader sees it. */
export default class extends Controller {
    submit() {
        this.element.requestSubmit();
    }
}
