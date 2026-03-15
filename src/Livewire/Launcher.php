<?php

namespace MakeDev\Orca\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Attributes\Session;
use MakeDev\MakeDev\Livewire\MakeDevModuleComponent;
use MakeDev\Orca\Enums\OrcaSessionStatus;
use MakeDev\Orca\Enums\OrcaSessionType;
use MakeDev\Orca\Jobs\RunClaudeSession;
use MakeDev\Orca\Jobs\RunCommand;
use MakeDev\Orca\Models\OrcaSession;
use MakeDev\Orca\Services\ClaudeEventParser;
use MakeDev\Orca\Services\PopOutTerminalService;
use MakeDev\Orca\Services\RouteResolver;
use MakeDev\Orca\Services\SessionChannel;
use MakeDev\Orca\WebTerm\WebTermTokenService;

class Launcher extends MakeDevModuleComponent
{
    public string $command = '';

    public string $prompt = '';

    /** @var array<string, string> */
    public array $sessionInputs = [];

    /** @var array<string, string> */
    public array $sessionScreenshots = [];

    public bool $launcherOpen = false;

    #[Session]
    public string $model = '';

    #[Session]
    public string $expandedSessionId = '';

    public string $toolPhraseText = '';

    public string $toolPhraseName = '';

    public string $toolPhraseMessageId = '';

    public int $toolPhraseSetAt = 0;

    public string $screenshotPath = '';

    #[Session]
    public string $webtermSessionId = '';

    public string $sourceUrl = '';

    public string $debugContext = '';

    /** @var array{name?: string, version?: string, description?: string, keyFiles?: string[], capabilities?: string[], dependencies?: string[]} */
    public array $moduleContext = [];

    public function mount(): void
    {
        if ($this->model === '') {
            $this->model = config('orca.claude.default_model', 'claude-opus-4-6');
        }

        $pending = session()->pull('orca.pending_harpoon_refactor');

        if ($pending) {
            $this->prompt = $pending['prompt'] ?? '';
            $this->moduleContext = $pending['moduleInfo'] ?? [];
            $this->launcherOpen = true;
            $this->expandedSessionId = '';
        }
    }

    #[On('orca:chat-module')]
    public function chatModule(array $moduleInfo): void
    {
        $this->moduleContext = $moduleInfo;
        $this->launcherOpen = true;
        $this->expandedSessionId = '';
    }

    #[On('orca:harpoon-refactor')]
    public function harpoonRefactor(string $prompt, array $moduleInfo = []): void
    {
        $this->moduleContext = $moduleInfo;
        $this->prompt = $prompt;
        $this->launcherOpen = true;
        $this->expandedSessionId = '';
    }

    #[On('orca:scaffold-module')]
    public function scaffoldModule(array $sourceModule, string $newModuleName, string $newModuleDescription): void
    {
        $this->moduleContext = $sourceModule;
        $this->prompt = $this->buildScaffoldPrompt($sourceModule, $newModuleName, $newModuleDescription);
        $this->launcherOpen = true;
        $this->expandedSessionId = '';
    }

    #[On('orca:scaffold-module-terminal')]
    public function scaffoldModuleInTerminal(array $sourceModule, string $newModuleName, string $newModuleDescription): void
    {
        $service = app(PopOutTerminalService::class);

        $prompt = $this->buildScaffoldPrompt($sourceModule, $newModuleName, $newModuleDescription);
        $this->moduleContext = $sourceModule;
        $prompt = $this->appendModuleContext($prompt);

        if (! $service->isAvailable()) {
            $this->prompt = $prompt;
            $this->launcherOpen = true;
            $this->expandedSessionId = '';
            $this->moduleContext = [];

            return;
        }

        $session = OrcaSession::create([
            'session_type' => OrcaSessionType::Claude,
            'prompt' => $prompt,
            'skip_permissions' => true,
            'max_turns' => config('orca.claude.max_turns', 50),
            'allowed_tools' => config('orca.claude.default_allowed_tools', []) ?: null,
            'working_directory' => base_path(),
            'status' => OrcaSessionStatus::PoppedOut,
            'popped_out_at' => now(),
        ]);

        $service->popOut($session, request()->getSchemeAndHttpHost());

        $this->prompt = '';
        $this->moduleContext = [];
        $this->expandedSessionId = $session->id;
        $this->launcherOpen = false;
    }

    public function toggleSession(string $id): void
    {
        $this->expandedSessionId = $this->expandedSessionId === $id ? '' : $id;
        $this->launcherOpen = false;
    }

    public function toggleLauncher(string $sourceUrl = ''): void
    {
        $this->launcherOpen = ! $this->launcherOpen;
        $this->expandedSessionId = '';

        if ($this->launcherOpen && $sourceUrl) {
            $this->sourceUrl = $sourceUrl;
        }
    }

    public function launch(): void
    {
        $this->validate([
            'command' => 'required|string|max:2000',
        ]);

        $session = OrcaSession::create([
            'session_type' => OrcaSessionType::Command,
            'command' => $this->command,
            'status' => OrcaSessionStatus::Pending,
        ]);

        RunCommand::dispatch($session->id);

        $this->command = '';
        $this->expandedSessionId = $session->id;
        $this->launcherOpen = false;
    }

    public function launchClaude(): void
    {
        $this->validate([
            'prompt' => 'required|string|max:10000',
        ]);
        $prompt = $this->buildPromptWithScreenshot($this->prompt);
        $prompt = $this->appendModuleContext($prompt);
        $prompt = $this->appendDebugContext($prompt);

        $session = OrcaSession::create([
            'session_type' => OrcaSessionType::Claude,
            'prompt' => $prompt,
            'screenshot_path' => $this->screenshotPath ?: null,
            'source_url' => $this->sourceUrl ?: null,
            ...$this->resolveSessionContext(),
            'model' => $this->model,
            'permission_mode' => 'plan',
            'max_turns' => config('orca.claude.max_turns', 50),
            'allowed_tools' => config('orca.claude.default_allowed_tools', []) ?: null,
            'working_directory' => base_path(),
            'status' => OrcaSessionStatus::Pending,
        ]);

        RunClaudeSession::dispatch($session->id);

        $this->prompt = '';
        $this->screenshotPath = '';
        $this->sourceUrl = '';
        $this->debugContext = '';
        $this->moduleContext = [];
        $this->expandedSessionId = $session->id;
        $this->launcherOpen = false;
    }

    public function launchClaudeExecute(): void
    {
        $this->validate([
            'prompt' => 'required|string|max:10000',
        ]);
        $prompt = $this->buildPromptWithScreenshot($this->prompt);
        $prompt = $this->appendModuleContext($prompt);
        $prompt = $this->appendDebugContext($prompt);

        $session = OrcaSession::create([
            'session_type' => OrcaSessionType::Claude,
            'prompt' => $prompt,
            'screenshot_path' => $this->screenshotPath ?: null,
            'source_url' => $this->sourceUrl ?: null,
            ...$this->resolveSessionContext(),
            'model' => $this->model,
            'skip_permissions' => true,
            'max_turns' => config('orca.claude.max_turns', 50),
            'allowed_tools' => config('orca.claude.default_allowed_tools', []) ?: null,
            'working_directory' => base_path(),
            'status' => OrcaSessionStatus::Pending,
        ]);

        RunClaudeSession::dispatch($session->id);

        $this->prompt = '';
        $this->screenshotPath = '';
        $this->sourceUrl = '';
        $this->debugContext = '';
        $this->moduleContext = [];
        $this->expandedSessionId = $session->id;
        $this->launcherOpen = false;
    }

    public function respond(string $sessionId): void
    {
        $this->validate([
            "sessionInputs.$sessionId" => 'required|string|max:10000',
        ]);

        $session = OrcaSession::find($sessionId);

        if (! $session || $session->status->isTerminal() || $session->status === OrcaSessionStatus::Pending) {
            return;
        }

        $input = $this->sessionInputs[$sessionId];
        $screenshotPath = $this->sessionScreenshots[$sessionId] ?? '';
        $metadata = [];

        if ($screenshotPath && file_exists($screenshotPath)) {
            $input .= "\n\nI've attached a screenshot of the page at {$screenshotPath}. Please read it with your Read tool to see what I'm referring to.";
            $metadata['screenshot_path'] = $screenshotPath;
        }

        $session->messages()->create([
            'direction' => 'inbound',
            'type' => 'answer',
            'content' => ['text' => $input],
            'metadata' => $metadata ?: null,
        ]);

        $parser = app(ClaudeEventParser::class);
        $this->publishToChannel($sessionId, $parser->buildStdinPayload($input));

        $this->sessionInputs[$sessionId] = '';
        unset($this->sessionScreenshots[$sessionId]);

        $this->dispatch('screenshot-cleared');
    }

    public function approvePermission(string $sessionId): void
    {
        $session = OrcaSession::find($sessionId);

        if (! $session || ! $session->isAwaitingInput()) {
            return;
        }

        $session->messages()->create([
            'direction' => 'inbound',
            'type' => 'answer',
            'content' => ['text' => 'yes'],
            'metadata' => ['interaction_type' => 'permission'],
        ]);

        $parser = app(ClaudeEventParser::class);
        $this->publishToChannel($sessionId, $parser->buildPermissionPayload(true));
    }

    public function denyPermission(string $sessionId): void
    {
        $session = OrcaSession::find($sessionId);

        if (! $session || ! $session->isAwaitingInput()) {
            return;
        }

        $session->messages()->create([
            'direction' => 'inbound',
            'type' => 'answer',
            'content' => ['text' => 'no'],
            'metadata' => ['interaction_type' => 'permission'],
        ]);

        $parser = app(ClaudeEventParser::class);
        $this->publishToChannel($sessionId, $parser->buildPermissionPayload(false));
    }

    public function kill(string $id): void
    {
        $session = OrcaSession::find($id);

        if (! $session || $session->status->isTerminal()) {
            return;
        }

        // Terminate the terminal window for popped-out sessions
        if ($session->status === OrcaSessionStatus::PoppedOut) {
            // Notify browser to disconnect webterm if open
            if ($this->webtermSessionId === $id) {
                $this->webtermSessionId = '';
            }
            $this->dispatch('orca:webterm-disconnect', sessionId: $id);

            app(PopOutTerminalService::class)->terminateTerminal($session);

            $session->update([
                'status' => OrcaSessionStatus::Cancelled,
                'completed_at' => now(),
            ]);

            return;
        }

        if ($session->isClaude()) {
            // For Claude sessions, set cancelled — the job loop checks this
            $session->update([
                'status' => OrcaSessionStatus::Cancelled,
                'completed_at' => now(),
            ]);

            return;
        }

        if ($session->pid && function_exists('posix_kill')) {
            posix_kill($session->pid, 15); // SIGTERM
        }

        $session->update([
            'status' => OrcaSessionStatus::Failed,
            'exit_code' => 137,
            'completed_at' => now(),
        ]);

        $session->appendOutput("\n[KILLED]");
    }

    public function resumeSession(string $id): void
    {
        $original = OrcaSession::find($id);

        if (! $original || ! $original->isClaude()) {
            return;
        }

        // Kill the original if still active
        if ($original->status->isActive()) {
            $original->update([
                'status' => OrcaSessionStatus::Cancelled,
                'completed_at' => now(),
            ]);
        }

        // Walk up to the root session for the parent chain
        $root = $original;
        while ($root->parent_id) {
            $root = OrcaSession::find($root->parent_id) ?? $root;
            break;
        }

        $session = OrcaSession::create([
            'session_type' => OrcaSessionType::Claude,
            'prompt' => $original->claude_session_id
                ? 'Continue with the previous task. Permissions have been granted.'
                : $original->prompt,
            'resume_session_id' => $original->claude_session_id,
            'parent_id' => $root->id,
            'skip_permissions' => true,
            'max_turns' => $original->max_turns,
            'allowed_tools' => $original->allowed_tools,
            'working_directory' => $original->working_directory,
            'status' => OrcaSessionStatus::Pending,
        ]);

        RunClaudeSession::dispatch($session->id);

        $this->expandedSessionId = $session->id;
    }

    public function resumeSessionInTerminal(string $id): void
    {
        $service = app(PopOutTerminalService::class);

        if (! $service->isAvailable()) {
            return;
        }

        $original = OrcaSession::find($id);

        if (! $original || ! $original->isClaude()) {
            return;
        }

        if ($original->status->isActive()) {
            $original->update([
                'status' => OrcaSessionStatus::Cancelled,
                'completed_at' => now(),
            ]);
        }

        $root = $original;
        while ($root->parent_id) {
            $root = OrcaSession::find($root->parent_id) ?? $root;
            break;
        }

        $session = OrcaSession::create([
            'session_type' => OrcaSessionType::Claude,
            'prompt' => $original->claude_session_id
                ? 'Continue with the previous task. Permissions have been granted.'
                : $original->prompt,
            'resume_session_id' => $original->claude_session_id,
            'parent_id' => $root->id,
            'skip_permissions' => true,
            'max_turns' => $original->max_turns,
            'allowed_tools' => $original->allowed_tools,
            'working_directory' => $original->working_directory,
            'status' => OrcaSessionStatus::PoppedOut,
            'popped_out_at' => now(),
        ]);

        $service->popOut($session, request()->getSchemeAndHttpHost());

        $this->expandedSessionId = $session->id;
    }

    public function popOutSession(string $id): void
    {
        $service = app(PopOutTerminalService::class);

        if (! $service->isAvailable()) {
            return;
        }

        $session = OrcaSession::find($id);

        if (! $session || ! $session->isClaude()) {
            return;
        }

        // Cancel actively managed sessions so RunClaudeSession terminates the process
        if (in_array($session->status, [OrcaSessionStatus::Running, OrcaSessionStatus::AwaitingInput])) {
            $session->update([
                'status' => OrcaSessionStatus::Cancelled,
                'completed_at' => now(),
            ]);
        }

        $service->popOut($session, request()->getSchemeAndHttpHost());
    }

    public function launchClaudeTerminal(): void
    {
        $service = app(PopOutTerminalService::class);

        if (! $service->isAvailable()) {
            return;
        }

        $this->validate([
            'prompt' => 'required|string|max:10000',
        ]);
        $prompt = $this->buildPromptWithScreenshot($this->prompt);
        $prompt = $this->appendModuleContext($prompt);
        $prompt = $this->appendDebugContext($prompt);

        $session = OrcaSession::create([
            'session_type' => OrcaSessionType::Claude,
            'prompt' => $prompt,
            'screenshot_path' => $this->screenshotPath ?: null,
            'source_url' => $this->sourceUrl ?: null,
            ...$this->resolveSessionContext(),
            'model' => $this->model,
            'permission_mode' => 'plan',
            'max_turns' => config('orca.claude.max_turns', 50),
            'allowed_tools' => config('orca.claude.default_allowed_tools', []) ?: null,
            'working_directory' => base_path(),
            'status' => OrcaSessionStatus::PoppedOut,
            'popped_out_at' => now(),
        ]);

        $service->popOut($session, request()->getSchemeAndHttpHost());

        $this->prompt = '';
        $this->screenshotPath = '';
        $this->sourceUrl = '';
        $this->debugContext = '';
        $this->moduleContext = [];
        $this->expandedSessionId = $session->id;
        $this->launcherOpen = false;
    }

    public function launchClaudeTerminalExecute(): void
    {
        $service = app(PopOutTerminalService::class);

        if (! $service->isAvailable()) {
            return;
        }

        $this->validate([
            'prompt' => 'required|string|max:10000',
        ]);
        $prompt = $this->buildPromptWithScreenshot($this->prompt);
        $prompt = $this->appendModuleContext($prompt);
        $prompt = $this->appendDebugContext($prompt);

        $session = OrcaSession::create([
            'session_type' => OrcaSessionType::Claude,
            'prompt' => $prompt,
            'screenshot_path' => $this->screenshotPath ?: null,
            'source_url' => $this->sourceUrl ?: null,
            ...$this->resolveSessionContext(),
            'model' => $this->model,
            'skip_permissions' => true,
            'max_turns' => config('orca.claude.max_turns', 50),
            'allowed_tools' => config('orca.claude.default_allowed_tools', []) ?: null,
            'working_directory' => base_path(),
            'status' => OrcaSessionStatus::PoppedOut,
            'popped_out_at' => now(),
        ]);

        $service->popOut($session, request()->getSchemeAndHttpHost());

        $this->prompt = '';
        $this->screenshotPath = '';
        $this->sourceUrl = '';
        $this->debugContext = '';
        $this->moduleContext = [];
        $this->expandedSessionId = $session->id;
        $this->launcherOpen = false;
    }

    public function launchClaudePlan(): void
    {
        if ($this->isWebTermAvailable()) {
            $this->launchClaudeWebTerm();
        } elseif (app(PopOutTerminalService::class)->isAvailable()) {
            $this->launchClaudeTerminal();
        } else {
            $this->launchClaude();
        }
    }

    public function launchClaudeExec(): void
    {
        if ($this->isWebTermAvailable()) {
            $this->launchClaudeWebTermExecute();
        } elseif (app(PopOutTerminalService::class)->isAvailable()) {
            $this->launchClaudeTerminalExecute();
        } else {
            $this->launchClaudeExecute();
        }
    }

    public function launchClaudeWebTerm(): void
    {
        if (! $this->isWebTermAvailable()) {
            return;
        }

        $this->validate([
            'prompt' => 'required|string|max:10000',
        ]);
        $prompt = $this->buildPromptWithScreenshot($this->prompt);
        $prompt = $this->appendModuleContext($prompt);
        $prompt = $this->appendDebugContext($prompt);

        $session = OrcaSession::create([
            'session_type' => OrcaSessionType::Claude,
            'prompt' => $prompt,
            'screenshot_path' => $this->screenshotPath ?: null,
            'source_url' => $this->sourceUrl ?: null,
            ...$this->resolveSessionContext(),
            'model' => $this->model,
            'permission_mode' => 'plan',
            'max_turns' => config('orca.claude.max_turns', 50),
            'allowed_tools' => config('orca.claude.default_allowed_tools', []) ?: null,
            'working_directory' => base_path(),
            'status' => OrcaSessionStatus::PoppedOut,
            'popped_out_at' => now(),
        ]);

        $token = app(WebTermTokenService::class)->generate($session->id);
        $host = config('orca.webterm.host', '127.0.0.1');
        $port = (int) config('orca.webterm.port', 8085);
        $wsUrl = "ws://{$host}:{$port}?token=".urlencode($token);

        $this->prompt = '';
        $this->screenshotPath = '';
        $this->sourceUrl = '';
        $this->debugContext = '';
        $this->moduleContext = [];
        $this->webtermSessionId = $session->id;
        $this->expandedSessionId = $session->id;
        $this->launcherOpen = false;

        $this->dispatch('orca:webterm-connect', wsUrl: $wsUrl, sessionId: $session->id);
    }

    public function launchClaudeWebTermExecute(): void
    {
        if (! $this->isWebTermAvailable()) {
            return;
        }

        $this->validate([
            'prompt' => 'required|string|max:10000',
        ]);
        $prompt = $this->buildPromptWithScreenshot($this->prompt);
        $prompt = $this->appendModuleContext($prompt);
        $prompt = $this->appendDebugContext($prompt);

        $session = OrcaSession::create([
            'session_type' => OrcaSessionType::Claude,
            'prompt' => $prompt,
            'screenshot_path' => $this->screenshotPath ?: null,
            'source_url' => $this->sourceUrl ?: null,
            ...$this->resolveSessionContext(),
            'model' => $this->model,
            'skip_permissions' => true,
            'max_turns' => config('orca.claude.max_turns', 50),
            'allowed_tools' => config('orca.claude.default_allowed_tools', []) ?: null,
            'working_directory' => base_path(),
            'status' => OrcaSessionStatus::PoppedOut,
            'popped_out_at' => now(),
        ]);

        $token = app(WebTermTokenService::class)->generate($session->id);
        $host = config('orca.webterm.host', '127.0.0.1');
        $port = (int) config('orca.webterm.port', 8085);
        $wsUrl = "ws://{$host}:{$port}?token=".urlencode($token);

        $this->prompt = '';
        $this->screenshotPath = '';
        $this->sourceUrl = '';
        $this->debugContext = '';
        $this->moduleContext = [];
        $this->webtermSessionId = $session->id;
        $this->expandedSessionId = $session->id;
        $this->launcherOpen = false;

        $this->dispatch('orca:webterm-connect', wsUrl: $wsUrl, sessionId: $session->id);
    }

    public function resumeSessionInWebTerm(string $id): void
    {
        if (! $this->isWebTermAvailable()) {
            return;
        }

        $original = OrcaSession::find($id);

        if (! $original || ! $original->isClaude()) {
            return;
        }

        if ($original->status->isActive()) {
            $original->update([
                'status' => OrcaSessionStatus::Cancelled,
                'completed_at' => now(),
            ]);
        }

        $root = $original;
        while ($root->parent_id) {
            $root = OrcaSession::find($root->parent_id) ?? $root;
            break;
        }

        $session = OrcaSession::create([
            'session_type' => OrcaSessionType::Claude,
            'prompt' => $original->claude_session_id
                ? 'Continue with the previous task. Permissions have been granted.'
                : $original->prompt,
            'resume_session_id' => $original->claude_session_id,
            'parent_id' => $root->id,
            'skip_permissions' => true,
            'max_turns' => $original->max_turns,
            'allowed_tools' => $original->allowed_tools,
            'working_directory' => $original->working_directory,
            'status' => OrcaSessionStatus::PoppedOut,
            'popped_out_at' => now(),
        ]);

        $token = app(WebTermTokenService::class)->generate($session->id);
        $host = config('orca.webterm.host', '127.0.0.1');
        $port = (int) config('orca.webterm.port', 8085);
        $wsUrl = "ws://{$host}:{$port}?token=".urlencode($token);

        $this->webtermSessionId = $session->id;
        $this->expandedSessionId = $session->id;

        $this->dispatch('orca:webterm-connect', wsUrl: $wsUrl, sessionId: $session->id);
    }

    public function isWebTermAvailable(): bool
    {
        if (! app()->isLocal()) {
            return false;
        }

        if (! config('orca.webterm.enabled', true)) {
            return false;
        }

        $host = config('orca.webterm.host', '127.0.0.1');
        $port = (int) config('orca.webterm.port', 8085);

        $connection = @fsockopen($host, $port, $errno, $errstr, 1);

        if ($connection) {
            fclose($connection);

            return true;
        }

        return false;
    }

    public function injectText(string $id, string $text): void
    {
        $session = OrcaSession::find($id);

        if (! $session || $session->status !== OrcaSessionStatus::PoppedOut) {
            return;
        }

        $service = app(PopOutTerminalService::class);
        $service->sendSocketCommand($id, 'inject '.$text);
    }

    public function focusTerminal(string $id): void
    {
        $session = OrcaSession::find($id);

        if (! $session || $session->status !== OrcaSessionStatus::PoppedOut) {
            return;
        }

        $service = app(PopOutTerminalService::class);

        // Try socket command first (Go binary)
        $response = $service->sendSocketCommand($id, 'focus');
        if ($response !== null) {
            return;
        }

        // Fallback: direct osascript for legacy bash wrapper
        $windowIdFile = sys_get_temp_dir().'/orca_window_'.$id.'.txt';

        if (! file_exists($windowIdFile)) {
            return;
        }

        $windowId = (int) trim(file_get_contents($windowIdFile));

        if ($windowId <= 0) {
            return;
        }

        exec('osascript -e '.escapeshellarg(
            'tell application "Terminal"'."\n".
            '  repeat with w in windows'."\n".
            '    if id of w is '.$windowId.' then'."\n".
            '      set index of w to 1'."\n".
            '    else'."\n".
            '      set miniaturized of w to true'."\n".
            '    end if'."\n".
            '  end repeat'."\n".
            'end tell'."\n".
            'tell application "System Events" to set frontmost of process "Terminal" to true'
        ).' > /dev/null 2>&1 &');
    }

    public function clearScreenshot(): void
    {
        if ($this->screenshotPath && file_exists($this->screenshotPath)) {
            @unlink($this->screenshotPath);
        }

        $this->screenshotPath = '';
    }

    public function clearSessionScreenshot(string $sessionId): void
    {
        $path = $this->sessionScreenshots[$sessionId] ?? '';

        if ($path && file_exists($path)) {
            @unlink($path);
        }

        unset($this->sessionScreenshots[$sessionId]);

        $this->dispatch('screenshot-cleared');
    }

    public function dismiss(string $id): void
    {
        $session = OrcaSession::find($id);

        if (! $session) {
            return;
        }

        // Kill active sessions first
        if ($session->status->isActive()) {
            $this->kill($id);
            $session->refresh();
        }

        if ($session->child()->active()->exists()) {
            return;
        }

        if ($this->expandedSessionId === $id) {
            $this->expandedSessionId = '';
        }

        // Also delete the parent session if this is a child
        if ($session->parent_id) {
            OrcaSession::where('id', $session->parent_id)->delete();
        }

        $session->delete();
    }

    public function clearAll(): void
    {
        OrcaSession::whereIn('status', [
            OrcaSessionStatus::Completed,
            OrcaSessionStatus::Failed,
            OrcaSessionStatus::Cancelled,
        ])
            ->whereDoesntHave('child', fn ($q) => $q->active())
            ->delete();
    }

    /**
     * @return Collection<int, OrcaSession>
     */
    public function getSessions(): Collection
    {
        return OrcaSession::query()
            ->whereDoesntHave('child')
            ->with([
                'messages' => function ($query) {
                    $query->oldest('created_at');
                },
                'parent.messages' => function ($query) {
                    $query->oldest('created_at');
                },
            ])
            ->latest()
            ->limit(50)
            ->get();
    }

    public function getHasActiveSessions(): bool
    {
        return OrcaSession::active()->exists();
    }

    public function getActiveCount(): int
    {
        return OrcaSession::active()->count();
    }

    /**
     * @return array{user_id: int|string|null, user_email: string|null, route_handler: string|null, route_handler_type: string|null, route_name: string|null}
     */
    private function resolveSessionContext(): array
    {
        $user = auth()->user();
        $context = [
            'user_id' => $user?->id,
            'user_email' => $user?->email,
            'route_handler' => null,
            'route_handler_type' => null,
            'route_name' => null,
        ];

        if ($this->sourceUrl) {
            $resolved = app(RouteResolver::class)->resolve($this->sourceUrl);
            $context['route_handler'] = $resolved['handler'];
            $context['route_handler_type'] = $resolved['type'];
            $context['route_name'] = $resolved['name'];
        }

        return $context;
    }

    private function appendModuleContext(string $prompt): string
    {
        if (empty($this->moduleContext)) {
            return $prompt;
        }

        $parts = ['# Module Context'];
        $parts[] = '## Module: '.($this->moduleContext['name'] ?? 'Unknown');

        if (! empty($this->moduleContext['version'])) {
            $parts[] = '## Version: '.$this->moduleContext['version'];
        }

        if (! empty($this->moduleContext['description'])) {
            $parts[] = '## Description'."\n".$this->moduleContext['description'];
        }

        if (! empty($this->moduleContext['keyFiles'])) {
            $parts[] = '## Key Files'."\n".implode("\n", $this->moduleContext['keyFiles']);
        }

        if (! empty($this->moduleContext['capabilities'])) {
            $parts[] = '## Capabilities'."\n".implode(', ', $this->moduleContext['capabilities']);
        }

        if (! empty($this->moduleContext['dependencies'])) {
            $parts[] = '## Dependencies'."\n".implode(', ', $this->moduleContext['dependencies']);
        }

        return $prompt."\n\n---\n\n".implode("\n\n", $parts);
    }

    private function buildScaffoldPrompt(array $sourceModule, string $newModuleName, string $newModuleDescription): string
    {
        $sourceName = $sourceModule['name'] ?? 'Unknown';

        $lines = ["Create a new module called \"{$newModuleName}\"."];

        if ($newModuleDescription !== '') {
            $lines[] = "Description: {$newModuleDescription}";
        }

        $lines[] = "Use the \"{$sourceName}\" module as a fully working example to reference for structure, patterns, and conventions.";

        if (! empty($sourceModule['keyFiles'])) {
            $lines[] = 'Key files to study:';
            foreach ($sourceModule['keyFiles'] as $file) {
                $lines[] = "- {$file}";
            }
        }

        if (! empty($sourceModule['capabilities'])) {
            $lines[] = 'Source module capabilities: '.implode(', ', $sourceModule['capabilities']);
        }

        if (! empty($sourceModule['dependencies'])) {
            $lines[] = 'Source module dependencies: '.implode(', ', $sourceModule['dependencies']);
        }

        return implode("\n\n", $lines);
    }

    private function appendDebugContext(string $prompt): string
    {
        if (! $this->sourceUrl) {
            return $prompt;
        }

        $debug = $this->resolveCurrentPageDebugContext();

        $parts = ['# Debug Context'];
        $parts[] = "## Source URL\n{$debug['sourceUrl']}";
        $parts[] = "## Route\n{$debug['route']}";
        $parts[] = "## Handler\n{$debug['handler']}";
        $parts[] = "## Views\n{$debug['views']}";
        $parts[] = "## Auth User\n{$debug['authUser']}";

        $prompt .= "\n\n---\n\n".implode("\n\n", $parts);

        return $prompt;
    }

    /**
     * @return array{sourceUrl: string|null, route: string, handler: string, views: string, authUser: string}
     */
    private function resolveCurrentPageDebugContext(): array
    {
        $resolver = app(RouteResolver::class);
        $resolved = $resolver->resolve($this->sourceUrl);
        $user = auth()->user();

        $routeDisplay = 'N/A';
        if ($resolved['handler']) {
            $routeDisplay = $resolved['type'].': '.class_basename(Str::before($resolved['handler'], '@'));
            if (Str::contains($resolved['handler'], '@')) {
                $routeDisplay .= '@'.Str::after($resolved['handler'], '@');
            }
            if ($resolved['name']) {
                $routeDisplay .= ' ('.$resolved['name'].')';
            }
        }

        return [
            'sourceUrl' => $this->sourceUrl,
            'route' => $routeDisplay,
            'handler' => $resolver->resolveHandlerFile($resolved['handler']) ?? 'N/A',
            'views' => implode("\n", $resolver->resolveViewFiles($resolved['handler'], $this->sourceUrl)) ?: 'N/A',
            'authUser' => $user ? $user->email.' (#'.$user->id.')' : 'Guest',
        ];
    }

    private function buildPromptWithScreenshot(string $prompt): string
    {
        if ($this->screenshotPath && file_exists($this->screenshotPath)) {
            $prompt .= "\n\nI've attached a screenshot of the page at {$this->screenshotPath}. Please read it with your Read tool to see what I'm referring to.";
        }

        return $prompt;
    }

    private function publishToChannel(string $sessionId, string $payload): void
    {
        app(SessionChannel::class)->push($sessionId, $payload);
    }

    public function resolveToolPhrase(?string $messageId, string $toolName): ?string
    {
        if (! $messageId) {
            return null;
        }

        $now = time();
        $sameTool = $this->toolPhraseName === $toolName;
        $sameMessage = $this->toolPhraseMessageId === $messageId;

        // Same message, same tool — keep the phrase unless it's the raw tool name (fallback)
        if ($sameMessage && $sameTool && $this->toolPhraseText !== '' && $this->toolPhraseText !== $toolName) {
            return $this->toolPhraseText;
        }

        // Different tool — show new phrase immediately
        if (! $sameTool) {
            $this->toolPhraseText = Arr::random(config("orca.agents.tools.labels.{$toolName}", [$toolName]));
            $this->toolPhraseName = $toolName;
            $this->toolPhraseMessageId = $messageId;
            $this->toolPhraseSetAt = $now;

            return $this->toolPhraseText;
        }

        // Same tool, different message — only refresh if 10+ seconds passed
        if ($now - $this->toolPhraseSetAt >= 10) {
            $this->toolPhraseText = Arr::random(config("orca.agents.tools.labels.{$toolName}", [$toolName]));
            $this->toolPhraseMessageId = $messageId;
            $this->toolPhraseSetAt = $now;
        }

        return $this->toolPhraseText;
    }

    public function moduleInfo(): array
    {
        return [
            'name' => 'Orca',
            'description' => 'AI-powered development assistant with Claude integration, terminal sessions, and browser-based code interaction.',
            'version' => '1.0.0',
            'keyFiles' => [
                'packages/MakeDev/Orca/src/Livewire/Launcher.php',
                'packages/MakeDev/Orca/src/Jobs/RunClaudeSession.php',
                'packages/MakeDev/Orca/src/Models/OrcaSession.php',
                'packages/MakeDev/Orca/config/orca.php',
            ],
            'capabilities' => [
                'Claude Sessions',
                'Terminal Pop-Out',
                'Screenshot Capture',
                'Session Management',
            ],
            'dependencies' => [
                'livewire/livewire',
                'laravel/framework',
            ],
            'agentReadme' => $this->loadAgentReadme(),
        ];
    }

    public function render(): View
    {
        $sessions = $this->getSessions();

        $expandedSession = $this->expandedSessionId
            ? $sessions->firstWhere('id', $this->expandedSessionId)
            : null;

        // Auto-follow to child when the expanded session was replaced (e.g., terminal auto-resume)
        if (! $expandedSession && $this->expandedSessionId) {
            $child = $sessions->firstWhere('parent_id', $this->expandedSessionId);
            if ($child) {
                $expandedSession = $child;
                $this->expandedSessionId = $child->id;
            }
        }

        // Resolve tool phrase for active expanded session
        $currentToolPhrase = null;
        if ($expandedSession?->isClaude() && $expandedSession->status->isActive()) {
            $allMessages = $expandedSession->parent
                ? $expandedSession->parent->messages->concat($expandedSession->messages)->sortBy('created_at')->values()
                : $expandedSession->messages;

            $latestTool = $allMessages->where('type', 'tool_use')->last();

            if ($latestTool) {
                $currentToolPhrase = $this->resolveToolPhrase(
                    $latestTool->id,
                    $latestTool->metadata['tool'] ?? 'Tool',
                );
            }
        }

        // Determine poll interval: no polling needed when only webterm sessions are active
        $hasNonWebtermActive = $sessions->contains(
            fn (OrcaSession $s) => $s->status->isActive() && $s->id !== $this->webtermSessionId
        );
        $pollInterval = $hasNonWebtermActive ? '1s' : null;

        return view('orca::livewire.launcher', [
            'sessions' => $sessions,
            'expandedSession' => $expandedSession,
            'hasActiveSessions' => $this->getHasActiveSessions(),
            'activeCount' => $this->getActiveCount(),
            'currentToolPhrase' => $currentToolPhrase,
            'pollInterval' => $pollInterval,
            'canPopOut' => app(PopOutTerminalService::class)->isAvailable(),
            'launcherDebugContext' => $this->launcherOpen && $this->sourceUrl ? $this->resolveCurrentPageDebugContext() : null,
            'heartbeatData' => $sessions->mapWithKeys(fn ($s) => [
                $s->id => $s->last_heartbeat_at?->toIso8601String(),
            ])->filter()->all(),
            'heartbeatStale' => $sessions->mapWithKeys(fn ($s) => [
                $s->id => $s->isHeartbeatStale(),
            ])->filter()->all(),
        ]);
    }
}
