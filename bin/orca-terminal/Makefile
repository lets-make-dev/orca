.PHONY: build clean test-unit test-e2e

build:
	GOOS=darwin GOARCH=arm64 go build -o orca-terminal .

clean:
	rm -f orca-terminal

test-unit:
	CGO_ENABLED=0 go test -v -count=1 ./...

test-e2e: build
	bash test_session_capture.sh
