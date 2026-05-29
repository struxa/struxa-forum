<?php

declare(strict_types=1);

namespace ForumPlugin\Repositories;

use ForumPlugin\SlugGenerator;
use PDO;

/**
 * Categories — the top-level grouping for forums (MyBB "category").
 * Small table, fully cached on a single SELECT … ORDER BY sort_order.
 */
final class CategoryRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array{
     *   id:int, name:string, slug:string, description:?string,
     *   sort_order:int, is_hidden:int, created_at:string, updated_at:string
     * }>
     */
    public function all(bool $includeHidden = true): array
    {
        $sql = 'SELECT id, name, slug, description, sort_order, is_hidden, created_at, updated_at
                  FROM forum_categories'
             . ($includeHidden ? '' : ' WHERE is_hidden = 0')
             . ' ORDER BY sort_order ASC, id ASC';
        $stmt = $this->pdo->query($sql);
        $rows = $stmt instanceof \PDOStatement ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        return array_map(static fn (array $r): array => [
            'id'          => (int) $r['id'],
            'name'        => (string) $r['name'],
            'slug'        => (string) $r['slug'],
            'description' => $r['description'] !== null ? (string) $r['description'] : null,
            'sort_order'  => (int) $r['sort_order'],
            'is_hidden'   => (int) $r['is_hidden'],
            'created_at'  => (string) $r['created_at'],
            'updated_at'  => (string) $r['updated_at'],
        ], $rows);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM forum_categories WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM forum_categories WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function create(array $data): int
    {
        $slug = SlugGenerator::uniqueSlug(
            $this->pdo,
            'forum_categories',
            'slug',
            $data['slug'] !== '' ? $data['slug'] : $data['name']
        );
        $stmt = $this->pdo->prepare(
            'INSERT INTO forum_categories (name, slug, description, sort_order, is_hidden)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (string) $data['name'],
            $slug,
            $data['description'] !== '' ? (string) $data['description'] : null,
            (int) ($data['sort_order'] ?? $this->nextSortOrder()),
            (int) (!empty($data['is_hidden']) ? 1 : 0),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $slug = SlugGenerator::uniqueSlug(
            $this->pdo,
            'forum_categories',
            'slug',
            $data['slug'] !== '' ? $data['slug'] : $data['name'],
            $id
        );
        $stmt = $this->pdo->prepare(
            'UPDATE forum_categories
                SET name = ?, slug = ?, description = ?, sort_order = ?, is_hidden = ?
              WHERE id = ?'
        );
        $stmt->execute([
            (string) $data['name'],
            $slug,
            $data['description'] !== '' ? (string) $data['description'] : null,
            (int) ($data['sort_order'] ?? 0),
            (int) (!empty($data['is_hidden']) ? 1 : 0),
            $id,
        ]);
    }

    public function delete(int $id): void
    {
        // Re-parent the forums onto a placeholder? In v1 we simply
        // refuse the delete from the admin if any forums still reference
        // this category; that check is enforced there, not here.
        $stmt = $this->pdo->prepare('DELETE FROM forum_categories WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function nextSortOrder(): int
    {
        $stmt = $this->pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM forum_categories');
        return $stmt instanceof \PDOStatement ? (int) $stmt->fetchColumn() : 10;
    }

    /** Count forums under this category (so admin can warn before delete). */
    public function forumCount(int $categoryId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM forum_forums WHERE category_id = ?');
        $stmt->execute([$categoryId]);
        return (int) $stmt->fetchColumn();
    }
}
