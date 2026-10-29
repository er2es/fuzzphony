import { Controller } from '@hotwired/stimulus';

/** The one tooltip that is open: opening another closes it. */
let current = null;

/**
 * An info button with a tooltip (the Tune page). The bubble is a role=tooltip element the button points to with
 * aria-describedby, so a screen reader reads it with the button; aria-expanded says whether it is shown.
 *
 * Opens on hover (mouse), on focus and on a click or tap (which also keeps it open until the next click);
 * closes on Escape (focus goes back to the button), on a pointer press outside, on blur and when the pointer leaves.
 * The bubble is position: fixed and placed inside the window (below the button, above when there is no room), so it
 * is never clipped and opening it moves nothing. The host carries data-live-ignore: a Live Component re-render leaves it as it is.
 */
export default class extends Controller {
    static targets = ['button', 'bubble'];

    connect() {
        this.pinned = false;
        this.timer = null;
        this.quiet = false;
        this.reposition = () => this.place();
        this.onPress = (event) => {
            if (!this.element.contains(event.target)) {
                this.close();
            }
        };
        this.onKey = (event) => {
            if (event.key === 'Escape') {
                this.close();
                this.quiet = true; // moving focus back must not open it again
                this.buttonTarget.focus();
                this.quiet = false;
            }
        };
        this.listen(this.element, {
            pointerenter: (e) => e.pointerType === 'mouse' && this.hover(true),
            pointerleave: (e) => e.pointerType === 'mouse' && this.hover(false),
            focusin: () => !this.quiet && this.open(),
            focusout: (e) => !this.element.contains(e.relatedTarget) && this.close(),
        });
        this.listen(this.buttonTarget, { click: () => this.toggle() });
    }

    disconnect() {
        this.close();
        this.cleanups.forEach((undo) => undo());
    }

    listen(target, handlers) {
        this.cleanups ??= [];
        for (const [type, handler] of Object.entries(handlers)) {
            target.addEventListener(type, handler);
            this.cleanups.push(() => target.removeEventListener(type, handler));
        }
    }

    get isOpen() {
        return !this.bubbleTarget.hidden;
    }

    hover(inside) {
        clearTimeout(this.timer);
        if (inside) {
            this.open();
        } else if (!this.pinned && !this.element.contains(document.activeElement)) {
            this.timer = setTimeout(() => this.close(), 150); // time to move onto the bubble
        }
    }

    toggle() {
        if (this.isOpen && this.pinned) {
            this.close();
        } else {
            this.open();
            this.pinned = true;
        }
    }

    open() {
        clearTimeout(this.timer);
        if (this.isOpen) {
            return;
        }
        if (current && current !== this) {
            current.close();
        }
        current = this;
        this.bubbleTarget.hidden = false;
        this.buttonTarget.setAttribute('aria-expanded', 'true');
        this.place();
        document.addEventListener('pointerdown', this.onPress, true);
        document.addEventListener('keydown', this.onKey);
        window.addEventListener('resize', this.reposition);
        window.addEventListener('scroll', this.reposition, true);
    }

    close() {
        clearTimeout(this.timer);
        this.pinned = false;
        if (current === this) {
            current = null;
        }
        if (!this.isOpen) {
            return;
        }
        this.bubbleTarget.hidden = true;
        this.buttonTarget.setAttribute('aria-expanded', 'false');
        document.removeEventListener('pointerdown', this.onPress, true);
        document.removeEventListener('keydown', this.onKey);
        window.removeEventListener('resize', this.reposition);
        window.removeEventListener('scroll', this.reposition, true);
    }

    /** Centred under the button, moved sideways to stay inside the window, above the button when it does not fit below. */
    place() {
        const margin = 8;
        const gap = 6;
        const view = document.documentElement;
        const bubble = this.bubbleTarget;
        const button = this.buttonTarget.getBoundingClientRect();
        bubble.style.left = '0px';
        bubble.style.top = '0px';
        const size = bubble.getBoundingClientRect();
        const left = Math.min(Math.max(button.left + button.width / 2 - size.width / 2, margin), Math.max(margin, view.clientWidth - size.width - margin));
        const fitsBelow = button.bottom + gap + size.height <= view.clientHeight - margin;
        const fitsAbove = button.top - gap - size.height >= margin;
        const top = fitsBelow || !fitsAbove ? button.bottom + gap : button.top - gap - size.height;
        bubble.style.left = `${Math.round(left)}px`;
        bubble.style.top = `${Math.round(Math.max(margin, top))}px`;
    }
}
