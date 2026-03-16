<?php

namespace MakeDev\Orca\WebTerm;

use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use MakeDev\Orca\Enums\OrcaSessionStatus;
use MakeDev\Orca\Models\OrcaSession;
use MakeDev\Orca\Services\PopOutTerminalService;
use MakeDev\Orca\Services\TmuxService;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

class WebTermConnection
{
    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private ?TimerInterface $readTimer = null;

    private bool $terminated = false;

    private bool $detached = false;

    private bool $processSpawned = false;

    private bool $usingTmux = false;

    private ?string $childTtyPath = null;

    /** @var list<string> Output buffered while no client is connected */
    private array $replayBuffer = [];

    private int $replayBufferMaxBytes = 100_000;

    /**
     * @param  Closure(string): void  $send
     */
    public function __construct(
        private ?Closure $send,
        private OrcaSession $session,
        private LoopInterface $loop,
    ) {}

    public function getSessionId(): string
    {
        return $this->session->id;
    }

    public function isRunning(): bool
    {
        if (! $this->processSpawned || $this->terminated) {
            return false;
        }

        // When using tmux, the session may still be alive even if the attach process is dead
        if ($this->usingTmux) {
            return app(TmuxService::class)->sessionExists($this->session->id);
        }

        if (! is_resource($this->process)) {
            return false;
        }

        $status = proc_get_status($this->process);

        return $status['running'];
    }

    public function isUsingTmux(): bool
    {
        return $this->usingTmux;
    }

    /**
     * Send 'connected' to the client so it replies with its actual terminal dimensions.
     * The process is deferred until the first resize event arrives.
     */
    public function start(): void
    {
        $this->sendJson(['type' => 'connected', 'session_id' => $this->session->id]);
    }

    /**
     * Spawn the Claude CLI process in a PTY with the correct terminal dimensions.
     */
    private function spawnProcess(int $cols, int $rows): void
    {
        $tmux = app(TmuxService::class);

        if ($tmux->isAvailable()) {
            $this->spawnViaTmux($tmux, $cols, $rows);

            return;
        }

        $this->spawnViaScript($cols, $rows);
    }

    /**
     * Spawn via tmux: create a tmux session with Claude, then attach to it.
     * If a tmux session already exists (e.g. from a pop-out), just attach.
     */
    private function spawnViaTmux(TmuxService $tmux, int $cols, int $rows): void
    {
        $sessionId = $this->session->id;
        $workingDir = $this->session->working_directory ?: base_path();

        // If the tmux session already exists (started by pop-out), just attach to it
        $existingSession = $tmux->sessionExists($sessionId);

        if (! $existingSession) {
            $service = new PopOutTerminalService;
            $claudeCmd = $service->buildClaudeCommand($this->session, true);

            if ($this->session->prompt && ! $this->session->resume_session_id && ! $this->session->claude_session_id) {
                $claudeCmd .= ' '.escapeshellarg($this->session->prompt);
            }

            $created = $tmux->createSession($sessionId, $claudeCmd, $workingDir, $cols, $rows, [
                'ORCA_SESSION_ID' => $sessionId,
                'ORCA_BASE_PATH' => base_path(),
                'TERM' => 'xterm-256color',
            ]);

            if (! $created) {
                $this->sendError('Failed to create tmux session');

                return;
            }

            $tmux->configurePopIn($sessionId, config('app.url', 'http://localhost:8000'));
        }

        $this->usingTmux = true;

        // Spawn a tmux attach process as our I/O pipe, wrapped in script for PTY
        $attachCmd = $tmux->attachCommand($sessionId);
        $command = PHP_OS_FAMILY === 'Darwin'
            ? "script -q /dev/null {$attachCmd}"
            : "script -qc {$attachCmd} /dev/null";

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = array_merge($_ENV, $_SERVER, [
            'TERM' => 'xterm-256color',
            'COLUMNS' => (string) $cols,
            'LINES' => (string) $rows,
        ]);
        $env = array_filter($env, fn ($v) => is_string($v));

        $this->process = proc_open($command, $descriptors, $this->pipes, $workingDir, $env);

        if (! is_resource($this->process)) {
            $tmux->killSession($sessionId);
            $this->sendError('Failed to attach to tmux session');

            return;
        }

        $this->processSpawned = true;

        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);

        $tmuxName = $tmux->sessionName($sessionId);

        $this->session->update([
            'status' => OrcaSessionStatus::PoppedOut,
            'popped_out_at' => now(),
            'tmux_session_name' => $tmuxName,
        ]);

        // Force the script PTY size then resize the tmux window after the
        // attach client connects — otherwise tmux auto-sizes to the script
        // PTY's default dimensions (80x24)
        $this->loop->addTimer(0.3, function () use ($tmux, $sessionId, $cols, $rows): void {
            if (! is_resource($this->process)) {
                return;
            }

            $status = proc_get_status($this->process);

            if ($status['running'] && $status['pid']) {
                $this->setTtySize($status['pid'], $cols, $rows);
            }

            $tmux->resize($sessionId, $cols, $rows);
        });

        $this->readTimer = $this->loop->addPeriodicTimer(0.01, function (): void {
            $this->readOutput();
        });
    }

    /**
     * Spawn via script (original PTY allocation approach).
     */
    private function spawnViaScript(int $cols, int $rows): void
    {
        $service = new PopOutTerminalService;
        $claudeCmd = $service->buildClaudeCommand($this->session, true);

        if ($this->session->prompt && ! $this->session->resume_session_id && ! $this->session->claude_session_id) {
            $claudeCmd .= ' '.escapeshellarg($this->session->prompt);
        }

        $workingDir = $this->session->working_directory ?: base_path();

        $command = PHP_OS_FAMILY === 'Darwin'
            ? "script -q /dev/null {$claudeCmd}"
            : "script -qc {$claudeCmd} /dev/null";

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = array_merge($_ENV, $_SERVER, [
            'TERM' => 'xterm-256color',
            'COLUMNS' => (string) $cols,
            'LINES' => (string) $rows,
        ]);
        $env = array_filter($env, fn ($v) => is_string($v));

        $this->process = proc_open($command, $descriptors, $this->pipes, $workingDir, $env);

        if (! is_resource($this->process)) {
            $this->sendError('Failed to start process');

            return;
        }

        $this->processSpawned = true;

        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);

        $this->session->update([
            'status' => OrcaSessionStatus::PoppedOut,
            'popped_out_at' => now(),
        ]);

        // Force the PTY size after a short delay
        $this->loop->addTimer(0.3, function () use ($cols, $rows): void {
            if (! is_resource($this->process)) {
                return;
            }

            $status = proc_get_status($this->process);

            if ($status['running'] && $status['pid']) {
                $this->setTtySize($status['pid'], $cols, $rows);
                @exec("kill -WINCH -{$status['pid']} 2>/dev/null");
            }
        });

        $this->readTimer = $this->loop->addPeriodicTimer(0.01, function (): void {
            $this->readOutput();
        });
    }

    /**
     * Detach the WebSocket client without killing the process.
     * Output is buffered so it can be replayed on reconnect.
     * When using tmux, the tmux session stays alive and no replay buffer is needed.
     */
    public function detach(): void
    {
        $this->send = null;
        $this->detached = true;

        if ($this->usingTmux) {
            // Kill the attach process — tmux session stays alive
            $this->killAttachProcess();
        }
    }

    /**
     * Reattach a new WebSocket client, replaying buffered output.
     * When using tmux, spawn a fresh attach process — tmux replays scrollback automatically.
     *
     * @param  Closure(string): void  $send
     */
    public function reattach(Closure $send): void
    {
        $this->send = $send;
        $this->detached = false;

        $this->sendJson(['type' => 'connected', 'session_id' => $this->session->id]);

        if ($this->usingTmux) {
            // Spawn a fresh tmux attach process
            $this->reattachTmux();

            return;
        }

        // Replay buffered output for non-tmux sessions
        foreach ($this->replayBuffer as $chunk) {
            $this->sendJson(['type' => 'output', 'data' => $chunk]);
        }

        $this->replayBuffer = [];
    }

    /**
     * Spawn a fresh tmux attach process for reattachment.
     */
    private function reattachTmux(): void
    {
        $tmux = app(TmuxService::class);
        $sessionId = $this->session->id;

        if (! $tmux->sessionExists($sessionId)) {
            $this->sendJson(['type' => 'exit', 'code' => 0]);

            return;
        }

        $attachCmd = $tmux->attachCommand($sessionId);
        $command = PHP_OS_FAMILY === 'Darwin'
            ? "script -q /dev/null {$attachCmd}"
            : "script -qc {$attachCmd} /dev/null";

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = array_filter(array_merge($_ENV, $_SERVER, [
            'TERM' => 'xterm-256color',
        ]), fn ($v) => is_string($v));

        $workingDir = $this->session->working_directory ?: base_path();
        $this->process = proc_open($command, $descriptors, $this->pipes, $workingDir, $env);

        if (! is_resource($this->process)) {
            $this->sendError('Failed to reattach to tmux session');

            return;
        }

        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);

        if (! $this->readTimer) {
            $this->readTimer = $this->loop->addPeriodicTimer(0.01, function (): void {
                $this->readOutput();
            });
        }
    }

    /**
     * Kill the tmux attach process without affecting the tmux session.
     */
    private function killAttachProcess(): void
    {
        if ($this->readTimer) {
            $this->loop->cancelTimer($this->readTimer);
            $this->readTimer = null;
        }

        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        $this->pipes = [];

        if (is_resource($this->process)) {
            $status = proc_get_status($this->process);
            if ($status['running'] && $status['pid']) {
                @posix_kill($status['pid'], 15);
            }
            @proc_close($this->process);
        }

        $this->process = null;
    }

    /**
     * Write keystrokes to the process stdin.
     */
    public function writeToProcess(string $data): void
    {
        if (isset($this->pipes[0]) && is_resource($this->pipes[0])) {
            @fwrite($this->pipes[0], $data);
        }
    }

    /**
     * Handle a resize event from the client.
     * On the first call, this spawns the process with the correct dimensions.
     * On subsequent calls, it updates the PTY size via stty/tmux and sends SIGWINCH.
     */
    public function resize(int $cols, int $rows): void
    {
        if (! $this->processSpawned) {
            $this->spawnProcess($cols, $rows);

            return;
        }

        if ($this->usingTmux) {
            app(TmuxService::class)->resize($this->session->id, $cols, $rows);

            return;
        }

        if (! is_resource($this->process)) {
            return;
        }

        $status = proc_get_status($this->process);

        if ($status['running'] && $status['pid']) {
            $pgid = $status['pid'];

            $this->setTtySize($pgid, $cols, $rows);

            @exec("kill -WINCH -{$pgid} 2>/dev/null");
        }
    }

    /**
     * Set the PTY size via stty so the child process can read the correct dimensions.
     */
    private function setTtySize(int $parentPid, int $cols, int $rows): void
    {
        if ($this->childTtyPath === null) {
            $this->childTtyPath = $this->resolveChildTty($parentPid);
        }

        if ($this->childTtyPath) {
            @exec(sprintf(
                'stty -f %s rows %d cols %d 2>/dev/null',
                escapeshellarg($this->childTtyPath),
                $rows,
                $cols,
            ));
        }
    }

    /**
     * Walk the process tree to find a child with a PTY device.
     * Process tree: sh -> script -> claude
     */
    private function resolveChildTty(int $parentPid): ?string
    {
        $search = $parentPid;

        for ($depth = 0; $depth < 4; $depth++) {
            $childPid = (int) trim(@exec("pgrep -oP {$search} 2>/dev/null") ?? '');

            if ($childPid <= 0) {
                break;
            }

            $tty = trim(@exec("ps -o tty= -p {$childPid} 2>/dev/null") ?? '');

            if ($tty && $tty !== '??' && $tty !== '?') {
                $path = "/dev/{$tty}";

                if (file_exists($path)) {
                    return $path;
                }
            }

            $search = $childPid;
        }

        return null;
    }

    /**
     * Terminate the PTY process and clean up timers.
     */
    public function terminate(): void
    {
        if ($this->terminated) {
            return;
        }

        $this->terminated = true;

        if ($this->readTimer) {
            $this->loop->cancelTimer($this->readTimer);
            $this->readTimer = null;
        }

        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }

        if (is_resource($this->process)) {
            $status = proc_get_status($this->process);

            if ($status['running'] && $status['pid']) {
                @posix_kill($status['pid'], 15); // SIGTERM
            }

            @proc_close($this->process);
        }

        // Also kill the tmux session if we were using one
        if ($this->usingTmux) {
            app(TmuxService::class)->killSession($this->session->id);
        }

        // Update session status if still active (session may have been deleted)
        try {
            $this->session->refresh();

            if ($this->session->status === OrcaSessionStatus::PoppedOut) {
                $this->session->update([
                    'status' => OrcaSessionStatus::Completed,
                    'completed_at' => now(),
                ]);
            }
        } catch (ModelNotFoundException) {
            // Session was deleted before we could update it
        }
    }

    /**
     * Read available output from stdout/stderr and send to client.
     */
    private function readOutput(): void
    {
        if ($this->terminated) {
            return;
        }

        $output = '';

        if (isset($this->pipes[1]) && is_resource($this->pipes[1])) {
            $chunk = @fread($this->pipes[1], 65536);
            if ($chunk !== false && $chunk !== '') {
                $output .= $chunk;
            }
        }

        if (isset($this->pipes[2]) && is_resource($this->pipes[2])) {
            $chunk = @fread($this->pipes[2], 65536);
            if ($chunk !== false && $chunk !== '') {
                $output .= $chunk;
            }
        }

        if ($output !== '') {
            if ($this->detached) {
                $this->bufferOutput($output);
            } else {
                $this->sendJson(['type' => 'output', 'data' => $output]);
            }
        }

        // Check if process has exited
        if ($this->usingTmux) {
            $this->checkTmuxProcessExit();
        } elseif (is_resource($this->process)) {
            $status = proc_get_status($this->process);

            if (! $status['running']) {
                $this->handleProcessExit($status['exitcode']);
            }
        }
    }

    /**
     * Check if the Claude process inside tmux has exited.
     */
    private function checkTmuxProcessExit(): void
    {
        $tmux = app(TmuxService::class);
        $sessionId = $this->session->id;

        if (! $tmux->hasProcessExited($sessionId)) {
            return;
        }

        // Read any remaining output from the attach process
        $this->drainRemainingOutput();

        $exitCode = $tmux->getExitCode($sessionId) ?? 0;

        if (! $this->detached) {
            $this->sendJson(['type' => 'exit', 'code' => $exitCode]);
        }

        try {
            $this->session->update([
                'status' => $exitCode === 0 ? OrcaSessionStatus::Completed : OrcaSessionStatus::Failed,
                'exit_code' => $exitCode,
                'completed_at' => now(),
            ]);
        } catch (ModelNotFoundException) {
            // Session was deleted
        }

        $this->terminate();
    }

    private function handleProcessExit(int $exitCode): void
    {
        $this->drainRemainingOutput();

        if (! $this->detached) {
            $this->sendJson(['type' => 'exit', 'code' => $exitCode]);
        }

        try {
            $this->session->update([
                'status' => $exitCode === 0 ? OrcaSessionStatus::Completed : OrcaSessionStatus::Failed,
                'exit_code' => $exitCode,
                'completed_at' => now(),
            ]);
        } catch (ModelNotFoundException) {
            // Session was deleted
        }

        $this->terminate();
    }

    private function drainRemainingOutput(): void
    {
        $remaining = '';

        if (isset($this->pipes[1]) && is_resource($this->pipes[1])) {
            $remaining .= stream_get_contents($this->pipes[1]) ?: '';
        }

        if ($remaining !== '') {
            if ($this->detached) {
                $this->bufferOutput($remaining);
            } else {
                $this->sendJson(['type' => 'output', 'data' => $remaining]);
            }
        }
    }

    /**
     * Buffer output while detached, trimming old data if over limit.
     */
    private function bufferOutput(string $data): void
    {
        $this->replayBuffer[] = $data;

        // Trim from front if buffer grows too large
        $totalSize = 0;
        foreach ($this->replayBuffer as $chunk) {
            $totalSize += strlen($chunk);
        }

        while ($totalSize > $this->replayBufferMaxBytes && count($this->replayBuffer) > 1) {
            $removed = array_shift($this->replayBuffer);
            $totalSize -= strlen($removed);
        }
    }

    private function sendJson(array $data): void
    {
        if ($this->send) {
            ($this->send)(json_encode($data));
        }
    }

    private function sendError(string $message): void
    {
        $this->sendJson(['type' => 'error', 'message' => $message]);
    }
}
