<?php

declare(strict_types=1);

namespace ForumPlugin;

use PDO;

/**
 * Slug helpers shared across the plugin (threads, forums, categories).
 *
 * - `slugify()` is the deterministic part: lowercase, ASCII transliteration,
 *   collapse to dashes.
 * - `uniqueSlug()` adds a numeric suffix when the candidate already exists
 *   in the target table.
 */
final class SlugGenerator
{
    public static function slugify(string $input): string
    {
        $s = (string) $input;
        // Best-effort transliteration if iconv is available.
        $tr = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if (is_string($tr) && $tr !== '') {
            $s = $tr;
        }
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $s = trim($s, '-');
        if ($s === '') {
            // Fall back to a short timestamp suffix so we never write an
            // empty slug.
            $s = 'item-' . substr((string) microtime(true), -5);
        }
        return mb_substr($s, 0, 150);
    }

    /**
     * Produce a slug guaranteed unique within `$table.$column`. If the
     * raw slug is taken, append `-2`, `-3`, … until we find a free one.
     * `$ignoreId` lets edit forms keep their current slug without
     * tripping the uniqueness check.
     */
    public static function uniqueSlug(PDO $pdo, string $table, string $column, string $candidate, ?int $ignoreId = null): string
    {
        $base = self::slugify($candidate);
        $tableSafe = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?? $table;
        $columnSafe = preg_replace('/[^a-zA-Z0-9_]/', '', $column) ?? $column;
        $sql = "SELECT id FROM `{$tableSafe}` WHERE `{$columnSafe}` = ? AND (? IS NULL OR id <> ?) LIMIT 1";

        $candidate = $base;
        $suffix = 1;
        $stmt = $pdo->prepare($sql);
        while (true) {
            $stmt->execute([$candidate, $ignoreId, $ignoreId]);
            if ($stmt->fetchColumn() === false) {
                return $candidate;
            }
            $suffix++;
            $candidate = $base . '-' . $suffix;
        }
    }
}
