import { Controller } from '@hotwired/stimulus';

/**
 * The colour scheme switch of the toolbar: System (follow the device, the default), Light, Dark. The choice is per visitor, kept in
 * localStorage (every access is guarded: a private window or blocked storage only means the choice does not last) and applied to
 * <html data-theme> by the small inline script in the head of every page BEFORE the first paint, so there is no flash of the other
 * scheme. This controller only handles the buttons: it sets the attribute, stores the choice and keeps `aria-pressed` right.
 * The look of the pressed button comes from the attribute itself (app.css), so it is right before this script has run.
 */
const KEY = 'fuzzphony-theme';

export default class extends Controller {
    static targets = ['button'];

    connect() {
        this.mark(this.current());
    }

    choose(event) {
        const value = event.params.value;
        if (value === 'light' || value === 'dark') {
            document.documentElement.dataset.theme = value;
        } else {
            delete document.documentElement.dataset.theme;
        }
        try {
            if (value === 'light' || value === 'dark') {
                localStorage.setItem(KEY, value);
            } else {
                localStorage.removeItem(KEY);
            }
        } catch {
            // storage is not available: the choice holds for this page view only
        }
        this.mark(value);
    }

    current() {
        const value = document.documentElement.dataset.theme;

        return value === 'light' || value === 'dark' ? value : 'system';
    }

    mark(value) {
        for (const button of this.buttonTargets) {
            button.setAttribute('aria-pressed', String(button.dataset.themeValueParam === value));
        }
    }
}
