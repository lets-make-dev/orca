<?php

namespace MakeDev\Orca\WebTerm;

use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use MakeDev\Orca\Enums\OrcaSessionStatus;
use MakeDev\Orca\Models\OrcaSession;
use MakeDev\Orca\Services\PopOutTerminalService;
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
        if ($this->terminated || ! is_resource($this->process)) {
            return false;
        }

        $status = proc_get_status($this->process);

        return $status['running'];
    }

    /**
     * Spawn the Claude CLI process in a PTY.
     */
    public function start(): void
    {
        $service = new PopOutTerminalService;
        $claudeCmd = $service->buildClaudeCommand($this->session, true);
        $workingDir = $this->session->working_directory ?: base_path();

        // Use script to allocate a PTY on macOS/Linux
        $command = PHP_OS_FAMILY === 'Darwin'
            ? "script -q /dev/null {$claudeCmd}"
            : "script -qc {$claudeCmd} /dev/null";

        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $env = array_merge($_ENV, $_SERVER, [
            'TERM' => 'xterm-256color',
            'COLUMNS' => '120',
            'LINES' => '30',
        ]);

        // Remove non-string values that proc_open can't handle
        $env = array_filter($env, fn ($v) => is_string($v));

        $this->process = proc_open($command, $descriptors, $this->pipes, $workingDir, $env);

        if (! is_resource($this->process)) {
            $this->sendError('Failed to start process');

            return;
        }

        // Make stdout/stderr non-blocking
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);

        $this->session->update([
            'status' => OrcaSessionStatus::PoppedOut,
            'popped_out_at' => now(),
        ]);

        $this->sendJson(['type' => 'connected', 'session_id' => $this->session->id]);

        // Read timer: poll stdout/stderr every 10ms
        $this->readTimer = $this->loop->addPeriodicTimer(0.01, function (): void {
            $this->readOutput();
        });

    }

    /**
     * Detach the WebSocket client without killing the process.
     * Output is buffered so it can be replayed on reconnect.
     */
    public function detach(): void
    {
        $this->send = null;
        $this->detached = true;
    }

    /**
     * Reattach a new WebSocket client, replaying buffered output.
     *
     * @param  Closure(string): void  $send
     */
    public function reattach(Closure $send): void
    {
        $this->send = $send;
        $this->detached = false;

        // Send current status
        $this->sendJson(['type' => 'connected', 'session_id' => $this->session->id]);

        // Replay buffered output
        foreach ($this->replayBuffer as $chunk) {
            $this->sendJson(['type' => 'output', 'data' => $chunk]);
        }

        $this->replayBuffer = [];
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
     * Send a resize signal via stty.
     */
    public function resize(int $cols, int $rows): void
    {
        if (! is_resource($this->process)) {
            return;
        }

        $status = proc_get_status($this->process);

        if ($status['running'] && $status['pid']) {
            // Send SIGWINCH to the process group
            $pgid = $status['pid'];
            @exec("kill -WINCH -{$pgid} 2>/dev/null");

            // Also set stty size on the child
            $this->writeToProcess("\x1b[8;{$rows};{$cols}t");
        }
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
        if (is_resource($this->process)) {
            $status = proc_get_status($this->process);

            if (! $status['running']) {
                // Read any remaining output
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

                $exitCode = $status['exitcode'];

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
