## Architecture

### Key Classes

| Class | Description |
|---|---|
| `RunClaudeSession` | Queue job that manages the Claude CLI subprocess. Handles process lifecycle, stdout/stderr parsing, stdin delivery via Redis, and cancellation. |
| `RunCommand` | Queue job for shell command execution with streaming output. |
| `ClaudeEventParser` | Parses Claude's `stream-json` NDJSON output. Classifies events, extracts display content and metadata, detects interaction requests, and builds stdin payloads. |
| `SessionChannel` | Redis-backed FIFO queue for delivering user input to Claude's stdin pipe. Uses `rpush`/`lpop` with configurable TTL. |
| `PopOutTerminalService` | macOS Terminal integration. Generates bash wrapper scripts with `script` for transcript capture and `curl` callbacks for auto-resume. |
| `RouteResolver` | Resolves URLs to route metadata (controller, Livewire component, route name) and associated handler/view files. |
| `Launcher` | Livewire component handling all UI state: session launching, input handling, permission responses, screenshot management, and session lifecycle. |
| `InjectLauncher` | Middleware that auto-injects the `<livewire:orca-launcher>` component into HTML responses in local environments. |

### Models

| Model | Table | Description |
|---|---|---|
| `OrcaSession` | `orca_sessions` | Represents a session (command or Claude). Tracks prompt, status, permission mode, Claude session ID, PID, exit code, parent/child relationships, pop-out state, and page context (URL, route, user). Uses ULIDs. |
| `OrcaSessionMessage` | `orca_session_messages` | Individual messages within a session. Tracks direction (inbound/outbound), type (text, tool_use, error, system), JSON content, metadata, and delivery status. Uses ULIDs. |

### Enums

- **`OrcaSessionStatus`** — `Pending`, `Running`, `AwaitingInput`, `Completed`, `Failed`, `Cancelled`, `PoppedOut`
- **`OrcaSessionType`** — `Command`, `Claude`
