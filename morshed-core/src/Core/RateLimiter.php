<?php
declare(strict_types=1);
namespace Morshed\Core;
final class RateLimiter
{
    public function __construct(private Database $db, private string $key) {}
    public function hit(string $scope, int $limit, int $seconds): void
    {
        $now = time(); $bucket = intdiv($now,$seconds)*$seconds;
        $hash = hash_hmac('sha256',$scope.':'.$seconds,$this->key);
        $allowed = $this->db->tx(function () use ($hash,$bucket,$seconds,$limit) {
            $this->db->run('INSERT IGNORE INTO m_rate_limits(key_hash,bucket,attempts,expires_at) VALUES (?,?,0,?)',[$hash,$bucket,$bucket+$seconds*2]);
            $r = $this->db->one('SELECT attempts FROM m_rate_limits WHERE key_hash=? AND bucket=? FOR UPDATE',[$hash,$bucket]);
            if ((int)$r['attempts'] >= $limit) { return false; }
            $this->db->run('UPDATE m_rate_limits SET attempts=attempts+1 WHERE key_hash=? AND bucket=?',[$hash,$bucket]);
            return true;
        });
        if (!$allowed) { throw new Problem('RATE_LIMITED','تعداد تلاش‌ها زیاد است؛ کمی بعد دوباره امتحان کنید.',429); }
    }
}
