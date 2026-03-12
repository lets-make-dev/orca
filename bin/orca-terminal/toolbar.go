package main

import (
	"fmt"
	"os"

	"golang.org/x/term"
)

// toolbarInitialized tracks whether initToolbar has been called,
// so refreshToolbar can be safely called from other goroutines.
var toolbarInitialized bool

// getTerminalSize returns the current terminal dimensions.
func getTerminalSize() (cols, rows int) {
	c, r, err := term.GetSize(int(os.Stdout.Fd()))
	if err != nil {
		return 80, 24
	}
	return c, r
}

// renderToolbar draws the toolbar content at the current cursor position.
// It fills the full row width and updates buttonRegions for mouse click detection.
func renderToolbar(cols int) {
	buttonRegions = nil
	col := 1

	// Clear line to avoid artifacts on resize
	fmt.Fprint(os.Stdout, "\033[2K")

	// Background
	fmt.Fprint(os.Stdout, "\033[48;5;235m")

	// Left label
	prefix := " orca "
	fmt.Fprintf(os.Stdout, "\033[1;38;5;117m%s\033[22m", prefix)
	col += len(prefix)

	// Separator
	fmt.Fprintf(os.Stdout, "\033[38;5;240m│ ")
	col += 2

	// Buttons
	for i, hk := range hotkeys {
		if i > 0 {
			fmt.Fprint(os.Stdout, " ")
			col++
		}

		startCol := col
		fmt.Fprintf(os.Stdout, "\033[48;5;238;38;5;255m%s\033[48;5;235m", hk.label)
		col += len(hk.label)

		buttonRegions = append(buttonRegions, buttonRegion{
			startCol: startCol,
			endCol:   col,
			index:    i,
		})
	}

	// Pad rest of row
	padding := cols - col + 1
	if padding > 0 {
		fmt.Fprintf(os.Stdout, "%*s", padding, "")
	}

	fmt.Fprint(os.Stdout, "\033[0m")
}

// initToolbar draws the toolbar on row 1 and sets up a DECSTBM scroll region
// so the toolbar stays fixed while PTY output scrolls below it.
func initToolbar() {
	cols, rows := getTerminalSize()

	// Draw toolbar on row 1
	fmt.Fprint(os.Stdout, "\033[1;1H")
	renderToolbar(cols)

	// Set scroll region: rows 2..N (toolbar on row 1 stays fixed)
	fmt.Fprintf(os.Stdout, "\033[2;%dr", rows)

	// Position cursor at start of scroll region
	fmt.Fprint(os.Stdout, "\033[2;1H")

	toolbarMu.Lock()
	toolbarInitialized = true
	toolbarMu.Unlock()
}

// refreshToolbar redraws the toolbar and resets the scroll region.
// Safe to call from any goroutine after initToolbar.
func refreshToolbar() {
	cols, rows := getTerminalSize()

	// Save cursor position
	fmt.Fprint(os.Stdout, "\0337")

	// Move to row 1 (outside scroll region — works with DECOM reset)
	fmt.Fprint(os.Stdout, "\033[1;1H")
	renderToolbar(cols)

	// Reset scroll region for new dimensions
	fmt.Fprintf(os.Stdout, "\033[2;%dr", rows)

	// Restore cursor position
	fmt.Fprint(os.Stdout, "\0338")
}

// resetToolbar removes the scroll region, restoring full-screen scrolling.
func resetToolbar() {
	fmt.Fprint(os.Stdout, "\033[r")

	toolbarMu.Lock()
	toolbarInitialized = false
	toolbarMu.Unlock()
}

// adjustedPTYSize returns terminal size with rows reduced by 1 for the fixed toolbar.
func adjustedPTYSize() (cols, rows uint16) {
	c, r := getTerminalSize()
	if r > 1 {
		r--
	}
	return uint16(c), uint16(r)
}

// buildAdjustedMouseSeq creates an SGR mouse sequence with the row decremented
// by 1 to account for the fixed toolbar occupying screen row 1.
func buildAdjustedMouseSeq(button, col, row int, terminator byte) []byte {
	adjustedRow := row - 1
	if adjustedRow < 1 {
		adjustedRow = 1
	}
	return []byte(fmt.Sprintf("\033[<%d;%d;%d%c", button, col, adjustedRow, terminator))
}
