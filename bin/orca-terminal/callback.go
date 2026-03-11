package main

import (
	"bytes"
	"encoding/json"
	"log"
	"net/http"
	"time"
)

func postCallback(url string, sessionID string, exitCode int, transcriptPath string) {
	payload := map[string]interface{}{
		"session_id":      sessionID,
		"exit_code":       exitCode,
		"transcript_path": transcriptPath,
	}

	body, err := json.Marshal(payload)
	if err != nil {
		log.Printf("Callback marshal error: %v", err)
		return
	}

	client := &http.Client{Timeout: 10 * time.Second}
	resp, err := client.Post(url, "application/json", bytes.NewReader(body))
	if err != nil {
		log.Printf("Callback POST error: %v", err)
		return
	}
	resp.Body.Close()
}
