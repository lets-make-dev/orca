<?php

namespace MakeDev\Orca\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\URL;
use MakeDev\Orca\Database\Factories\OrcaSessionFactory;
use MakeDev\Orca\Enums\OrcaSessionStatus;
use MakeDev\Orca\Enums\OrcaSessionType;

class OrcaSession extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'session_type',
        'command',
        'prompt',
        'screenshot_path',
        'source_url',
        'user_id',
        'user_email',
        'route_handler',
        'route_handler_type',
        'route_name',
        'claude_session_id',
        'resume_session_id',
        'parent_id',
        'skip_permissions',
        'permission_mode',
        'model',
        'allowed_tools',
        'working_directory',
        'max_turns',
        'status',
        'output',
        'exit_code',
        'pid',
        'started_at',
        'completed_at',
        'last_heartbeat_at',
        'popped_out_at',
        'popout_transcript',
        'popout_script_path',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'session_type' => OrcaSessionType::class,
            'status' => OrcaSessionStatus::class,
            'skip_permissions' => 'boolean',
            'allowed_tools' => 'array',
            'max_turns' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'popped_out_at' => 'datetime',
            'exit_code' => 'integer',
            'pid' => 'integer',
        ];
    }

    protected static function newFactory(): OrcaSessionFactory
    {
        return OrcaSessionFactory::new();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<OrcaSessionMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(OrcaSessionMessage::class, 'session_id');
    }

    /**
     * @return BelongsTo<OrcaSession, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasOne<OrcaSession, $this>
     */
    public function child(): HasOne
    {
        return $this->hasOne(self::class, 'parent_id');
    }

    public function appendOutput(string $buffer): void
    {
        $this->output .= $buffer;
        $this->save();
    }

    public function isRunning(): bool
    {
        return $this->status === OrcaSessionStatus::Running;
    }

    public function isAwaitingInput(): bool
    {
        return $this->status === OrcaSessionStatus::AwaitingInput;
    }

    public function isClaude(): bool
    {
        return $this->session_type === OrcaSessionType::Claude;
    }

    public function isPoppedOut(): bool
    {
        return $this->status === OrcaSessionStatus::PoppedOut;
    }

    public function isHeartbeatStale(): bool
    {
        if (! $this->last_heartbeat_at) {
            return false;
        }

        $threshold = (int) config('orca.popout.heartbeat_stale_seconds', 30);

        return $this->last_heartbeat_at->diffInSeconds(now()) > $threshold;
    }

    public function isSkipPermissions(): bool
    {
        return (bool) $this->skip_permissions;
    }

    public function permissionLabel(): string
    {
        if ($this->skip_permissions) {
            return 'Execute';
        }

        return match ($this->permission_mode) {
            'plan' => 'Plan',
            'acceptEdits' => 'Accept Edits',
            'bypassPermissions' => 'Bypass',
            default => 'Default',
        };
    }

    public function previewUrl(): ?string
    {
        if (! $this->user_id || ! $this->source_url) {
            return null;
        }

        $expiry = config('orca.auto_login.expiry_minutes', 30);

        return URL::temporarySignedRoute('orca.auto-login', now()->addMinutes($expiry), [
            'user' => $this->user_id,
            'redirect' => $this->source_url,
        ]);
    }

    /**
     * @param  Builder<OrcaSession>  $query
     * @return Builder<OrcaSession>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            OrcaSessionStatus::Pending,
            OrcaSessionStatus::Running,
            OrcaSessionStatus::AwaitingInput,
            OrcaSessionStatus::PoppedOut,
        ]);
    }
}
