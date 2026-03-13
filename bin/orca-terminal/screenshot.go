package main

import (
	"fmt"
	"os/exec"
	"sync"
	"time"
)

type screenshotLoop struct {
	windowID   int
	path       string
	interval   time.Duration
	delay      time.Duration
	triggerCh  chan struct{}
	stopCh     chan struct{}
	stopOnce   sync.Once
}

func newScreenshotLoop(windowID int, path string, interval, delay time.Duration) *screenshotLoop {
	return &screenshotLoop{
		windowID:  windowID,
		path:      path,
		interval:  interval,
		delay:     delay,
		triggerCh: make(chan struct{}, 1),
		stopCh:    make(chan struct{}),
	}
}

func (s *screenshotLoop) run() {
	if s.windowID <= 0 {
		fmt.Println("No valid window ID, screenshot loop disabled")
		return
	}

	time.Sleep(s.delay)

	// Take initial screenshot
	s.capture()

	ticker := time.NewTicker(s.interval)
	defer ticker.Stop()

	for {
		select {
		case <-s.stopCh:
			return
		case <-ticker.C:
			s.capture()
		case <-s.triggerCh:
			s.capture()
		}
	}
}

func (s *screenshotLoop) trigger() {
	select {
	case s.triggerCh <- struct{}{}:
	default:
	}
}

func (s *screenshotLoop) stop() {
	s.stopOnce.Do(func() {
		close(s.stopCh)
	})
}

func (s *screenshotLoop) capture() {
	windowArg := fmt.Sprintf("-l%d", s.windowID)
	err := exec.Command("screencapture", windowArg, "-x", "-o", s.path).Run()
	if err != nil {
		fmt.Print(".")
	}
}
