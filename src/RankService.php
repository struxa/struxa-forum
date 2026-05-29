<?php

declare(strict_types=1);

namespace ForumPlugin;

/**
 * Maps a user's post count onto a "rank" badge — the MyBB convention
 * (Newbie / Junior Member / Member / Senior Member / Veteran).
 *
 * Each tier is a (slug, label, min_posts) tuple. We keep the list small
 * and well-spaced so a user's progression feels meaningful without
 * forcing the table to grow into hundreds of rows.
 */
final class RankService
{
    /**
     * Ordered low → high. Always include a 0-post baseline so a brand
     * new user has a rank to display.
     *
     * @var list<array{slug:string,label:string,min_posts:int,colour:string}>
     */
    public const RANKS = [
        ['slug' => 'newbie',    'label' => 'Newbie',         'min_posts' => 0,    'colour' => '#94a3b8'],
        ['slug' => 'junior',    'label' => 'Junior Member',  'min_posts' => 10,   'colour' => '#22c55e'],
        ['slug' => 'member',    'label' => 'Member',         'min_posts' => 50,   'colour' => '#3b82f6'],
        ['slug' => 'senior',    'label' => 'Senior Member',  'min_posts' => 250,  'colour' => '#a855f7'],
        ['slug' => 'veteran',   'label' => 'Veteran Member', 'min_posts' => 1000, 'colour' => '#f59e0b'],
        ['slug' => 'legend',    'label' => 'Legend',         'min_posts' => 5000, 'colour' => '#ef4444'],
    ];

    /**
     * @return array{slug:string,label:string,min_posts:int,colour:string}
     */
    public static function rankFor(int $posts): array
    {
        $best = self::RANKS[0];
        foreach (self::RANKS as $rank) {
            if ($posts >= $rank['min_posts']) {
                $best = $rank;
            }
        }
        return $best;
    }

    /**
     * Returns the next-up rank a user could earn, or null if they're
     * already at the top. Useful for "X posts until next rank" UI.
     *
     * @return array{slug:string,label:string,min_posts:int,colour:string}|null
     */
    public static function nextRankFor(int $posts): ?array
    {
        foreach (self::RANKS as $rank) {
            if ($posts < $rank['min_posts']) {
                return $rank;
            }
        }
        return null;
    }
}
