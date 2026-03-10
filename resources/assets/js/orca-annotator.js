import html2canvas from 'html2canvas-pro';

/**
 * OrcaAnnotator — standalone annotation + screenshot class for Orca.
 *
 * Single annotation at a time: one element highlight OR one text selection.
 * Ported from the Feedback module's FeedbackWidget / annotation-highlighter patterns.
 */
class OrcaAnnotator {
    constructor() {
        this._annotation = null;   // { type: 'element'|'text', xpath, rect, text? }
        this._overlayEl = null;
        this._pinEl = null;
        this._enabled = false;
        this._onClickCapture = this._handleClick.bind(this);
        this._onMouseUp = this._handleMouseUp.bind(this);
        this._onKeyDown = this._handleKeyDown.bind(this);
    }

    /* ------------------------------------------------------------------ */
    /*  Public API                                                         */
    /* ------------------------------------------------------------------ */

    get hasAnnotation() {
        return this._annotation !== null;
    }

    get enabled() {
        return this._enabled;
    }

    enable() {
        if (this._enabled) return;
        this._enabled = true;
        document.addEventListener('click', this._onClickCapture, true);
        document.addEventListener('mouseup', this._onMouseUp, true);
        document.addEventListener('keydown', this._onKeyDown, true);
        document.body.style.cursor = 'crosshair';
    }

    disable() {
        if (!this._enabled) return;
        this._enabled = false;
        document.removeEventListener('click', this._onClickCapture, true);
        document.removeEventListener('mouseup', this._onMouseUp, true);
        document.removeEventListener('keydown', this._onKeyDown, true);
        document.body.style.cursor = '';
        this.clear();
    }

    clear() {
        this._annotation = null;
        this._removeOverlay();
    }

    /**
     * Take a screenshot of the visible viewport, hiding the Orca widget.
     * Returns a PNG Blob.
     */
    async capture() {
        const launcher = document.querySelector('[data-orca-launcher]');
        let prevDisplay = '';
        if (launcher) {
            prevDisplay = launcher.style.display;
            launcher.style.display = 'none';
        }

        // Small delay so the browser repaints without the widget
        await new Promise(r => setTimeout(r, 80));

        try {
            const canvas = await html2canvas(document.body, {
                width: window.innerWidth,
                height: window.innerHeight,
                x: window.scrollX,
                y: window.scrollY,
                windowWidth: window.innerWidth,
                windowHeight: window.innerHeight,
                useCORS: true,
                logging: false,
                scale: window.devicePixelRatio || 1,
            });

            return await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
        } finally {
            if (launcher) {
                launcher.style.display = prevDisplay;
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Event handlers                                                     */
    /* ------------------------------------------------------------------ */

    _handleClick(e) {
        // Ignore clicks inside the Orca launcher
        if (this._isInsideOrca(e.target)) return;

        e.preventDefault();
        e.stopPropagation();

        const el = e.target;
        const rect = el.getBoundingClientRect();

        this._annotation = {
            type: 'element',
            xpath: this._getXPath(el),
            rect: {
                top: rect.top + window.scrollY,
                left: rect.left + window.scrollX,
                width: rect.width,
                height: rect.height,
            },
        };

        this._renderOverlay();
    }

    _handleMouseUp() {
        const selection = window.getSelection();
        if (!selection || selection.isCollapsed || selection.rangeCount === 0) return;

        const range = selection.getRangeAt(0);
        const text = selection.toString().trim();
        if (!text) return;

        // Ignore selections inside Orca
        if (this._isInsideOrca(range.commonAncestorContainer)) return;

        const rects = range.getClientRects();
        if (rects.length === 0) return;

        // Compute bounding box of all rects
        let top = Infinity, left = Infinity, bottom = 0, right = 0;
        for (const r of rects) {
            top = Math.min(top, r.top);
            left = Math.min(left, r.left);
            bottom = Math.max(bottom, r.bottom);
            right = Math.max(right, r.right);
        }

        this._annotation = {
            type: 'text',
            xpath: this._getXPath(range.startContainer.nodeType === Node.TEXT_NODE ? range.startContainer.parentElement : range.startContainer),
            text,
            rect: {
                top: top + window.scrollY,
                left: left + window.scrollX,
                width: right - left,
                height: bottom - top,
            },
        };

        // Clear browser selection so it doesn't interfere
        selection.removeAllRanges();
        this._renderOverlay();
    }

    _handleKeyDown(e) {
        if (e.key === 'Escape') {
            this.disable();
            // Dispatch custom event so Alpine can react
            window.dispatchEvent(new CustomEvent('orca-annotator-cancelled'));
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Overlay rendering                                                  */
    /* ------------------------------------------------------------------ */

    _renderOverlay() {
        this._removeOverlay();

        if (!this._annotation) return;

        const { rect } = this._annotation;

        // Dashed overlay
        this._overlayEl = document.createElement('div');
        Object.assign(this._overlayEl.style, {
            position: 'absolute',
            top: rect.top + 'px',
            left: rect.left + 'px',
            width: rect.width + 'px',
            height: rect.height + 'px',
            border: '2px dashed #f59e0b',
            borderRadius: '4px',
            backgroundColor: 'rgba(245, 158, 11, 0.08)',
            pointerEvents: 'none',
            zIndex: '99999',
            transition: 'all 0.15s ease',
        });
        document.body.appendChild(this._overlayEl);

        // Pin marker (top-right corner)
        this._pinEl = document.createElement('div');
        Object.assign(this._pinEl.style, {
            position: 'absolute',
            top: (rect.top - 8) + 'px',
            left: (rect.left + rect.width - 8) + 'px',
            width: '16px',
            height: '16px',
            borderRadius: '50%',
            backgroundColor: '#f59e0b',
            border: '2px solid #fff',
            boxShadow: '0 1px 3px rgba(0,0,0,0.3)',
            pointerEvents: 'none',
            zIndex: '100000',
        });
        document.body.appendChild(this._pinEl);
    }

    _removeOverlay() {
        if (this._overlayEl) {
            this._overlayEl.remove();
            this._overlayEl = null;
        }
        if (this._pinEl) {
            this._pinEl.remove();
            this._pinEl = null;
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                            */
    /* ------------------------------------------------------------------ */

    _isInsideOrca(node) {
        const el = node.nodeType === Node.TEXT_NODE ? node.parentElement : node;
        return el && el.closest('[data-orca-launcher]');
    }

    /**
     * Generate a simple XPath for an element.
     */
    _getXPath(el) {
        if (!el || el === document.body) return '/html/body';

        const parts = [];
        let current = el;

        while (current && current !== document.body && current.nodeType === Node.ELEMENT_NODE) {
            let tag = current.tagName.toLowerCase();
            const parent = current.parentElement;

            if (parent) {
                const siblings = Array.from(parent.children).filter(c => c.tagName === current.tagName);
                if (siblings.length > 1) {
                    const index = siblings.indexOf(current) + 1;
                    tag += '[' + index + ']';
                }
            }

            parts.unshift(tag);
            current = parent;
        }

        return '/html/body/' + parts.join('/');
    }
}

// Export to window for Alpine access
window.OrcaAnnotator = OrcaAnnotator;
