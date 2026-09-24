import { Controller } from '@hotwired/stimulus';

/**
 * Shows a slider's value while it is being dragged. The Live Component only re-renders once the handle has
 * rested for a moment, so without this the number next to the label would lag behind the handle.
 */
export default class extends Controller {
    static targets = ['output'];

    connect() {
        this.update();
    }

    update() {
        const input = this.element.querySelector('input[type=range]');
        if (input && this.hasOutputTarget) {
            this.outputTarget.textContent = input.value;
        }
    }
}
