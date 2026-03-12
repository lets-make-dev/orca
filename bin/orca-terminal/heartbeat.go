package main

import (
	"bytes"
	"encoding/json"
	"log"
	"net/http"
	"sync"
	"time"
)

type heartbeatLoop struct {
	url       string
	sessionID string
	interval  time.Duration
	stopCh    chan struct{}
	closeOnce sync.Once
}

func newHeartbeatLoop(url, sessionID string, interval time.Duration) *heartbeatLoop {
	return &heartbeatLoop{
		url:       url,
		sessionID: sessionID,
		interval:  interval,
		stopCh:    make(chan struct{}),
	}
}

func (h *heartbeatLoop) run() {
	h.send()

	ticker := time.NewTicker(h.interval)
	defer ticker.Stop()

	for {
		select {
		case <-ticker.C:
			h.send()
		case <-h.stopCh:
			return
		}
	}
}

func (h *heartbeatLoop) send() {
	payload, _ := json.Marshal(map[string]string{
		"session_id": h.sessionID,
	})

	client := &http.Client{Timeout: 5 * time.Second}
	resp, err := client.Post(h.url, "application/json", bytes.NewReader(payload))
	if err != nil {
		log.Printf("Heartbeat error: %v", err)
		return
	}
	resp.Body.Close()
}

func (h *heartbeatLoop) stop() {
	h.closeOnce.Do(func() {
		close(h.stopCh)
	})
}
