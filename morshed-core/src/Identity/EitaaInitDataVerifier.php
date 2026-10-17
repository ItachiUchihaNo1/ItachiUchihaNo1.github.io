<?php
declare(strict_types=1);
namespace Morshed\Identity;

/**
 * Pure verification primitive based on Eitaa's official AuthorizationViaHash.md.
 * NOT a login endpoint. No user creation, merging, session issue, or network send.
 * Integration MUST atomically consume the verified hash before issuing a session,
 * bind linking to a recently reauthenticated account, and enforce provider enablement.
 */
final class EitaaInitDataVerifier
{
    private string $appToken;
    private int $maxAge;

    public function __construct(string $appToken, int $maxAgeSeconds = 60)
    {
        if (strlen($appToken) < 16 || $maxAgeSeconds < 1 || $maxAgeSeconds > 300) {
            throw new \InvalidArgumentException('Invalid verifier configuration.');
        }
        $this->appToken = $appToken;
        $this->maxAge = $maxAgeSeconds;
    }

    /** @return array{subject: string, auth_date: int, replay_key: string} */
    public function verify(string $initData, int $now): array
    {
        if ($initData === '' || strlen($initData) > 16384 || substr_count($initData, '&') > 32) {
            throw new \DomainException('Invalid identity assertion.');
        }
        $fields = [];
        foreach (explode('&', $initData) as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) !== 2 || preg_match('/%(?![0-9A-Fa-f]{2})/', $pair)) {
                throw new \DomainException('Invalid identity assertion.');
            }
            $key = urldecode($parts[0]); $value = urldecode($parts[1]);
            if (!preg_match('/^[a-z_][a-z_0-9]{0,63}$/D', $key) || array_key_exists($key, $fields)) {
                throw new \DomainException('Ambiguous identity assertion.');
            }
            $fields[$key] = $value;
        }
        $hash = $fields['hash'] ?? '';
        unset($fields['hash']);
        if (!preg_match('/^[a-f0-9]{64}$/D', $hash)) {
            throw new \DomainException('Invalid identity assertion.');
        }
        ksort($fields, SORT_STRING);
        $pairs = [];
        foreach ($fields as $key => $value) { $pairs[] = $key . '=' . $value; }
        $secret = hash_hmac('sha256', $this->appToken, 'WebAppData', true);
        $expected = hash_hmac('sha256', implode("\n", $pairs), $secret);
        if (!hash_equals($expected, $hash)) { throw new \DomainException('Invalid identity signature.'); }
        $date = $fields['auth_date'] ?? '';
        if (!preg_match('/^[1-9][0-9]{0,10}$/D', $date)) {
            throw new \DomainException('Invalid assertion timestamp.');
        }
        $date = (int) $date;
        if ($date > $now + 10 || $now - $date > $this->maxAge) {
            throw new \DomainException('Expired or future identity assertion.');
        }
        try { $user = json_decode($fields['user'] ?? '', true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); }
        catch (\JsonException $e) { throw new \DomainException('Invalid identity subject.'); }
        $id = is_array($user) ? ($user['id'] ?? null) : null;
        if ((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]{0,19}$/D', (string) $id)) {
            throw new \DomainException('Invalid identity subject.');
        }
        return ['subject' => (string) $id, 'auth_date' => $date, 'replay_key' => $hash];
    }
}

