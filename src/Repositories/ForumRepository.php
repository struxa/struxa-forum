<?php

declare(strict_types=1);

namespace ForumPlugin\Repositories;

use ForumPlugin\SlugGenerator;
use PDO;

/**
 * Forums (= MyBB "forums" sat under a category).
 *
 * Two key responsibilities:
 *   - CRUD + slug uniqueness for the admin panel
 *   - Maintaining the denormalised counters (threads_count, posts_count,
 *     last_thread_id, last_post_id, last_post_at) so the public index
 *     can render "Last post by X, 12 min ago" cheaply.
 */
final class ForumRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Used by the public forum index — pulls every visible forum joined
     * with its category so we can group on the template side without
     * issuing extra queries.
     *
     * @return list<array<string, mixed>>
     */
    public function allWithCategory(bool $includeHidden = false): array
    {
        $sql = 'SELECT
                    f.id, f.category_id, f.parent_id, f.name, f.slug, f.description, f.icon, f.icon_image,
                    f.sort_order, f.is_locked, f.is_hidden,
                    f.threads_count, f.posts_count, f.last_thread_id, f.last_post_id, f.last_post_at,
                    c.id   AS cat_id,
                    c.name AS cat_name,
                    c.slug AS cat_slug,
                    c.sort_order AS cat_sort
                  FROM forum_forums f
                  JOIN forum_categories c ON c.id = f.category_id'
             . ($includeHidden ? '' : ' WHERE f.is_hidden = 0 AND c.is_hidden = 0')
             . ' ORDER BY c.sort_order ASC, c.id ASC, f.sort_order ASC, f.id ASC';
        $stmt = $this->pdo->query($sql);
        $rows = $stmt instanceof \PDOStatement ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        return array_map(static fn (array $r): array => [
            'id'             => (int) $r['id'],
            'category_id'    => (int) $r['category_id'],
            'parent_id'      => $r['parent_id'] !== null ? (int) $r['parent_id'] : null,
            'name'           => (string) $r['name'],
            'slug'           => (string) $r['slug'],
            'description'    => $r['description'] !== null ? (string) $r['description'] : null,
            'icon'           => $r['icon'] !== null ? (string) $r['icon'] : null,
            'icon_image'     => $r['icon_image'] !== null ? (string) $r['icon_image'] : null,
            'sort_order'     => (int) $r['sort_order'],
            'is_locked'      => (int) $r['is_locked'],
            'is_hidden'      => (int) $r['is_hidden'],
            'threads_count'  => (int) $r['threads_count'],
            'posts_count'    => (int) $r['posts_count'],
            'last_thread_id' => $r['last_thread_id'] !== null ? (int) $r['last_thread_id'] : null,
            'last_post_id'   => $r['last_post_id'] !== null ? (int) $r['last_post_id'] : null,
            'last_post_at'   => $r['last_post_at'] !== null ? (string) $r['last_post_at'] : null,
            'category'       => [
                'id'        => (int) $r['cat_id'],
                'name'      => (string) $r['cat_name'],
                'slug'      => (string) $r['cat_slug'],
                'sort'      => (int) $r['cat_sort'],
            ],
        ], $rows);
    }

    /** Flat list for admin tools (always includes hidden). */
    public function allForAdmin(): array
    {
        return $this->allWithCategory(true);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM forum_forums WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM forum_forums WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function create(array $data): int
    {
        $slug = SlugGenerator::uniqueSlug(
            $this->pdo,
            'forum_forums',
            'slug',
            $data['slug'] !== '' ? $data['slug'] : $data['name']
        );
        $stmt = $this->pdo->prepare(
            'INSERT INTO forum_forums
                (category_id, parent_id, name, slug, description, icon, icon_image, sort_order, is_locked, is_hidden)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (int) $data['category_id'],
            isset($data['parent_id']) && (int) $data['parent_id'] > 0 ? (int) $data['parent_id'] : null,
            (string) $data['name'],
            $slug,
            $data['description'] !== '' ? (string) $data['description'] : null,
            $data['icon'] !== '' ? (string) $data['icon'] : null,
            !empty($data['icon_image']) ? (string) $data['icon_image'] : null,
            (int) ($data['sort_order'] ?? $this->nextSortOrder()),
            (int) (!empty($data['is_locked']) ? 1 : 0),
            (int) (!empty($data['is_hidden']) ? 1 : 0),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $slug = SlugGenerator::uniqueSlug(
            $this->pdo,
            'forum_forums',
            'slug',
            $data['slug'] !== '' ? $data['slug'] : $data['name'],
            $id
        );
        $stmt = $this->pdo->prepare(
            'UPDATE forum_forums
                SET category_id = ?, parent_id = ?, name = ?, slug = ?, description = ?,
                    icon = ?, icon_image = ?, sort_order = ?, is_locked = ?, is_hidden = ?
              WHERE id = ?'
        );
        $stmt->execute([
            (int) $data['category_id'],
            isset($data['parent_id']) && (int) $data['parent_id'] > 0 ? (int) $data['parent_id'] : null,
            (string) $data['name'],
            $slug,
            $data['description'] !== '' ? (string) $data['description'] : null,
            $data['icon'] !== '' ? (string) $data['icon'] : null,
            array_key_exists('icon_image', $data)
                ? ($data['icon_image'] !== '' && $data['icon_image'] !== null ? (string) $data['icon_image'] : null)
                : null,
            (int) ($data['sort_order'] ?? 0),
            (int) (!empty($data['is_locked']) ? 1 : 0),
            (int) (!empty($data['is_hidden']) ? 1 : 0),
            $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM forum_forums WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function nextSortOrder(): int
    {
        $stmt = $this->pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM forum_forums');
        return $stmt instanceof \PDOStatement ? (int) $stmt->fetchColumn() : 10;
    }

    /**
     * Bump the counters and last-post pointer on a forum after a new
     * post is created. Idempotent recompute; safer than incrementing
     * because deletes can run interleaved.
     */
    public function refreshCounters(int $forumId): void
    {
        // last_thread_id via subquery — GROUP_CONCAT of every thread id hits group_concat_max_len
        // on large forums and fails with "Row N was cut by GROUP_CONCAT()".
        $sql = <<<SQL
            UPDATE forum_forums f
              LEFT JOIN (
                SELECT t.forum_id,
                       COUNT(DISTINCT t.id)                      AS threads_count,
                       COUNT(p.id)                               AS posts_count,
                       MAX(p.id)                                 AS last_post_id,
                       MAX(p.created_at)                         AS last_post_at,
                       (SELECT t2.id
                          FROM forum_threads t2
                          INNER JOIN forum_posts p2 ON p2.thread_id = t2.id AND p2.is_deleted = 0
                         WHERE t2.forum_id = t.forum_id AND t2.is_deleted = 0
                         ORDER BY p2.created_at DESC, p2.id DESC
                         LIMIT 1)                                 AS last_thread_id
                  FROM forum_threads t
             LEFT JOIN forum_posts p
                    ON p.thread_id = t.id AND p.is_deleted = 0
                 WHERE t.forum_id = ? AND t.is_deleted = 0
                 GROUP BY t.forum_id
              ) s ON s.forum_id = f.id
              SET f.threads_count  = COALESCE(s.threads_count, 0),
                  f.posts_count    = COALESCE(s.posts_count, 0),
                  f.last_post_id   = s.last_post_id,
                  f.last_post_at   = s.last_post_at,
                  f.last_thread_id = CASE WHEN s.last_thread_id IS NULL THEN NULL ELSE CAST(s.last_thread_id AS UNSIGNED) END
              WHERE f.id = ?
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$forumId, $forumId]);
    }

    /**
     * "Where can I post?" list for the New Thread form. Excludes locked
     * + hidden forums by default (mods can still create threads via the
     * admin panel which doesn't use this list).
     *
     * @return list<array{id:int, name:string, slug:string, category:string}>
     */
    public function postableForumsForUser(): array
    {
        $sql = 'SELECT f.id, f.name, f.slug, c.name AS category
                  FROM forum_forums f
                  JOIN forum_categories c ON c.id = f.category_id
                 WHERE f.is_hidden = 0 AND f.is_locked = 0 AND c.is_hidden = 0
              ORDER BY c.sort_order ASC, f.sort_order ASC';
        $stmt = $this->pdo->query($sql);
        $rows = $stmt instanceof \PDOStatement ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        return array_map(static fn (array $r): array => [
            'id'       => (int) $r['id'],
            'name'     => (string) $r['name'],
            'slug'     => (string) $r['slug'],
            'category' => (string) $r['category'],
        ], $rows);
    }
}
