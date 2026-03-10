<?php

namespace MakeDev\Orca\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MakeDev\Orca\Database\Factories\OrcaSessionMessageFactory;

class OrcaSessionMessage extends Model
{
    use HasFactory, HasUlids;

    public $timestamps = false;

    protected $fillable = [
        'session_id',
        'direction',
        'type',
        'content',
        'metadata',
        'delivered_at',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'content' => 'array',
            'metadata' => 'array',
            'delivered_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function newFactory(): OrcaSessionMessageFactory
    {
        return OrcaSessionMessageFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (self $message) {
            $message->created_at ??= now();
        });
    }

    /**
     * @return BelongsTo<OrcaSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(OrcaSession::class, 'session_id');
    }

    public function isOutbound(): bool
    {
        return $this->direction === 'outbound';
    }

    public function isInbound(): bool
    {
        return $this->direction === 'inbound';
    }

    public function isDelivered(): bool
    {
        return $this->delivered_at !== null;
    }

    /**
     * @param  Builder<OrcaSessionMessage>  $query
     * @return Builder<OrcaSessionMessage>
     */
    public function scopeUndelivered(Builder $query): Builder
    {
        return $query->where('direction', 'inbound')
            ->whereNull('delivered_at');
    }
}
