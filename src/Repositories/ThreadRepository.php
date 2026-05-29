<?php

declare(strict_types=1);

namespace ForumPlugin\Repositories;

use ForumPlugin\MarkdownRenderer;
use ForumPlugin\SlugGenerator;
use PDO;

/**
 * Threads (= MyBB threads). Each thread is backed by both a
 * forum_threads row and a cms_content_entries row of type
 * "forum-thread" so we inherit SEO + featured-image + admin Content
 * Type listing for free.
 *
 * Thread creation is transactional:
 *   1. Insert cms_content_entries (status=published)
 *   2. Insert forum_threads with entry_id pointer
 *   3. Insert forum_posts (is_first_post=1)
 *   4. Bump forum counters + content entry values
 *
 * If any step throws we ROLLBACK to leave the DB consistent.
 */
final class ThreadRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly MarkdownRenderer $markdown,
    ) {
    }

    /**
     * Paginated list of threads in a forum. Ordered by latest activity
     * (last reply or OP time), newest first. Sticky is surfaced in the UI
     * only — it does not float old threads above recently active ones.
     *
     * @return list<array<string, mixed>>
     */
    public function listForForum(int $forumId, int $limit, int $offset): array
    {
        $sql = 'SELECT t.id, t.forum_id, t.entry_id, t.author_user_id, t.title, t.slug,
                       t.is_sticky, t.is_locked, t.is_deleted,
                       t.views_count, t.replies_count, t.likes_count,
                       t.last_post_id, t.last_post_at, t.last_poster_id,
                       t.created_at, t.updated_at
                  FROM forum_threads t
                 WHERE t.forum_id = ? AND t.is_deleted = 0
              ORDER BY COALESCE(t.last_post_at, t.created_at) DESC, t.is_sticky DESC, t.id DESC
                 LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$forumId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function countForForum(int $forumId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM forum_threads WHERE forum_id = ? AND is_deleted = 0');
        $stmt->execute([$forumId]);
        return (int) $stmt->fetchColumn();
    }

    /** Recent threads across the whole forum — for the admin dashboard. */
    public function recent(int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.id, t.title, t.slug, t.forum_id, t.author_user_id,
                    t.is_sticky, t.is_locked, t.is_deleted,
                    t.replies_count, t.likes_count, t.last_post_at, t.created_at,
                    f.name AS forum_name, f.slug AS forum_slug
               FROM forum_threads t
          LEFT JOIN forum_forums f ON f.id = t.forum_id
              ORDER BY t.last_post_at DESC, t.id DESC
              LIMIT ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Used by the admin moderation list. Supports search + forum filter
     * + soft-deleted toggle.
     *
     * @return list<array<string, mixed>>
     */
    public function listForAdmin(array $filter, int $limit = 50, int $offset = 0): array
    {
        [$where, $bind] = $this->buildAdminWhere($filter);
        $sql = 'SELECT t.*, f.name AS forum_name, f.slug AS forum_slug
                  FROM forum_threads t
             LEFT JOIN forum_forums f ON f.id = t.forum_id'
             . ($where === '' ? '' : ' WHERE ' . $where)
             . ' ORDER BY t.last_post_at DESC, t.id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bind);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Total rows matching the same filter — for the admin pagination counter. */
    public function countForAdmin(array $filter): int
    {
        [$where, $bind] = $this->buildAdminWhere($filter);
        $sql = 'SELECT COUNT(*) FROM forum_threads t LEFT JOIN forum_forums f ON f.id = t.forum_id'
             . ($where === '' ? '' : ' WHERE ' . $where);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bind);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Shared WHERE / bind builder for the admin moderation list and counter.
     * `q` is escaped against MySQL's `%` and `_` LIKE wildcards so a search of
     * "10%" doesn't match every row in the table.
     *
     * @return array{0:string, 1:list<mixed>}
     */
    private function buildAdminWhere(array $filter): array
    {
        $where = [];
        $bind  = [];

        $q = trim((string) ($filter['q'] ?? ''));
        if ($q !== '') {
            $escaped = addcslashes($q, '%_\\');
            $where[] = '(t.title LIKE ? OR t.slug LIKE ?)';
            $bind[]  = '%' . $escaped . '%';
            $bind[]  = '%' . $escaped . '%';
        }

        $forumId = (int) ($filter['forum_id'] ?? 0);
        if ($forumId > 0) {
            $where[] = 't.forum_id = ?';
            $bind[]  = $forumId;
        }

        $status = (string) ($filter['status'] ?? '');
        if ($status === 'deleted') {
            $where[] = 't.is_deleted = 1';
        } elseif ($status === 'sticky') {
            $where[] = 't.is_sticky = 1 AND t.is_deleted = 0';
        } elseif ($status === 'locked') {
            $where[] = 't.is_locked = 1 AND t.is_deleted = 0';
        } elseif ($status === 'all') {
            // include everything (live + deleted)
        } else {
            $where[] = 't.is_deleted = 0';
        }

        return [implode(' AND ', $where), $bind];
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM forum_threads WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM forum_threads WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Create a new thread + its opening post in a transaction. Returns
     * the new thread id.
     *
     * Optional `first_post_created_at` (`Y-m-d H:i:s`) back-dates the CMS entry,
     * thread row, and opening post so imports can replay historical timelines.
     *
     * @param array{forum_id:int, title:string, body_markdown:string,
     *              author_user_id:?int, first_post_created_at?:?string} $payload
     */
    public function createThread(array $payload): int
    {
        $forumId = (int) $payload['forum_id'];
        $title   = trim((string) $payload['title']);
        $body    = (string) $payload['body_markdown'];
        $authorId = isset($payload['author_user_id']) ? (int) $payload['author_user_id'] : null;
        $firstAt = isset($payload['first_post_created_at']) ? trim((string) $payload['first_post_created_at']) : '';
        $firstAt = $firstAt !== '' ? $firstAt : null;

        $slug    = SlugGenerator::uniqueSlug($this->pdo, 'forum_threads', 'slug', $title);
        $bodyHtml = $this->markdown->render($body);

        $this->pdo->beginTransaction();
        try {
            // 1. CMS content entry (forum-thread type).
            $entryId = $this->insertContentEntry($title, $slug, $body, $forumId, $authorId, $firstAt);

            // 2. Forum thread row.
            if ($firstAt !== null) {
                $tStmt = $this->pdo->prepare(
                    'INSERT INTO forum_threads
                        (forum_id, entry_id, author_user_id, title, slug,
                         is_sticky, is_locked, is_deleted,
                         last_post_at, last_poster_id, created_at)
                     VALUES (?, ?, ?, ?, ?, 0, 0, 0, ?, ?, ?)'
                );
                $tStmt->execute([$forumId, $entryId, $authorId, $title, $slug, $firstAt, $authorId, $firstAt]);
            } else {
                $tStmt = $this->pdo->prepare(
                    'INSERT INTO forum_threads
                        (forum_id, entry_id, author_user_id, title, slug,
                         is_sticky, is_locked, is_deleted,
                         last_post_at, last_poster_id)
                     VALUES (?, ?, ?, ?, ?, 0, 0, 0, CURRENT_TIMESTAMP, ?)'
                );
                $tStmt->execute([$forumId, $entryId, $authorId, $title, $slug, $authorId]);
            }
            $threadId = (int) $this->pdo->lastInsertId();

            // 3. First post.
            if ($firstAt !== null) {
                $pStmt = $this->pdo->prepare(
                    'INSERT INTO forum_posts
                        (thread_id, forum_id, author_user_id, is_first_post, is_deleted, body_markdown, body_html, created_at, updated_at)
                     VALUES (?, ?, ?, 1, 0, ?, ?, ?, ?)'
                );
                $pStmt->execute([$threadId, $forumId, $authorId, $body, $bodyHtml, $firstAt, $firstAt]);
            } else {
                $pStmt = $this->pdo->prepare(
                    'INSERT INTO forum_posts
                        (thread_id, forum_id, author_user_id, is_first_post, is_deleted, body_markdown, body_html)
                     VALUES (?, ?, ?, 1, 0, ?, ?)'
                );
                $pStmt->execute([$threadId, $forumId, $authorId, $body, $bodyHtml]);
            }
            $postId = (int) $this->pdo->lastInsertId();

            // 4. Set the thread's last_post_id pointer to the OP.
            $this->pdo->prepare(
                'UPDATE forum_threads SET last_post_id = ? WHERE id = ?'
            )->execute([$postId, $threadId]);

            // 5. Update author's denormalised post count.
            if ($authorId !== null) {
                $this->bumpAuthorPostCount($authorId, +1);
            }

            $this->pdo->commit();
            return $threadId;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Toggle sticky / locked / deleted flags (admin moderation). All in
     * one method to keep the controller code tight.
     *
     * @param array{is_sticky?:int, is_locked?:int, is_deleted?:int} $flags
     */
    public function setFlags(int $id, array $flags): void
    {
        $sets = [];
        $bind = [];
        foreach (['is_sticky', 'is_locked', 'is_deleted'] as $k) {
            if (array_key_exists($k, $flags)) {
                $sets[] = "$k = ?";
                $bind[] = (int) (!empty($flags[$k]) ? 1 : 0);
            }
        }
        if ($sets === []) {
            return;
        }
        $bind[] = $id;
        $stmt = $this->pdo->prepare('UPDATE forum_threads SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($bind);
    }

    public function bumpViews(int $threadId): void
    {
        $this->pdo->prepare('UPDATE forum_threads SET views_count = views_count + 1 WHERE id = ?')
            ->execute([$threadId]);
    }

    /**
     * Move a thread to a different forum. Updates the thread row,
     * every post's denormalised forum_id, and refreshes counters on
     * both the source and destination forums. Returns false if the
     * destination doesn't exist or the thread is already there.
     */
    public function moveToForum(int $threadId, int $destinationForumId): bool
    {
        $thread = $this->find($threadId);
        if ($thread === null) {
            return false;
        }
        $fromForumId = (int) $thread['forum_id'];
        if ($fromForumId === $destinationForumId) {
            return false;
        }

        // Verify destination exists.
        $stmt = $this->pdo->prepare('SELECT slug FROM forum_forums WHERE id = ? LIMIT 1');
        $stmt->execute([$destinationForumId]);
        $destSlug = $stmt->fetchColumn();
        if ($destSlug === false) {
            return false;
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE forum_threads SET forum_id = ? WHERE id = ?')
                ->execute([$destinationForumId, $threadId]);
            $this->pdo->prepare('UPDATE forum_posts SET forum_id = ? WHERE thread_id = ?')
                ->execute([$destinationForumId, $threadId]);

            // Keep the CMS content entry's forum_slug field in sync so
            // the CMS content browser still filters correctly.
            $entryId = (int) ($thread['entry_id'] ?? 0);
            if ($entryId > 0) {
                $typeId = $this->ensureContentTypeId();
                if ($typeId !== null) {
                    $fields = $this->fieldIdsForType($typeId);
                    if (isset($fields['forum_slug'])) {
                        $up = $this->pdo->prepare(
                            'INSERT INTO cms_content_entry_values (content_entry_id, field_id, value_longtext)
                                  VALUES (?, ?, ?)
                             ON DUPLICATE KEY UPDATE value_longtext = VALUES(value_longtext)'
                        );
                        $up->execute([$entryId, $fields['forum_slug'], (string) $destSlug]);
                    }
                }
            }

            $forums = new ForumRepository($this->pdo);
            $forums->refreshCounters($fromForumId);
            $forums->refreshCounters($destinationForumId);

            $this->pdo->commit();
            return true;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Recompute denormalised counters (replies_count, likes_count,
     * last_post_id, last_post_at, last_poster_id) for a thread.
     * Idempotent — safe to call after any post change.
     */
    public function refreshCounters(int $threadId): void
    {
        // Use a scalar subquery for last_poster instead of GROUP_CONCAT — long threads
        // exceed group_concat_max_len and MySQL raises "Row N was cut by GROUP_CONCAT()".
        $sql = <<<SQL
            UPDATE forum_threads t
              LEFT JOIN (
                SELECT p.thread_id,
                       COUNT(*) - SUM(p.is_first_post)              AS replies,
                       SUM(p.likes_count)                           AS total_likes,
                       MAX(p.id)                                    AS last_post_id,
                       MAX(p.created_at)                            AS last_at,
                       (SELECT p2.author_user_id
                          FROM forum_posts p2
                         WHERE p2.thread_id = p.thread_id
                           AND p2.is_deleted = 0
                         ORDER BY p2.created_at DESC, p2.id DESC
                         LIMIT 1)                                    AS last_poster
                  FROM forum_posts p
                 WHERE p.thread_id = ? AND p.is_deleted = 0
                 GROUP BY p.thread_id
              ) s ON s.thread_id = t.id
              SET t.replies_count = COALESCE(s.replies, 0),
                  t.likes_count   = COALESCE(s.total_likes, 0),
                  t.last_post_id  = s.last_post_id,
                  t.last_post_at  = s.last_at,
                  t.last_poster_id = CASE
                    WHEN s.last_poster IS NULL OR s.last_poster = '' THEN NULL
                    ELSE CAST(s.last_poster AS UNSIGNED)
                  END
              WHERE t.id = ?
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$threadId, $threadId]);
    }

    /** Convenience for refreshing both the thread and its forum after any write. */
    public function refreshAround(int $threadId): void
    {
        $thread = $this->find($threadId);
        if ($thread === null) {
            return;
        }
        $this->refreshCounters($threadId);
        (new ForumRepository($this->pdo))->refreshCounters((int) $thread['forum_id']);
    }

    /**
     * Create the cms_content_entries row + its field values. Pulled
     * into its own method so transaction handling stays readable.
     */
    private function insertContentEntry(string $title, string $slug, string $body, int $forumId, ?int $authorId, ?string $mysqlDatetime = null): int
    {
        $typeId = $this->ensureContentTypeId();
        if ($typeId === null) {
            // No content type yet — happens during the first activation
            // before migration 002 ran. Fail silent so threads are still
            // usable; we just won't have an entry pointer.
            return 0;
        }

        $excerpt = (new MarkdownRenderer())->excerpt($body, 200);

        if ($mysqlDatetime !== null && $mysqlDatetime !== '') {
            $stmt = $this->pdo->prepare(
                'INSERT INTO cms_content_entries
                    (content_type_id, title, slug, status, seo_title, seo_description, published_at, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $typeId,
                $title,
                $slug,
                'published',
                $title,
                $excerpt,
                $mysqlDatetime,
                $authorId,
                $mysqlDatetime,
                $mysqlDatetime,
            ]);
        } else {
            $stmt = $this->pdo->prepare(
                'INSERT INTO cms_content_entries
                    (content_type_id, title, slug, status, seo_title, seo_description, published_at, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?)'
            );
            $stmt->execute([
                $typeId,
                $title,
                $slug,
                'published',
                $title,
                $excerpt,
                $authorId,
            ]);
        }
        $entryId = (int) $this->pdo->lastInsertId();
        if ($entryId === 0) {
            return 0;
        }

        // Look up the field ids once.
        $fields = $this->fieldIdsForType($typeId);

        // Save the markdown body + forum slug into entry-values.
        $forumSlugStmt = $this->pdo->prepare('SELECT slug FROM forum_forums WHERE id = ?');
        $forumSlugStmt->execute([$forumId]);
        $forumSlug = (string) ($forumSlugStmt->fetchColumn() ?: '');

        $valueStmt = $this->pdo->prepare(
            'INSERT INTO cms_content_entry_values (content_entry_id, field_id, value_longtext) VALUES (?, ?, ?)'
        );
        if (isset($fields['body'])) {
            $valueStmt->execute([$entryId, $fields['body'], $body]);
        }
        if (isset($fields['forum_slug'])) {
            $valueStmt->execute([$entryId, $fields['forum_slug'], $forumSlug]);
        }

        return $entryId;
    }

    private function ensureContentTypeId(): ?int
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached === 0 ? null : $cached;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM cms_content_types WHERE slug = ? LIMIT 1');
        $stmt->execute(['forum-thread']);
        $id = $stmt->fetchColumn();
        $cached = $id === false ? 0 : (int) $id;
        return $cached === 0 ? null : $cached;
    }

    /** @return array<string, int> */
    private function fieldIdsForType(int $typeId): array
    {
        $stmt = $this->pdo->prepare('SELECT field_key, id FROM cms_content_fields WHERE content_type_id = ?');
        $stmt->execute([$typeId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(string) $row['field_key']] = (int) $row['id'];
        }
        return $out;
    }

    /**
     * Adjust a user's denormalised post count by `$delta` (typically +1
     * for a new post, -1 on delete). Idempotent w.r.t. row presence:
     * if no activity row exists, we INSERT one with the delta as the
     * starting count (clamped to 0); otherwise we ADD the delta to the
     * existing count, never letting it go negative.
     */
    private function bumpAuthorPostCount(int $userId, int $delta): void
    {
        $this->pdo->prepare(
            'INSERT INTO forum_user_activity (user_id, last_seen, posts_count)
                  VALUES (?, CURRENT_TIMESTAMP, GREATEST(?, 0))
             ON DUPLICATE KEY UPDATE posts_count = GREATEST(0, CAST(posts_count AS SIGNED) + ?)'
        )->execute([$userId, $delta, $delta]);
    }
}
