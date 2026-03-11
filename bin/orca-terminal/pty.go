package main

import (
	"io"
	"log"
	"os"
	"os/exec"
	"os/signal"
	"syscall"
	"time"

	"github.com/creack/pty"
	"golang.org/x/term"
)

func runPTY(cmdStr string, prompt string, promptDelaySec int, transcriptPath string, debugLogPath string, injectCh <-chan string, rawKeyCh <-chan byte) int {
	// Open transcript file for tee-ing output
	transcriptFile, err := os.Create(transcriptPath)
	if err != nil {
		log.Printf("Warning: cannot create transcript file: %v", err)
		transcriptFile = nil
	}
	if transcriptFile != nil {
		defer transcriptFile.Close()
	}

	// Print toolbar hint and enable mouse tracking
	printToolbar()
	enableMouse()
	defer disableMouse()

	// Start command via PTY
	cmd := exec.Command("sh", "-c", cmdStr)
	ptmx, err := pty.Start(cmd)
	if err != nil {
		log.Fatalf("Failed to start PTY: %v", err)
	}
	defer ptmx.Close()

	// Handle SIGWINCH for terminal resize
	sigwinch := make(chan os.Signal, 1)
	signal.Notify(sigwinch, syscall.SIGWINCH)
	go func() {
		for range sigwinch {
			if err := pty.InheritSize(os.Stdin, ptmx); err != nil {
				log.Printf("Error resizing PTY: %v", err)
			}
		}
	}()
	// Initial resize
	sigwinch <- syscall.SIGWINCH

	// Set stdin to raw mode
	oldState, err := term.MakeRaw(int(os.Stdin.Fd()))
	if err != nil {
		log.Printf("Warning: cannot set raw mode (not a terminal?): %v", err)
		oldState = nil
	}
	if oldState != nil {
		defer term.Restore(int(os.Stdin.Fd()), oldState)
	}

	// Copy stdin -> PTY (with hotkey/mouse interception)
	go func() {
		stdinInterceptor(ptmx)
	}()

	// Copy PTY -> stdout + transcript, with output scanning for session ID
	var baseWriter io.Writer
	if transcriptFile != nil {
		baseWriter = io.MultiWriter(os.Stdout, transcriptFile)
	} else {
		baseWriter = os.Stdout
	}
	scanner := newOutputScanner(baseWriter, debugLogPath)
	globalScanner = scanner
	defer scanner.closeDebug()
	go func() {
		io.Copy(scanner, ptmx)
	}()

	// Listen for injected text from socket
	go func() {
		for text := range injectCh {
			injectCommand(ptmx, text)
		}
	}()

	// Listen for raw keypress from socket
	go func() {
		for key := range rawKeyCh {
			ptmx.Write([]byte{key})
		}
	}()

	// Auto-send prompt after delay using the same Escape→Enter submission
	if prompt != "" {
		go func() {
			time.Sleep(time.Duration(promptDelaySec) * time.Second)
			injectCommand(ptmx, prompt)
		}()
	}

	// Wait for command to exit
	exitCode := 0
	if err := cmd.Wait(); err != nil {
		if exitErr, ok := err.(*exec.ExitError); ok {
			exitCode = exitErr.ExitCode()
		} else {
			log.Printf("Command error: %v", err)
			exitCode = 1
		}
	}

	signal.Stop(sigwinch)
	close(sigwinch)

	return exitCode
}
