<?php

declare(strict_types=1);

namespace ForumPlugin\Security;

/**
 * Per-user, multi-tier, filesystem-backed rate limiter for the forum.
 *
 * Designed to absorb spam against the mutating endpoints (reply, new
 * thread, like, report, edit, subscribe) without pulling in a queue or
 * Redis dependency.
 *
 * Each call supplies a set of tiers (max-hits, window-seconds). All
 * tiers must pass for the request to be allowed. If any tier blocks,
 * NO tier is incremented and the most-restrictive Retry-After is
 * returned, so legitimate retries do not punish their other quotas.
 *
 * Storage is a small JSON file per (bucket, key, window-size, window-
 * index) tuple inside the project's `storage/cache/forum_rate_limit/`
 * directory. Old files age out naturally because their window-index is
 * baked into the filename — we never write to a stale one again.
 */
final class RateLimitGuard
{
    public function __construct(private readonly string $storageDir)
    {
    }

    /**
     * @param list<array{0:int,1:int}> $tiers list of [maxHits, windowSeconds] pairs
     * @return int|null null if allowed (and counters were incremented),
     *                  otherwise seconds until the strictest tier resets.
     */
    public function checkAndHit(string $bucket, string $key, array $tiers): ?int
    {
        if ($tiers === []) {
            return null;
        }
        if (!is_dir($this->storageDir)
            && !@mkdir($this->storageDir, 0775, true)
            && !is_dir($this->storageDir)
        ) {
            return null;
        }

        $now = time();
        $reads = [];
        $worstWait = null;

        foreach ($tiers as $tier) {
            $max    = isset($tier[0]) ? (int) $tier[0] : 0;
            $window = isset($tier[1]) ? (int) $tier[1] : 0;
            if ($max < 1 || $window < 1) {
                continue;
            }
            $windowIdx = (int) ($now / $window);
            $path = $this->pathFor($bucket, $key, $window, $windowIdx);
            $count = $this->readCount($path, $windowIdx);
            $reads[] = ['path' => $path, 'window' => $windowIdx, 'count' => $count];

            if ($count >= $max) {
                $wait = max(1, ($windowIdx + 1) * $window - $now);
                if ($worstWait === null || $wait > $worstWait) {
                    $worstWait = $wait;
                }
            }
        }

        if ($worstWait !== null) {
            return $worstWait;
        }

        foreach ($reads as $r) {
            try {
                $payload = json_encode(
                    ['w' => $r['window'], 'c' => $r['count'] + 1],
                    JSON_THROW_ON_ERROR
                );
                @file_put_contents($r['path'], $payload, LOCK_EX);
            } catch (\JsonException) {
            }
        }

        return null;
    }

    private function pathFor(string $bucket, string $key, int $window, int $windowIdx): string
    {
        $hash = hash(
            'sha256',
            $bucket . "\0" . $key . "\0" . (string) $window . "\0" . (string) $windowIdx
        );
        return $this->storageDir . DIRECTORY_SEPARATOR . $hash . '.json';
    }

    private function readCount(string $path, int $expectedWindow): int
    {
        if (!is_file($path)) {
            return 0;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return 0;
        }
        try {
            $j = json_decode($raw, true, 2, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 0;
        }
        if (!is_array($j)
            || ($j['w'] ?? null) !== $expectedWindow
            || !isset($j['c'])
            || !is_int($j['c'])
            || $j['c'] < 0
        ) {
            return 0;
        }
        return $j['c'];
    }
}
