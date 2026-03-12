package main

import (
	"fmt"
	"os/exec"
	"strconv"
	"strings"
)

func captureWindowID() (int, error) {
	out, err := exec.Command("osascript", "-e",
		`tell application "Terminal" to get id of front window`,
	).Output()
	if err != nil {
		return 0, fmt.Errorf("osascript: %w", err)
	}

	id, err := strconv.Atoi(strings.TrimSpace(string(out)))
	if err != nil {
		return 0, fmt.Errorf("parse window id %q: %w", strings.TrimSpace(string(out)), err)
	}

	return id, nil
}

func focusWindow(windowID int) error {
	script := fmt.Sprintf(`
tell application "Terminal"
	repeat with w in windows
		if id of w is %d then
			set index of w to 1
		else
			set miniaturized of w to true
		end if
	end repeat
end tell
tell application "System Events" to set frontmost of process "Terminal" to true`, windowID)

	return exec.Command("osascript", "-e", script).Run()
}
