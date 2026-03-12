<?php

namespace MakeDev\Orca\Console\Commands;

use Illuminate\Console\Command;
use MakeDev\Orca\Models\OrcaSession;

class ClaudeHook extends Command
{
    protected $signature = 'orca:claude-hook';

    protected $description = 'Handle Claude Code hook events for Orca sessions';

    public function handle(): int
    {
        $sessionId = env('ORCA_SESSION_ID');
        if (! $sessionId) {
            return self::SUCCESS;
        }

        $input = $this->readStdin();
        if (! $input) {
            return self::SUCCESS;
        }

        $data = json_decode($input, true);
        if (! is_array($data) || ! isset($data['hook_event_name'])) {
            return self::SUCCESS;
        }

        $session = OrcaSession::find($sessionId);
        if (! $session) {
            return self::SUCCESS;
        }

        match ($data['hook_event_name']) {
            'SessionStart' => $this->handleSessionStart($session, $data),
            'PostToolUse' => $session->update(['last_heartbeat_at' => now()]),
            'Stop' => $session->update(['last_heartbeat_at' => now()]),
            default => null,
        };

        return self::SUCCESS;
    }

    private function handleSessionStart(OrcaSession $session, array $data): void
    {
        $updates = ['last_heartbeat_at' => now()];

        if (! $session->claude_session_id && isset($data['session_id'])) {
            $updates['claude_session_id'] = $data['session_id'];
        }

        $session->update($updates);
    }

    protected function readStdin(): ?string
    {
        $stdin = fopen('php://stdin', 'r');
        if (! $stdin) {
            return null;
        }

        stream_set_blocking($stdin, false);
        $input = stream_get_contents($stdin);
        fclose($stdin);

        return $input ?: null;
    }
}
