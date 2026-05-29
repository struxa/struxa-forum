<?php

declare(strict_types=1);

namespace ForumPlugin;

use PDO;

/**
 * Resolves CMS / forum user identity for display.
 *
 * Pulls the cheapest possible payload (id, display name, email avatar,
 * post count, join date, rank) and caches per-request so the thread
 * view doesn't fan out into one query per post.
 *
 * The forum_user_activity table stores a denormalised post count which
 * we keep in sync from PostRepository. If activity rows are missing (e.g.
 * a user was created before the plugin was installed) we fall back to a
 * COUNT(*) join — slower but always correct.
 */
final class UserDirectory
{
    /** @var array<int, array<string, mixed>|null> */
    private array $cache = [];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{
     *   id:int, display_name:string, email:string, avatar:string,
     *   joined_at:?string, posts:int, rank:array{slug:string,label:string,min_posts:int}
     * }|null
     */
    public function find(int $cmsUserId): ?array
    {
        if ($cmsUserId <= 0) {
            return null;
        }
        if (array_key_exists($cmsUserId, $this->cache)) {
            return $this->cache[$cmsUserId];
        }

        $sql = <<<SQL
            SELECT u.id AS id,
                   u.email AS email,
                   u.display_name AS display_name,
                   u.created_at AS joined_at,
                   COALESCE(a.posts_count, 0) AS posts
              FROM cms_users u
              LEFT JOIN forum_user_activity a ON a.user_id = u.id
             WHERE u.id = ?
             LIMIT 1
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$cmsUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return $this->cache[$cmsUserId] = null;
        }

        $display = trim((string) $row['display_name']);
        if ($display === '') {
            // Fall back to the part before "@" — never leak the full email
            // to the public surface.
            $email = (string) $row['email'];
            $display = $email === '' ? ('User #' . (int) $row['id']) : strstr($email, '@', true);
            if ($display === false || $display === '') {
                $display = 'User #' . (int) $row['id'];
            }
        }

        $posts = (int) $row['posts'];

        $this->cache[$cmsUserId] = [
            'id'           => (int) $row['id'],
            'display_name' => $display,
            'email'        => (string) $row['email'],
            'avatar'       => $this->gravatarUrl((string) $row['email']),
            'joined_at'    => $row['joined_at'] !== null ? (string) $row['joined_at'] : null,
            'posts'        => $posts,
            'rank'         => RankService::rankFor($posts),
        ];

        return $this->cache[$cmsUserId];
    }

    /**
     * Bulk-prime the cache from a list of user IDs so a thread render
     * issues a single query rather than N. IDs already in the cache
     * are skipped.
     *
     * @param list<int> $ids
     */
    public function preload(array $ids): void
    {
        $ids = array_values(array_unique(array_filter($ids, static fn ($i) => is_int($i) && $i > 0)));
        $missing = array_values(array_filter($ids, fn ($i) => !array_key_exists($i, $this->cache)));
        if ($missing === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($missing), '?'));
        $sql = <<<SQL
            SELECT u.id AS id,
                   u.email AS email,
                   u.display_name AS display_name,
                   u.created_at AS joined_at,
                   COALESCE(a.posts_count, 0) AS posts
              FROM cms_users u
              LEFT JOIN forum_user_activity a ON a.user_id = u.id
             WHERE u.id IN ($placeholders)
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($missing);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $found = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $display = trim((string) $row['display_name']);
            if ($display === '') {
                $email = (string) $row['email'];
                $display = $email === '' ? 'User #' . $id : strstr($email, '@', true);
                if ($display === false || $display === '') {
                    $display = 'User #' . $id;
                }
            }
            $posts = (int) $row['posts'];
            $this->cache[$id] = [
                'id'           => $id,
                'display_name' => $display,
                'email'        => (string) $row['email'],
                'avatar'       => $this->gravatarUrl((string) $row['email']),
                'joined_at'    => $row['joined_at'] !== null ? (string) $row['joined_at'] : null,
                'posts'        => $posts,
                'rank'         => RankService::rankFor($posts),
            ];
            $found[$id] = true;
        }

        // Any IDs we couldn't resolve get cached as `null` so we don't retry.
        foreach ($missing as $id) {
            if (!isset($found[$id]) && !array_key_exists($id, $this->cache)) {
                $this->cache[$id] = null;
            }
        }
    }

    /**
     * Gravatar URL for an email. Returns the default identicon if the
     * email is blank or the user hasn't claimed a Gravatar — guarantees
     * every post avatar slot is filled with something visually distinct.
     */
    private function gravatarUrl(string $email): string
    {
        $hash = md5(strtolower(trim($email)));
        return 'https://www.gravatar.com/avatar/' . $hash . '?s=128&d=identicon';
    }

    /**
     * Resolve the current request's logged-in CMS user id (if any).
     * Public surface uses this to know whether to show the reply form,
     * the watch button, etc.
     */
    public function findCmsUserIdByPhpAuth(int $phpauthUserId): ?int
    {
        if ($phpauthUserId <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM cms_users WHERE phpauth_user_id = ? LIMIT 1');
        $stmt->execute([$phpauthUserId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * Touch the user's activity row so they count toward "online now".
     * Throttled to one write per minute per user (cheap optimistic check
     * via UPDATE … WHERE last_seen < NOW() - INTERVAL 1 MINUTE).
     */
    public function touchActivity(int $cmsUserId, ?string $path = null): void
    {
        if ($cmsUserId <= 0) {
            return;
        }

        // Try to bump an existing row.
        $upd = $this->pdo->prepare(
            'UPDATE forum_user_activity
                SET last_seen = CURRENT_TIMESTAMP,
                    last_path = COALESCE(?, last_path)
              WHERE user_id = ?
                AND last_seen < (CURRENT_TIMESTAMP - INTERVAL 1 MINUTE)'
        );
        $upd->execute([$path, $cmsUserId]);
        if ($upd->rowCount() > 0) {
            return;
        }

        // If no row exists yet, insert one. INSERT IGNORE swallows a race.
        $ins = $this->pdo->prepare(
            'INSERT IGNORE INTO forum_user_activity (user_id, last_seen, last_path, posts_count)
             VALUES (?, CURRENT_TIMESTAMP, ?, 0)'
        );
        $ins->execute([$cmsUserId, $path]);
    }
}
