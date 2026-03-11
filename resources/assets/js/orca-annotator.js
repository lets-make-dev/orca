import html2canvas from 'html2canvas-pro';

/**
 * OrcaAnnotator — annotation + screenshot tool for Orca.
 *
 * Two modes:
 *   'click' (default) — click an element or select text to highlight it.
 *   'crop'            — drag a rectangle to select an area of the page.
 */
class OrcaAnnotator {
    constructor() {
        this._annotation = null;   // { type, rect, ... }
        this._overlayEl = null;
        this._pinEl = null;
        this._enabled = false;
        this._mode = 'click';
        this._isDragging = false;
        this._dragStart = null;

        // Bound handlers — click mode
        this._onClickCapture = this._handleClick.bind(this);
        this._onTextMouseUp = this._handleTextMouseUp.bind(this);

        // Bound handlers — crop mode
        this._onDragMouseDown = this._handleDragMouseDown.bind(this);
        this._onDragMouseMove = this._handleDragMouseMove.bind(this);
        this._onDragMouseUp = this._handleDragMouseUp.bind(this);

        // Shared
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

    get mode() {
        return this._mode;
    }

    enable(mode = 'click') {
        // If already enabled in a different mode, disable first
        if (this._enabled) this.disable();

        this._enabled = true;
        this._mode = mode;

        if (mode === 'crop') {
            document.addEventListener('mousedown', this._onDragMouseDown, true);
            document.addEventListener('mousemove', this._onDragMouseMove, true);
            document.addEventListener('mouseup', this._onDragMouseUp, true);
        } else {
            document.addEventListener('click', this._onClickCapture, true);
            document.addEventListener('mouseup', this._onTextMouseUp, true);
        }

        document.addEventListener('keydown', this._onKeyDown, true);
        document.body.style.cursor = 'crosshair';
    }

    disable() {
        if (!this._enabled) return;
        this._enabled = false;
        this._isDragging = false;
        this._dragStart = null;

        // Remove all listeners regardless of mode
        document.removeEventListener('click', this._onClickCapture, true);
        document.removeEventListener('mouseup', this._onTextMouseUp, true);
        document.removeEventListener('mousedown', this._onDragMouseDown, true);
        document.removeEventListener('mousemove', this._onDragMouseMove, true);
        document.removeEventListener('mouseup', this._onDragMouseUp, true);
        document.removeEventListener('keydown', this._onKeyDown, true);

        document.body.style.cursor = '';
        this.clear();
    }

    clear() {
        this._annotation = null;
        this._removeOverlay();
    }

    /**
     * Take a screenshot. In crop mode captures just the selected region;
     * in click mode captures the full visible viewport.
     * Hides the Orca widget and overlays before capture. Returns a PNG Blob.
     */
    async capture() {
        const launcher = document.querySelector('[data-orca-launcher]');
        let prevDisplay = '';
        if (launcher) {
            prevDisplay = launcher.style.display;
            launcher.style.display = 'none';
        }

        // Remove overlay so it doesn't appear in the screenshot
        this._removeOverlay();

        // Small delay so the browser repaints
        await new Promise(r => setTimeout(r, 80));

        try {
            const isCrop = this._mode === 'crop' && this._annotation?.type === 'region';
            const rect = isCrop ? this._annotation.rect : null;

            const canvas = await html2canvas(document.body, {
                width: rect ? rect.width : window.innerWidth,
                height: rect ? rect.height : window.innerHeight,
                x: rect ? rect.left : window.scrollX,
                y: rect ? rect.top : window.scrollY,
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
    /*  Click-mode event handlers                                          */
    /* ------------------------------------------------------------------ */

    _handleClick(e) {
        if (this._isInsideOrca(e.target)) return;

        e.preventDefault();
        e.stopPropagation();

        const el = e.target;
        const rect = el.getBoundingClientRect();

        this._annotation = {
            type: 'element',
            rect: {
                top: rect.top + window.scrollY,
                left: rect.left + window.scrollX,
                width: rect.width,
                height: rect.height,
            },
        };

        this._renderOverlay();
    }

    _handleTextMouseUp() {
        const selection = window.getSelection();
        if (!selection || selection.isCollapsed || selection.rangeCount === 0) return;

        const range = selection.getRangeAt(0);
        const text = selection.toString().trim();
        if (!text) return;

        if (this._isInsideOrca(range.commonAncestorContainer)) return;

        const rects = range.getClientRects();
        if (rects.length === 0) return;

        let top = Infinity, left = Infinity, bottom = 0, right = 0;
        for (const r of rects) {
            top = Math.min(top, r.top);
            left = Math.min(left, r.left);
            bottom = Math.max(bottom, r.bottom);
            right = Math.max(right, r.right);
        }

        this._annotation = {
            type: 'text',
            text,
            rect: {
                top: top + window.scrollY,
                left: left + window.scrollX,
                width: right - left,
                height: bottom - top,
            },
        };

        selection.removeAllRanges();
        this._renderOverlay();
    }

    /* ------------------------------------------------------------------ */
    /*  Crop-mode event handlers                                           */
    /* ------------------------------------------------------------------ */

    _handleDragMouseDown(e) {
        if (this._isInsideOrca(e.target)) return;
        if (e.button !== 0) return;

        e.preventDefault();
        e.stopPropagation();

        this._isDragging = true;
        this._dragStart = {
            x: e.clientX + window.scrollX,
            y: e.clientY + window.scrollY,
        };

        this.clear();
    }

    _handleDragMouseMove(e) {
        if (!this._isDragging || !this._dragStart) return;

        e.preventDefault();

        const currentX = e.clientX + window.scrollX;
        const currentY = e.clientY + window.scrollY;

        this._annotation = {
            type: 'region',
            rect: {
                left: Math.min(this._dragStart.x, currentX),
                top: Math.min(this._dragStart.y, currentY),
                width: Math.abs(currentX - this._dragStart.x),
                height: Math.abs(currentY - this._dragStart.y),
            },
        };

        this._renderOverlay();
    }

    _handleDragMouseUp() {
        if (!this._isDragging) return;

        this._isDragging = false;
        this._dragStart = null;

        // Require minimum size to avoid accidental clicks
        if (this._annotation && this._annotation.rect.width > 10 && this._annotation.rect.height > 10) {
            this._renderOverlay();
        } else {
            this.clear();
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Shared event handlers                                              */
    /* ------------------------------------------------------------------ */

    _handleKeyDown(e) {
        if (e.key === 'Escape') {
            this.disable();
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
        const isCrop = this._annotation.type === 'region';

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
            backgroundColor: isCrop ? 'rgba(245, 158, 11, 0.05)' : 'rgba(245, 158, 11, 0.08)',
            pointerEvents: 'none',
            zIndex: '99999',
            transition: isCrop ? 'none' : 'all 0.15s ease',
        });

        // Dim the rest of the page in crop mode
        if (isCrop) {
            this._overlayEl.style.boxShadow = '0 0 0 9999px rgba(0, 0, 0, 0.3)';
        }

        document.body.appendChild(this._overlayEl);

        // Pin marker (top-right corner) for click mode only
        if (!isCrop) {
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
}

// Export to window for Alpine access
window.OrcaAnnotator = OrcaAnnotator;
