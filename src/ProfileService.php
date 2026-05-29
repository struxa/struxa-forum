<?php

declare(strict_types=1);

namespace ForumPlugin;

use PDO;

/**
 * Builds the public user profile payload — rank, post count, join
 * date, recent threads started and recent replies. Pure read-only.
 *
 * Resolution rules for the `{username}` URL param:
 *   - Numeric → cms_users.id lookup
 *   - Otherwise → exact case-insensitive match on display_name. If
 *     several users share a name, prefers the one with the highest
 *     post count (active members typically claim the canonical name).
 */
final class ProfileService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly UserDirectory $users,
    ) {
    }

    /**
     * Resolve the URL param to a user record. Returns null if no user
     * matches (the route should 404).
     *
     * @return array<string, mixed>|null
     */
    public function resolve(string $usernameOrId): ?array
    {
        $param = trim($usernameOrId);
        if ($param === '') {
            return null;
        }

        if (ctype_digit($param)) {
            return $this->users->find((int) $param);
        }

        // Display_name lookup. We tolerate URL-decoding (the route
        // gives us raw bytes from the URL).
        $name = rawurldecode($param);
        $stmt = $this->pdo->prepare(
            'SELECT u.id, COALESCE(a.posts_count, 0) AS posts
               FROM cms_users u
          LEFT JOIN forum_user_activity a ON a.user_id = u.id
              WHERE LOWER(u.display_name) = LOWER(?)
           ORDER BY posts DESC, u.id ASC
              LIMIT 1'
        );
        $stmt->execute([$name]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return null;
        }
        return $this->users->find((int) $id);
    }

    /**
     * Stats summary for the profile page header.
     *
     * @return array{
     *   threads_started:int, replies_posted:int, likes_received:int,
     *   first_post_at:?string, last_post_at:?string
     * }
     */
    public function stats(int $userId): array
    {
        $threads = $this->scalar(
            'SELECT COUNT(*) FROM forum_threads WHERE author_user_id = ? AND is_deleted = 0',
            [$userId]
        );
        $replies = $this->scalar(
            'SELECT COUNT(*) FROM forum_posts WHERE author_user_id = ? AND is_first_post = 0 AND is_deleted = 0',
            [$userId]
        );
        $likes = $this->scalar(
            'SELECT COALESCE(SUM(likes_count), 0) FROM forum_posts WHERE author_user_id = ? AND is_deleted = 0',
            [$userId]
        );

        $stmt = $this->pdo->prepare(
            'SELECT MIN(created_at) AS first_post_at, MAX(created_at) AS last_post_at
               FROM forum_posts WHERE author_user_id = ? AND is_deleted = 0'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'threads_started' => $threads,
            'replies_posted'  => $replies,
            'likes_received'  => $likes,
            'first_post_at'   => $row['first_post_at'] ?? null,
            'last_post_at'    => $row['last_post_at'] ?? null,
        ];
    }

    /**
     * Recent threads the user *started*. Used for the "Topics started"
     * tab on the profile page.
     *
     * @return list<array<string, mixed>>
     */
    public function recentThreads(int $userId, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.id, t.title, t.slug, t.created_at, t.last_post_at,
                    t.replies_count, t.likes_count, t.views_count,
                    f.name AS forum_name, f.slug AS forum_slug
               FROM forum_threads t
          LEFT JOIN forum_forums f ON f.id = t.forum_id
              WHERE t.author_user_id = ? AND t.is_deleted = 0
           ORDER BY t.created_at DESC, t.id DESC
              LIMIT ?'
        );
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Recent replies (excluding the user's own thread openers). Each
     * row carries the parent thread title + forum slug for linking.
     *
     * @return list<array<string, mixed>>
     */
    public function recentReplies(int $userId, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.thread_id, p.body_markdown, p.likes_count, p.created_at,
                    t.title AS thread_title, t.slug AS thread_slug,
                    f.slug  AS forum_slug, f.name AS forum_name
               FROM forum_posts p
          LEFT JOIN forum_threads t ON t.id = p.thread_id
          LEFT JOIN forum_forums  f ON f.id = t.forum_id
              WHERE p.author_user_id = ? AND p.is_first_post = 0 AND p.is_deleted = 0
           ORDER BY p.created_at DESC, p.id DESC
              LIMIT ?'
        );
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function scalar(string $sql, array $bind): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bind);
        return (int) $stmt->fetchColumn();
    }
}
