<?php

namespace MakeDev\Orca\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class SessionChannel
{
    public function key(string $sessionId): string
    {
        return "orca:session:{$sessionId}:stdin";
    }

    public function push(string $sessionId, string $payload): void
    {
        Redis::connection($this->connection())
            ->rpush($this->key($sessionId), $payload);
    }

    public function pop(string $sessionId): ?string
    {
        try {
            $value = Redis::connection($this->connection())
                ->lpop($this->key($sessionId));

            return $value ?: null;
        } catch (\Throwable $e) {
            Log::error('SessionChannel: Failed to pop from Redis', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function cleanup(string $sessionId): void
    {
        Redis::connection($this->connection())
            ->del($this->key($sessionId));
    }

    public function setTtl(string $sessionId, int $seconds = 0): void
    {
        $ttl = $seconds ?: (int) config('orca.redis.ttl', 7200);

        Redis::connection($this->connection())
            ->expire($this->key($sessionId), $ttl);
    }

    private function connection(): string
    {
        return config('orca.redis.connection', 'default');
    }
}
