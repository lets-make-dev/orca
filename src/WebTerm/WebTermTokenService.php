<?php

namespace MakeDev\Orca\WebTerm;

use Illuminate\Support\Facades\Crypt;

class WebTermTokenService
{
    /**
     * Generate an encrypted token containing session ID and expiry.
     */
    public function generate(string $sessionId): string
    {
        $expiryMinutes = (int) config('orca.webterm.token_expiry_minutes', 60);

        $payload = json_encode([
            'session_id' => $sessionId,
            'expires_at' => now()->addMinutes($expiryMinutes)->timestamp,
        ]);

        return Crypt::encryptString($payload);
    }

    /**
     * Validate a token and return payload or null if invalid/expired.
     *
     * @return array{session_id: string, expires_at: int}|null
     */
    public function validate(string $token): ?array
    {
        try {
            $decrypted = Crypt::decryptString($token);
            $payload = json_decode($decrypted, true);

            if (! is_array($payload) || ! isset($payload['session_id'], $payload['expires_at'])) {
                return null;
            }

            if ($payload['expires_at'] < now()->timestamp) {
                return null;
            }

            return $payload;
        } catch (\Throwable) {
            return null;
        }
    }
}
