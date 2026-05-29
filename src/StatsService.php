<?php

declare(strict_types=1);

namespace ForumPlugin;

use ForumPlugin\Repositories\SettingsRepository;
use PDO;

/**
 * Forum-wide statistics service. Backs the homepage stats widget and
 * the admin dashboard tiles.
 *
 * Each method is intentionally one or two queries — these numbers run
 * on every page render of the forum index, so they need to be cheap.
 * We deliberately avoid caching here (Struxa CMS has its own public-
 * page cache layer that wraps the whole response).
 */
final class StatsService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly SettingsRepository $settings,
    ) {
    }

    /**
     * Aggregate counters in a single query for the public stats widget.
     *
     * @return array{
     *   members:int, threads:int, posts:int, online_now:int,
     *   newest_member:?array{id:int, display_name:string, joined_at:string}
     * }
     */
    public function snapshot(): array
    {
        return [
            'members'        => $this->totalMembers(),
            'threads'        => $this->totalThreads(),
            'posts'          => $this->totalPosts(),
            'online_now'     => $this->onlineNow(),
            'newest_member'  => $this->newestMember(),
        ];
    }

    public function totalMembers(): int
    {
        try {
            return (int) $this->pdo->query('SELECT COUNT(*) FROM cms_users')->fetchColumn();
        } catch (\PDOException) {
            return 0;
        }
    }

    public function totalThreads(): int
    {
        try {
            return (int) $this->pdo->query('SELECT COUNT(*) FROM forum_threads WHERE is_deleted = 0')->fetchColumn();
        } catch (\PDOException) {
            return 0;
        }
    }

    public function totalPosts(): int
    {
        try {
            return (int) $this->pdo->query('SELECT COUNT(*) FROM forum_posts WHERE is_deleted = 0')->fetchColumn();
        } catch (\PDOException) {
            return 0;
        }
    }

    /**
     * Distinct users seen in the last N minutes (default 5). Reads from
     * forum_user_activity (touched on every public forum hit).
     */
    public function onlineNow(): int
    {
        $minutes = max(1, $this->settings->getInt('online_now_window_minutes', 5));
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM forum_user_activity
                  WHERE last_seen >= (CURRENT_TIMESTAMP - INTERVAL ? MINUTE)'
            );
            $stmt->execute([$minutes]);
            return (int) $stmt->fetchColumn();
        } catch (\PDOException) {
            return 0;
        }
    }

    /**
     * @return array{id:int, display_name:string, joined_at:string}|null
     */
    public function newestMember(): ?array
    {
        try {
            $stmt = $this->pdo->query(
                'SELECT id, display_name, email, created_at
                   FROM cms_users
                  ORDER BY created_at DESC, id DESC
                  LIMIT 1'
            );
            $row = $stmt instanceof \PDOStatement ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        } catch (\PDOException) {
            return null;
        }
        if ($row === false) {
            return null;
        }
        $display = trim((string) $row['display_name']);
        if ($display === '') {
            $email = (string) $row['email'];
            $display = $email === '' ? ('User #' . (int) $row['id']) : strstr($email, '@', true);
            if ($display === false || $display === '') {
                $display = 'User #' . (int) $row['id'];
            }
        }
        return [
            'id'           => (int) $row['id'],
            'display_name' => $display,
            'joined_at'    => (string) $row['created_at'],
        ];
    }

    /**
     * "Hot topics" in the last N hours, ranked by a simple activity
     * score (recent posts * 3 + recent likes * 2 + views/20). Brand
     * new threads (created within the window but no replies yet) are
     * also included so the widget never looks empty.
     *
     * One query: a LEFT JOIN sub-select counts the in-window posts +
     * likes per thread, joined back to forum_threads/forum_forums for
     * the URL + title + author. Returns rows shaped like a thread row
     * with extra `recent_posts`, `recent_likes`, `recent_posters` and
     * `hot_score` columns.
     *
     * @return list<array<string,mixed>>
     */
    public function hotTopics(int $hours = 24, int $limit = 5): array
    {
        $hours = max(1, min(24 * 14, $hours));
        $limit = max(1, min(20, $limit));
        try {
            $sql = "
                SELECT t.id, t.title, t.slug, t.author_user_id,
                       t.replies_count, t.likes_count, t.views_count,
                       t.last_post_at, t.last_poster_id, t.created_at,
                       f.name AS forum_name, f.slug AS forum_slug,
                       COALESCE(act.recent_posts, 0)   AS recent_posts,
                       COALESCE(act.recent_likes, 0)   AS recent_likes,
                       COALESCE(act.recent_posters, 0) AS recent_posters,
                       (COALESCE(act.recent_posts, 0) * 3
                        + COALESCE(act.recent_likes, 0) * 2
                        + LEAST(t.views_count, 500) / 20) AS hot_score
                  FROM forum_threads t
             LEFT JOIN forum_forums  f ON f.id = t.forum_id
             LEFT JOIN (
                    SELECT p.thread_id,
                           COUNT(*) AS recent_posts,
                           COUNT(DISTINCT p.author_user_id) AS recent_posters,
                           (
                             SELECT COUNT(*) FROM forum_post_likes l
                              JOIN forum_posts pp ON pp.id = l.post_id
                              WHERE pp.thread_id = p.thread_id
                                AND l.created_at >= NOW() - INTERVAL ? HOUR
                           ) AS recent_likes
                      FROM forum_posts p
                     WHERE p.is_deleted = 0
                       AND p.created_at >= NOW() - INTERVAL ? HOUR
                  GROUP BY p.thread_id
                  ) act ON act.thread_id = t.id
                 WHERE t.is_deleted = 0
                   AND (act.recent_posts > 0 OR t.created_at >= NOW() - INTERVAL ? HOUR)
              ORDER BY hot_score DESC, t.last_post_at DESC, t.id DESC
                 LIMIT ?
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(1, $hours, PDO::PARAM_INT);
            $stmt->bindValue(2, $hours, PDO::PARAM_INT);
            $stmt->bindValue(3, $hours, PDO::PARAM_INT);
            $stmt->bindValue(4, $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException) {
            return [];
        }
    }

    /**
     * Most recently active threads site-wide (by last reply, else created).
     * Used by the homepage widget — not limited to a time window.
     *
     * @return list<array<string,mixed>>
     */
    public function latestThreads(int $limit = 5): array
    {
        $limit = max(1, min(20, $limit));
        try {
            $stmt = $this->pdo->prepare(
                'SELECT t.id, t.title, t.slug, t.author_user_id,
                        t.replies_count, t.likes_count, t.views_count,
                        t.last_post_at, t.last_poster_id, t.created_at,
                        f.name AS forum_name, f.slug AS forum_slug
                   FROM forum_threads t
              LEFT JOIN forum_forums f ON f.id = t.forum_id
                  WHERE t.is_deleted = 0
               ORDER BY COALESCE(t.last_post_at, t.created_at) DESC, t.id DESC
                  LIMIT ?'
            );
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException) {
            return [];
        }
    }

    /**
     * Post volume for each of the last N days (inclusive of today),
     * oldest first. Drives the activity sparkline on the admin
     * dashboard. Returns a continuous range — days with zero posts
     * are filled in so the bar chart has a stable x-axis.
     *
     * @return list<array{date:string, posts:int}>
     */
    public function postsByDay(int $days = 7): array
    {
        $days = max(1, min(30, $days));
        try {
            $stmt = $this->pdo->prepare(
                'SELECT DATE(created_at) AS d, COUNT(*) AS n
                   FROM forum_posts
                  WHERE is_deleted = 0
                    AND created_at >= (CURDATE() - INTERVAL ? DAY)
               GROUP BY DATE(created_at)'
            );
            $stmt->bindValue(1, $days - 1, PDO::PARAM_INT);
            $stmt->execute();
            $byDate = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $byDate[(string) $row['d']] = (int) $row['n'];
            }
        } catch (\PDOException) {
            $byDate = [];
        }
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = (new \DateTimeImmutable("-{$i} day"))->format('Y-m-d');
            $out[] = ['date' => $d, 'posts' => $byDate[$d] ?? 0];
        }
        return $out;
    }

    /**
     * Top contributors by post count, used by the admin dashboard and
     * (optionally) the public forum landing widget.
     *
     * @return list<array{user_id:int, posts:int}>
     */
    public function topPosters(int $limit = 5): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT user_id, posts_count AS posts
                   FROM forum_user_activity
                  WHERE posts_count > 0
                  ORDER BY posts_count DESC, last_seen DESC
                  LIMIT ?'
            );
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException) {
            $rows = [];
        }
        return array_map(static fn (array $r): array => [
            'user_id' => (int) $r['user_id'],
            'posts'   => (int) $r['posts'],
        ], $rows);
    }
}
