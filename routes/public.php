<?php

declare(strict_types=1);

use App\Flash;
use App\Plugin\PluginBootContext;
use ForumPlugin\MarkdownRenderer;
use ForumPlugin\ProfileService;
use ForumPlugin\RankService;
use ForumPlugin\Repositories\CategoryRepository;
use ForumPlugin\Repositories\ForumRepository;
use ForumPlugin\Repositories\PostRepository;
use ForumPlugin\Repositories\ReportRepository;
use ForumPlugin\Repositories\SettingsRepository;
use ForumPlugin\Repositories\SubscriptionRepository;
use ForumPlugin\Repositories\TagRepository;
use ForumPlugin\Repositories\ThreadRepository;
use ForumPlugin\SearchService;
use ForumPlugin\Security\RateLimitGuard;
use ForumPlugin\Seo\ForumSitemapService;
use ForumPlugin\StatsService;
use ForumPlugin\UserDirectory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use Slim\Routing\RouteContext;

return function (App $app, PluginBootContext $ctx): void {
    $twig = $ctx->twig();
    $pdo  = $ctx->pdo();
    $auth = $ctx->auth();

    /**
     * Sidebar payload: top contributors, popular tags, and optional "me" panel.
     *
     * @return array{top_posters:list<array<string,mixed>>, tags:list<array<string,mixed>>, me:?array<string,mixed>}
     */
    $sidebarData = static function (PDO $pdo, StatsService $stats, UserDirectory $users, TagRepository $tags, ?int $currentUserId = null): array {
        $top = $stats->topPosters(5);
        $users->preload(array_column($top, 'user_id'));
        $topWithUser = [];
        foreach ($top as $row) {
            $u = $users->find((int) $row['user_id']);
            if ($u === null) {
                continue;
            }
            $topWithUser[] = ['posts' => (int) $row['posts'], 'user' => $u];
        }

        $me = null;
        if ($currentUserId !== null) {
            $u = $users->find($currentUserId);
            if ($u !== null) {
                $profileStats = (new ProfileService($pdo, $users))->stats($currentUserId);
                $next = RankService::nextRankFor((int) ($u['posts'] ?? 0));
                $me = [
                    'user'      => $u,
                    'stats'     => $profileStats,
                    'next_rank' => $next,
                ];
            }
        }

        return [
            'top_posters' => $topWithUser,
            'tags'        => $tags->all(24),
            'me'          => $me,
        ];
    };

    /**
     * Bundle every repo + service for a route handler. Cheap: nothing in
     * here touches the DB at construction time.
     *
     * @return array{
     *   markdown:MarkdownRenderer,
     *   categories:CategoryRepository,
     *   forums:ForumRepository,
     *   threads:ThreadRepository,
     *   posts:PostRepository,
     *   subs:SubscriptionRepository,
     *   settings:SettingsRepository,
     *   users:UserDirectory,
     *   stats:StatsService
     * }
     */
    $deps = static function () use ($pdo): array {
        $markdown = new MarkdownRenderer();
        $threads  = new ThreadRepository($pdo, $markdown);
        $settings = new SettingsRepository($pdo);
        $users    = new UserDirectory($pdo);
        return [
            'markdown'   => $markdown,
            'categories' => new CategoryRepository($pdo),
            'forums'     => new ForumRepository($pdo),
            'threads'    => $threads,
            'posts'      => new PostRepository($pdo, $markdown, $threads),
            'subs'       => new SubscriptionRepository($pdo),
            'settings'   => $settings,
            'users'      => $users,
            'stats'      => new StatsService($pdo, $settings),
            'tags'       => new TagRepository($pdo),
            'reports'    => new ReportRepository($pdo),
            'search'     => new SearchService($pdo),
            'profile'    => new ProfileService($pdo, $users),
        ];
    };

    /**
     * Resolve the current logged-in CMS user id (if any) and touch the
     * user's activity row so they count toward online-now stats. Called
     * at the top of every public read handler.
     */
    $currentCmsUserId = static function () use ($auth, $pdo): ?int {
        if (!$auth->isLogged()) {
            return null;
        }
        $uid = (int) $auth->getCurrentUID();
        if ($uid <= 0) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT id FROM cms_users WHERE phpauth_user_id = ? LIMIT 1');
        $stmt->execute([$uid]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    };

    /**
     * Quick "is this user a moderator?" check. Reuses the CMS access
     * pipeline: anyone with the access_admin permission can moderate
     * the forum (delete posts, edit other users' posts, post in
     * locked threads, etc.).
     */
    $isModerator = static function (?int $cmsUserId) use ($pdo): bool {
        if ($cmsUserId === null || $cmsUserId <= 0) {
            return false;
        }
        try {
            // PermissionService::userHasAny() is an instance method, not
            // static. Calling it statically silently throws and the catch
            // below masks it as "not a mod" — every admin was previously
            // being treated as a plain user (no delete button, no lock
            // override, no edit-window bypass).
            return (new \App\Access\PermissionService())
                ->userHasAny($pdo, $cmsUserId, [\App\Access\PermissionSlug::ACCESS_ADMIN]);
        } catch (\Throwable) {
            return false;
        }
    };

    /*
     * Rate-limit buckets (per CMS user). Tiers are [maxHits, windowSec]
     * and are all checked atomically: a request is allowed only if every
     * tier still has headroom, and counters are bumped only when allowed
     * so a blocked request doesn't drain the longer-window quotas.
     *
     * Tuned to be invisible to a human typing/clicking, but lethal to
     * a script:
     *   - reply / new_thread: covers "burst of N within seconds" plus
     *     "sustained N per hour" — a flood-bot trips Tier 1 immediately.
     *   - like:               1/sec sustained is fine; 30 in 30s is
     *     plenty for fast browsing; sustained spam past 90/5min blocks.
     *   - report:             reports should be deliberate; 3/min, 30/h.
     *   - edit / subscribe:   defends against toggle-script abuse.
     *
     * Moderators are skipped (they may legitimately need to act fast
     * across many posts, e.g. mass-deletion of a spam wave).
     */
    $rlBuckets = [
        'reply'      => [[3, 30],  [10, 300], [30, 3600]],
        'new_thread' => [[2, 60],  [5, 600],  [15, 3600]],
        'like'       => [[30, 30], [90, 300]],
        'report'     => [[3, 60],  [10, 600], [30, 3600]],
        'edit'       => [[10, 60], [30, 600]],
        'subscribe'  => [[20, 60]],
    ];
    $rlGuard = new RateLimitGuard(
        dirname(__DIR__, 3) . '/storage/cache/forum_rate_limit'
    );
    $rateLimit = static function (string $bucket, ?int $cmsUserId) use ($rlGuard, $rlBuckets, $isModerator): ?int {
        if ($cmsUserId === null || $cmsUserId <= 0) {
            return null;
        }
        if ($isModerator($cmsUserId)) {
            return null;
        }
        $tiers = $rlBuckets[$bucket] ?? [];
        if ($tiers === []) {
            return null;
        }
        return $rlGuard->checkAndHit($bucket, 'u:' . $cmsUserId, $tiers);
    };

    /*
     * View-counter dedup. Stored in its own filesystem bucket so it doesn't
     * collide with the spam guards. Re-uses RateLimitGuard semantics:
     * "1 hit per VIEW_WINDOW seconds" means a refresh inside the window is
     * silently dropped.
     *
     * Keys are `u:<cmsUserId>` for logged-in viewers and `ip:<sha256>` for
     * anonymous ones — we hash the IP so the on-disk files don't store raw
     * client addresses. The bucket name includes the thread id so each
     * thread gets an independent window per viewer.
     *
     * We also skip the thread's own author so an op can't farm their own
     * view count just by refreshing.
     */
    $viewGuard = new RateLimitGuard(
        dirname(__DIR__, 3) . '/storage/cache/forum_views'
    );
    $recordThreadView = static function (Request $request, array $thread, ?int $cmsUserId) use ($viewGuard): bool {
        $threadId = (int) ($thread['id'] ?? 0);
        if ($threadId <= 0) {
            return false;
        }
        $authorId = (int) ($thread['author_user_id'] ?? 0);
        if ($cmsUserId !== null && $cmsUserId === $authorId) {
            return false;
        }
        if ($cmsUserId !== null && $cmsUserId > 0) {
            $key = 'u:' . $cmsUserId;
        } else {
            try {
                $ip = \App\Http\ClientIp::fromRequest($request);
            } catch (\Throwable) {
                $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
            }
            $key = 'ip:' . substr(hash('sha256', $ip), 0, 32);
        }
        $bucket = 'thread:' . $threadId;
        $tiers = [[1, 1800]];
        return $viewGuard->checkAndHit($bucket, $key, $tiers) === null;
    };

    /**
     * Count words in a forum body. We strip:
     *
     *   - Fenced code blocks ``` … ``` (code is not "writing").
     *   - Inline code (`x`), markdown link syntax around URLs, and
     *     standalone URLs — so a post like "look: https://example.com"
     *     counts as 1 word, not 2.
     *   - Markdown punctuation (`*`, `_`, `>`, `-`, list bullets) that
     *     would otherwise glue onto adjacent tokens.
     *
     * Then we split on any Unicode whitespace run and count non-empty
     * tokens. Returns 0 for blank/null input.
     */
    $countWords = static function (string $body): int {
        $b = trim($body);
        if ($b === '') {
            return 0;
        }
        $b = preg_replace('/```.*?```/s', ' ', $b) ?? $b;
        $b = preg_replace('/`[^`]+`/', ' ', $b) ?? $b;
        $b = preg_replace('/!\[[^\]]*\]\([^)\s]+\)/', ' ', $b) ?? $b;
        $b = preg_replace('/\[([^\]]+)\]\([^)\s]+\)/', '$1', $b) ?? $b;
        $b = preg_replace('~https?://\S+~i', ' ', $b) ?? $b;
        $b = preg_replace('/[#>*_~\-]+/u', ' ', $b) ?? $b;
        $b = preg_replace('/^\s*\d+\.\s+/m', ' ', $b) ?? $b;
        $tokens = preg_split('/[\s\p{Z}]+/u', trim($b), -1, PREG_SPLIT_NO_EMPTY);
        return is_array($tokens) ? count($tokens) : 0;
    };

    // =====================================================================
    // Asset serving (CSS/JS) — mirrors the pattern used by the other
    // plugins: realpath containment + cache-busted via filemtime.
    // =====================================================================
    $app->get(
        '/plugins/forum-plugin/assets/{path:.+}',
        function (Request $request, Response $response, array $args): Response {
            $rel = (string) ($args['path'] ?? '');
            $base = realpath(dirname(__DIR__) . '/assets');
            if ($base === false) {
                return $response->withStatus(404);
            }
            if ($rel === '' || str_contains($rel, "\0") || str_starts_with($rel, '/') || preg_match('#^[a-z]+://#i', $rel)) {
                return $response->withStatus(404);
            }
            $segments = [];
            foreach (explode('/', $rel) as $seg) {
                if ($seg === '' || $seg === '.') {
                    continue;
                }
                if ($seg === '..') {
                    return $response->withStatus(404);
                }
                $segments[] = $seg;
            }
            if ($segments === []) {
                return $response->withStatus(404);
            }
            $target = realpath($base . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments));
            if ($target === false || !is_file($target)) {
                return $response->withStatus(404);
            }
            $baseWithSep = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if (!str_starts_with($target . DIRECTORY_SEPARATOR, $baseWithSep)) {
                return $response->withStatus(404);
            }
            $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
            $mime = match ($ext) {
                'css'        => 'text/css; charset=utf-8',
                'js', 'mjs'  => 'application/javascript; charset=utf-8',
                'json'       => 'application/json; charset=utf-8',
                'svg'        => 'image/svg+xml',
                'png'        => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                default      => 'application/octet-stream',
            };
            parse_str($request->getUri()->getQuery(), $q);
            $hasBust = isset($q['v']) && is_string($q['v']) && ctype_digit($q['v']);
            $cacheControl = $hasBust
                ? 'public, max-age=31536000, immutable'
                : 'public, max-age=300, must-revalidate';
            $response->getBody()->write((string) file_get_contents($target));
            return $response->withHeader('Content-Type', $mime)->withHeader('Cache-Control', $cacheControl);
        }
    )->setName('plugin.forum.assets');

    // =====================================================================
    // Forum sitemap — /forum/sitemap.xml
    //
    // Declared *before* /forum and /forum/{slug} so the more specific path
    // wins the router race. Operators should reference this from their
    // robots.txt (via Settings → robots_txt_custom) so search engines pick
    // up new threads on their next crawl.
    // =====================================================================
    $app->get('/forum/sitemap.xml', function (Request $request, Response $response) use ($pdo, $ctx): Response {
        $globals = $ctx->viewData([]);
        $siteUrl = rtrim((string) ($globals['site_url'] ?? ''), '/');
        $xml = (new ForumSitemapService($pdo))->xml($siteUrl);
        $response->getBody()->write($xml);

        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=600')
            // X-Robots-Tag tells search engines the file itself is meant for
            // them but shouldn't appear in result pages.
            ->withHeader('X-Robots-Tag', 'noindex');
    })->setName('plugin.forum.public.sitemap');

    // =====================================================================
    // Forum index — /forum
    // =====================================================================
    $app->get('/forum', function (Request $request, Response $response) use ($ctx, $twig, $deps, $currentCmsUserId, $isModerator, $sidebarData, $pdo): Response {
        $d = $deps();
        $cmsUserId = $currentCmsUserId();
        if ($cmsUserId !== null) {
            $d['users']->touchActivity($cmsUserId, '/forum');
        }

        $forums = $d['forums']->allWithCategory(false);

        // Group forums under their category for the template.
        $grouped = [];
        foreach ($forums as $f) {
            $cid = (int) $f['category_id'];
            if (!isset($grouped[$cid])) {
                $grouped[$cid] = [
                    'category' => $f['category'],
                    'forums'   => [],
                ];
            }
            // Skip sub-forums in the top-level listing — they're shown
            // as a one-liner under their parent forum row.
            if ($f['parent_id'] === null) {
                $grouped[$cid]['forums'][] = $f;
            }
        }
        // Attach sub-forums by parent_id.
        $byParent = [];
        foreach ($forums as $f) {
            if ($f['parent_id'] !== null) {
                $byParent[(int) $f['parent_id']][] = $f;
            }
        }
        foreach ($grouped as &$g) {
            foreach ($g['forums'] as &$f) {
                $f['subs'] = $byParent[(int) $f['id']] ?? [];
            }
            unset($f);
        }
        unset($g);

        // Decorate forum rows with the last thread's title/slug + last
        // poster id so the template can render a "Last post by X" deep
        // link without issuing one query per forum.
        $userIds = [];
        foreach ($grouped as &$g) {
            foreach ($g['forums'] as &$f) {
                if ($f['last_thread_id'] !== null) {
                    $tstmt = $ctx->pdo()->prepare('SELECT title, slug, last_poster_id FROM forum_threads WHERE id = ?');
                    $tstmt->execute([(int) $f['last_thread_id']]);
                    $trow = $tstmt->fetch(\PDO::FETCH_ASSOC);
                    if ($trow !== false) {
                        $f['last_thread_title'] = (string) $trow['title'];
                        $f['last_thread_slug']  = (string) $trow['slug'];
                        $f['last_poster_id']    = $trow['last_poster_id'] !== null ? (int) $trow['last_poster_id'] : null;
                        if ($f['last_poster_id'] !== null) {
                            $userIds[] = $f['last_poster_id'];
                        }
                    }
                }
            }
            unset($f);
        }
        unset($g);

        $d['users']->preload(array_values(array_unique($userIds)));
        $usersById = [];
        foreach ($userIds as $uid) {
            $u = $d['users']->find($uid);
            if ($u !== null) {
                $usersById[$uid] = $u;
            }
        }

        $stats = $d['stats']->snapshot();
        $sidebar = $sidebarData($pdo, $d['stats'], $d['users'], $d['tags'], $cmsUserId);

        return $twig->render($response, '@plugin_forum_plugin/public/index.twig', $ctx->viewData([
            'page_title'         => 'Forum',
            'meta_description'   => 'Community discussions — browse forums, start threads and join the conversation.',
            'forum_grouped'      => $grouped,
            'forum_stats'        => $stats,
            'forum_logged_in'    => $cmsUserId !== null,
            'forum_is_moderator' => $isModerator($cmsUserId),
            'forum_users_by_id'  => $usersById,
            'forum_sidebar'      => $sidebar,
        ]));
    })->setName('plugin.forum.public.index');

    // =====================================================================
    // Search — /forum/search?q=...&forum_id=...
    //
    // Declared before /forum/{slug} so "search" isn't mistaken for a
    // forum slug. Renders empty-state when q is too short.
    // =====================================================================
    $app->get('/forum/search', function (Request $request, Response $response) use ($ctx, $twig, $deps, $currentCmsUserId, $isModerator, $sidebarData, $pdo): Response {
        $d = $deps();
        $cmsUserId = $currentCmsUserId();
        if ($cmsUserId !== null) {
            $d['users']->touchActivity($cmsUserId, '/forum/search');
        }

        $params = $request->getQueryParams();
        $q       = trim((string) ($params['q'] ?? ''));
        $forumId = (int) ($params['forum_id'] ?? 0);
        $page    = max(1, (int) ($params['page'] ?? 1));
        $perPage = 20;

        $results = [];
        $total = 0;
        if (mb_strlen($q) >= 2) {
            $results = $d['search']->search([
                'q'        => $q,
                'forum_id' => $forumId,
                'limit'    => $perPage,
                'offset'   => ($page - 1) * $perPage,
            ]);
            $total = $d['search']->count(['q' => $q, 'forum_id' => $forumId]);
        }

        // Preload authors / last-posters for the result rows.
        $userIds = [];
        foreach ($results as $r) {
            if (!empty($r['author_user_id']))  { $userIds[] = (int) $r['author_user_id']; }
            if (!empty($r['last_poster_id'])) { $userIds[] = (int) $r['last_poster_id']; }
        }
        $userIds = array_values(array_unique($userIds));
        $d['users']->preload($userIds);
        $usersById = [];
        foreach ($userIds as $uid) {
            $u = $d['users']->find($uid);
            if ($u !== null) { $usersById[$uid] = $u; }
        }

        $sidebar = $sidebarData($pdo, $d['stats'], $d['users'], $d['tags'], $cmsUserId);
        $totalPages = (int) max(1, (int) ceil($total / $perPage));

        return $twig->render($response, '@plugin_forum_plugin/public/search.twig', $ctx->viewData([
            'page_title'        => $q !== '' ? ('Search · ' . $q) : 'Search the forum',
            'meta_description'  => 'Search forum threads and posts.',
            'forum_query'       => $q,
            'forum_query_forum' => $forumId,
            'forum_forums'      => $d['forums']->allForAdmin(),
            'forum_results'     => $results,
            'forum_total'       => $total,
            'forum_page'        => $page,
            'forum_total_pages' => $totalPages,
            'forum_logged_in'   => $cmsUserId !== null,
            'forum_is_moderator'=> $isModerator($cmsUserId),
            'forum_users_by_id' => $usersById,
            'forum_sidebar'     => $sidebar,
        ]));
    })->setName('plugin.forum.public.search');

    // =====================================================================
    // Tag pages — /forum/tag/{slug}
    // =====================================================================
    $app->get('/forum/tag/{slug:[a-z0-9\-]+}', function (Request $request, Response $response, array $args) use ($ctx, $twig, $deps, $currentCmsUserId, $isModerator, $sidebarData, $pdo): Response {
        $d = $deps();
        $cmsUserId = $currentCmsUserId();
        if ($cmsUserId !== null) {
            $d['users']->touchActivity($cmsUserId, '/forum/tag/' . $args['slug']);
        }
        $term = $d['tags']->findBySlug((string) $args['slug']);
        if ($term === null) {
            return $response->withStatus(404);
        }
        $page = max(1, (int) ($request->getQueryParams()['page'] ?? 1));
        $perPage = 30;
        $threads = $d['tags']->threadsForTerm((int) $term['id'], $perPage, ($page - 1) * $perPage);
        $total   = $d['tags']->countThreadsForTerm((int) $term['id']);
        $totalPages = (int) max(1, (int) ceil($total / $perPage));

        $userIds = [];
        foreach ($threads as $t) {
            if (!empty($t['author_user_id'])) { $userIds[] = (int) $t['author_user_id']; }
            if (!empty($t['last_poster_id'])) { $userIds[] = (int) $t['last_poster_id']; }
        }
        $userIds = array_values(array_unique($userIds));
        $d['users']->preload($userIds);
        $usersById = [];
        foreach ($userIds as $uid) {
            $u = $d['users']->find($uid);
            if ($u !== null) { $usersById[$uid] = $u; }
        }

        $sidebar = $sidebarData($pdo, $d['stats'], $d['users'], $d['tags'], $cmsUserId);

        $postsPerPage = max(1, $d['settings']->getInt('posts_per_page', 15));

        return $twig->render($response, '@plugin_forum_plugin/public/tag.twig', $ctx->viewData([
            'page_title'         => 'Tagged "' . $term['name'] . '"',
            'meta_description'   => 'Forum threads tagged ' . $term['name'] . '.',
            'forum_tag'          => $term,
            'forum_threads'      => $threads,
            'forum_total'        => $total,
            'forum_page'         => $page,
            'forum_total_pages'  => $totalPages,
            'forum_posts_per_page' => $postsPerPage,
            'forum_logged_in'    => $cmsUserId !== null,
            'forum_is_moderator' => $isModerator($cmsUserId),
            'forum_users_by_id'  => $usersById,
            'forum_sidebar'      => $sidebar,
        ]));
    })->setName('plugin.forum.public.tag');

    // =====================================================================
    // User profile — /forum/user/{username}
    // Param is normally cms_users.id (digits). Legacy url-encoded display_name
    // URLs still resolve for backwards compatibility.
    // =====================================================================
    $app->get('/forum/user/{username:[A-Za-z0-9_.%\-]+}', function (Request $request, Response $response, array $args) use ($ctx, $twig, $deps, $currentCmsUserId, $isModerator, $sidebarData, $pdo): Response {
        $d = $deps();
        $cmsUserId = $currentCmsUserId();
        $user = $d['profile']->resolve((string) $args['username']);
        if ($user === null) {
            return $response->withStatus(404);
        }
        if ($cmsUserId !== null) {
            $d['users']->touchActivity($cmsUserId, '/forum/user/' . $args['username']);
        }
        $stats   = $d['profile']->stats((int) $user['id']);
        $threads = $d['profile']->recentThreads((int) $user['id'], 10);
        $replies = $d['profile']->recentReplies((int) $user['id'], 10);
        $rank    = \ForumPlugin\RankService::rankFor((int) ($user['posts'] ?? 0));
        $nextRank = \ForumPlugin\RankService::nextRankFor((int) ($user['posts'] ?? 0));

        $sidebar = $sidebarData($pdo, $d['stats'], $d['users'], $d['tags'], $cmsUserId);

        return $twig->render($response, '@plugin_forum_plugin/public/profile.twig', $ctx->viewData([
            'page_title'        => $user['display_name'] . ' · Profile',
            'meta_description'  => $user['display_name'] . "'s forum profile — posts, replies and reputation.",
            'forum_user'        => $user,
            'forum_user_stats'  => $stats,
            'forum_user_rank_struct' => $rank,
            'forum_user_next_rank'   => $nextRank,
            'forum_user_threads'  => $threads,
            'forum_user_replies'  => $replies,
            'forum_logged_in'   => $cmsUserId !== null,
            'forum_is_moderator'=> $isModerator($cmsUserId),
            'forum_sidebar'     => $sidebar,
        ]));
    })->setName('plugin.forum.public.profile');

    // =====================================================================
    // Forum view — /forum/{slug}
    // =====================================================================
    $app->get('/forum/{slug:[a-z0-9\-]+}', function (Request $request, Response $response, array $args) use ($ctx, $twig, $deps, $currentCmsUserId, $isModerator, $sidebarData, $pdo): Response {
        $d = $deps();
        $cmsUserId = $currentCmsUserId();
        if ($cmsUserId !== null) {
            $d['users']->touchActivity($cmsUserId, '/forum/' . $args['slug']);
        }

        $forum = $d['forums']->findBySlug((string) $args['slug']);
        if ($forum === null || (int) $forum['is_hidden'] === 1) {
            return $response->withStatus(404);
        }

        $perPage = max(1, $d['settings']->getInt('threads_per_page', 20));
        $page = max(1, (int) ($request->getQueryParams()['page'] ?? 1));
        $offset = ($page - 1) * $perPage;
        $threads = $d['threads']->listForForum((int) $forum['id'], $perPage, $offset);
        $totalThreads = $d['threads']->countForForum((int) $forum['id']);
        $totalPages = (int) max(1, (int) ceil($totalThreads / $perPage));

        $userIds = [];
        foreach ($threads as $t) {
            if (!empty($t['author_user_id'])) {
                $userIds[] = (int) $t['author_user_id'];
            }
            if (!empty($t['last_poster_id'])) {
                $userIds[] = (int) $t['last_poster_id'];
            }
        }
        $userIds = array_values(array_unique($userIds));
        $d['users']->preload($userIds);
        $usersById = [];
        foreach ($userIds as $uid) {
            $u = $d['users']->find($uid);
            if ($u !== null) {
                $usersById[$uid] = $u;
            }
        }

        $sidebar = $sidebarData($pdo, $d['stats'], $d['users'], $d['tags'], $cmsUserId);

        $postsPerPage = max(1, $d['settings']->getInt('posts_per_page', 15));

        return $twig->render($response, '@plugin_forum_plugin/public/forum.twig', $ctx->viewData([
            'page_title'         => $forum['name'] . ' · Forum',
            'meta_description'   => $forum['description'] ?? 'Discussion in ' . $forum['name'],
            'forum'              => $forum,
            'forum_threads'      => $threads,
            'forum_page'         => $page,
            'forum_total_pages'  => $totalPages,
            'forum_posts_per_page' => $postsPerPage,
            'forum_logged_in'    => $cmsUserId !== null,
            'forum_is_moderator' => $isModerator($cmsUserId),
            'forum_users_by_id'  => $usersById,
            'forum_sidebar'      => $sidebar,
        ]));
    })->setName('plugin.forum.public.forum');

    // =====================================================================
    // New thread form — /forum/{slug}/new
    // =====================================================================
    $app->get('/forum/{slug:[a-z0-9\-]+}/new', function (Request $request, Response $response, array $args) use ($ctx, $twig, $deps, $currentCmsUserId): Response {
        $d = $deps();
        $cmsUserId = $currentCmsUserId();

        $parser = RouteContext::fromRequest($request)->getRouteParser();
        if ($cmsUserId === null) {
            // Bounce to login, remember where we came from.
            Flash::set('error', 'Sign in to start a new thread.');
            $next = '/forum/' . $args['slug'] . '/new';
            return $response->withHeader('Location', $parser->urlFor('login') . '?' . http_build_query(['next' => $next]))->withStatus(302);
        }

        $forum = $d['forums']->findBySlug((string) $args['slug']);
        if ($forum === null || (int) $forum['is_hidden'] === 1) {
            return $response->withStatus(404);
        }
        if ((int) $forum['is_locked'] === 1) {
            Flash::set('error', 'This forum is locked — new threads aren\'t allowed.');
            return $response->withHeader('Location', '/forum/' . $args['slug'])->withStatus(302);
        }

        return $twig->render($response, '@plugin_forum_plugin/public/new-thread.twig', $ctx->viewData([
            'page_title'          => 'New thread in ' . $forum['name'],
            'forum'               => $forum,
            'forum_logged_in'     => true,
            'forum_form'          => ['title' => '', 'body' => '', 'tags' => ''],
            'forum_existing_tags' => $d['tags']->all(60),
            'forum_min_words'     => max(0, $d['settings']->getInt('min_post_words', 15)),
        ]));
    })->setName('plugin.forum.public.new_thread');

    // =====================================================================
    // POST handlers (state-changing actions)
    // =====================================================================

    // Create thread
    $app->post('/forum/post/new', function (Request $request, Response $response) use ($deps, $currentCmsUserId, $rateLimit, $countWords): Response {
        $d = $deps();
        $cmsUserId = $currentCmsUserId();
        $parser = RouteContext::fromRequest($request)->getRouteParser();

        $form = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $forumId = (int) ($form['forum_id'] ?? 0);
        $title   = trim((string) ($form['title'] ?? ''));
        $body    = trim((string) ($form['body'] ?? ''));
        $forum = $d['forums']->find($forumId);
        $forumSlug = $forum !== null ? (string) $forum['slug'] : '';

        if ($cmsUserId === null) {
            Flash::set('error', 'Sign in to start a new thread.');
            return $response->withHeader('Location', $parser->urlFor('login'))->withStatus(302);
        }
        if ($forum === null || (int) $forum['is_hidden'] === 1) {
            return $response->withStatus(404);
        }
        if ((int) $forum['is_locked'] === 1) {
            Flash::set('error', 'This forum is locked.');
            return $response->withHeader('Location', '/forum/' . $forumSlug)->withStatus(302);
        }
        $wait = $rateLimit('new_thread', $cmsUserId);
        if ($wait !== null) {
            Flash::set('error', 'You are starting threads too quickly — please wait ' . $wait . ' second' . ($wait === 1 ? '' : 's') . ' and try again.');
            return $response
                ->withHeader('Location', '/forum/' . $forumSlug . '/new')
                ->withHeader('Retry-After', (string) $wait)
                ->withStatus(302);
        }

        $minTitle = max(1, $d['settings']->getInt('min_thread_title_length', 4));
        $minBody  = max(1, $d['settings']->getInt('min_post_length', 4));
        $minWords = max(0, $d['settings']->getInt('min_post_words', 15));
        if (mb_strlen($title) < $minTitle) {
            Flash::set('error', 'Title needs to be at least ' . $minTitle . ' characters.');
            return $response->withHeader('Location', '/forum/' . $forumSlug . '/new')->withStatus(302);
        }
        if (mb_strlen($body) < $minBody) {
            Flash::set('error', 'Post body needs to be at least ' . $minBody . ' characters.');
            return $response->withHeader('Location', '/forum/' . $forumSlug . '/new')->withStatus(302);
        }
        if ($minWords > 0) {
            $words = $countWords($body);
            if ($words < $minWords) {
                Flash::set('error', sprintf(
                    'Your post is %d word%s — please write at least %d to start a thread.',
                    $words, $words === 1 ? '' : 's', $minWords
                ));
                return $response->withHeader('Location', '/forum/' . $forumSlug . '/new')->withStatus(302);
            }
        }

        $threadId = $d['threads']->createThread([
            'forum_id'       => (int) $forum['id'],
            'title'          => $title,
            'body_markdown'  => $body,
            'author_user_id' => $cmsUserId,
        ]);

        // Persist tags if the form submitted any (comma-separated).
        $thread = $d['threads']->find($threadId);
        if ($thread !== null && !empty($thread['entry_id'])) {
            $tagInput = trim((string) ($form['tags'] ?? ''));
            if ($tagInput !== '') {
                $names = array_filter(array_map('trim', explode(',', $tagInput)));
                $d['tags']->syncForEntry((int) $thread['entry_id'], $names);
            }
        }

        // Auto-subscribe the author so they get pinged on replies.
        if ($thread !== null) {
            $d['subs']->toggle($cmsUserId, $threadId);
            Flash::set('success', 'Thread published.');
            return $response->withHeader('Location', '/forum/' . $forumSlug . '/' . $thread['slug'])->withStatus(302);
        }
        return $response->withHeader('Location', '/forum/' . $forumSlug)->withStatus(302);
    })->setName('plugin.forum.public.create_thread');

    // Reply
    $app->post('/forum/post/reply', function (Request $request, Response $response) use ($deps, $currentCmsUserId, $isModerator, $rateLimit, $countWords): Response {
        $d = $deps();
        $cmsUserId = $currentCmsUserId();
        $parser = RouteContext::fromRequest($request)->getRouteParser();

        $form = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $threadId = (int) ($form['thread_id'] ?? 0);
        $body     = trim((string) ($form['body'] ?? ''));

        if ($cmsUserId === null) {
            Flash::set('error', 'Sign in to reply.');
            return $response->withHeader('Location', $parser->urlFor('login'))->withStatus(302);
        }
        $wait = $rateLimit('reply', $cmsUserId);
        if ($wait !== null) {
            Flash::set('error', 'You are posting a little too quickly — please wait ' . $wait . ' second' . ($wait === 1 ? '' : 's') . ' and try again.');
            $referer = (string) $request->getHeaderLine('Referer');
            $loc = $referer !== '' && str_starts_with($referer, '/') ? $referer : '/forum';
            return $response
                ->withHeader('Location', $loc)
                ->withHeader('Retry-After', (string) $wait)
                ->withStatus(302);
        }
        $thread = $d['threads']->find($threadId);
        if ($thread === null || (int) $thread['is_deleted'] === 1) {
            return $response->withStatus(404);
        }
        $forum = $d['forums']->find((int) $thread['forum_id']);
        if ($forum === null) {
            return $response->withStatus(404);
        }
        if ((int) $thread['is_locked'] === 1 && !$isModerator($cmsUserId)) {
            Flash::set('error', 'This thread is locked.');
            return $response->withHeader('Location', '/forum/' . $forum['slug'] . '/' . $thread['slug'])->withStatus(302);
        }

        $minBody  = max(1, $d['settings']->getInt('min_post_length', 4));
        $minWords = max(0, $d['settings']->getInt('min_post_words', 15));
        if (mb_strlen($body) < $minBody) {
            Flash::set('error', 'Reply needs to be at least ' . $minBody . ' characters.');
            return $response->withHeader('Location', '/forum/' . $forum['slug'] . '/' . $thread['slug'] . '#reply')->withStatus(302);
        }
        if ($minWords > 0) {
            $words = $countWords($body);
            if ($words < $minWords) {
                Flash::set('error', sprintf(
                    'Your reply is %d word%s — please write at least %d before posting.',
                    $words, $words === 1 ? '' : 's', $minWords
                ));
                return $response->withHeader('Location', '/forum/' . $forum['slug'] . '/' . $thread['slug'] . '#reply')->withStatus(302);
            }
        }

        $d['posts']->reply($threadId, (int) $thread['forum_id'], $cmsUserId, $body);
        Flash::set('success', 'Reply posted.');
        return $response->withHeader('Location', '/forum/' . $forum['slug'] . '/' . $thread['slug'] . '#reply')->withStatus(302);
    })->setName('plugin.forum.public.reply');

    // Toggle like
    $app->post('/forum/post/{id:[0-9]+}/like', function (Request $request, Response $response, array $args) use ($deps, $currentCmsUserId, $rateLimit): Response {
        $d = $deps();
        $cmsUserId = $currentCmsUserId();
        if ($cmsUserId === null) {
            return $response->withStatus(401);
        }
        $postId = (int) $args['id'];
        $post = $d['posts']->find($postId);
        if ($post === null || (int) $post['is_deleted'] === 1) {
            return $response->withStatus(404);
        }
        $wait = $rateLimit('like', $cmsUserId);
        if ($wait !== null) {
            $accept = (string) $request->getHeaderLine('Accept');
            if (str_contains($accept, 'application/json')) {
                $response->getBody()->write((string) json_encode([
                    'ok'          => false,
                    'error'       => 'rate_limited',
                    'retry_after' => $wait,
                ]));
                return $response
                    ->withStatus(429)
                    ->withHeader('Retry-After', (string) $wait)
                    ->withHeader('Content-Type', 'application/json');
            }
            Flash::set('error', 'You are clicking a little too quickly — please wait ' . $wait . ' second' . ($wait === 1 ? '' : 's') . '.');
            $referer = (string) $request->getHeaderLine('Referer');
            $loc = $referer !== '' && str_starts_with($referer, '/') ? $referer : '/forum';
            return $response
                ->withHeader('Location', $loc . '#post-' . $postId)
                ->withHeader('Retry-After', (string) $wait)
                ->withStatus(302);
        }
        $liked = $d['posts']->toggleLike($postId, $cmsUserId);

        // If this is a XHR call, return JSON; otherwise bounce back.
        $accept = (string) $request->getHeaderLine('Accept');
        if (str_contains($accept, 'application/json')) {
            $fresh = $d['posts']->find($postId);
            $response->getBody()->write((string) json_encode([
                'ok'    => true,
                'liked' => $liked,
                'count' => $fresh !== null ? (int) $fresh['likes_count'] : 0,
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $referer = (string) $request->getHeaderLine('Referer');
        $loc = $referer !== '' && str_starts_with($referer, '/') ? $referer : '/forum';
        return $response->withHeader('Location', $loc . '#post-' . $postId)->withStatus(302);
    })->setName('plugin.forum.public.like');

    // Toggle subscription
    $app->post('/forum/thread/{id:[0-9]+}/subscribe', function (Request $request, Response $response, array $args) use ($deps, $currentCmsUserId, $rateLimit): Response {
        $d = $deps();
        $cmsUserId = $currentCmsUserId();
        if ($cmsUserId === null) {
            return $response->withStatus(401);
        }
        $threadId = (int) $args['id'];
        $thread = $d['threads']->find($threadId);
        if ($thread === null || (int) $thread['is_deleted'] === 1) {
            return $response->withStatus(404);
        }
        $wait = $rateLimit('subscribe', $cmsUserId);
        if ($wait !== null) {
            Flash::set('error', 'Too many subscribe toggles — please wait ' . $wait . ' second' . ($wait === 1 ? '' : 's') . '.');
            $forum = $d['forums']->find((int) $thread['forum_id']);
            $slug = $forum !== null ? (string) $forum['slug'] : '';
            return $response
                ->withHeader('Location', '/forum/' . $slug . '/' . $thread['slug'])
                ->withHeader('Retry-After', (string) $wait)
                ->withStatus(302);
        }
        $subscribed = $d['subs']->toggle($cmsUserId, $threadId);
        Flash::set('success', $subscribed ? 'Watching this thread.' : 'No longer watching this thread.');
        $forum = $d['forums']->find((int) $thread['forum_id']);
        $slug = $forum !== null ? (string) $forum['slug'] : '';
        return $response->withHeader('Location', '/forum/' . $slug . '/' . $thread['slug'])->withStatus(302);
    })->setName('plugin.forum.public.subscribe');

    // Report a post — adds (or re-opens) a row in forum_reports.
    $app->post('/forum/post/{id:[0-9]+}/report', function (Request $request, Response $response, array $args) use ($deps, $currentCmsUserId, $rateLimit): Response {
        $d = $deps();
        $cmsUserId = $currentCmsUserId();
        if ($cmsUserId === null) {
            Flash::set('error', 'Sign in to report posts.');
            return $response->withHeader('Location', '/login')->withStatus(302);
        }
        $postId = (int) $args['id'];
        $post = $d['posts']->find($postId);
        if ($post === null || (int) $post['is_deleted'] === 1) {
            return $response->withStatus(404);
        }
        $wait = $rateLimit('report', $cmsUserId);
        if ($wait !== null) {
            Flash::set('error', 'You are reporting posts too quickly — please wait ' . $wait . ' second' . ($wait === 1 ? '' : 's') . ' and try again.');
            $referer = (string) $request->getHeaderLine('Referer');
            $loc = $referer !== '' && str_starts_with($referer, '/') ? $referer : '/forum';
            return $response
                ->withHeader('Location', $loc)
                ->withHeader('Retry-After', (string) $wait)
                ->withStatus(302);
        }
        $form = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $reasonKey  = (string) ($form['reason_key'] ?? 'other');
        $reasonText = (string) ($form['reason_text'] ?? '');
        $d['reports']->create($postId, (int) $post['thread_id'], $cmsUserId, $reasonKey, $reasonText);

        Flash::set('success', 'Report received — a moderator will take a look. Thanks for keeping the forum healthy.');

        $thread = $d['threads']->find((int) $post['thread_id']);
        $forum = $thread !== null ? $d['forums']->find((int) $thread['forum_id']) : null;
        $loc = $thread !== null && $forum !== null
            ? '/forum/' . $forum['slug'] . '/' . $thread['slug'] . '#post-' . $postId
            : '/forum';
        return $response->withHeader('Location', $loc)->withStatus(302);
    })->setName('plugin.forum.public.report');

    // Edit post (own post, within edit window — moderators always)
    $app->post('/forum/post/{id:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($deps, $currentCmsUserId, $isModerator, $rateLimit, $countWords): Response {
        $d = $deps();
        $cmsUserId = $currentCmsUserId();
        if ($cmsUserId === null) {
            return $response->withStatus(401);
        }
        $postId = (int) $args['id'];
        $post = $d['posts']->find($postId);
        if ($post === null || (int) $post['is_deleted'] === 1) {
            return $response->withStatus(404);
        }
        $isMod = $isModerator($cmsUserId);
        $isOwner = (int) $post['author_user_id'] === $cmsUserId;

        if (!$isMod && !$isOwner) {
            return $response->withStatus(403);
        }
        $wait = $rateLimit('edit', $cmsUserId);
        if ($wait !== null) {
            Flash::set('error', 'You are editing too quickly — please wait ' . $wait . ' second' . ($wait === 1 ? '' : 's') . '.');
            $forum = $d['forums']->find((int) $post['forum_id']);
            $thread = $d['threads']->find((int) $post['thread_id']);
            $loc = $forum !== null && $thread !== null ? '/forum/' . $forum['slug'] . '/' . $thread['slug'] : '/forum';
            return $response
                ->withHeader('Location', $loc)
                ->withHeader('Retry-After', (string) $wait)
                ->withStatus(302);
        }
        if (!$isMod) {
            $window = $d['settings']->getInt('edit_window_minutes', 15);
            $created = strtotime((string) $post['created_at']) ?: 0;
            if ($window > 0 && (time() - $created) > $window * 60) {
                Flash::set('error', 'The edit window has closed for this post.');
                $f = $d['forums']->find((int) $post['forum_id']);
                $t = $d['threads']->find((int) $post['thread_id']);
                $loc = $f !== null && $t !== null ? '/forum/' . $f['slug'] . '/' . $t['slug'] : '/forum';
                return $response->withHeader('Location', $loc)->withStatus(302);
            }
        }

        $body = trim((string) ($request->getParsedBody()['body'] ?? ''));
        $minBody  = max(1, $d['settings']->getInt('min_post_length', 4));
        $minWords = max(0, $d['settings']->getInt('min_post_words', 15));
        if (mb_strlen($body) < $minBody) {
            Flash::set('error', 'Post needs to be at least ' . $minBody . ' characters.');
            $forum = $d['forums']->find((int) $post['forum_id']);
            $thread = $d['threads']->find((int) $post['thread_id']);
            $slug = $forum !== null && $thread !== null ? '/forum/' . $forum['slug'] . '/' . $thread['slug'] : '/forum';
            return $response->withHeader('Location', $slug)->withStatus(302);
        }
        if ($minWords > 0) {
            $words = $countWords($body);
            if ($words < $minWords) {
                Flash::set('error', sprintf(
                    'Your edit is %d word%s — please write at least %d.',
                    $words, $words === 1 ? '' : 's', $minWords
                ));
                $forum = $d['forums']->find((int) $post['forum_id']);
                $thread = $d['threads']->find((int) $post['thread_id']);
                $slug = $forum !== null && $thread !== null ? '/forum/' . $forum['slug'] . '/' . $thread['slug'] : '/forum';
                return $response->withHeader('Location', $slug)->withStatus(302);
            }
        }
        $d['posts']->edit($postId, $body, $cmsUserId);

        $forum = $d['forums']->find((int) $post['forum_id']);
        $thread = $d['threads']->find((int) $post['thread_id']);
        $loc = $forum !== null && $thread !== null
            ? '/forum/' . $forum['slug'] . '/' . $thread['slug'] . '#post-' . $postId
            : '/forum';
        Flash::set('success', 'Post updated.');
        return $response->withHeader('Location', $loc)->withStatus(302);
    })->setName('plugin.forum.public.edit_post');

    // Delete post (own, or moderator)
    $app->post('/forum/post/{id:[0-9]+}/delete', function (Request $request, Response $response, array $args) use ($deps, $currentCmsUserId, $isModerator): Response {
        $d = $deps();
        $cmsUserId = $currentCmsUserId();
        if ($cmsUserId === null) {
            return $response->withStatus(401);
        }
        $postId = (int) $args['id'];
        $post = $d['posts']->find($postId);
        if ($post === null) {
            return $response->withStatus(404);
        }
        $isMod = $isModerator($cmsUserId);
        $isOwner = (int) $post['author_user_id'] === $cmsUserId;
        if (!$isMod && !$isOwner) {
            return $response->withStatus(403);
        }
        $d['posts']->softDelete($postId);
        Flash::set('success', 'Post deleted.');

        $forum = $d['forums']->find((int) $post['forum_id']);
        $thread = $d['threads']->find((int) $post['thread_id']);
        $loc = $forum !== null && $thread !== null
            ? '/forum/' . $forum['slug'] . '/' . $thread['slug']
            : '/forum';
        return $response->withHeader('Location', $loc)->withStatus(302);
    })->setName('plugin.forum.public.delete_post');

    // =====================================================================
    // Thread view — /forum/{forumSlug}/{threadSlug}
    //
    // Declared last so the more specific POST routes above win in the
    // router's match order.
    // =====================================================================
    $app->get('/forum/{forumSlug:[a-z0-9\-]+}/{threadSlug:[a-z0-9\-]+}', function (Request $request, Response $response, array $args) use ($ctx, $twig, $deps, $currentCmsUserId, $isModerator, $recordThreadView, $sidebarData, $pdo): Response {
        $d = $deps();
        $cmsUserId = $currentCmsUserId();
        if ($cmsUserId !== null) {
            $d['users']->touchActivity($cmsUserId, '/forum/' . $args['forumSlug'] . '/' . $args['threadSlug']);
        }

        $forum = $d['forums']->findBySlug((string) $args['forumSlug']);
        if ($forum === null || (int) $forum['is_hidden'] === 1) {
            return $response->withStatus(404);
        }
        $thread = $d['threads']->findBySlug((string) $args['threadSlug']);
        if ($thread === null || (int) $thread['is_deleted'] === 1 || (int) $thread['forum_id'] !== (int) $forum['id']) {
            return $response->withStatus(404);
        }

        // Only bump views when this viewer (user or IP) hasn't been seen
        // on this thread in the last 30 minutes, and never for the
        // thread's own author — otherwise a refresh loop or a vain author
        // could inflate the counter without limit.
        if ($recordThreadView($request, $thread, $cmsUserId)) {
            $d['threads']->bumpViews((int) $thread['id']);
        }

        $perPage = max(1, $d['settings']->getInt('posts_per_page', 15));
        $page = max(1, (int) ($request->getQueryParams()['page'] ?? 1));
        $offset = ($page - 1) * $perPage;
        $totalPosts = $d['posts']->countForThread((int) $thread['id']);
        $totalPages = (int) max(1, (int) ceil($totalPosts / $perPage));
        $posts = $d['posts']->listForThread((int) $thread['id'], $perPage, $offset);

        // Preload author user data + the current user's liked posts.
        $userIds = [];
        foreach ($posts as $p) {
            if (!empty($p['author_user_id'])) {
                $userIds[] = (int) $p['author_user_id'];
            }
        }
        if (!empty($thread['author_user_id'])) {
            $userIds[] = (int) $thread['author_user_id'];
        }
        $userIds = array_values(array_unique($userIds));
        $d['users']->preload($userIds);
        $usersById = [];
        foreach ($userIds as $uid) {
            $u = $d['users']->find($uid);
            if ($u !== null) {
                $usersById[$uid] = $u;
            }
        }

        $likedMap = [];
        if ($cmsUserId !== null) {
            $likedMap = $d['posts']->likedByUser($cmsUserId, array_map(static fn ($p): int => (int) $p['id'], $posts));
        }
        $isSubscribed = $cmsUserId !== null && $d['subs']->isSubscribed($cmsUserId, (int) $thread['id']);

        // Determine which posts the current user can edit (and within
        // what time window). Computed server-side so the template
        // doesn't have to know about edit-window math.
        $editWindow = max(0, $d['settings']->getInt('edit_window_minutes', 15));
        $now = time();
        $canEditMap = [];
        $isMod = $isModerator($cmsUserId);
        foreach ($posts as $p) {
            $owner = $cmsUserId !== null && (int) $p['author_user_id'] === $cmsUserId;
            if ($isMod) {
                $canEditMap[(int) $p['id']] = true;
                continue;
            }
            if (!$owner) {
                continue;
            }
            $ts = strtotime((string) $p['created_at']) ?: 0;
            $canEditMap[(int) $p['id']] = $editWindow === 0 || ($now - $ts) <= $editWindow * 60;
        }

        // Thread tags (from the CMS taxonomy) + reported-state for the
        // current user so the UI can swap "Report" for "Reported".
        $threadTags = !empty($thread['entry_id']) ? $d['tags']->forEntry((int) $thread['entry_id']) : [];
        $reportedMap = [];
        if ($cmsUserId !== null) {
            foreach ($posts as $p) {
                $pid = (int) $p['id'];
                if ($d['reports']->userHasOpenReport($pid, $cmsUserId)) {
                    $reportedMap[$pid] = true;
                }
            }
        }

        $sidebar = $sidebarData($pdo, $d['stats'], $d['users'], $d['tags'], $cmsUserId);

        return $twig->render($response, '@plugin_forum_plugin/public/thread.twig', $ctx->viewData([
            'page_title'         => $thread['title'],
            'meta_description'   => (new MarkdownRenderer())->excerpt($posts[0]['body_markdown'] ?? '', 200),
            'forum'              => $forum,
            'thread'             => $thread,
            'forum_posts'        => $posts,
            'forum_page'         => $page,
            'forum_total_pages'  => $totalPages,
            'forum_posts_per_page' => $perPage,
            'forum_logged_in'    => $cmsUserId !== null,
            'forum_is_moderator' => $isMod,
            'forum_subscribed'   => $isSubscribed,
            'forum_can_edit'     => $canEditMap,
            'forum_liked'        => $likedMap,
            'forum_users_by_id'  => $usersById,
            'forum_thread_tags'  => $threadTags,
            'forum_report_reasons' => ReportRepository::REASONS,
            'forum_reported'     => $reportedMap,
            'forum_min_words'    => max(0, $d['settings']->getInt('min_post_words', 15)),
            'forum_sidebar'      => $sidebar,
            'forum_current_user_id' => $cmsUserId,
        ]));
    })->setName('plugin.forum.public.thread');
};
