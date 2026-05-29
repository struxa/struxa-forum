<?php

declare(strict_types=1);

namespace ForumPlugin\Repositories;

use PDO;

/**
 * Plugin-scoped key/value settings, mirrored into a static cache per
 * request so we never round-trip to the DB more than once for the
 * same key.
 */
final class SettingsRepository
{
    private const DEFAULTS = [
        'threads_per_page'           => '20',
        'posts_per_page'             => '15',
        'edit_window_minutes'        => '15',
        'online_now_window_minutes'  => '5',
        'min_post_length'            => '4',
        'min_thread_title_length'    => '4',
        // Minimum word count enforced on thread bodies, replies and edits.
        // Counted by splitting trimmed body on Unicode whitespace.
        'min_post_words'             => '15',
    ];

    /** @var array<string, string>|null */
    private ?array $cache = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, string> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $rows = [];
        try {
            $stmt = $this->pdo->query('SELECT setting_key, setting_value FROM forum_settings');
            $rows = $stmt instanceof \PDOStatement ? ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: []) : [];
        } catch (\PDOException) {
            $rows = [];
        }
        $merged = array_merge(self::DEFAULTS, array_map('strval', $rows));
        return $this->cache = $merged;
    }

    public function get(string $key, string $default = ''): string
    {
        $all = $this->all();
        return $all[$key] ?? $default;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $v = $this->get($key);
        return $v === '' ? $default : max(0, (int) $v);
    }

    /** @param array<string, string|int> $kv */
    public function setMany(array $kv): void
    {
        if ($kv === []) {
            return;
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO forum_settings (setting_key, setting_value)
                  VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        foreach ($kv as $k => $v) {
            $stmt->execute([(string) $k, (string) $v]);
        }
        $this->cache = null;
    }
}
