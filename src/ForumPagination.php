<?php

declare(strict_types=1);

namespace ForumPlugin;

/**
 * Page-number windows for forum thread / listing pagers (ellipsis gaps).
 */
final class ForumPagination
{
    public static function threadPageCount(int $repliesCount, int $postsPerPage): int
    {
        $postsPerPage = max(1, $postsPerPage);
        $totalPosts = max(1, $repliesCount + 1);

        return (int) max(1, (int) ceil($totalPosts / $postsPerPage));
    }

    /**
     * @return list<array{type: 'page'|'ellipsis', page: int}>
     */
    public static function pageNumbers(int $current, int $total, int $window = 2): array
    {
        if ($total <= 1) {
            return [];
        }
        $current = max(1, min($total, $current));
        $window = max(0, min(4, $window));

        $show = [1, $total, $current];
        for ($i = $current - $window; $i <= $current + $window; ++$i) {
            if ($i >= 1 && $i <= $total) {
                $show[] = $i;
            }
        }
        sort($show);
        $show = array_values(array_unique($show));

        $out = [];
        $prev = 0;
        foreach ($show as $n) {
            if ($prev > 0 && $n > $prev + 1) {
                $out[] = ['type' => 'ellipsis', 'page' => 0];
            }
            $out[] = ['type' => 'page', 'page' => $n];
            $prev = $n;
        }

        return $out;
    }

    public static function pageHref(string $baseUrl, int $page, string $querySuffix = ''): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        $extra = trim($querySuffix, "&? \t\n\r\0\x0B");
        if ($page <= 1) {
            return $extra === '' ? $baseUrl : $baseUrl . '?' . $extra;
        }
        $pageQ = 'page=' . $page;

        return $extra === '' ? $baseUrl . '?' . $pageQ : $baseUrl . '?' . $pageQ . '&' . $extra;
    }
}
