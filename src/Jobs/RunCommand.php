<?php

namespace MakeDev\Orca\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use MakeDev\Orca\Enums\OrcaSessionStatus;
use MakeDev\Orca\Models\OrcaSession;
use Symfony\Component\Process\Process;

class RunCommand implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout;

    public function __construct(public string $sessionId)
    {
        $this->timeout = (int) config('orca.timeout', 300);
        $this->onQueue(config('orca.queue', 'default'));
    }

    public function handle(): void
    {
        $session = OrcaSession::findOrFail($this->sessionId);
        $session->update(['status' => OrcaSessionStatus::Running, 'started_at' => now()]);

        $process = Process::fromShellCommandline($session->command);
        $process->setTimeout($this->timeout);
        $process->start();

        $session->update(['pid' => $process->getPid()]);

        while ($process->isRunning()) {
            $this->flushOutput($process, $session);
            usleep(250_000);
        }

        $this->flushOutput($process, $session);

        $session->update([
            'status' => $process->isSuccessful() ? OrcaSessionStatus::Completed : OrcaSessionStatus::Failed,
            'exit_code' => $process->getExitCode(),
            'completed_at' => now(),
        ]);
    }

    public function failed(\Throwable $e): void
    {
        $session = OrcaSession::find($this->sessionId);

        if ($session) {
            $session->appendOutput("\n[ERROR] ".$e->getMessage());
            $session->update([
                'status' => OrcaSessionStatus::Failed,
                'completed_at' => now(),
            ]);
        }
    }

    private function flushOutput(Process $process, OrcaSession $session): void
    {
        $stdout = $process->getIncrementalOutput();
        $stderr = $process->getIncrementalErrorOutput();
        $buffer = $stdout.$stderr;

        if ($buffer !== '') {
            $session->appendOutput($buffer);
        }
    }
}
