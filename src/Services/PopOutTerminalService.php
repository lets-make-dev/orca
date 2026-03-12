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

    public function binaryPath(): ?string
    {
        $paths = [
            dirname(__DIR__, 2).'/bin/orca-terminal/orca-terminal',
        ];

        foreach ($paths as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    public function sendSocketCommand(string $sessionId, string $command): ?string
    {
        $sockPath = sys_get_temp_dir().'/orca_terminal_'.$sessionId.'.sock';

        if (! file_exists($sockPath)) {
            return null;
        }

        $socket = @stream_socket_client('unix://'.$sockPath, $errno, $errstr, 2);
        if (! $socket) {
            return null;
        }

        fwrite($socket, $command."\n");

        $response = trim(fgets($socket, 1024) ?: '');
        fclose($socket);

        return $response;
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
        $interactive = $session->status === OrcaSessionStatus::PoppedOut;
        $claudeCmd = $this->buildClaudeCommand($session, $interactive);
        $sessionId = $session->id;
        $workingDir = $session->working_directory ?: base_path();
        $binaryPath = $this->binaryPath();

        if ($binaryPath) {
            return $this->buildGoBinaryScript($session, $binaryPath, $claudeCmd, $callbackUrl, $workingDir, $sessionId, $interactive);
        }

        return $this->buildLegacyScript($session, $claudeCmd, $callbackUrl, $workingDir, $sessionId, $interactive);
    }

    private function buildGoBinaryScript(
        OrcaSession $session,
        string $binaryPath,
        string $claudeCmd,
        string $callbackUrl,
        string $workingDir,
        string $sessionId,
        bool $interactive,
    ): string {
        $mode = $session->skip_permissions ? 'execute' : ($session->permission_mode ?: 'default');
        $screenshotInterval = (int) config('orca.popout.screenshot_interval', 5);
        $screenshotDelay = (int) config('orca.popout.screenshot_initial_delay', 4);
        $promptDelay = (int) config('orca.popout.prompt_delay', config('orca.popout.expect_initial_delay', 3));

        $tempDir = sys_get_temp_dir();

        $args = [
            escapeshellarg($binaryPath),
            '--session-id', escapeshellarg($sessionId),
            '--claude-cmd', escapeshellarg($claudeCmd),
            '--callback-url', escapeshellarg($callbackUrl),
            '--screenshot-interval', $screenshotInterval,
            '--screenshot-delay', $screenshotDelay,
            '--working-dir', escapeshellarg($workingDir),
            '--temp-dir', escapeshellarg($tempDir),
        ];

        $heartbeatUrl = str_replace('/popout/return', '/popout/heartbeat', $callbackUrl);
        $heartbeatInterval = (int) config('orca.popout.heartbeat_interval', 10);
        $args[] = '--heartbeat-url';
        $args[] = escapeshellarg($heartbeatUrl);
        $args[] = '--heartbeat-interval';
        $args[] = $heartbeatInterval;

        if ($interactive && $session->prompt) {
            $args[] = '--prompt';
            $args[] = escapeshellarg($session->prompt);
            $args[] = '--prompt-delay';
            $args[] = $promptDelay;
        }

        $argsStr = implode(' ', $args);

        $escapedSessionId = $this->escapeForBash($sessionId);
        $escapedBasePath = $this->escapeForBash(base_path());

        return <<<BASH
#!/bin/bash
clear
export ORCA_SESSION_ID={$escapedSessionId}
export ORCA_BASE_PATH={$escapedBasePath}
exec {$argsStr}
BASH;
    }

    private function buildLegacyScript(
        OrcaSession $session,
        string $claudeCmd,
        string $callbackUrl,
        string $workingDir,
        string $sessionId,
        bool $interactive,
    ): string {
        $transcriptPath = sys_get_temp_dir().'/orca_transcript_'.$sessionId.'.txt';
        $logPath = storage_path('logs/orca-popout.log');
        $mode = $session->skip_permissions ? 'execute' : ($session->permission_mode ?: 'default');
        $screenshotPath = sys_get_temp_dir().'/orca_terminal_screenshot_'.$sessionId.'.png';

        $promptBlock = '';
        if ($interactive && $session->prompt) {
            $escapedPrompt = $this->escapeForBash($session->prompt);
            $promptBlock = <<<PROMPT

# Copy prompt to clipboard and display it
echo {$escapedPrompt} | pbcopy
echo -e "\\033[90m▸ Prompt copied to clipboard — paste it into Claude\\033[0m"
echo -e "\\033[90m────────────────────────────────────────\\033[0m"
echo -e "\\033[37m\$(echo {$escapedPrompt} | head -c 500)\\033[0m"
echo -e "\\033[90m────────────────────────────────────────\\033[0m"
echo ""
PROMPT;
        }

        $runBlock = <<<RUN
{$promptBlock}
# Run Claude interactively with transcript capture
script -q "\$TRANSCRIPT_PATH" {$claudeCmd}
EXIT_CODE=\$?
RUN;

        $screenshotBlock = $this->buildScreenshotCaptureBlock($sessionId, $screenshotPath);

        $escapedSessionId = $this->escapeForBash($sessionId);
        $escapedBasePath = $this->escapeForBash(base_path());

        return <<<BASH
#!/bin/bash
cd {$this->escapeForBash($workingDir)}
export ORCA_SESSION_ID={$escapedSessionId}
export ORCA_BASE_PATH={$escapedBasePath}

TRANSCRIPT_PATH="{$transcriptPath}"
LOG_PATH="{$logPath}"

# Set terminal window title for screenshot capture
echo -ne "\\033]0;orca-{$sessionId}\\007"

# Save Terminal window ID for focusTerminal (stable, not overwritten by Claude)
ORCA_WINDOW_ID=\$(osascript -e 'tell application "Terminal" to get id of front window' 2>/dev/null)
echo "\$ORCA_WINDOW_ID" > "/tmp/orca_window_{$sessionId}.txt"

# Log the command
echo "" >> "\$LOG_PATH"
echo "────────────────────────────────────────" >> "\$LOG_PATH"
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Session: {$sessionId}" >> "\$LOG_PATH"
echo "Mode: {$mode}" >> "\$LOG_PATH"
echo "Command: {$claudeCmd}" >> "\$LOG_PATH"
echo "────────────────────────────────────────" >> "\$LOG_PATH"

# Show the command in terminal
echo -e "\\033[90m▸ {$mode} mode\\033[0m"
echo -e "\\033[90m▸ {$claudeCmd}\\033[0m"

{$screenshotBlock}
{$runBlock}

# Kill screenshot capture loop
if [ -n "\$SCREENSHOT_PID" ]; then
    kill \$SCREENSHOT_PID 2>/dev/null
    wait \$SCREENSHOT_PID 2>/dev/null
fi

# Callback to Orca with exit code and transcript path
curl -s -X POST "{$callbackUrl}" \
    -H "Content-Type: application/json" \
    -d "{\"session_id\":\"{$sessionId}\",\"exit_code\":\$EXIT_CODE,\"transcript_path\":\"\$TRANSCRIPT_PATH\"}" \
    > /dev/null 2>&1

exit \$EXIT_CODE
BASH;
    }

    public function buildClaudeCommand(OrcaSession $session, bool $interactive = false): string
    {
        $binary = config('orca.claude.binary', 'claude');
        $parts = ['env', '-u', 'CLAUDECODE', escapeshellarg($binary)];

        if ($session->resume_session_id || $session->claude_session_id) {
            $resumeId = $session->resume_session_id ?: $session->claude_session_id;
            $parts[] = '--resume';
            $parts[] = escapeshellarg($resumeId);
        } elseif (! $interactive && $session->prompt) {
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

        $tempDir = sys_get_temp_dir();
        $id = $session->id;

        $tempFiles = [
            $tempDir.'/orca_transcript_'.$id.'.txt',
            $tempDir.'/orca_terminal_screenshot_'.$id.'.png',
            $tempDir.'/orca_window_'.$id.'.txt',
            $tempDir.'/orca_terminal_'.$id.'.sock',
        ];

        foreach ($tempFiles as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
    }

    public function screenshotPath(OrcaSession $session): string
    {
        return sys_get_temp_dir().'/orca_terminal_screenshot_'.$session->id.'.png';
    }

    private function buildScreenshotCaptureBlock(string $sessionId, string $screenshotPath): string
    {
        $interval = (int) config('orca.popout.screenshot_interval', 5);
        $initialDelay = (int) config('orca.popout.screenshot_initial_delay', 4);

        return <<<BASH
# Background screenshot capture loop using Terminal window ID
(
    sleep {$initialDelay}
    while true; do
        CAPTURE_WID=\$(osascript -e 'tell application "Terminal" to get id of front window' 2>/dev/null)
        if [ -n "\$CAPTURE_WID" ]; then
            screencapture -l"\$CAPTURE_WID" -x -o "{$screenshotPath}" 2>/dev/null
        fi
        sleep {$interval}
    done
) &
SCREENSHOT_PID=\$!
BASH;
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
