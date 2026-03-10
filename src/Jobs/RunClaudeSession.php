<?php

namespace MakeDev\Orca\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use MakeDev\Orca\Enums\OrcaSessionStatus;
use MakeDev\Orca\Models\OrcaSession;
use MakeDev\Orca\Services\ClaudeEventParser;
use MakeDev\Orca\Services\SessionChannel;

class RunClaudeSession implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout;

    private bool $sessionDeleted = false;

    public function __construct(public string $sessionId)
    {
        $this->timeout = (int) config('orca.claude.timeout', 3600);
        $this->onQueue(config('orca.queue', 'default'));
    }

    public function handle(ClaudeEventParser $parser, SessionChannel $channel): void
    {
        $session = OrcaSession::findOrFail($this->sessionId);
        $session->update([
            'status' => OrcaSessionStatus::Running,
            'started_at' => now(),
        ]);

        $stdoutFile = tempnam(sys_get_temp_dir(), 'orca_stdout_');
        $stderrFile = tempnam(sys_get_temp_dir(), 'orca_stderr_');

        try {
            $this->runProcess($session, $parser, $channel, $stdoutFile, $stderrFile);
        } catch (\Throwable $e) {
            $channel->cleanup($this->sessionId);

            $fresh = $session->fresh();
            if ($fresh && ! $fresh->status->isTerminal()) {
                $fresh->messages()->create([
                    'direction' => 'outbound',
                    'type' => 'error',
                    'content' => ['text' => $e->getMessage()],
                ]);
                $fresh->update([
                    'status' => OrcaSessionStatus::Failed,
                    'completed_at' => now(),
                    'exit_code' => $e->getCode() ?: 1,
                ]);
            }

            throw $e;
        } finally {
            @unlink($stdoutFile);
            @unlink($stderrFile);
        }
    }

    public function failed(\Throwable $e): void
    {
        // Unwrap MaxAttemptsExceededException to get the real error
        $realError = $e->getPrevious() ?? $e;

        $session = OrcaSession::find($this->sessionId);

        if (! $session) {
            return;
        }

        // Only store error if handle() didn't already record one
        if (! $session->status->isTerminal()) {
            $session->messages()->create([
                'direction' => 'outbound',
                'type' => 'error',
                'content' => ['text' => $realError->getMessage()],
            ]);
            $session->update([
                'status' => OrcaSessionStatus::Failed,
                'completed_at' => now(),
            ]);
        }
    }

    private function runProcess(OrcaSession $session, ClaudeEventParser $parser, SessionChannel $channel, string $stdoutFile, string $stderrFile): void
    {
        $cmd = $this->buildCommand($session);
        $cwd = $session->working_directory ?: base_path();

        // Claude CLI doesn't write stream-json to pipes, so we use file descriptors
        // for stdout/stderr. stdin remains a pipe kept open for bidirectional
        // communication via --input-format stream-json.
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $stdoutFile, 'w'],
            2 => ['file', $stderrFile, 'w'],
        ];

        $cmd = 'env -u CLAUDECODE '.$cmd;

        $proc = proc_open($cmd, $descriptors, $pipes, $cwd);

        if (! is_resource($proc)) {
            $session->update([
                'status' => OrcaSessionStatus::Failed,
                'completed_at' => now(),
            ]);
            $session->messages()->create([
                'direction' => 'outbound',
                'type' => 'error',
                'content' => ['text' => 'Failed to start Claude process.'],
            ]);

            return;
        }

        $stdinPipe = $pipes[0];

        if ($session->resume_session_id) {
            // Resuming: send a continuation message instead of the original prompt
            $resumePayload = $parser->buildStdinPayload($session->prompt ?: 'Continue with the previous task.');
            fwrite($stdinPipe, $resumePayload);
            fflush($stdinPipe);
        } else {
            // New session: send the initial prompt via stdin as stream-json NDJSON
            $initialPayload = $parser->buildStdinPayload($session->prompt);
            fwrite($stdinPipe, $initialPayload);
            fflush($stdinPipe);
        }

        stream_set_blocking($stdinPipe, false);

        $pid = proc_get_status($proc)['pid'] ?? null;
        $session->update(['pid' => $pid]);

        $channel->setTtl($this->sessionId);

        $stdoutOffset = 0;
        $stderrOffset = 0;
        $stdoutBuffer = '';

        try {
            while ($this->isProcessRunning($proc)) {
                $stdoutOffset = $this->readFileOutput($stdoutFile, $stdoutOffset, $stdoutBuffer, $session, $parser);
                $stderrOffset = $this->readFileErrors($stderrFile, $stderrOffset, $session);
                $this->deliverInboundMessages($session, $channel, $stdinPipe);
                $this->checkCancellation($session, $proc);

                if ($this->sessionDeleted) {
                    break;
                }

                usleep(250_000);
            }

            // Final flush after process exits
            if (! $this->sessionDeleted) {
                usleep(100_000);
                $this->readFileOutput($stdoutFile, $stdoutOffset, $stdoutBuffer, $session, $parser);
                $this->readFileErrors($stderrFile, $stderrOffset, $session);
            }
        } finally {
            @fclose($stdinPipe);
            $channel->cleanup($this->sessionId);
            $status = proc_close($proc);
        }

        $fresh = $session->fresh();
        if (! $fresh) {
            return;
        }
        if (! $fresh->status->isTerminal()) {
            if ($status !== 0) {
                // Read any remaining stderr for the error message
                clearstatcache(true, $stderrFile);
                $stderrContent = @file_get_contents($stderrFile);
                if ($stderrContent) {
                    $fresh->messages()->create([
                        'direction' => 'outbound',
                        'type' => 'error',
                        'content' => ['text' => trim($stderrContent)],
                    ]);
                }
            }

            $fresh->update([
                'status' => $status === 0 ? OrcaSessionStatus::Completed : OrcaSessionStatus::Failed,
                'exit_code' => $status,
                'completed_at' => now(),
            ]);
        }
    }

    private function buildCommand(OrcaSession $session): string
    {
        $binary = config('orca.claude.binary', 'claude');
        $parts = [
            escapeshellarg($binary),
            '--print',
            '--verbose',
            '--output-format', 'stream-json',
            '--input-format', 'stream-json',
        ];

        if ($session->resume_session_id) {
            $parts[] = '--resume';
            $parts[] = escapeshellarg($session->resume_session_id);
        }

        if ($session->skip_permissions) {
            $parts[] = '--dangerously-skip-permissions';
        } elseif ($session->permission_mode) {
            $parts[] = '--permission-mode';
            $parts[] = escapeshellarg($session->permission_mode);
        }

        if ($session->claude_session_id && ! $session->resume_session_id) {
            $parts[] = '--session-id';
            $parts[] = escapeshellarg($session->claude_session_id);
        }

        if ($session->max_turns) {
            $parts[] = '--max-turns';
            $parts[] = (string) $session->max_turns;
        }

        if ($session->allowed_tools) {
            $parts[] = '--allowedTools';
            foreach ($session->allowed_tools as $tool) {
                $parts[] = escapeshellarg($tool);
            }
        }

        // Prompt is sent via stdin as stream-json, not as a CLI argument
        return implode(' ', $parts);
    }

    private function readFileOutput(string $file, int $offset, string &$buffer, OrcaSession $session, ClaudeEventParser $parser): int
    {
        clearstatcache(true, $file);
        $size = filesize($file);

        if ($size === false || $size <= $offset) {
            return $offset;
        }

        $handle = fopen($file, 'r');
        fseek($handle, $offset);
        $chunk = fread($handle, $size - $offset);
        fclose($handle);

        if ($chunk === false || $chunk === '') {
            return $offset;
        }

        $buffer .= $chunk;

        while (($pos = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 1);

            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $event = json_decode($line, true);
            if ($event === null) {
                continue;
            }

            $type = $parser->classify($event);
            $displayContent = $parser->extractDisplayContent($event);

            // Skip internal events with no displayable content
            if ($type === 'system' && $displayContent === '') {
                continue;
            }

            $content = [
                'text' => $displayContent,
                'raw' => $event,
            ];
            $metadata = $parser->extractMetadata($event);

            if ($this->sessionDeleted) {
                break;
            }

            if (! $session->claude_session_id && isset($event['session_id'])) {
                $session->update(['claude_session_id' => $event['session_id']]);
            }

            $session->messages()->create([
                'direction' => 'outbound',
                'type' => $type,
                'content' => $content,
                'metadata' => $metadata ?: null,
            ]);

            if ($parser->isInteractionRequired($event)) {
                $session->update(['status' => OrcaSessionStatus::AwaitingInput]);
            }
        }

        return $size;
    }

    private function readFileErrors(string $file, int $offset, OrcaSession $session): int
    {
        if ($this->sessionDeleted) {
            return $offset;
        }

        clearstatcache(true, $file);
        $size = filesize($file);

        if ($size === false || $size <= $offset) {
            return $offset;
        }

        $handle = fopen($file, 'r');
        fseek($handle, $offset);
        $chunk = fread($handle, $size - $offset);
        fclose($handle);

        if ($chunk === false || $chunk === '') {
            return $offset;
        }

        $session->messages()->create([
            'direction' => 'outbound',
            'type' => 'error',
            'content' => ['text' => $chunk],
        ]);

        return $size;
    }

    /**
     * @param  resource  $stdinPipe
     */
    private function deliverInboundMessages(OrcaSession $session, SessionChannel $channel, $stdinPipe): void
    {
        $payload = $channel->pop($this->sessionId);

        if ($payload === null) {
            return;
        }

        @fwrite($stdinPipe, $payload);
        @fflush($stdinPipe);

        $session->messages()
            ->where('direction', 'inbound')
            ->whereNull('delivered_at')
            ->oldest('created_at')
            ->limit(1)
            ->update(['delivered_at' => now()]);

        if ($session->status === OrcaSessionStatus::AwaitingInput) {
            $session->update(['status' => OrcaSessionStatus::Running]);
        }
    }

    /**
     * @param  resource  $proc
     */
    private function checkCancellation(OrcaSession $session, $proc): void
    {
        $fresh = $session->fresh();

        if (! $fresh) {
            $this->sessionDeleted = true;
            proc_terminate($proc);

            return;
        }

        $session->setRawAttributes($fresh->getAttributes());
        $session->syncOriginal();

        if ($session->status === OrcaSessionStatus::Cancelled) {
            proc_terminate($proc);

            $session->update([
                'exit_code' => 137,
                'completed_at' => now(),
            ]);
        }
    }

    /**
     * @param  resource  $proc
     */
    private function isProcessRunning($proc): bool
    {
        $status = proc_get_status($proc);

        return $status['running'] ?? false;
    }
}
