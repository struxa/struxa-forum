<?php

declare(strict_types=1);

namespace ForumPlugin;

use ForumPlugin\Repositories\SettingsRepository;
use ForumPlugin\UserDirectory;
use PDO;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig glue for the forum plugin.
 *
 * Exposes:
 *   - `forum_stats()` → snapshot dict (members/threads/posts/online_now/newest_member)
 *   - `forum_user_rank(posts)` → rank tuple {slug,label,min_posts,colour}
 *   - `forum_relative_time(ts)` → "2 hours ago" style relative formatter
 *   - `forum_asset(path)` → cache-busted plugin asset URL
 *   - `forum_excerpt(markdown, max=220)` → SEO-friendly plain-text excerpt
 *   - filter `markdown` → render forum markdown to safe HTML
 */
final class ForumTwigExtension extends AbstractExtension
{
    private ?StatsService $stats = null;
    private ?UserDirectory $users = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly MarkdownRenderer $markdown,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('forum_stats', $this->statsSnapshot(...)),
            new TwigFunction('forum_user_rank', static fn (int $posts): array => RankService::rankFor($posts)),
            new TwigFunction('forum_relative_time', $this->relativeTime(...)),
            new TwigFunction('forum_asset', $this->asset(...)),
            new TwigFunction('forum_excerpt', fn (string $md, int $max = 220): string => $this->markdown->excerpt($md, $max)),
            new TwigFunction('forum_hot_topics', $this->hotTopics(...)),
            new TwigFunction('forum_latest_threads', $this->latestThreads(...)),
            new TwigFunction('forum_thread_page_count', static fn (int $replies, int $perPage = 15): int => ForumPagination::threadPageCount($replies, $perPage)),
            new TwigFunction('forum_pager_items', static fn (int $current, int $total, int $window = 2): array => ForumPagination::pageNumbers($current, $total, $window)),
            new TwigFunction('forum_page_href', static fn (string $baseUrl, int $page, string $querySuffix = ''): string => ForumPagination::pageHref($baseUrl, $page, $querySuffix)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('forum_markdown', fn (string $md): string => $this->markdown->render($md), ['is_safe' => ['html']]),
        ];
    }

    /** @return array<string, mixed> */
    private function statsSnapshot(): array
    {
        return $this->statsService()->snapshot();
    }

    /**
     * Hot topics in the last N hours. Each row is enriched with a
     * `last_poster` array (display_name + avatar) and an `author`
     * array so templates don't have to issue extra lookups.
     *
     * @return list<array<string,mixed>>
     */
    private function hotTopics(int $hours = 24, int $limit = 5): array
    {
        $rows = $this->statsService()->hotTopics($hours, $limit);
        if ($rows === []) {
            return [];
        }
        $users = $this->userDirectory();
        $userIds = [];
        foreach ($rows as $r) {
            if (!empty($r['author_user_id']))   { $userIds[] = (int) $r['author_user_id']; }
            if (!empty($r['last_poster_id']))   { $userIds[] = (int) $r['last_poster_id']; }
        }
        $users->preload(array_values(array_unique($userIds)));
        foreach ($rows as &$r) {
            $r['author']      = !empty($r['author_user_id']) ? $users->find((int) $r['author_user_id']) : null;
            $r['last_poster'] = !empty($r['last_poster_id']) ? $users->find((int) $r['last_poster_id']) : null;
        }
        unset($r);
        return $rows;
    }

    /**
     * Latest threads by last activity. Enriched like hotTopics for templates.
     *
     * @return list<array<string,mixed>>
     */
    private function latestThreads(int $limit = 5): array
    {
        $rows = $this->statsService()->latestThreads($limit);
        if ($rows === []) {
            return [];
        }
        $users = $this->userDirectory();
        $userIds = [];
        foreach ($rows as $r) {
            if (!empty($r['author_user_id'])) {
                $userIds[] = (int) $r['author_user_id'];
            }
            if (!empty($r['last_poster_id'])) {
                $userIds[] = (int) $r['last_poster_id'];
            }
        }
        $users->preload(array_values(array_unique($userIds)));
        foreach ($rows as &$r) {
            $r['author']      = !empty($r['author_user_id']) ? $users->find((int) $r['author_user_id']) : null;
            $r['last_poster'] = !empty($r['last_poster_id']) ? $users->find((int) $r['last_poster_id']) : null;
        }
        unset($r);
        return $rows;
    }

    private function statsService(): StatsService
    {
        if ($this->stats === null) {
            $this->stats = new StatsService($this->pdo, new SettingsRepository($this->pdo));
        }
        return $this->stats;
    }

    private function userDirectory(): UserDirectory
    {
        if ($this->users === null) {
            $this->users = new UserDirectory($this->pdo);
        }
        return $this->users;
    }

    /**
     * Lightweight "2 minutes ago" / "3h ago" / "Yesterday" / actual date
     * formatter. Accepts SQL timestamps as well as numeric epoch values.
     */
    private function relativeTime(mixed $value): string
    {
        if ($value === null || $value === '' || $value === false) {
            return '';
        }
        if (is_numeric($value)) {
            $ts = (int) $value;
        } else {
            $ts = strtotime((string) $value) ?: 0;
        }
        if ($ts <= 0) {
            return '';
        }
        $diff = time() - $ts;
        if ($diff < 0) {
            return date('j M Y, H:i', $ts);
        }
        if ($diff < 60) {
            return 'just now';
        }
        if ($diff < 3600) {
            $m = (int) floor($diff / 60);
            return $m . ' min' . ($m === 1 ? '' : 's') . ' ago';
        }
        if ($diff < 86400) {
            $h = (int) floor($diff / 3600);
            return $h . ' hour' . ($h === 1 ? '' : 's') . ' ago';
        }
        if ($diff < 86400 * 7) {
            $d = (int) floor($diff / 86400);
            return $d . ' day' . ($d === 1 ? '' : 's') . ' ago';
        }
        return date('j M Y', $ts);
    }

    /**
     * URL helper for the plugin's static assets, mirroring the pattern
     * used by the other plugins (acc_asset, hma_asset, a2n_asset).
     * Appends ?v=<filemtime> for cache busting.
     */
    private function asset(string $relativePath): string
    {
        $clean = ltrim($relativePath, '/');
        $base = '/plugins/forum-plugin/assets/';
        $diskPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $clean);
        $mtime = @filemtime($diskPath);
        $bust = $mtime !== false ? ('?v=' . $mtime) : '';
        return $base . $clean . $bust;
    }
}
