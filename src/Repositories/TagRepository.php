<?php

declare(strict_types=1);

namespace ForumPlugin\Repositories;

use ForumPlugin\SlugGenerator;
use PDO;

/**
 * Wrapper around the CMS taxonomy tables for forum-thread tags.
 *
 * Tags live in the same `cms_taxonomies` + `cms_taxonomy_terms` tables
 * the rest of the CMS uses, scoped to the `forum-thread` content type.
 * Each thread's tags are linked to its content entry via
 * `cms_content_entry_taxonomy_terms`.
 *
 * This wrapper guards against a missing taxonomy row (which would be
 * the case if migration 004 hasn't run yet) by returning empty results
 * rather than throwing — the public forum stays usable even mid-deploy.
 */
final class TagRepository
{
    /** Taxonomy slug owned by the forum plugin. */
    public const TAXONOMY_SLUG = 'forum-tags';

    /** Cached taxonomy id so we don't re-query on every call. */
    private ?int $taxonomyId = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Resolve the taxonomy id once per request. Returns null when the
     * migration that registers the taxonomy hasn't run — the rest of
     * the repo soft-fails to empty results in that case.
     */
    public function taxonomyId(): ?int
    {
        if ($this->taxonomyId !== null) {
            return $this->taxonomyId === 0 ? null : $this->taxonomyId;
        }
        $stmt = $this->pdo->prepare(
            'SELECT tax.id
               FROM cms_taxonomies tax
               JOIN cms_content_types ct ON ct.id = tax.content_type_id
              WHERE tax.slug = ? AND ct.slug = ?
              LIMIT 1'
        );
        $stmt->execute([self::TAXONOMY_SLUG, 'forum-thread']);
        $id = $stmt->fetchColumn();
        $this->taxonomyId = $id === false ? 0 : (int) $id;
        return $this->taxonomyId === 0 ? null : $this->taxonomyId;
    }

    /**
     * All tag terms with their usage counts. Used by the tag cloud
     * widget in the sidebar.
     *
     * @return list<array{id:int, name:string, slug:string, count:int}>
     */
    public function all(int $limit = 50): array
    {
        $taxId = $this->taxonomyId();
        if ($taxId === null) {
            return [];
        }
        $stmt = $this->pdo->prepare(
            'SELECT term.id, term.name, term.slug,
                    COUNT(DISTINCT et.content_entry_id) AS usage_count
               FROM cms_taxonomy_terms term
          LEFT JOIN cms_content_entry_taxonomy_terms et ON et.taxonomy_term_id = term.id
          LEFT JOIN forum_threads th ON th.entry_id = et.content_entry_id AND th.is_deleted = 0
              WHERE term.taxonomy_id = ?
           GROUP BY term.id, term.name, term.slug
           ORDER BY usage_count DESC, term.name ASC
              LIMIT ' . (int) max(1, min(200, $limit))
        );
        $stmt->execute([$taxId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $count = (int) $r['usage_count'];
            if ($count === 0) {
                // Skip orphaned terms (every existing thread that used
                // them was deleted) so the cloud only shows live tags.
                continue;
            }
            $out[] = [
                'id'    => (int) $r['id'],
                'name'  => (string) $r['name'],
                'slug'  => (string) $r['slug'],
                'count' => $count,
            ];
        }
        return $out;
    }

    /**
     * Look up a tag term by slug.
     *
     * @return array{id:int, name:string, slug:string, description:?string}|null
     */
    public function findBySlug(string $slug): ?array
    {
        $taxId = $this->taxonomyId();
        if ($taxId === null) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, name, slug, description
               FROM cms_taxonomy_terms
              WHERE taxonomy_id = ? AND slug = ?
              LIMIT 1'
        );
        $stmt->execute([$taxId, $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id'          => (int) $row['id'],
            'name'        => (string) $row['name'],
            'slug'        => (string) $row['slug'],
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
        ];
    }

    /**
     * Threads carrying a given tag, ordered by recency. Result rows
     * mirror ThreadRepository::listForForum for template reuse, plus
     * a `forum_name` / `forum_slug` join.
     *
     * @return list<array<string, mixed>>
     */
    public function threadsForTerm(int $termId, int $limit = 30, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.id, t.forum_id, t.entry_id, t.author_user_id, t.title, t.slug,
                    t.is_sticky, t.is_locked, t.is_deleted,
                    t.views_count, t.replies_count, t.likes_count,
                    t.last_post_id, t.last_post_at, t.last_poster_id,
                    t.created_at, t.updated_at,
                    f.name AS forum_name, f.slug AS forum_slug
               FROM cms_content_entry_taxonomy_terms et
               JOIN forum_threads t ON t.entry_id = et.content_entry_id
          LEFT JOIN forum_forums  f ON f.id = t.forum_id
              WHERE et.taxonomy_term_id = ? AND t.is_deleted = 0
           ORDER BY COALESCE(t.last_post_at, t.created_at) DESC, t.is_sticky DESC, t.id DESC
              LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset
        );
        $stmt->execute([$termId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function countThreadsForTerm(int $termId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
               FROM cms_content_entry_taxonomy_terms et
               JOIN forum_threads t ON t.entry_id = et.content_entry_id
              WHERE et.taxonomy_term_id = ? AND t.is_deleted = 0'
        );
        $stmt->execute([$termId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Tags attached to one thread (by content entry id).
     *
     * @return list<array{id:int, name:string, slug:string}>
     */
    public function forEntry(int $entryId): array
    {
        if ($entryId <= 0) {
            return [];
        }
        $taxId = $this->taxonomyId();
        if ($taxId === null) {
            return [];
        }
        $stmt = $this->pdo->prepare(
            'SELECT term.id, term.name, term.slug
               FROM cms_content_entry_taxonomy_terms et
               JOIN cms_taxonomy_terms term ON term.id = et.taxonomy_term_id
              WHERE et.content_entry_id = ? AND term.taxonomy_id = ?
           ORDER BY term.name ASC'
        );
        $stmt->execute([$entryId, $taxId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[] = [
                'id'   => (int) $r['id'],
                'name' => (string) $r['name'],
                'slug' => (string) $r['slug'],
            ];
        }
        return $out;
    }

    /**
     * Bulk-load tags for a list of entry ids — used by the search +
     * tag-listing pages so we don't issue N queries per result.
     *
     * @param list<int> $entryIds
     * @return array<int, list<array{id:int, name:string, slug:string}>> keyed by entry id
     */
    public function forEntries(array $entryIds): array
    {
        $entryIds = array_values(array_unique(array_filter(array_map('intval', $entryIds))));
        if ($entryIds === [] || $this->taxonomyId() === null) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($entryIds), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT et.content_entry_id AS entry_id, term.id, term.name, term.slug
               FROM cms_content_entry_taxonomy_terms et
               JOIN cms_taxonomy_terms term ON term.id = et.taxonomy_term_id
              WHERE et.content_entry_id IN (' . $placeholders . ')
                AND term.taxonomy_id = ?
           ORDER BY term.name ASC'
        );
        $bind = $entryIds;
        $bind[] = $this->taxonomyId();
        $stmt->execute($bind);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $eid = (int) $r['entry_id'];
            $out[$eid] ??= [];
            $out[$eid][] = [
                'id'   => (int) $r['id'],
                'name' => (string) $r['name'],
                'slug' => (string) $r['slug'],
            ];
        }
        return $out;
    }

    /**
     * Replace the set of tags attached to a thread's content entry with
     * the supplied list of tag names. Unknown names are auto-created as
     * new terms. Empty entryId or empty input is a no-op.
     *
     * @param list<string> $tagNames
     */
    public function syncForEntry(int $entryId, array $tagNames): void
    {
        if ($entryId <= 0) {
            return;
        }
        $taxId = $this->taxonomyId();
        if ($taxId === null) {
            return;
        }

        // Normalise: trim, drop empties + dupes (case-insensitive),
        // cap at 8 tags so spammers can't fill the tag cloud.
        $seen = [];
        $normalised = [];
        foreach ($tagNames as $raw) {
            $name = trim((string) $raw);
            if ($name === '') {
                continue;
            }
            if (mb_strlen($name) > 60) {
                $name = mb_substr($name, 0, 60);
            }
            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $normalised[] = $name;
            if (count($normalised) >= 8) {
                break;
            }
        }

        $this->pdo->beginTransaction();
        try {
            // Clear existing links for this entry within our taxonomy
            // only — leaves any other taxonomies untouched.
            $this->pdo->prepare(
                'DELETE et FROM cms_content_entry_taxonomy_terms et
                  JOIN cms_taxonomy_terms term ON term.id = et.taxonomy_term_id
                 WHERE et.content_entry_id = ? AND term.taxonomy_id = ?'
            )->execute([$entryId, $taxId]);

            foreach ($normalised as $name) {
                $termId = $this->upsertTerm($taxId, $name);
                $this->pdo->prepare(
                    'INSERT IGNORE INTO cms_content_entry_taxonomy_terms (content_entry_id, taxonomy_term_id) VALUES (?, ?)'
                )->execute([$entryId, $termId]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Find-or-create a term by name within a taxonomy. Slug is
     * SlugGenerator-derived from the name and made unique within the
     * taxonomy (rare collisions get a numeric suffix).
     */
    private function upsertTerm(int $taxonomyId, string $name): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM cms_taxonomy_terms WHERE taxonomy_id = ? AND LOWER(name) = LOWER(?) LIMIT 1'
        );
        $stmt->execute([$taxonomyId, $name]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $slug = SlugGenerator::slugify($name);
        if ($slug === '') {
            $slug = 'tag-' . substr(md5($name), 0, 6);
        }
        $slug = $this->uniqueSlug($taxonomyId, $slug);

        $ins = $this->pdo->prepare(
            'INSERT INTO cms_taxonomy_terms (taxonomy_id, name, slug, sort_order) VALUES (?, ?, ?, 0)'
        );
        $ins->execute([$taxonomyId, $name, $slug]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Ensure a slug is unique within the (taxonomy_id, slug) uniqueness
     * constraint. Falls back to {slug}-2, -3, … until we find a hole.
     */
    private function uniqueSlug(int $taxonomyId, string $slug): string
    {
        $candidate = $slug;
        $n = 2;
        while ($n < 200) {
            $stmt = $this->pdo->prepare('SELECT 1 FROM cms_taxonomy_terms WHERE taxonomy_id = ? AND slug = ? LIMIT 1');
            $stmt->execute([$taxonomyId, $candidate]);
            if ($stmt->fetchColumn() === false) {
                return $candidate;
            }
            $candidate = $slug . '-' . $n;
            $n++;
        }
        // Pathological case — append a hash. Should never happen in practice.
        return $slug . '-' . substr(md5(microtime(true) . $slug), 0, 6);
    }
}
