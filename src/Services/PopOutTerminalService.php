<?php

namespace MakeDev\Orca\Services;

use MakeDev\Orca\Enums\OrcaSessionStatus;
use MakeDev\Orca\Enums\OrcaSessionType;
use MakeDev\Orca\Jobs\RunClaudeSession;
use MakeDev\Orca\Models\OrcaSession;

class PopOutTerminalService
{
    public function isAvailable(): bool
    {
        return app()->isLocal()
            && PHP_OS_FAMILY === 'Darwin'
            && config('orca.popout.enabled', true);
    }

    public function popOut(OrcaSession $session, string $baseUrl): void
    {
        $callbackUrl = $baseUrl.'/orca/popout/return';

        $script = $this->buildWrapperScript($session, $callbackUrl);
        $scriptPath = sys_get_temp_dir().'/orca_popout_'.$session->id.'.sh';

        file_put_contents($scriptPath, $script);
        chmod($scriptPath, 0755);

        $session->update([
            'status' => OrcaSessionStatus::PoppedOut,
            'popped_out_at' => now(),
            'popout_script_path' => $scriptPath,
        ]);

        exec('open -a Terminal '.escapeshellarg($scriptPath));
    }

    public function buildWrapperScript(OrcaSession $session, string $callbackUrl): string
    {
        $claudeCmd = $this->buildClaudeCommand($session);
        $transcriptPath = sys_get_temp_dir().'/orca_transcript_'.$session->id.'.txt';
        $sessionId = $session->id;

        return <<<BASH
#!/bin/bash
cd {$this->escapeForBash($session->working_directory ?: base_path())}

TRANSCRIPT_PATH="{$transcriptPath}"

# Run Claude interactively with transcript capture
script -q "\$TRANSCRIPT_PATH" {$claudeCmd}
EXIT_CODE=\$?

# Callback to Orca with exit code and transcript path
curl -s -X POST "{$callbackUrl}" \
    -H "Content-Type: application/json" \
    -d "{\"session_id\":\"{$sessionId}\",\"exit_code\":\$EXIT_CODE,\"transcript_path\":\"\$TRANSCRIPT_PATH\"}" \
    > /dev/null 2>&1

exit \$EXIT_CODE
BASH;
    }

    public function buildClaudeCommand(OrcaSession $session): string
    {
        $binary = config('orca.claude.binary', 'claude');
        $parts = ['env', '-u', 'CLAUDECODE', escapeshellarg($binary)];

        if ($session->resume_session_id || $session->claude_session_id) {
            $resumeId = $session->resume_session_id ?: $session->claude_session_id;
            $parts[] = '--resume';
            $parts[] = escapeshellarg($resumeId);
        } elseif ($session->prompt) {
            $parts[] = '-p';
            $parts[] = escapeshellarg($session->prompt);
        }

        if ($session->skip_permissions) {
            $parts[] = '--dangerously-skip-permissions';
        } elseif ($session->permission_mode) {
            $parts[] = '--permission-mode';
            $parts[] = escapeshellarg($session->permission_mode);
        }

        if ($session->allowed_tools) {
            $parts[] = '--allowedTools';
            foreach ($session->allowed_tools as $tool) {
                $parts[] = escapeshellarg($tool);
            }
        }

        return implode(' ', $parts);
    }

    public function handleReturn(OrcaSession $session, int $exitCode, ?string $transcriptPath): void
    {
        $transcript = $this->readTranscript($transcriptPath);

        $session->messages()->create([
            'direction' => 'outbound',
            'type' => 'system',
            'content' => ['text' => 'Session completed in external terminal.'],
        ]);

        $session->update([
            'status' => $exitCode === 0 ? OrcaSessionStatus::Completed : OrcaSessionStatus::Failed,
            'exit_code' => $exitCode,
            'completed_at' => now(),
            'popout_transcript' => $transcript,
        ]);

        if (config('orca.popout.cleanup_on_return', true)) {
            $this->cleanup($session);
        }

        // Auto-resume back into Orca so the user can continue interacting
        if ($session->claude_session_id) {
            $this->autoResume($session);
        }
    }

    private function autoResume(OrcaSession $session): void
    {
        // Walk up to the root session for the parent chain
        $root = $session;
        if ($root->parent_id) {
            $root = OrcaSession::find($root->parent_id) ?? $root;
        }

        $child = OrcaSession::create([
            'session_type' => OrcaSessionType::Claude,
            'prompt' => 'Continue. The user may provide feedback.',
            'resume_session_id' => $session->claude_session_id,
            'parent_id' => $root->id,
            'skip_permissions' => true,
            'max_turns' => $session->max_turns,
            'allowed_tools' => $session->allowed_tools,
            'working_directory' => $session->working_directory,
            'status' => OrcaSessionStatus::Pending,
        ]);

        RunClaudeSession::dispatch($child->id);
    }

    private function readTranscript(?string $transcriptPath): ?string
    {
        if (! $transcriptPath || ! file_exists($transcriptPath)) {
            return null;
        }

        $raw = file_get_contents($transcriptPath);
        $transcript = $this->stripAnsiCodes($raw);

        $maxKb = config('orca.popout.transcript_max_kb', 50);
        if (strlen($transcript) > $maxKb * 1024) {
            $transcript = substr($transcript, 0, $maxKb * 1024).'... [truncated]';
        }

        return $transcript;
    }

    public function cleanup(OrcaSession $session): void
    {
        if ($session->popout_script_path && file_exists($session->popout_script_path)) {
            @unlink($session->popout_script_path);
        }

        $transcriptPath = sys_get_temp_dir().'/orca_transcript_'.$session->id.'.txt';
        if (file_exists($transcriptPath)) {
            @unlink($transcriptPath);
        }
    }

    private function stripAnsiCodes(string $text): string
    {
        return preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', $text);
    }

    private function escapeForBash(string $value): string
    {
        return escapeshellarg($value);
    }
}
