<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Mints the short-lived signed token the rcp_messaging WebSocket server
 * accepts as proof of identity. That server is a separate PHP process
 * with no access to this app's session store, so this token — not the
 * session — is what it checks on connect. Must stay byte-for-byte
 * compatible with rcp_messaging's AuthService::verify().
 */
class WsToken
{
    /**
     * Minted once per page load and never refreshed while the tab stays
     * open — the socket itself doesn't re-check it after the initial
     * handshake, but a dropped connection reconnects with this same
     * token, so the TTL needs to outlast a normal chat session rather
     * than just the handshake moment.
     */
    public static function mint(string $userId, string $role, string $secret, int $ttlSeconds = 43200): string
    {
        $payload = base64_encode(json_encode([
            'sub'  => $userId,
            'role' => $role,
            'exp'  => time() + $ttlSeconds,
        ]));
        $payload = rtrim(strtr($payload, '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $payload, $secret);

        return $payload . '.' . $signature;
    }
}
