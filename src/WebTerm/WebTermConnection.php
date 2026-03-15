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

    /**
     * @param  Closure(string): void  $send
     */
    public function __construct(
        private Closure $send,
        private OrcaSession $session,
        private LoopInterface $loop,
    ) {}

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
            $this->sendJson(['type' => 'output', 'data' => $output]);
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
                    $this->sendJson(['type' => 'output', 'data' => $remaining]);
                }

                $exitCode = $status['exitcode'];
                $this->sendJson(['type' => 'exit', 'code' => $exitCode]);

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

    private function sendJson(array $data): void
    {
        ($this->send)(json_encode($data));
    }

    private function sendError(string $message): void
    {
        $this->sendJson(['type' => 'error', 'message' => $message]);
    }
}
