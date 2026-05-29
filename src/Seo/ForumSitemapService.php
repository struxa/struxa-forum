<?php

declare(strict_types=1);

namespace ForumPlugin\Seo;

use PDO;

/**
 * Builds the URL list for the forum's own sitemap.xml.
 *
 * We deliberately don't piggyback on App\Seo\SitemapService — that lives in
 * core and only knows about CMS pages + content entries. Wiring forum data
 * into it would require core changes, which we explicitly avoid so the
 * Struxa CMS self-updater never has to merge into our changes.
 *
 * The output of `xml()` is RFC-3066-valid sitemap XML; pair it with
 * `Cache-Control: public, max-age=600` and reference it from
 * `robots.txt` (via the CMS's `robots_txt_custom` setting — see
 * docs/local-overrides.md for the one-line operator step).
 *
 * Pagination: the sitemap spec allows up to 50,000 URLs per file. We don't
 * paginate yet because the forum is small; once we cross 30k threads we
 * should split into a sitemap-index. Adding `LIMIT 50000` keeps the file
 * spec-compliant even if the row count grows unexpectedly.
 */
final class ForumSitemapService
{
    public const URL_LIMIT = 50000;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array{loc:string, lastmod:?string, changefreq:?string, priority:?string}>
     */
    public function collectUrls(string $siteUrl): array
    {
        $siteUrl = rtrim($siteUrl, '/');
        $urls = [];

        // 1. The forum index itself. lastmod = most recent post anywhere.
        $stmt = $this->pdo->query(
            'SELECT MAX(t.last_post_at) AS lastmod
               FROM forum_threads t
              WHERE t.is_deleted = 0'
        );
        $lastmod = $stmt !== false ? (string) ($stmt->fetchColumn() ?: '') : '';
        $urls[] = [
            'loc'        => $siteUrl . '/forum',
            'lastmod'    => $this->w3cDate($lastmod),
            'changefreq' => 'hourly',
            'priority'   => '0.8',
        ];

        // 2. Each visible forum (not hidden) with its own lastmod from its
        //    most recent thread. We intentionally include `is_locked` forums
        //    since they're still public content even if no new threads
        //    can be created.
        $stmt = $this->pdo->query(
            'SELECT f.slug, f.last_post_at, f.updated_at
               FROM forum_forums f
              WHERE f.is_hidden = 0
              ORDER BY f.sort_order, f.id'
        );
        if ($stmt !== false) {
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $urls[] = [
                    'loc'        => $siteUrl . '/forum/' . rawurlencode((string) $row['slug']),
                    'lastmod'    => $this->w3cDate((string) ($row['last_post_at'] ?? $row['updated_at'] ?? '')),
                    'changefreq' => 'hourly',
                    'priority'   => '0.7',
                ];
            }
        }

        // 3. Every visible thread joined back to its forum so the URL
        //    matches the slug structure `/forum/{forumSlug}/{threadSlug}`.
        //    Deleted threads and threads in hidden forums are skipped.
        $sql = 'SELECT t.slug AS t_slug, f.slug AS f_slug,
                       COALESCE(t.last_post_at, t.updated_at, t.created_at) AS lastmod
                  FROM forum_threads t
            INNER JOIN forum_forums  f ON f.id = t.forum_id
                 WHERE t.is_deleted = 0 AND f.is_hidden = 0
              ORDER BY lastmod DESC
                 LIMIT ' . self::URL_LIMIT;
        $stmt = $this->pdo->query($sql);
        if ($stmt !== false) {
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $urls[] = [
                    'loc'        => $siteUrl . '/forum/' . rawurlencode((string) $row['f_slug']) . '/' . rawurlencode((string) $row['t_slug']),
                    'lastmod'    => $this->w3cDate((string) ($row['lastmod'] ?? '')),
                    'changefreq' => 'daily',
                    'priority'   => '0.6',
                ];
            }
        }

        // 4. Tag archive pages. cms_taxonomies / cms_taxonomy_terms are the
        //    CMS-native tag storage that the forum hooks into; we join via
        //    `forum_tags_taxonomy` to know which terms actually belong to
        //    the forum and skip every other content type's tags.
        $stmt = $this->pdo->query(
            "SELECT tt.slug, tt.updated_at
               FROM cms_taxonomy_terms tt
         INNER JOIN cms_taxonomies tx ON tx.id = tt.taxonomy_id
              WHERE tx.slug = 'forum-tags' AND COALESCE(tt.seo_noindex, 0) = 0"
        );
        if ($stmt !== false) {
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $urls[] = [
                    'loc'        => $siteUrl . '/forum/tag/' . rawurlencode((string) $row['slug']),
                    'lastmod'    => $this->w3cDate((string) ($row['updated_at'] ?? '')),
                    'changefreq' => 'weekly',
                    'priority'   => '0.4',
                ];
            }
        }

        return $urls;
    }

    public function xml(string $siteUrl): string
    {
        $urls = $this->collectUrls($siteUrl);
        $lines = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];
        foreach ($urls as $u) {
            $lines[] = '  <url>';
            $lines[] = '    <loc>' . htmlspecialchars($u['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>';
            if (!empty($u['lastmod'])) {
                $lines[] = '    <lastmod>' . htmlspecialchars($u['lastmod'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</lastmod>';
            }
            if (!empty($u['changefreq'])) {
                $lines[] = '    <changefreq>' . $u['changefreq'] . '</changefreq>';
            }
            if (!empty($u['priority'])) {
                $lines[] = '    <priority>' . $u['priority'] . '</priority>';
            }
            $lines[] = '  </url>';
        }
        $lines[] = '</urlset>';

        return implode("\n", $lines) . "\n";
    }

    private function w3cDate(string $mysqlTs): ?string
    {
        $mysqlTs = trim($mysqlTs);
        if ($mysqlTs === '') {
            return null;
        }
        $t = strtotime($mysqlTs);

        return $t !== false ? gmdate('Y-m-d', $t) : null;
    }
}
