<?php
/**
 * One-off seeder for forum test data.
 *
 * Run inside the app container:
 *   docker compose exec -T app php /var/www/html/plugins/forum-plugin/scripts/seed-test-data.php
 *
 * Idempotent: re-running skips threads whose slug already exists.
 * Drops new test data with realistic created_at timestamps so the
 * "recent activity" widget on the dashboard looks lived-in.
 */

declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';

// Register the plugin's PSR-4 namespace manually — this seeder runs
// outside the CMS lifecycle so the PluginManager hasn't wired it yet.
spl_autoload_register(static function (string $class): void {
    $prefix = 'ForumPlugin\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
    $path = '/var/www/html/plugins/forum-plugin/src/' . $rel . '.php';
    if (is_file($path)) {
        require $path;
    }
});

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST'), getenv('DB_PORT'), getenv('DB_NAME')),
    getenv('DB_USER'),
    getenv('DB_PASS'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// Resolve user ids by display name.
$users = [];
foreach ($pdo->query('SELECT id, display_name FROM cms_users')->fetchAll() as $u) {
    $users[(string) $u['display_name']] = (int) $u['id'];
}
foreach (['Admin','SophieK','JamesWalker','PriyaJ','TomF','LottieLondon'] as $n) {
    if (!isset($users[$n])) {
        fwrite(STDERR, "Missing user: $n — run the SQL seeder first.\n");
        exit(1);
    }
}

// Resolve forum ids by slug.
$forums = [];
foreach ($pdo->query('SELECT id, slug FROM forum_forums')->fetchAll() as $f) {
    $forums[(string) $f['slug']] = (int) $f['id'];
}

$markdown = new ForumPlugin\MarkdownRenderer();
$threads  = new ForumPlugin\Repositories\ThreadRepository($pdo, $markdown);
$posts    = new ForumPlugin\Repositories\PostRepository($pdo, $markdown, $threads);

/**
 * Helper: backdate a thread's OP + first post + last-post pointers so
 * the timeline looks realistic. createThread() always stamps
 * CURRENT_TIMESTAMP; we rewrite the timestamps after the fact.
 */
$backdate = static function (PDO $pdo, int $threadId, string $createdAt): void {
    $pdo->prepare(
        'UPDATE forum_threads SET created_at = ?, last_post_at = ?, updated_at = ? WHERE id = ?'
    )->execute([$createdAt, $createdAt, $createdAt, $threadId]);
    $pdo->prepare(
        'UPDATE forum_posts SET created_at = ?, updated_at = ? WHERE thread_id = ? AND is_first_post = 1'
    )->execute([$createdAt, $createdAt, $threadId]);
};

/**
 * Helper: backdate a single reply and bump the parent thread's
 * last_post_at to match (so listings show the right timestamp).
 */
$backdateReply = static function (PDO $pdo, int $postId, string $createdAt): void {
    $pdo->prepare('UPDATE forum_posts SET created_at = ?, updated_at = ? WHERE id = ?')
        ->execute([$createdAt, $createdAt, $postId]);
    $stmt = $pdo->prepare('SELECT thread_id, forum_id FROM forum_posts WHERE id = ?');
    $stmt->execute([$postId]);
    $row = $stmt->fetch();
    if ($row !== false) {
        $pdo->prepare('UPDATE forum_threads SET last_post_at = ?, updated_at = ? WHERE id = ?')
            ->execute([$createdAt, $createdAt, (int) $row['thread_id']]);
        $pdo->prepare('UPDATE forum_forums SET last_post_at = ?, updated_at = ? WHERE id = ?')
            ->execute([$createdAt, $createdAt, (int) $row['forum_id']]);
    }
};

/**
 * Slug existence check so we can re-run the seeder safely.
 */
$slugExists = static function (PDO $pdo, string $slug): bool {
    $s = $pdo->prepare('SELECT 1 FROM forum_threads WHERE slug = ? LIMIT 1');
    $s->execute([$slug]);
    return $s->fetchColumn() !== false;
};

/* =====================================================================
 * Seed data: each thread has author, forum, title, body, replies array,
 * and a relative offset in days so the timeline looks plausible.
 * ===================================================================== */

$seed = [
    [
        'forum'  => 'announcements',
        'author' => 'Admin',
        'days_ago' => 45,
        'sticky' => true,
        'title'  => 'Welcome to the FL350 forum — please read first',
        'body'   => <<<MD
Hello and welcome! 👋

This is the brand new FL350 community forum — a place to chat about Avios, redemptions, trip reports, credit cards and all things travel-loyalty.

**A few ground rules:**

- Be kind. Treat others how you'd want to be treated.
- No spam, no shilling, no referral-link drops outside the Earning forum.
- Search before you ask — chances are someone has already covered it.
- Tag your trip reports with the cabin (e.g. _Club World_) so they're easy to find.

If you spot a problem, hit the report button or DM a mod. Pinned threads in each forum cover the FAQ.

Happy posting,
**The FL350 team**
MD,
        'replies' => [
            ['JamesWalker', 1, 'Welcome page is great. Looking forward to it!'],
            ['SophieK', 2, "Hello everyone! Glad this place exists — I've been waiting for a proper UK-focused Avios community."],
            ['PriyaJ', 5, 'Just signed up. Quick q — is there a separate forum for award booking help, or does that go in **Help & support**?'],
        ],
    ],
    [
        'forum'  => 'introductions',
        'author' => 'SophieK',
        'days_ago' => 30,
        'title'  => 'Hi from Manchester — long-time lurker, first-time poster',
        'body'   => <<<MD
Been collecting Avios for about 8 years now, mostly through BA's Amex and the occasional shopping portal. Favourite redemption to date: 47k Avios + £550 LHR–JFK in Club World, booked 11 months out.

Currently sitting on ~280k. Saving for two First seats to Tokyo for our anniversary in 2027. Anyone done LHR–HND in F recently?

Cheers,
Sophie
MD,
        'replies' => [
            ['TomF', 1, "HND in First is *the* one. I did it two years ago — service was incredible. Make sure to use Pyjamas, lounge, all of it."],
            ['LottieLondon', 3, "Welcome Sophie! That's a great stash. Tokyo is amazing."],
        ],
    ],
    [
        'forum'  => 'introductions',
        'author' => 'TomF',
        'days_ago' => 20,
        'title'  => 'New here — Bristol-based, mostly EU short-hauls',
        'body'   => <<<MD
Hello all 👋

Lower-stakes Avios collector here — about 60k a year, mostly through the BAPP card and occasional Tesco transfers. Use them almost exclusively for short-hauls (Faro, Krakow, Athens) because the redemption rates on those flights still feel like a steal.

Looking forward to learning from this lot.
MD,
        'replies' => [],
    ],
    [
        'forum'  => 'redemptions',
        'author' => 'JamesWalker',
        'days_ago' => 14,
        'title'  => 'Trip report: 4 nights in Lisbon for 26k Avios + £62 return (Club Europe)',
        'body'   => <<<MD
Just got back from Lisbon and thought I'd share since this was a banger of a redemption.

**The booking:**

- LHR–LIS outbound: 13,000 Avios + £31
- LIS–LHR return:   13,000 Avios + £31
- Both legs in **Club Europe** off-peak

> Total: 26,000 Avios + £62 for two short-hauls in business class.

A few takeaways:

1. Lisbon-bound morning flights had _loads_ of Club availability when I looked, even inside 30 days.
2. The Heathrow Galleries North lounge before the flight is genuinely good — try the rib-eye if it's on.
3. Lisbon airport at 6am on a weekday is a horror show. Get there earlier than you think.

Happy to answer questions on the booking process if it helps anyone planning the same.
MD,
        'replies' => [
            ['SophieK', 1, "That's a fantastic redemption — 13k off-peak in CE is *exactly* what those points are for. Did you book exactly 355 days out or inside the window?"],
            ['JamesWalker', 1, "Inside the window — about 4 weeks before departure. Availability was way better than I expected."],
            ['PriyaJ', 3, "Bookmarking this. Trying to talk my partner into Lisbon for our anniversary and a CE redemption would seal it."],
            ['Admin', 5, "Pinned this one to the homepage redemptions widget — great write-up."],
        ],
    ],
    [
        'forum'  => 'redemptions',
        'author' => 'PriyaJ',
        'days_ago' => 7,
        'title'  => 'Help me decide: 80k for Doha in J vs 50k for Madrid in F (BA First short-haul)',
        'body'   => <<<MD
Stuck between two redemptions and would love a sanity check.

**Option A** — LHR–DOH return in Club World: 80,000 Avios + £450  
**Option B** — LHR–MAD return in BA First (yes, _short-haul First_): 50,000 Avios + £80

I know option A is technically better value per mile, but Madrid in F sounds like a lot of fun for the points. Do they actually distinguish F service on short-hauls or is it just lounge access + a slightly bigger seat?

Thoughts?
MD,
        'replies' => [
            ['JamesWalker', 1, "Short-haul F is basically Club Europe + Concorde Room access at LHR. The seat is the same as CE. So unless the lounge is the main draw, Doha is the better experience."],
            ['LottieLondon', 1, "Concorde Room is genuinely worth experiencing once though. I'd do Madrid F as a bucket-list thing, then save Doha for when you have a partner to take."],
            ['TomF', 2, "Big agree with James — Madrid F is mostly lounge value. If you've already done CCR, take Doha."],
        ],
    ],
    [
        'forum'  => 'redemptions',
        'author' => 'LottieLondon',
        'days_ago' => 3,
        'title'  => 'Avios redemption sweet spot for Caribbean — anyone done UVF or BGI recently?',
        'body'   => <<<MD
Looking at St Lucia (UVF) or Barbados (BGI) for next March. Off-peak Club World looks like ~75k each way which feels reasonable.

Anyone flown either route recently? Curious if availability tends to open at +355 or trickles in later.
MD,
        'replies' => [],
    ],
    [
        'forum'  => 'earning',
        'author' => 'TomF',
        'days_ago' => 12,
        'title'  => 'Tesco Clubcard → Avios transfer rate — best move right now?',
        'body'   => <<<MD
Quick one — sitting on £55 of Clubcard vouchers and unsure whether to:

a) Transfer to Avios at 2.5×
b) Hold for a Reward Partner promotion (sometimes 3× for restaurants etc.)
c) Use them on groceries and treat the cash savings as the "redemption"

Anyone tracking the promo cycles on this?
MD,
        'replies' => [
            ['SophieK', 1, "Promos have been weaker the last 6 months — fewer 3× offers. I'd transfer now unless you have a specific dining plan."],
            ['PriyaJ', 2, "Agreed with Sophie. Transferred mine last month at 2.5×."],
        ],
    ],
    [
        'forum'  => 'earning',
        'author' => 'JamesWalker',
        'days_ago' => 5,
        'title'  => 'BAPP companion voucher — what counts as the qualifying spend in 2026?',
        'body'   => <<<MD
Trying to confirm what HSBC/Amex are counting toward the £15k spend this year. Specifically:

1. Are Amazon purchases via the Amex Offers portal in?
2. Refunds — do they net off the spend or just the points?
3. Manufactured spend (council tax via Curve etc.) — anyone tested in 2026?

I'm at £11.4k with 4 months to go, so I want to avoid any last-minute surprises.
MD,
        'replies' => [
            ['LottieLondon', 1, "Refunds net off both the spend AND the points — confirmed by Amex when I asked in chat last month."],
        ],
    ],
    [
        'forum'  => 'help',
        'author' => 'PriyaJ',
        'days_ago' => 2,
        'title'  => 'Award seat disappeared between adding to basket and payment — refresh trick?',
        'body'   => <<<MD
Frustrating one — found a Club World seat to Singapore, added it to the basket, started entering passenger details... and by the time I hit "pay" it was gone.

Is there a known refresh trick or shortcut to lock the seat while I fill in details? Or is this just the cost of doing business with ba.com?
MD,
        'replies' => [
            ['Admin', 0, "It's a known annoyance. Best bet: have all passenger details (passport numbers, APIs etc.) pre-saved in your Executive Club profile so the checkout is as fast as possible. Some users swear by opening the seat in two browsers and racing."],
            ['JamesWalker', 1, "Two-browser trick works for me about 70% of the time. The other 30% I'm cursing the gods."],
        ],
    ],
    [
        'forum'  => 'general-chat',
        'author' => 'LottieLondon',
        'days_ago' => 1,
        'title'  => 'Anyone else watching the BA 787 grounding stories? Worried about a March trip',
        'body'   => <<<MD
Saw the news about the Rolls-Royce engine issues spreading and BA having to swap a few 787 routings to 777s.

I've got a Club World 787 booking to Bangalore in March. Anyone tracking this closely? Wondering if I should keep an eye on equipment changes or just trust it'll sort itself out by then.
MD,
        'replies' => [
            ['TomF', 0, "Following too — same plane to Hyderabad next month."],
        ],
    ],
];

$created = 0;
$skipped = 0;

foreach ($seed as $entry) {
    $authorId = $users[$entry['author']];
    $forumId  = $forums[$entry['forum']];

    // Use the title to predict the slug. We compute it the same way
    // SlugGenerator::slugify() does so we can check-then-skip.
    $predictedSlug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $entry['title']));
    $predictedSlug = trim($predictedSlug, '-');
    if ($slugExists($pdo, $predictedSlug)) {
        $skipped++;
        echo "  (skipped) {$entry['title']}\n";
        continue;
    }

    $threadId = $threads->createThread([
        'forum_id'       => $forumId,
        'title'          => $entry['title'],
        'body_markdown'  => $entry['body'],
        'author_user_id' => $authorId,
    ]);

    $threadCreatedAt = date('Y-m-d H:i:s', strtotime("-{$entry['days_ago']} days +" . random_int(1, 18) . ' hours'));
    $backdate($pdo, $threadId, $threadCreatedAt);

    if (!empty($entry['sticky'])) {
        $threads->setFlags($threadId, ['is_sticky' => 1]);
    }

    $replyDayOffset = $entry['days_ago'];
    foreach (($entry['replies'] ?? []) as [$rAuthor, $rDelayDays, $rBody]) {
        $replyDayOffset = max(0, $replyDayOffset - $rDelayDays);
        $replyId = $posts->reply($threadId, $forumId, $users[$rAuthor], $rBody);
        $replyAt = date('Y-m-d H:i:s', strtotime("-{$replyDayOffset} days +" . random_int(1, 22) . ' hours'));
        $backdateReply($pdo, $replyId, $replyAt);
    }

    // Sprinkle a few likes for variety. ToggleLike updates likes_count.
    if (!empty($entry['replies'])) {
        // Like the first reply from a different user.
        $likers = array_diff([$users['SophieK'], $users['JamesWalker'], $users['PriyaJ']], [$authorId]);
        $likeTarget = $pdo->prepare('SELECT id FROM forum_posts WHERE thread_id = ? AND is_first_post = 0 ORDER BY id LIMIT 1');
        $likeTarget->execute([$threadId]);
        $likePostId = (int) ($likeTarget->fetchColumn() ?: 0);
        if ($likePostId > 0) {
            foreach (array_slice(array_values($likers), 0, random_int(1, count($likers))) as $liker) {
                $posts->toggleLike($likePostId, $liker);
            }
        }
    }

    // Refresh counters & forum pointer after backdating timestamps.
    $threads->refreshAround($threadId);

    $created++;
    echo "  ✓ {$entry['title']}\n";
}

// Re-derive per-user post counts from forum_posts so leaderboards / ranks
// reflect the backdated seed data accurately.
$pdo->exec(
    'UPDATE forum_user_activity a
       LEFT JOIN (
         SELECT author_user_id, COUNT(*) AS n
           FROM forum_posts
          WHERE is_deleted = 0 AND author_user_id IS NOT NULL
          GROUP BY author_user_id
       ) p ON p.author_user_id = a.user_id
        SET a.posts_count = COALESCE(p.n, 0)'
);

echo "\nSeed complete: $created threads created, $skipped already existed.\n";
