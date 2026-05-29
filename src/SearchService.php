<?php

declare(strict_types=1);

namespace ForumPlugin;

use PDO;

/**
 * Simple LIKE-based search across thread titles and post bodies.
 *
 * Trades sophistication for predictability: a query for "avios" matches
 * any thread/post containing that substring. Results are deduplicated
 * to one row per thread (the most relevant match), ordered with title
 * matches first, then by recency.
 *
 * For a higher-scale install we'd swap this for a FULLTEXT index or
 * an external search engine — the route stays the same.
 */
final class SearchService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Search threads + posts. Returns one row per thread, decorated
     * with a `match_field` (title|body) and a `snippet` extracted from
     * the matching post body.
     *
     * @param array{q:string, forum_id?:int, limit?:int, offset?:int} $params
     * @return list<array<string, mixed>>
     */
    public function search(array $params): array
    {
        $q = trim((string) ($params['q'] ?? ''));
        if (mb_strlen($q) < 2) {
            return [];
        }
        $forumId = (int) ($params['forum_id'] ?? 0);
        $limit   = max(1, min(50, (int) ($params['limit'] ?? 20)));
        $offset  = max(0, (int) ($params['offset'] ?? 0));

        $like = '%' . $this->escapeLike($q) . '%';

        // Two-step:
        //   1. Find candidate thread ids matching title OR any post body
        //   2. Pick the best matching post per thread for the snippet
        //
        // We do the heavy lifting in a single SQL with a CTE-like sub-
        // query so we don't issue N follow-ups.
        $forumClause = $forumId > 0 ? ' AND t.forum_id = ? ' : '';

        $sql = "
            SELECT t.id        AS thread_id,
                   t.forum_id,
                   t.entry_id,
                   t.author_user_id,
                   t.title,
                   t.slug,
                   t.is_sticky,
                   t.is_locked,
                   t.replies_count,
                   t.likes_count,
                   t.views_count,
                   t.last_post_at,
                   t.last_poster_id,
                   t.created_at,
                   f.name       AS forum_name,
                   f.slug       AS forum_slug,
                   CASE
                     WHEN t.title LIKE ? THEN 'title'
                     ELSE 'body'
                   END         AS match_field,
                   (SELECT p.body_markdown
                      FROM forum_posts p
                     WHERE p.thread_id = t.id
                       AND p.is_deleted = 0
                       AND p.body_markdown LIKE ?
                  ORDER BY p.is_first_post DESC, p.created_at ASC
                     LIMIT 1)   AS match_body
              FROM forum_threads t
         LEFT JOIN forum_forums  f ON f.id = t.forum_id
             WHERE t.is_deleted = 0
               $forumClause
               AND (
                 t.title LIKE ?
                 OR EXISTS (
                   SELECT 1 FROM forum_posts p
                    WHERE p.thread_id = t.id
                      AND p.is_deleted = 0
                      AND p.body_markdown LIKE ?
                 )
               )
          ORDER BY CASE WHEN t.title LIKE ? THEN 0 ELSE 1 END,
                   t.last_post_at DESC, t.id DESC
             LIMIT $limit OFFSET $offset
        ";

        $bind = [$like, $like];
        if ($forumId > 0) {
            $bind[] = $forumId;
        }
        $bind[] = $like;
        $bind[] = $like;
        $bind[] = $like;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bind);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$r) {
            $r['snippet'] = $this->snippet((string) ($r['match_body'] ?? ''), $q);
            unset($r['match_body']);
        }
        unset($r);
        return $rows;
    }

    /**
     * Total matching thread count — used for pagination / "About X
     * results" copy. Same predicate as the main query without the
     * snippet sub-select.
     */
    public function count(array $params): int
    {
        $q = trim((string) ($params['q'] ?? ''));
        if (mb_strlen($q) < 2) {
            return 0;
        }
        $forumId = (int) ($params['forum_id'] ?? 0);
        $like = '%' . $this->escapeLike($q) . '%';

        $forumClause = $forumId > 0 ? ' AND t.forum_id = ? ' : '';

        $sql = "
            SELECT COUNT(*)
              FROM forum_threads t
             WHERE t.is_deleted = 0
               $forumClause
               AND (
                 t.title LIKE ?
                 OR EXISTS (
                   SELECT 1 FROM forum_posts p
                    WHERE p.thread_id = t.id
                      AND p.is_deleted = 0
                      AND p.body_markdown LIKE ?
                 )
               )
        ";
        $bind = $forumId > 0 ? [$forumId, $like, $like] : [$like, $like];
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bind);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Best-effort plain-text excerpt centred on the first match of
     * `$needle`. Falls back to the first 240 chars of the body.
     */
    private function snippet(string $body, string $needle): string
    {
        $stripped = $this->stripMarkdown($body);
        if ($needle === '' || $stripped === '') {
            return mb_substr($stripped, 0, 240);
        }

        $pos = mb_stripos($stripped, $needle);
        if ($pos === false) {
            return mb_substr($stripped, 0, 240);
        }

        $start = max(0, $pos - 60);
        $excerpt = mb_substr($stripped, $start, 240);
        if ($start > 0) {
            $excerpt = '…' . ltrim($excerpt);
        }
        if (mb_strlen($stripped) > $start + 240) {
            $excerpt = rtrim($excerpt) . '…';
        }
        return $excerpt;
    }

    /**
     * Strip the simplest markdown formatting so snippets aren't full
     * of pipes/asterisks. We deliberately don't invoke the full
     * markdown renderer here — that would defeat the snippet idea.
     */
    private function stripMarkdown(string $body): string
    {
        $body = preg_replace('/```.*?```/s', ' ', $body) ?? $body;
        $body = preg_replace('/`([^`]+)`/', '$1', $body) ?? $body;
        $body = preg_replace('/!\[[^\]]*\]\([^)]+\)/', ' ', $body) ?? $body;
        $body = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $body) ?? $body;
        $body = preg_replace('/[*_~>#]+/', '', $body) ?? $body;
        return trim((string) preg_replace('/\s+/', ' ', $body));
    }

    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
