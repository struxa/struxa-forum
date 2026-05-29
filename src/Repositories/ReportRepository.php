<?php

declare(strict_types=1);

namespace ForumPlugin\Repositories;

use PDO;

/**
 * Moderation reports queue.
 *
 * One row per (reporter_user_id, post_id) — re-reporting the same post
 * upserts the row (refreshes reason + reopens if previously resolved).
 *
 * The admin queue groups multiple reports against the same post in the
 * view layer; here we return raw rows to keep the repo simple.
 */
final class ReportRepository
{
    /** Allowed reason keys (the UI surfaces friendlier labels). */
    public const REASONS = [
        'spam'         => 'Spam or advertising',
        'harassment'   => 'Harassment or hate',
        'off_topic'    => 'Off-topic / wrong forum',
        'misleading'   => 'Misleading or incorrect info',
        'illegal'      => 'Illegal content',
        'other'        => 'Other',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Create or refresh a report. If the same reporter already flagged
     * the post, we update their reason + bump the row back to open.
     */
    public function create(int $postId, int $threadId, int $reporterUserId, string $reasonKey, string $reasonText = ''): int
    {
        if (!array_key_exists($reasonKey, self::REASONS)) {
            $reasonKey = 'other';
        }
        $reasonText = trim($reasonText);
        if (mb_strlen($reasonText) > 500) {
            $reasonText = mb_substr($reasonText, 0, 500);
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO forum_reports
                (post_id, thread_id, reporter_user_id, reason_key, reason_text, status)
             VALUES (?, ?, ?, ?, ?, "open")
             ON DUPLICATE KEY UPDATE
                reason_key = VALUES(reason_key),
                reason_text = VALUES(reason_text),
                status = "open",
                resolved_at = NULL,
                resolved_by_user_id = NULL,
                resolution_note = NULL,
                updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([$postId, $threadId, $reporterUserId, $reasonKey, $reasonText !== '' ? $reasonText : null]);
        $id = (int) $this->pdo->lastInsertId();
        if ($id === 0) {
            // Upsert path — fetch the existing row id.
            $sel = $this->pdo->prepare('SELECT id FROM forum_reports WHERE reporter_user_id = ? AND post_id = ? LIMIT 1');
            $sel->execute([$reporterUserId, $postId]);
            $id = (int) ($sel->fetchColumn() ?: 0);
        }
        return $id;
    }

    /**
     * Has this user already filed a report against this post (and is
     * the report still open)? Used by the UI to swap "Report" for
     * "Reported" so the button doesn't look stuck.
     */
    public function userHasOpenReport(int $postId, int $reporterUserId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM forum_reports WHERE post_id = ? AND reporter_user_id = ? AND status = "open" LIMIT 1'
        );
        $stmt->execute([$postId, $reporterUserId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Admin queue. Groups one row per post but surfaces the report
     * count so moderators can prioritise heavily-flagged content.
     *
     * @param array{status?:string, q?:string, reason?:string} $filter
     * @return list<array<string, mixed>>
     */
    public function listForAdmin(array $filter = [], int $limit = 50, int $offset = 0): array
    {
        [$whereSql, $bind] = $this->buildAdminWhere($filter);
        $sql = 'SELECT r.id, r.post_id, r.thread_id, r.reporter_user_id,
                       r.reason_key, r.reason_text, r.status,
                       r.resolved_at, r.resolved_by_user_id, r.resolution_note,
                       r.created_at, r.updated_at,
                       t.title  AS thread_title, t.slug  AS thread_slug,
                       f.name   AS forum_name,   f.slug  AS forum_slug,
                       p.author_user_id AS post_author_id,
                       p.is_first_post,
                       p.body_markdown AS post_body
                  FROM forum_reports r
             LEFT JOIN forum_posts   p ON p.id = r.post_id
             LEFT JOIN forum_threads t ON t.id = r.thread_id
             LEFT JOIN forum_forums  f ON f.id = t.forum_id'
                . $whereSql
                . ' ORDER BY r.status = "open" DESC, r.created_at DESC
                    LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bind);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Count rows matching the same filter shape as listForAdmin. Used by
     * the admin queue to render numbered pagination.
     *
     * @param array{status?:string, q?:string, reason?:string} $filter
     */
    public function countForAdmin(array $filter = []): int
    {
        [$whereSql, $bind] = $this->buildAdminWhere($filter);
        $sql = 'SELECT COUNT(*)
                  FROM forum_reports r
             LEFT JOIN forum_posts   p ON p.id = r.post_id
             LEFT JOIN forum_threads t ON t.id = r.thread_id
             LEFT JOIN forum_forums  f ON f.id = t.forum_id'
                . $whereSql;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bind);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Shared WHERE builder for listForAdmin / countForAdmin. Returns the
     * full ' WHERE …' clause (empty string when no filters apply) plus
     * the bind list, so the two callers can share clamps and LIKE
     * escaping rules.
     *
     * @param array{status?:string, q?:string, reason?:string} $filter
     * @return array{0:string,1:list<scalar>}
     */
    private function buildAdminWhere(array $filter): array
    {
        $status = (string) ($filter['status'] ?? 'open');
        if (!in_array($status, ['open', 'resolved', 'dismissed', 'all'], true)) {
            $status = 'open';
        }

        $reason = (string) ($filter['reason'] ?? '');
        if ($reason !== '' && !array_key_exists($reason, self::REASONS)) {
            $reason = '';
        }

        // Clamp `q` to a sensible length so admins can't accidentally
        // (or maliciously) inflate the LIKE term to multi-KB. Also
        // escape LIKE wildcards so a user searching for `100%` doesn't
        // turn into a full-table scan.
        $q = mb_substr(trim((string) ($filter['q'] ?? '')), 0, 80);

        $where = [];
        $bind = [];

        if ($status !== 'all') {
            $where[] = 'r.status = ?';
            $bind[] = $status;
        }
        if ($reason !== '') {
            $where[] = 'r.reason_key = ?';
            $bind[] = $reason;
        }
        if ($q !== '') {
            $escaped = addcslashes($q, '%_\\');
            $where[] = '(t.title LIKE ? OR r.reason_text LIKE ?)';
            $bind[] = '%' . $escaped . '%';
            $bind[] = '%' . $escaped . '%';
        }

        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $bind];
    }

    /** @return array{open:int, resolved:int, dismissed:int} */
    public function counts(): array
    {
        $stmt = $this->pdo->query(
            'SELECT status, COUNT(*) AS n FROM forum_reports GROUP BY status'
        );
        $out = ['open' => 0, 'resolved' => 0, 'dismissed' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $key = (string) $row['status'];
            if (isset($out[$key])) {
                $out[$key] = (int) $row['n'];
            }
        }
        return $out;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM forum_reports WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Resolve or dismiss a report. `$status` must be one of
     * 'resolved' / 'dismissed' / 'open' (the last to reopen if needed).
     */
    public function setStatus(int $id, string $status, int $resolverUserId, string $note = ''): void
    {
        if (!in_array($status, ['open', 'resolved', 'dismissed'], true)) {
            return;
        }
        $note = trim($note);
        if (mb_strlen($note) > 500) {
            $note = mb_substr($note, 0, 500);
        }
        if ($status === 'open') {
            $this->pdo->prepare(
                'UPDATE forum_reports
                    SET status = "open",
                        resolved_at = NULL,
                        resolved_by_user_id = NULL,
                        resolution_note = NULL,
                        updated_at = CURRENT_TIMESTAMP
                  WHERE id = ?'
            )->execute([$id]);
            return;
        }
        $this->pdo->prepare(
            'UPDATE forum_reports
                SET status = ?,
                    resolved_at = CURRENT_TIMESTAMP,
                    resolved_by_user_id = ?,
                    resolution_note = ?,
                    updated_at = CURRENT_TIMESTAMP
              WHERE id = ?'
        )->execute([$status, $resolverUserId, $note !== '' ? $note : null, $id]);
    }

    /** Used after a post is hard-deleted so the queue doesn't show orphans. */
    public function deleteForPost(int $postId): void
    {
        $this->pdo->prepare('DELETE FROM forum_reports WHERE post_id = ?')->execute([$postId]);
    }
}
