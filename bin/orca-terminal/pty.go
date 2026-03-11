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

func runPTY(cmdStr string, prompt string, promptDelaySec int, transcriptPath string) int {
	// Open transcript file for tee-ing output
	transcriptFile, err := os.Create(transcriptPath)
	if err != nil {
		log.Printf("Warning: cannot create transcript file: %v", err)
		transcriptFile = nil
	}
	if transcriptFile != nil {
		defer transcriptFile.Close()
	}

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

	// Set stdin to raw mode (only works when attached to a real terminal)
	oldState, err := term.MakeRaw(int(os.Stdin.Fd()))
	if err != nil {
		log.Printf("Warning: cannot set raw mode (not a terminal?): %v", err)
		oldState = nil
	}
	if oldState != nil {
		defer term.Restore(int(os.Stdin.Fd()), oldState)
	}

	// Copy stdin -> PTY
	go func() {
		io.Copy(ptmx, os.Stdin)
	}()

	// Copy PTY -> stdout + transcript
	var writer io.Writer
	if transcriptFile != nil {
		writer = io.MultiWriter(os.Stdout, transcriptFile)
	} else {
		writer = os.Stdout
	}
	go func() {
		io.Copy(writer, ptmx)
	}()

	// Auto-send prompt after delay
	if prompt != "" {
		go func() {
			time.Sleep(time.Duration(promptDelaySec) * time.Second)
			ptmx.Write([]byte(prompt + "\n"))
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
