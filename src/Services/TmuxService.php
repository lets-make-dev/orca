<?php

namespace MakeDev\Orca\Services;

class TmuxService
{
    private ?bool $available = null;

    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        if (! config('orca.tmux.enabled', true)) {
            return $this->available = false;
        }

        $binary = $this->binary();
        exec("{$binary} -V 2>/dev/null", $output, $exitCode);

        return $this->available = $exitCode === 0;
    }

    public function binary(): string
    {
        return config('orca.tmux.binary', 'tmux');
    }

    public function sessionName(string $sessionId): string
    {
        return "orca-{$sessionId}";
    }

    public function sessionExists(string $sessionId): bool
    {
        $name = $this->sessionName($sessionId);
        $binary = $this->binary();

        exec("{$binary} has-session -t ".escapeshellarg($name).' 2>/dev/null', $output, $exitCode);

        return $exitCode === 0;
    }

    /**
     * Create a detached tmux session running the given command.
     *
     * @param  array<string, string>  $env
     */
    public function createSession(string $sessionId, string $command, string $workingDir, int $cols, int $rows, array $env = []): bool
    {
        $name = $this->sessionName($sessionId);
        $binary = $this->binary();

        // Set environment variables inside the tmux session
        $envCommands = [];
        foreach ($env as $key => $value) {
            $envCommands[] = sprintf(
                '%s set-environment -t %s %s %s',
                $binary,
                escapeshellarg($name),
                escapeshellarg($key),
                escapeshellarg($value),
            );
        }

        // Create a detached session with the given dimensions
        $cmd = sprintf(
            '%s new-session -d -s %s -x %d -y %d -c %s %s 2>/dev/null',
            $binary,
            escapeshellarg($name),
            $cols,
            $rows,
            escapeshellarg($workingDir),
            escapeshellarg($command),
        );

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            return false;
        }

        // Set remain-on-exit so we can read the exit code after the command finishes
        exec(sprintf(
            '%s set-option -t %s remain-on-exit on 2>/dev/null',
            $binary,
            escapeshellarg($name),
        ));

        // Set environment variables
        foreach ($envCommands as $envCmd) {
            exec($envCmd);
        }

        return true;
    }

    /**
     * Configure tmux status bar and key binding for popping back into the browser.
     */
    public function configurePopIn(string $sessionId, string $baseUrl): void
    {
        $name = $this->sessionName($sessionId);
        $binary = $this->binary();
        $popInUrl = rtrim($baseUrl, '/').'/orca/pop-in';

        // The key binding extracts the session ID from the tmux session name (orca-{id}),
        // signals the pop-in endpoint, then detaches the client so the terminal window closes
        $bindScript = sprintf(
            'SN=$(tmux display-message -p "#{session_name}"); SID="${SN#orca-}"; curl -s -X POST %s -H "Content-Type: application/json" -d "{\\"session_id\\":\\"$SID\\"}" > /dev/null 2>&1; tmux detach-client',
            escapeshellarg($popInUrl),
        );

        // Bind Prefix + B globally — the script checks the session name
        exec(sprintf(
            '%s bind-key B run-shell %s 2>/dev/null',
            $binary,
            escapeshellarg($bindScript),
        ));

        // Set session-specific status bar with hint
        exec(sprintf(
            '%s set-option -t %s status-right %s 2>/dev/null',
            $binary,
            escapeshellarg($name),
            escapeshellarg(' #[fg=cyan]^b B#[default] → Browser '),
        ));

        exec(sprintf(
            '%s set-option -t %s status-style %s 2>/dev/null',
            $binary,
            escapeshellarg($name),
            escapeshellarg('bg=black,fg=white'),
        ));

        exec(sprintf(
            '%s set-option -t %s status-left %s 2>/dev/null',
            $binary,
            escapeshellarg($name),
            escapeshellarg(' #[fg=cyan]ORCA#[default] '),
        ));
    }

    public function setStatusBar(string $sessionId, bool $visible): void
    {
        $name = $this->sessionName($sessionId);
        $binary = $this->binary();
        $value = $visible ? 'on' : 'off';

        exec(sprintf(
            '%s set-option -t %s status %s 2>/dev/null',
            $binary,
            escapeshellarg($name),
            $value,
        ));
    }

    public function attachCommand(string $sessionId): string
    {
        $name = $this->sessionName($sessionId);

        return sprintf('%s attach-session -t %s', $this->binary(), escapeshellarg($name));
    }

    public function resize(string $sessionId, int $cols, int $rows): void
    {
        $name = $this->sessionName($sessionId);
        $binary = $this->binary();

        exec(sprintf(
            '%s resize-window -t %s -x %d -y %d 2>/dev/null',
            $binary,
            escapeshellarg($name),
            $cols,
            $rows,
        ));
    }

    public function killSession(string $sessionId): void
    {
        $name = $this->sessionName($sessionId);
        $binary = $this->binary();

        exec(sprintf(
            '%s kill-session -t %s 2>/dev/null',
            $binary,
            escapeshellarg($name),
        ));
    }

    /**
     * Check if the process inside the tmux pane has exited.
     */
    public function hasProcessExited(string $sessionId): bool
    {
        $name = $this->sessionName($sessionId);
        $binary = $this->binary();

        $result = trim(exec(sprintf(
            '%s list-panes -t %s -F "#{pane_dead}" 2>/dev/null',
            $binary,
            escapeshellarg($name),
        )) ?? '');

        return $result === '1';
    }

    /**
     * Get the exit code of the dead process inside the tmux pane.
     */
    public function getExitCode(string $sessionId): ?int
    {
        $name = $this->sessionName($sessionId);
        $binary = $this->binary();

        $result = trim(exec(sprintf(
            '%s list-panes -t %s -F "#{pane_dead_status}" 2>/dev/null',
            $binary,
            escapeshellarg($name),
        )) ?? '');

        if ($result === '' || ! is_numeric($result)) {
            return null;
        }

        return (int) $result;
    }
}
