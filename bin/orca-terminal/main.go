package main

import (
	"flag"
	"fmt"
	"log"
	"os"
	"os/signal"
	"syscall"
	"time"
)

func main() {
	sessionID := flag.String("session-id", "", "Session ID (required)")
	claudeCmd := flag.String("claude-cmd", "", "Full command string to run (required)")
	prompt := flag.String("prompt", "", "Auto-send prompt after delay")
	promptDelay := flag.Int("prompt-delay", 3, "Seconds to wait before sending prompt")
	callbackURL := flag.String("callback-url", "", "POST callback URL on exit (required)")
	screenshotInterval := flag.Int("screenshot-interval", 5, "Screenshot interval in seconds")
	screenshotDelay := flag.Int("screenshot-delay", 4, "Initial delay before screenshots start")
	workingDir := flag.String("working-dir", "", "Working directory (default: cwd)")
	tempDir := flag.String("temp-dir", os.TempDir(), "Temp directory for screenshots, transcripts, socket")
	flag.Parse()

	if *sessionID == "" || *claudeCmd == "" || *callbackURL == "" {
		fmt.Fprintln(os.Stderr, "Usage: orca-terminal --session-id <id> --claude-cmd <cmd> --callback-url <url>")
		os.Exit(1)
	}

	if *workingDir != "" {
		if err := os.Chdir(*workingDir); err != nil {
			log.Fatalf("Failed to change to working directory %s: %v", *workingDir, err)
		}
	}

	// Set terminal title
	fmt.Fprintf(os.Stdout, "\033]0;orca-%s\007", *sessionID)

	// Capture window ID immediately while we are the front window
	windowID, err := captureWindowID()
	if err != nil {
		log.Printf("Warning: failed to capture window ID: %v", err)
	}

	// Start Unix socket listener
	sockPath := fmt.Sprintf("%s/orca_terminal_%s.sock", *tempDir, *sessionID)
	socketServer := newSocketServer(sockPath, *sessionID, windowID)
	go socketServer.listen()
	defer socketServer.close()

	// Start screenshot loop
	screenshotPath := fmt.Sprintf("%s/orca_terminal_screenshot_%s.png", *tempDir, *sessionID)
	screenshotLoop := newScreenshotLoop(windowID, screenshotPath,
		time.Duration(*screenshotInterval)*time.Second,
		time.Duration(*screenshotDelay)*time.Second,
	)
	go screenshotLoop.run()
	defer screenshotLoop.stop()

	// Wire up on-demand screenshot trigger from socket to screenshot loop
	socketServer.onScreenshot = func() {
		screenshotLoop.trigger()
	}

	// Channels for injecting into the PTY from the socket
	injectCh := make(chan string, 10)
	rawKeyCh := make(chan byte, 10)
	statusCh := make(chan struct{}, 10)

	// Handle signals for clean shutdown
	sigCh := make(chan os.Signal, 1)
	signal.Notify(sigCh, syscall.SIGINT, syscall.SIGTERM)
	go func() {
		<-sigCh
		screenshotLoop.stop()
		socketServer.close()
	}()

	// Wire up text injection from socket to PTY
	socketServer.onInject = func(text string) {
		injectCh <- text
	}

	// Wire up raw key from socket to PTY
	socketServer.onRawKey = func(key byte) {
		rawKeyCh <- key
	}

	// Wire up status trigger from socket to PTY (runs full /status hotkey flow)
	socketServer.onStatus = func() {
		statusCh <- struct{}{}
	}

	// Run Claude via PTY
	transcriptPath := fmt.Sprintf("%s/orca_transcript_%s.txt", *tempDir, *sessionID)
	debugLogPath := fmt.Sprintf("%s/orca_scanner_debug_%s.log", *tempDir, *sessionID)
	exitCode := runPTY(*claudeCmd, *prompt, *promptDelay, transcriptPath, debugLogPath, injectCh, rawKeyCh, statusCh)

	// POST callback
	postCallback(*callbackURL, *sessionID, exitCode, transcriptPath)

	os.Exit(exitCode)
}
