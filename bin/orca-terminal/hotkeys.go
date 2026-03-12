package main

import (
	"bufio"
	"bytes"
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"time"
)

type hotkey struct {
	label   string
	command string
	onDone  func(ptmx *os.File)
}

type buttonRegion struct {
	startCol int
	endCol   int
	index    int
}

var (
	toolbarMu               sync.Mutex
	buttonRegions           []buttonRegion
	claudeSessionID         string
	modifiedFilesWebhookURL string
)

var hotkeys []hotkey

func init() {
	hotkeys = []hotkey{
		{label: " Modified Files (Ctrl+D) ", command: "", onDone: func(ptmx *os.File) {
			go postModifiedFiles()
		}},
	}
}

// enableMouse turns on SGR mouse tracking
func enableMouse() {
	fmt.Fprint(os.Stdout, "\033[?1000h\033[?1006h")
}

// disableMouse turns off mouse tracking
func disableMouse() {
	fmt.Fprint(os.Stdout, "\033[?1000l\033[?1006l")
}

// setTerminalTitle sets the terminal window/tab title via OSC 0
func setTerminalTitle(title string) {
	fmt.Fprintf(os.Stdout, "\033]0;%s\007", title)
}

// extractModifiedFilesFromSession reads the Claude session JSONL file and
// extracts unique file paths from Write and Edit tool calls.
func extractModifiedFilesFromSession() []string {
	toolbarMu.Lock()
	sid := claudeSessionID
	toolbarMu.Unlock()

	if sid == "" {
		log.Printf("[orca] no session ID available — session ID is captured via hooks")
		return nil
	}

	// Claude stores sessions at ~/.claude/projects/<project-hash>/<session-id>.jsonl
	// The project hash is a mangled working directory path, so we glob for the session ID.
	home, err := os.UserHomeDir()
	if err != nil {
		log.Printf("[orca] cannot determine home dir: %v", err)
		return nil
	}

	pattern := fmt.Sprintf("%s/.claude/projects/*/%s.jsonl", home, sid)
	matches, err := filepath.Glob(pattern)
	if err != nil || len(matches) == 0 {
		log.Printf("[orca] session JSONL not found: %s", pattern)
		return nil
	}

	jsonlPath := matches[0]
	log.Printf("[orca] reading session JSONL: %s", jsonlPath)

	f, err := os.Open(jsonlPath)
	if err != nil {
		log.Printf("[orca] cannot open session JSONL: %v", err)
		return nil
	}
	defer f.Close()

	// Each line is a JSON object. We look for assistant messages with tool_use
	// content blocks where name is "Write" or "Edit" and extract input.file_path.
	seen := make(map[string]bool)
	var files []string

	scanner := bufio.NewScanner(f)
	scanner.Buffer(make([]byte, 1024*1024), 1024*1024) // 1MB line buffer
	for scanner.Scan() {
		line := scanner.Bytes()

		// Quick pre-check to avoid parsing every line
		if !bytes.Contains(line, []byte(`"tool_use"`)) {
			continue
		}

		var entry struct {
			Type    string `json:"type"`
			Message struct {
				Content []struct {
					Type  string `json:"type"`
					Name  string `json:"name"`
					Input struct {
						FilePath string `json:"file_path"`
					} `json:"input"`
				} `json:"content"`
			} `json:"message"`
		}

		if err := json.Unmarshal(line, &entry); err != nil {
			continue
		}

		if entry.Type != "assistant" {
			continue
		}

		for _, block := range entry.Message.Content {
			if block.Type != "tool_use" {
				continue
			}
			if block.Name != "Write" && block.Name != "Edit" {
				continue
			}
			fp := block.Input.FilePath
			if fp != "" && !seen[fp] {
				seen[fp] = true
				files = append(files, fp)
			}
		}
	}

	return files
}

func postModifiedFiles() {
	files := extractModifiedFilesFromSession()
	if len(files) == 0 {
		log.Printf("[orca] no modified files found in session")
		return
	}

	url := modifiedFilesWebhookURL
	if url == "" {
		log.Printf("[orca] modified files (no webhook configured):")
		for _, f := range files {
			log.Printf("[orca]   %s", f)
		}
		return
	}

	payload, _ := json.Marshal(map[string]interface{}{
		"modified_files": files,
	})
	client := &http.Client{Timeout: 10 * time.Second}
	resp, err := client.Post(url, "application/json", bytes.NewReader(payload))
	if err != nil {
		log.Printf("[orca] modified-files webhook error: %v", err)
		return
	}
	resp.Body.Close()
	log.Printf("[orca] modified-files webhook posted %d files to %s", len(files), url)
}

// stdinInterceptor reads stdin, handles mouse clicks and hotkeys.
func stdinInterceptor(ptmx *os.File) {
	buf := make([]byte, 512)
	escBuf := make([]byte, 0, 32)
	inEsc := false

	for {
		n, err := os.Stdin.Read(buf)
		if err != nil {
			return
		}

		for i := 0; i < n; i++ {
			b := buf[i]

			if inEsc {
				escBuf = append(escBuf, b)

				// SGR mouse: \033[<btn;col;rowM or m
				if len(escBuf) >= 4 && escBuf[1] == '[' && escBuf[2] == '<' {
					if b == 'M' || b == 'm' {
						handleMouseEvent(ptmx, escBuf)
						escBuf = escBuf[:0]
						inEsc = false
						continue
					}
					if (b >= '0' && b <= '9') || b == ';' {
						continue
					}
					ptmx.Write(escBuf)
					escBuf = escBuf[:0]
					inEsc = false
					continue
				}

				// CSI terminated by letter
				if len(escBuf) >= 2 && escBuf[1] == '[' && b >= 'A' && b <= 'z' && b != '[' {
					ptmx.Write(escBuf)
					escBuf = escBuf[:0]
					inEsc = false
					continue
				}

				// Alt+key
				if len(escBuf) == 2 && escBuf[1] != '[' && escBuf[1] != 'O' {
					ptmx.Write(escBuf)
					escBuf = escBuf[:0]
					inEsc = false
					continue
				}

				continue
			}

			if b == 0x1b {
				inEsc = true
				escBuf = append(escBuf[:0], b)
				continue
			}

			// Ctrl+D → hotkey 0 (modified files)
			if b == 0x04 {
				go runHotkey(ptmx, 0)
				continue
			}

			ptmx.Write([]byte{b})
		}
	}
}

// handleMouseEvent processes SGR mouse sequences. Toolbar row clicks are
// handled locally; all other events are dropped since Claude Code doesn't
// enable mouse input.
func handleMouseEvent(ptmx *os.File, seq []byte) {
	terminator := seq[len(seq)-1]
	isPress := terminator == 'M'

	inner := string(seq[3 : len(seq)-1])
	parts := strings.Split(inner, ";")
	if len(parts) != 3 {
		return
	}

	button, _ := strconv.Atoi(parts[0])
	col, _ := strconv.Atoi(parts[1])
	row, _ := strconv.Atoi(parts[2])

	// Left-click on toolbar row → check button regions
	if isPress && button == 0 && row == 1 {
		for _, br := range buttonRegions {
			if col >= br.startCol && col < br.endCol {
				go runHotkey(ptmx, br.index)
				return
			}
		}
		return
	}

	// All other mouse events are dropped — mouse tracking is only for our
	// toolbar. Claude Code doesn't enable mouse input, so forwarding these
	// would cause raw escape sequences to appear as visible text.
}

func runHotkey(ptmx *os.File, index int) {
	if index < 0 || index >= len(hotkeys) {
		return
	}
	hk := &hotkeys[index]

	if hk.command != "" {
		injectCommand(ptmx, hk.command)
	}

	if hk.onDone != nil {
		hk.onDone(ptmx)
	}
}

func injectCommand(ptmx *os.File, command string) {
	ptmx.Write([]byte(command))
	time.Sleep(300 * time.Millisecond)
	ptmx.Write([]byte("\x1b")) // Escape — dismiss autocomplete
	time.Sleep(100 * time.Millisecond)
	ptmx.Write([]byte("\r")) // Enter — submit
}

func max(a, b int) int {
	if a > b {
		return a
	}
	return b
}

func min(a, b int) int {
	if a < b {
		return a
	}
	return b
}
