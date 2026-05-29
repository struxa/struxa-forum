<?php

declare(strict_types=1);

namespace ForumPlugin\Repositories;

use PDO;

/**
 * Thread "watch" subscriptions. Single composite-PK row per (user, thread).
 *
 * v1 just tracks the relationship — there's no background mailer. The
 * data is there so a future plugin / scheduled job can read it and
 * push notifications (email, webhook, etc.).
 */
final class SubscriptionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function isSubscribed(int $userId, int $threadId): bool
    {
        if ($userId <= 0 || $threadId <= 0) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM forum_subscriptions WHERE user_id = ? AND thread_id = ? LIMIT 1'
        );
        $stmt->execute([$userId, $threadId]);
        return $stmt->fetchColumn() !== false;
    }

    /** Toggle the subscription. Returns `true` if subscribed afterwards. */
    public function toggle(int $userId, int $threadId): bool
    {
        if ($this->isSubscribed($userId, $threadId)) {
            $this->pdo->prepare('DELETE FROM forum_subscriptions WHERE user_id = ? AND thread_id = ?')
                ->execute([$userId, $threadId]);
            return false;
        }
        $this->pdo->prepare('INSERT IGNORE INTO forum_subscriptions (user_id, thread_id) VALUES (?, ?)')
            ->execute([$userId, $threadId]);
        return true;
    }

    /** Subscriber count for a thread — surfaced in the thread header. */
    public function countForThread(int $threadId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM forum_subscriptions WHERE thread_id = ?');
        $stmt->execute([$threadId]);
        return (int) $stmt->fetchColumn();
    }
}
