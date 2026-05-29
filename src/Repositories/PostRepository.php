<?php

declare(strict_types=1);

namespace ForumPlugin\Repositories;

use ForumPlugin\MarkdownRenderer;
use PDO;

/**
 * Posts (= MyBB posts). One row per reply (including the OP, which has
 * is_first_post = 1). All writes funnel through this repository so the
 * markdown → HTML render and the counter-refresh on the parent thread
 * stay in lockstep.
 */
final class PostRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly MarkdownRenderer $markdown,
        private readonly ThreadRepository $threads,
    ) {
    }

    /**
     * Paginated linear list of posts in a thread, oldest first. Includes
     * soft-deleted posts only if `$includeDeleted` is true (admin view).
     *
     * @return list<array<string, mixed>>
     */
    public function listForThread(int $threadId, int $limit, int $offset, bool $includeDeleted = false): array
    {
        $where = 'thread_id = ?';
        if (!$includeDeleted) {
            $where .= ' AND is_deleted = 0';
        }
        $sql = "SELECT id, thread_id, forum_id, author_user_id, is_first_post, is_deleted,
                       body_markdown, body_html, likes_count, edited_at, edited_by_user_id,
                       created_at, updated_at
                  FROM forum_posts
                 WHERE $where
              ORDER BY is_first_post DESC, created_at ASC, id ASC
                 LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$threadId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function countForThread(int $threadId, bool $includeDeleted = false): int
    {
        $where = 'thread_id = ?';
        if (!$includeDeleted) {
            $where .= ' AND is_deleted = 0';
        }
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM forum_posts WHERE $where");
        $stmt->execute([$threadId]);
        return (int) $stmt->fetchColumn();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM forum_posts WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Reply to a thread.
     *
     * When `$createdAtMysql` is set (format `Y-m-d H:i:s`), both `created_at`
     * and `updated_at` are stored explicitly — used by bulk/historical imports.
     *
     * @return int New post id
     */
    public function reply(int $threadId, int $forumId, ?int $authorId, string $bodyMarkdown, ?string $createdAtMysql = null): int
    {
        $bodyHtml = $this->markdown->render($bodyMarkdown);
        if ($createdAtMysql !== null && $createdAtMysql !== '') {
            $stmt = $this->pdo->prepare(
                'INSERT INTO forum_posts
                    (thread_id, forum_id, author_user_id, is_first_post, is_deleted, body_markdown, body_html, created_at, updated_at)
                 VALUES (?, ?, ?, 0, 0, ?, ?, ?, ?)'
            );
            $stmt->execute([$threadId, $forumId, $authorId, $bodyMarkdown, $bodyHtml, $createdAtMysql, $createdAtMysql]);
        } else {
            $stmt = $this->pdo->prepare(
                'INSERT INTO forum_posts
                    (thread_id, forum_id, author_user_id, is_first_post, is_deleted, body_markdown, body_html)
                 VALUES (?, ?, ?, 0, 0, ?, ?)'
            );
            $stmt->execute([$threadId, $forumId, $authorId, $bodyMarkdown, $bodyHtml]);
        }
        $postId = (int) $this->pdo->lastInsertId();

        $this->threads->refreshAround($threadId);
        if ($authorId !== null) {
            $this->pdo->prepare(
                'INSERT INTO forum_user_activity (user_id, last_seen, posts_count)
                      VALUES (?, CURRENT_TIMESTAMP, 1)
                 ON DUPLICATE KEY UPDATE posts_count = GREATEST(0, CAST(posts_count AS SIGNED) + 1),
                                         last_seen = CURRENT_TIMESTAMP'
            )->execute([$authorId]);
        }

        return $postId;
    }

    /**
     * Edit a post body. Re-renders the cached HTML and stamps
     * edited_at + edited_by_user_id so the UI can show "edited by X".
     */
    public function edit(int $postId, string $bodyMarkdown, int $editorUserId): void
    {
        $bodyHtml = $this->markdown->render($bodyMarkdown);
        $stmt = $this->pdo->prepare(
            'UPDATE forum_posts
                SET body_markdown = ?, body_html = ?, edited_at = CURRENT_TIMESTAMP, edited_by_user_id = ?
              WHERE id = ?'
        );
        $stmt->execute([$bodyMarkdown, $bodyHtml, $editorUserId, $postId]);

        $post = $this->find($postId);
        if ($post !== null) {
            $this->threads->refreshAround((int) $post['thread_id']);

            // If this was the OP, mirror the new body into the
            // cms_content_entries row so the CMS content browser stays
            // in sync.
            if ((int) $post['is_first_post'] === 1) {
                $this->mirrorOpenerToContentEntry((int) $post['thread_id'], $bodyMarkdown);
            }
        }
    }

    /**
     * Soft-delete a post. If the OP is deleted, every post in the thread is
     * soft-deleted and the thread is marked deleted (so nothing orphaned stays live).
     */
    public function softDelete(int $postId): void
    {
        $post = $this->find($postId);
        if ($post === null) {
            return;
        }
        if ((int) ($post['is_deleted'] ?? 0) === 1) {
            return;
        }

        $threadId = (int) $post['thread_id'];

        if ((int) $post['is_first_post'] === 1) {
            $this->softDeleteAllPostsInThread($threadId);
            $this->pdo->prepare('UPDATE forum_threads SET is_deleted = 1 WHERE id = ?')->execute([$threadId]);
            $this->threads->refreshAround($threadId);

            return;
        }

        $this->pdo->prepare('UPDATE forum_posts SET is_deleted = 1 WHERE id = ?')->execute([$postId]);
        if ($post['author_user_id'] !== null) {
            $this->pdo->prepare(
                'UPDATE forum_user_activity
                    SET posts_count = GREATEST(0, CAST(posts_count AS SIGNED) - 1)
                  WHERE user_id = ?'
            )->execute([(int) $post['author_user_id']]);
        }

        $this->threads->refreshAround($threadId);
    }

    /**
     * Soft-delete all still-live posts in a thread and decrement per-author
     * activity counts. Does not change {@see forum_threads.is_deleted}; callers
     * set that and should call {@see ThreadRepository::refreshAround} afterward.
     */
    public function softDeleteAllPostsInThread(int $threadId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT author_user_id, COUNT(*) AS c
               FROM forum_posts
              WHERE thread_id = ? AND is_deleted = 0 AND author_user_id IS NOT NULL
           GROUP BY author_user_id'
        );
        $stmt->execute([$threadId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $c = (int) ($row['c'] ?? 0);
            $uid = (int) ($row['author_user_id'] ?? 0);
            if ($c < 1 || $uid < 1) {
                continue;
            }
            $this->pdo->prepare(
                'UPDATE forum_user_activity
                    SET posts_count = GREATEST(0, CAST(posts_count AS SIGNED) - ?)
                  WHERE user_id = ?'
            )->execute([$c, $uid]);
        }

        $this->pdo->prepare('UPDATE forum_posts SET is_deleted = 1 WHERE thread_id = ? AND is_deleted = 0')
            ->execute([$threadId]);
    }

    /** Restore a soft-deleted post. The thread is *not* auto-restored. */
    public function restore(int $postId): void
    {
        $post = $this->find($postId);
        if ($post === null) {
            return;
        }
        $this->pdo->prepare('UPDATE forum_posts SET is_deleted = 0 WHERE id = ?')->execute([$postId]);
        if ($post['author_user_id'] !== null) {
            $this->pdo->prepare(
                'UPDATE forum_user_activity
                    SET posts_count = posts_count + 1
                  WHERE user_id = ?'
            )->execute([(int) $post['author_user_id']]);
        }
        $this->threads->refreshAround((int) $post['thread_id']);
    }

    /** Like / unlike toggle. Returns `true` if liked after the toggle. */
    public function toggleLike(int $postId, int $userId): bool
    {
        $checkStmt = $this->pdo->prepare(
            'SELECT 1 FROM forum_post_likes WHERE user_id = ? AND post_id = ? LIMIT 1'
        );
        $checkStmt->execute([$userId, $postId]);
        $exists = $checkStmt->fetchColumn() !== false;

        if ($exists) {
            $this->pdo->prepare('DELETE FROM forum_post_likes WHERE user_id = ? AND post_id = ?')
                ->execute([$userId, $postId]);
            $this->pdo->prepare(
                'UPDATE forum_posts SET likes_count = GREATEST(0, CAST(likes_count AS SIGNED) - 1) WHERE id = ?'
            )->execute([$postId]);
        } else {
            $this->pdo->prepare(
                'INSERT INTO forum_post_likes (user_id, post_id) VALUES (?, ?)'
            )->execute([$userId, $postId]);
            $this->pdo->prepare('UPDATE forum_posts SET likes_count = likes_count + 1 WHERE id = ?')
                ->execute([$postId]);
        }

        $post = $this->find($postId);
        if ($post !== null) {
            $this->threads->refreshAround((int) $post['thread_id']);
        }

        return !$exists;
    }

    /**
     * IDs of posts the given user has liked in a list. Used to render
     * the "Liked" filled-heart state when paginating a thread.
     *
     * @param list<int> $postIds
     * @return array<int, true>
     */
    public function likedByUser(int $userId, array $postIds): array
    {
        if ($postIds === [] || $userId <= 0) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        $sql = "SELECT post_id FROM forum_post_likes WHERE user_id = ? AND post_id IN ($placeholders)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$userId], $postIds));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) ?: [] as $r) {
            $out[(int) $r[0]] = true;
        }
        return $out;
    }

    public function recent(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.thread_id, p.forum_id, p.author_user_id, p.is_first_post, p.is_deleted,
                    p.body_markdown, p.body_html, p.likes_count, p.created_at,
                    t.title AS thread_title, t.slug AS thread_slug,
                    f.name AS forum_name, f.slug AS forum_slug
               FROM forum_posts p
          LEFT JOIN forum_threads t ON t.id = p.thread_id
          LEFT JOIN forum_forums  f ON f.id = p.forum_id
              WHERE p.is_deleted = 0
              ORDER BY p.created_at DESC, p.id DESC
              LIMIT ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Used by /admin/forum/posts. Mirrors ThreadRepository::listForAdmin —
     * supports a free-text search across body + thread title, forum filter,
     * status filter (live / deleted / op / all) and pagination.
     *
     * Author filter accepts either a cms_users id or a display-name fragment.
     *
     * @return list<array<string, mixed>>
     */
    public function listForAdmin(array $filter, int $limit = 25, int $offset = 0): array
    {
        [$where, $bind] = $this->buildAdminWhere($filter);

        $sql = 'SELECT p.id, p.thread_id, p.forum_id, p.author_user_id, p.is_first_post, p.is_deleted,
                       p.body_markdown, p.body_html, p.likes_count, p.created_at,
                       t.title AS thread_title, t.slug AS thread_slug,
                       f.name AS forum_name, f.slug AS forum_slug
                  FROM forum_posts p
             LEFT JOIN forum_threads t ON t.id = p.thread_id
             LEFT JOIN forum_forums  f ON f.id = p.forum_id'
              . ($where === '' ? '' : ' WHERE ' . $where)
              . ' ORDER BY p.created_at DESC, p.id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bind);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Returns the total number of rows matching the same filter (for pagination). */
    public function countForAdmin(array $filter): int
    {
        [$where, $bind] = $this->buildAdminWhere($filter);
        $sql = 'SELECT COUNT(*) FROM forum_posts p
             LEFT JOIN forum_threads t ON t.id = p.thread_id
             LEFT JOIN forum_forums  f ON f.id = p.forum_id'
              . ($where === '' ? '' : ' WHERE ' . $where);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bind);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Shared WHERE + bind builder for listForAdmin / countForAdmin so the
     * filter contract stays consistent across both.
     *
     * @return array{0:string, 1:list<mixed>}
     */
    private function buildAdminWhere(array $filter): array
    {
        $where = [];
        $bind  = [];

        $q = trim((string) ($filter['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(p.body_markdown LIKE ? OR t.title LIKE ?)';
            $bind[]  = '%' . $q . '%';
            $bind[]  = '%' . $q . '%';
        }

        $forumId = (int) ($filter['forum_id'] ?? 0);
        if ($forumId > 0) {
            $where[] = 'p.forum_id = ?';
            $bind[]  = $forumId;
        }

        $author = trim((string) ($filter['author'] ?? ''));
        if ($author !== '') {
            if (ctype_digit($author)) {
                $where[] = 'p.author_user_id = ?';
                $bind[]  = (int) $author;
            } else {
                // Match against cms_users.display_name OR phpauth_users.username
                $where[] = 'p.author_user_id IN (
                    SELECT cu.id FROM cms_users cu
                    LEFT JOIN phpauth_users pu ON pu.id = cu.phpauth_user_id
                    WHERE cu.display_name LIKE ? OR pu.username LIKE ?
                )';
                $bind[]  = '%' . $author . '%';
                $bind[]  = '%' . $author . '%';
            }
        }

        $status = (string) ($filter['status'] ?? '');
        if ($status === 'deleted') {
            $where[] = 'p.is_deleted = 1';
        } elseif ($status === 'op') {
            $where[] = 'p.is_first_post = 1 AND p.is_deleted = 0';
        } elseif ($status === 'all') {
            // no filter; include everything (live + deleted)
        } else {
            $where[] = 'p.is_deleted = 0';
        }

        return [implode(' AND ', $where), $bind];
    }

    /**
     * Update the cms_content_entries body value for a thread's OP. The
     * field uses the "body" key (configured in migration 002).
     */
    private function mirrorOpenerToContentEntry(int $threadId, string $bodyMarkdown): void
    {
        $stmt = $this->pdo->prepare('SELECT entry_id FROM forum_threads WHERE id = ?');
        $stmt->execute([$threadId]);
        $entryId = $stmt->fetchColumn();
        if ($entryId === false || (int) $entryId <= 0) {
            return;
        }
        $entryId = (int) $entryId;

        $field = $this->pdo->prepare(
            'SELECT id FROM cms_content_fields
              WHERE content_type_id = (SELECT id FROM cms_content_types WHERE slug = ? LIMIT 1)
                AND field_key = ? LIMIT 1'
        );
        $field->execute(['forum-thread', 'body']);
        $fid = $field->fetchColumn();
        if ($fid === false) {
            return;
        }
        $fid = (int) $fid;

        // Upsert the value row.
        $this->pdo->prepare(
            'INSERT INTO cms_content_entry_values (content_entry_id, field_id, value_longtext)
                  VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value_longtext = VALUES(value_longtext)'
        )->execute([$entryId, $fid, $bodyMarkdown]);
    }
}
