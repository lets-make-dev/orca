package main

import (
	"bufio"
	"fmt"
	"log"
	"net"
	"os"
	"strings"
	"sync"
)

type socketServer struct {
	path         string
	sessionID    string
	windowID     int
	listener     net.Listener
	onScreenshot func()
	onInject     func(text string)
	onRawKey     func(key byte)
	onStatus     func()
	closeOnce    sync.Once
}

func newSocketServer(path string, sessionID string, windowID int) *socketServer {
	return &socketServer{
		path:      path,
		sessionID: sessionID,
		windowID:  windowID,
	}
}

func (s *socketServer) listen() {
	// Remove stale socket file
	os.Remove(s.path)

	ln, err := net.Listen("unix", s.path)
	if err != nil {
		log.Printf("Socket listen error: %v", err)
		return
	}
	s.listener = ln

	for {
		conn, err := ln.Accept()
		if err != nil {
			return // listener closed
		}
		go s.handleConn(conn)
	}
}

func (s *socketServer) handleConn(conn net.Conn) {
	defer conn.Close()

	scanner := bufio.NewScanner(conn)
	if !scanner.Scan() {
		return
	}

	cmd := strings.TrimSpace(scanner.Text())

	switch cmd {
	case "ping":
		fmt.Fprintln(conn, "pong")

	case "focus":
		if s.windowID > 0 {
			if err := focusWindow(s.windowID); err != nil {
				log.Printf("Focus error: %v", err)
				fmt.Fprintln(conn, "error")
				return
			}
		}
		fmt.Fprintln(conn, "ok")

	case "screenshot":
		if s.onScreenshot != nil {
			s.onScreenshot()
		}
		fmt.Fprintln(conn, "ok")

	case "escape":
		if s.onRawKey != nil {
			s.onRawKey(0x1b)
		}
		fmt.Fprintln(conn, "ok")

	case "status":
		if s.onStatus != nil {
			go s.onStatus()
		}
		fmt.Fprintln(conn, "ok")

	case "modified-files":
		go postModifiedFiles()
		fmt.Fprintln(conn, "ok")

	case "session-id":
		toolbarMu.Lock()
		id := claudeSessionID
		toolbarMu.Unlock()
		if id == "" {
			fmt.Fprintln(conn, "none")
		} else {
			fmt.Fprintln(conn, id)
		}

	default:
		if strings.HasPrefix(cmd, "inject ") {
			text := strings.TrimPrefix(cmd, "inject ")
			if s.onInject != nil {
				s.onInject(text)
			}
			fmt.Fprintln(conn, "ok")
		} else {
			fmt.Fprintln(conn, "unknown command")
		}
	}
}

func (s *socketServer) close() {
	s.closeOnce.Do(func() {
		if s.listener != nil {
			s.listener.Close()
		}
		os.Remove(s.path)
	})
}
