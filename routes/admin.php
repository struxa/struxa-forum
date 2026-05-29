<?php

declare(strict_types=1);

use App\Access\PermissionSlug;
use App\Flash;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
use App\Plugin\PluginBootContext;
use ForumPlugin\MarkdownRenderer;
use ForumPlugin\Repositories\CategoryRepository;
use ForumPlugin\Repositories\ForumRepository;
use ForumPlugin\Repositories\PostRepository;
use ForumPlugin\Repositories\ReportRepository;
use ForumPlugin\Repositories\SettingsRepository;
use ForumPlugin\Repositories\ThreadRepository;
use ForumPlugin\Security\ForumIconUploader;
use ForumPlugin\StatsService;
use ForumPlugin\UserDirectory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Interfaces\RouteParserInterface;
use Slim\Routing\RouteCollectorProxy;
use Slim\Routing\RouteContext;

return function (App $app, PluginBootContext $ctx): void {
    $authMw    = new RequireCmsStaff($ctx->auth(), $ctx->pdo());
    $contentMw = new RequirePermission($ctx->pdo(), [PermissionSlug::EDIT_CONTENT]);
    $twig      = $ctx->twig();
    $pdo       = $ctx->pdo();

    // -----------------------------------------------------------------
    // Shared scaffolding: a tiny helper that bundles repositories +
    // common view data so each route handler stays compact.
    // -----------------------------------------------------------------
    $deps = static function () use ($pdo): array {
        $markdown = new MarkdownRenderer();
        $threads  = new ThreadRepository($pdo, $markdown);
        return [
            'categories' => new CategoryRepository($pdo),
            'forums'     => new ForumRepository($pdo),
            'threads'    => $threads,
            'posts'      => new PostRepository($pdo, $markdown, $threads),
            'users'      => new UserDirectory($pdo),
            'settings'   => new SettingsRepository($pdo),
            'reports'    => new ReportRepository($pdo),
        ];
    };

    /** @var callable(Request, string, string=, array=): array<string, mixed> $viewData */
    $viewData = static function (Request $request, string $section, string $title = '', array $extra = []) use ($ctx): array {
        $cmsUser = $request->getAttribute('cms_user') ?? [];
        return array_merge($ctx->viewData(), [
            'cms_user'         => is_array($cmsUser) ? $cmsUser : [],
            'admin_nav'        => 'extensions_plugins',
            'forum_admin_section' => $section,
            'page_title'       => $title !== '' ? ($title . ' · Forum admin') : 'Forum admin',
        ], $extra);
    };

    /** @param array<string, mixed> $form */
    $forumPostsListRedirect = static function (RouteParserInterface $parser, array $form): string {
        $base = $parser->urlFor('plugin.forum.admin.posts');
        $raw = $form['return_query'] ?? '';
        if (!\is_string($raw) || $raw === '') {
            return $base;
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $base;
        }
        if (!\is_array($decoded)) {
            return $base;
        }
        $out = [
            'q'        => \array_key_exists('q', $decoded) ? mb_substr((string) $decoded['q'], 0, 500) : '',
            'forum_id' => \array_key_exists('forum_id', $decoded) ? \max(0, (int) $decoded['forum_id']) : 0,
            'author'   => \array_key_exists('author', $decoded) ? (string) $decoded['author'] : '',
            'status'   => '',
            'page'     => \array_key_exists('page', $decoded) ? \max(1, (int) $decoded['page']) : 1,
        ];
        $st = \array_key_exists('status', $decoded) ? (string) $decoded['status'] : '';
        if (\in_array($st, ['', 'op', 'deleted', 'all'], true)) {
            $out['status'] = $st;
        }
        return $base . '?' . \http_build_query($out);
    };

    $app->group('/admin/forum', function (RouteCollectorProxy $g) use ($ctx, $twig, $pdo, $deps, $viewData, $forumPostsListRedirect): void {

        // =====================================================================
        // Dashboard
        // =====================================================================
        $g->get('', function (Request $request, Response $response) use ($ctx, $twig, $pdo, $deps, $viewData): Response {
            $d = $deps();
            $statsService = new StatsService($pdo, $d['settings']);
            $stats         = $statsService->snapshot();
            $recentThreads = $d['threads']->recent(6);
            $recentPosts   = $d['posts']->recent(6);
            $hotTopics     = $statsService->hotTopics(24, 5);
            $topPosters    = $statsService->topPosters(5);
            $postsByDay    = $statsService->postsByDay(14);
            $reportCounts  = $d['reports']->counts();

            // Prime user data so the templates can render display names
            // without N+1 queries — collect every user id we plan to
            // render and hand them to UserDirectory in one shot.
            $userIds = [];
            foreach ($recentThreads as $t) {
                if (!empty($t['author_user_id']))  { $userIds[] = (int) $t['author_user_id']; }
                if (!empty($t['last_poster_id'])) { $userIds[] = (int) $t['last_poster_id']; }
            }
            foreach ($recentPosts as $p) {
                if (!empty($p['author_user_id'])) { $userIds[] = (int) $p['author_user_id']; }
            }
            foreach ($hotTopics as $h) {
                if (!empty($h['author_user_id']))  { $userIds[] = (int) $h['author_user_id']; }
                if (!empty($h['last_poster_id'])) { $userIds[] = (int) $h['last_poster_id']; }
            }
            foreach ($topPosters as $tp) {
                if (!empty($tp['user_id'])) { $userIds[] = (int) $tp['user_id']; }
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

            // 7-day delta — useful as a "vs last week" hint on the stat
            // tiles. Splits the 14-day series we already fetched.
            $half        = (int) floor(count($postsByDay) / 2);
            $thisWeek    = array_slice($postsByDay, $half);
            $lastWeek    = array_slice($postsByDay, 0, $half);
            $postsThis   = array_sum(array_column($thisWeek, 'posts'));
            $postsLast   = array_sum(array_column($lastWeek, 'posts'));
            $postsDelta  = $postsLast > 0 ? (int) round((($postsThis - $postsLast) / $postsLast) * 100) : ($postsThis > 0 ? 100 : 0);

            return $twig->render($response, '@plugin_forum_plugin/admin/dashboard.twig',
                $viewData($request, 'dashboard', 'Dashboard', [
                    'forum_stats'    => $stats,
                    'recent_threads' => $recentThreads,
                    'recent_posts'   => $recentPosts,
                    'users_by_id'    => $usersById,
                    'hot_topics'     => $hotTopics,
                    'top_posters'    => $topPosters,
                    'posts_by_day'   => $postsByDay,
                    'posts_this_week'=> $postsThis,
                    'posts_last_week'=> $postsLast,
                    'posts_delta'    => $postsDelta,
                    'report_counts'  => $reportCounts,
                ]));
        })->setName('plugin.forum.admin.dashboard');

        // =====================================================================
        // Categories
        // =====================================================================
        $g->get('/categories', function (Request $request, Response $response) use ($ctx, $twig, $deps, $viewData): Response {
            $d = $deps();
            return $twig->render($response, '@plugin_forum_plugin/admin/categories/list.twig',
                $viewData($request, 'categories', 'Categories', [
                    'forum_categories' => $d['categories']->all(true),
                ]));
        })->setName('plugin.forum.admin.categories');

        $g->get('/categories/new', function (Request $request, Response $response) use ($ctx, $twig, $deps, $viewData): Response {
            $d = $deps();
            return $twig->render($response, '@plugin_forum_plugin/admin/categories/form.twig',
                $viewData($request, 'categories', 'New category', [
                    'is_new' => true,
                    'category' => ['id' => 0, 'name' => '', 'slug' => '', 'description' => '', 'sort_order' => $d['categories']->nextSortOrder(), 'is_hidden' => 0],
                ]));
        })->setName('plugin.forum.admin.categories.new');

        $g->post('/categories/new', function (Request $request, Response $response) use ($deps): Response {
            $d = $deps();
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $form = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
            $name = trim((string) ($form['name'] ?? ''));
            if ($name === '') {
                Flash::set('error', 'Name is required.');
                return $response->withHeader('Location', $parser->urlFor('plugin.forum.admin.categories.new'))->withStatus(302);
            }
            $d['categories']->create([
                'name'        => $name,
                'slug'        => trim((string) ($form['slug'] ?? '')),
                'description' => trim((string) ($form['description'] ?? '')),
                'sort_order'  => (int) ($form['sort_order'] ?? 0),
                'is_hidden'   => isset($form['is_hidden']) && $form['is_hidden'] === '1',
            ]);
            Flash::set('success', 'Category added.');
            return $response->withHeader('Location', $parser->urlFor('plugin.forum.admin.categories'))->withStatus(302);
        });

        $g->get('/categories/{id:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($ctx, $twig, $deps, $viewData): Response {
            $d = $deps();
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $cat = $d['categories']->find((int) $args['id']);
            if ($cat === null) {
                Flash::set('error', 'Category not found.');
                return $response->withHeader('Location', $parser->urlFor('plugin.forum.admin.categories'))->withStatus(302);
            }
            return $twig->render($response, '@plugin_forum_plugin/admin/categories/form.twig',
                $viewData($request, 'categories', 'Edit category', [
                    'is_new'   => false,
                    'category' => $cat,
                ]));
        })->setName('plugin.forum.admin.categories.edit');

        $g->post('/categories/{id:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($deps): Response {
            $d = $deps();
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $id = (int) $args['id'];
            $form = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
            $name = trim((string) ($form['name'] ?? ''));
            if ($name === '') {
                Flash::set('error', 'Name is required.');
                return $response->withHeader('Location', $parser->urlFor('plugin.forum.admin.categories.edit', ['id' => $id]))->withStatus(302);
            }
            $d['categories']->update($id, [
                'name'        => $name,
                'slug'        => trim((string) ($form['slug'] ?? '')),
                'description' => trim((string) ($form['description'] ?? '')),
                'sort_order'  => (int) ($form['sort_order'] ?? 0),
                'is_hidden'   => isset($form['is_hidden']) && $form['is_hidden'] === '1',
            ]);
            Flash::set('success', 'Category saved.');
            return $response->withHeader('Location', $parser->urlFor('plugin.forum.admin.categories'))->withStatus(302);
        });

        $g->post('/categories/{id:[0-9]+}/delete', function (Request $request, Response $response, array $args) use ($deps): Response {
            $d = $deps();
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $id = (int) $args['id'];
            if ($d['categories']->forumCount($id) > 0) {
                Flash::set('error', 'Move or delete the forums inside this category first.');
                return $response->withHeader('Location', $parser->urlFor('plugin.forum.admin.categories'))->withStatus(302);
            }
            $d['categories']->delete($id);
            Flash::set('success', 'Category deleted.');
            return $response->withHeader('Location', $parser->urlFor('plugin.forum.admin.categories'))->withStatus(302);
        });

        // =====================================================================
        // Forums
        // =====================================================================
        $g->get('/forums', function (Request $request, Response $response) use ($ctx, $twig, $deps, $viewData): Response {
            $d = $deps();
            return $twig->render($response, '@plugin_forum_plugin/admin/forums/list.twig',
                $viewData($request, 'forums', 'Forums', [
                    'forum_forums'     => $d['forums']->allForAdmin(),
                    'forum_categories' => $d['categories']->all(true),
                ]));
        })->setName('plugin.forum.admin.forums');

        $g->get('/forums/new', function (Request $request, Response $response) use ($ctx, $twig, $deps, $viewData): Response {
            $d = $deps();
            return $twig->render($response, '@plugin_forum_plugin/admin/forums/form.twig',
                $viewData($request, 'forums', 'New forum', [
                    'is_new'           => true,
                    'forum'            => [
                        'id' => 0, 'category_id' => 0, 'parent_id' => null,
                        'name' => '', 'slug' => '', 'description' => '', 'icon' => '',
                        'sort_order' => $d['forums']->nextSortOrder(),
                        'is_locked' => 0, 'is_hidden' => 0,
                    ],
                    'forum_categories' => $d['categories']->all(true),
                    'forum_forums'     => $d['forums']->allForAdmin(),
                ]));
        })->setName('plugin.forum.admin.forums.new');

        $g->post('/forums/new', function (Request $request, Response $response) use ($deps): Response {
            $d = $deps();
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $form = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
            $name = trim((string) ($form['name'] ?? ''));
            $catId = (int) ($form['category_id'] ?? 0);
            if ($name === '' || $catId <= 0) {
                Flash::set('error', 'Name and category are required.');
                return $response->withHeader('Location', $parser->urlFor('plugin.forum.admin.forums.new'))->withStatus(302);
            }

            // Resolve icon image: file upload wins, else typed URL, else null.
            $publicRoot = dirname(__DIR__, 3) . '/public';
            $uploader = new ForumIconUploader($publicRoot);
            $iconUrl = null;
            try {
                $uploaded = $request->getUploadedFiles()['icon_image_file'] ?? null;
                $slugHint = trim((string) ($form['slug'] ?? '')) !== ''
                    ? (string) $form['slug']
                    : $name;
                $iconUrl = $uploader->persist($uploaded, $slugHint);
                if ($iconUrl === null) {
                    $iconUrl = $uploader->normaliseUrl((string) ($form['icon_image_url'] ?? ''));
                }
            } catch (\RuntimeException $e) {
                Flash::set('error', $e->getMessage());
                return $response->withHeader('Location', $parser->urlFor('plugin.forum.admin.forums.new'))->withStatus(302);
            }

            $d['forums']->create([
                'category_id' => $catId,
                'parent_id'   => (int) ($form['parent_id'] ?? 0),
                'name'        => $name,
                'slug'        => trim((string) ($form['slug'] ?? '')),
                'description' => trim((string) ($form['description'] ?? '')),
                'icon'        => trim((string) ($form['icon'] ?? '')),
                'icon_image'  => $iconUrl,
                'sort_order'  => (int) ($form['sort_order'] ?? 0),
                'is_locked'   => isset($form['is_locked']) && $form['is_locked'] === '1',
                'is_hidden'   => isset($form['is_hidden']) && $form['is_hidden'] === '1',
            ]);
            Flash::set('success', 'Forum added.');
            return $response->withHeader('Location', $parser->urlFor('plugin.forum.admin.forums'))->withStatus(302);
        });

        $g->get('/forums/{id:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($ctx, $twig, $deps, $viewData): Response {
            $d = $deps();
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $f = $d['forums']->find((int) $args['id']);
            if ($f === null) {
                Flash::set('error', 'Forum not found.');
                return $response->withHeader('Location', $parser->urlFor('plugin.forum.admin.forums'))->withStatus(302);
            }
            return $twig->render($response, '@plugin_forum_plugin/admin/forums/form.twig',
                $viewData($request, 'forums', 'Edit forum', [
                    'is_new'           => false,
                    'forum'            => $f,
                    'forum_categories' => $d['categories']->all(true),
                    'forum_forums'     => $d['forums']->allForAdmin(),
                ]));
        })->setName('plugin.forum.admin.forums.edit');

        $g->post('/forums/{id:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($deps): Response {
            $d = $deps();
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $id = (int) $args['id'];
            $form = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
            $name = trim((string) ($form['name'] ?? ''));
            $catId = (int) ($form['category_id'] ?? 0);
            if ($name === '' || $catId <= 0) {
                Flash::set('error', 'Name and category are required.');
                return $response->withHeader('Location', $parser->urlFor('plugin.forum.admin.forums.edit', ['id' => $id]))->withStatus(302);
            }

            // Icon image resolution priority: new upload > typed URL >
            // (existing value or null if "remove" was ticked).
            $existing = $d['forums']->find($id);
            $current  = $existing !== null && !empty($existing['icon_image']) ? (string) $existing['icon_image'] : null;
            $remove   = isset($form['icon_image_remove']) && $form['icon_image_remove'] === '1';

            $publicRoot = dirname(__DIR__, 3) . '/public';
            $uploader = new ForumIconUploader($publicRoot);
            $iconUrl  = $current;
            try {
                $uploaded = $request->getUploadedFiles()['icon_image_file'] ?? null;
                $slugHint = trim((string) ($form['slug'] ?? '')) !== ''
                    ? (string) $form['slug']
                    : $name;
                $uploadedUrl = $uploader->persist($uploaded, $slugHint);
                if ($uploadedUrl !== null) {
                    $iconUrl = $uploadedUrl;
                } else {
                    $typedUrl = $uploader->normaliseUrl((string) ($form['icon_image_url'] ?? ''));
                    if ($typedUrl !== null) {
                        $iconUrl = $typedUrl;
                    }
                }
                if ($remove) {
                    $iconUrl = null;
                }
            } catch (\RuntimeException $e) {
                Flash::set('error', $e->getMessage());
                return $response->withHeader('Location', $parser->urlFor('plugin.forum.admin.forums.edit', ['id' => $id]))->withStatus(302);
            }

            $d['forums']->update($id, [
                'category_id' => $catId,
                'parent_id'   => (int) ($form['parent_id'] ?? 0),
                'name'        => $name,
                'slug'        => trim((string) ($form['slug'] ?? '')),
                'description' => trim((string) ($form['description'] ?? '')),
                'icon'        => trim((string) ($form['icon'] ?? '')),
                'icon_image'  => $iconUrl,
                'sort_order'  => (int) ($form['sort_order'] ?? 0),
                'is_locked'   => isset($form['is_locked']) && $form['is_locked'] === '1',
                'is_hidden'   => isset($form['is_hidden']) && $form['is_hidden'] === '1',
            ]);
            Flash::set('success', 'Forum saved.');
            return $response->withHeader('Location', $parser->urlFor('plugin.forum.admin.forums'))->withStatus(302);
        });

        $g->post('/forums/{id:[0-9]+}/delete', function (Request $request, Response $response, array $args) use ($deps): Response {
            $d = $deps();
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $id = (int) $args['id'];
            $d['forums']->delete($id);
            Flash::set('success', 'Forum deleted.');
            return $response->withHeader('Location', $parser->urlFor('plugin.forum.admin.forums'))->withStatus(302);
        });

        // =====================================================================
        // Threads (moderation)
        // =====================================================================
        $g->get('/threads', function (Request $request, Response $response) use ($ctx, $twig, $deps, $viewData): Response {
            $d = $deps();
            $params = $request->getQueryParams();
            // Cap q length defensively so a 1MB search string can't reach
            // MySQL. (Defence-in-depth — admin only, but cheap.)
            $rawQ = (string) ($params['q'] ?? '');
            $filter = [
                'q'        => mb_substr($rawQ, 0, 80),
                'forum_id' => (int) ($params['forum_id'] ?? 0),
                'status'   => (string) ($params['status'] ?? ''),
            ];
            $perPage = 30;
            $total   = $d['threads']->countForAdmin($filter);
            $pages   = max(1, (int) ceil($total / $perPage));
            $page    = max(1, min($pages, (int) ($params['page'] ?? 1)));
            $rows    = $d['threads']->listForAdmin($filter, $perPage, ($page - 1) * $perPage);

            $userIds = array_values(array_unique(array_filter(array_map(static fn ($r): int => (int) ($r['author_user_id'] ?? 0), $rows))));
            $d['users']->preload($userIds);
            $usersById = [];
            foreach ($userIds as $uid) {
                $u = $d['users']->find($uid);
                if ($u !== null) {
                    $usersById[$uid] = $u;
                }
            }

            return $twig->render($response, '@plugin_forum_plugin/admin/threads/list.twig',
                $viewData($request, 'threads', 'Threads', [
                    'forum_filter'  => $filter,
                    'forum_forums'  => $d['forums']->allForAdmin(),
                    'forum_threads' => $rows,
                    'forum_page'    => $page,
                    'forum_pages'   => $pages,
                    'forum_total'   => $total,
                    'users_by_id'   => $usersById,
                ]));
        })->setName('plugin.forum.admin.threads');

        $g->post('/threads/{id:[0-9]+}/move', function (Request $request, Response $response, array $args) use ($deps): Response {
            $d = $deps();
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $id = (int) $args['id'];
            $form = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
            $destination = (int) ($form['destination_forum_id'] ?? 0);
            if ($destination <= 0) {
                Flash::set('error', 'Pick a destination forum.');
            } else {
                $moved = $d['threads']->moveToForum($id, $destination);
                Flash::set($moved ? 'success' : 'error', $moved ? 'Thread moved.' : 'Could not move thread (already there or destination invalid).');
            }
            return $response->withHeader('Location', $parser->urlFor('plugin.forum.admin.threads'))->withStatus(302);
        });

        $g->post('/threads/{id:[0-9]+}/flag', function (Request $request, Response $response, array $args) use ($deps): Response {
            $d = $deps();
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $id = (int) $args['id'];
            $form = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
            $flags = [];
            foreach (['is_sticky', 'is_locked', 'is_deleted'] as $f) {
                if (array_key_exists($f, $form)) {
                    $flags[$f] = $form[$f] === '1' ? 1 : 0;
                }
            }
            if (isset($flags['is_deleted']) && (int) $flags['is_deleted'] === 1) {
                $d['posts']->softDeleteAllPostsInThread($id);
            }
            $d['threads']->setFlags($id, $flags);
            $d['threads']->refreshAround($id);
            Flash::set('success', 'Thread updated.');
            return $response->withHeader('Location', $parser->urlFor('plugin.forum.admin.threads'))->withStatus(302);
        });

        // =====================================================================
        // Posts (moderation)
        // =====================================================================
        $g->get('/posts', function (Request $request, Response $response) use ($ctx, $twig, $deps, $viewData): Response {
            $d = $deps();
            $params = $request->getQueryParams();
            $filter = [
                'q'        => (string) ($params['q'] ?? ''),
                'forum_id' => (int) ($params['forum_id'] ?? 0),
                'author'   => (string) ($params['author'] ?? ''),
                // '' = live (default), 'deleted', 'op', 'all'
                'status'   => (string) ($params['status'] ?? ''),
            ];
            $perPage = 25;
            $total   = $d['posts']->countForAdmin($filter);
            $pages   = max(1, (int) ceil($total / $perPage));
            $page    = max(1, min($pages, (int) ($params['page'] ?? 1)));
            $rows    = $d['posts']->listForAdmin($filter, $perPage, ($page - 1) * $perPage);

            $hasDeletable = false;
            foreach ($rows as $r) {
                if ((int) ($r['is_deleted'] ?? 0) === 0) {
                    $hasDeletable = true;
                    break;
                }
            }
            try {
                $returnQuery = json_encode([
                    'q'        => $filter['q'],
                    'forum_id' => $filter['forum_id'],
                    'author'   => $filter['author'],
                    'status'   => $filter['status'],
                    'page'     => $page,
                ], JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $returnQuery = '{}';
            }

            $userIds = array_values(array_unique(array_filter(array_map(static fn ($r): int => (int) ($r['author_user_id'] ?? 0), $rows))));
            $d['users']->preload($userIds);
            $usersById = [];
            foreach ($userIds as $uid) {
                $u = $d['users']->find($uid);
                if ($u !== null) {
                    $usersById[$uid] = $u;
                }
            }
            return $twig->render($response, '@plugin_forum_plugin/admin/posts/list.twig',
                $viewData($request, 'posts', 'Posts', [
                    'forum_posts'  => $rows,
                    'users_by_id'  => $usersById,
                    'forum_filter' => $filter,
                    'forum_forums' => $d['forums']->allForAdmin(),
                    'forum_page'   => $page,
                    'forum_pages'  => $pages,
                    'forum_total'  => $total,
                    'forum_posts_return_query' => $returnQuery,
                    'forum_posts_has_deletable' => $hasDeletable,
                ]));
        })->setName('plugin.forum.admin.posts');

        $g->post('/posts/bulk-delete', function (Request $request, Response $response) use ($deps, $forumPostsListRedirect): Response {
            $d = $deps();
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $form = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
            $ids = $form['post_ids'] ?? [];
            if (!\is_array($ids)) {
                $ids = [];
            }
            $n = 0;
            foreach ($ids as $raw) {
                $id = (int) $raw;
                if ($id < 1) {
                    continue;
                }
                $d['posts']->softDelete($id);
                ++$n;
            }
            Flash::set($n > 0 ? 'success' : 'info', $n > 0 ? \sprintf('Deleted %d post(s).', $n) : 'No posts selected.');
            return $response->withHeader('Location', $forumPostsListRedirect($parser, $form))->withStatus(302);
        })->setName('plugin.forum.admin.posts.bulk_delete');

        $g->post('/posts/{id:[0-9]+}/delete', function (Request $request, Response $response, array $args) use ($deps, $forumPostsListRedirect): Response {
            $d = $deps();
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $form = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
            $d['posts']->softDelete((int) $args['id']);
            Flash::set('success', 'Post deleted.');
            return $response->withHeader('Location', $forumPostsListRedirect($parser, $form))->withStatus(302);
        });

        $g->post('/posts/{id:[0-9]+}/restore', function (Request $request, Response $response, array $args) use ($deps, $forumPostsListRedirect): Response {
            $d = $deps();
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $form = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
            $d['posts']->restore((int) $args['id']);
            Flash::set('success', 'Post restored.');
            return $response->withHeader('Location', $forumPostsListRedirect($parser, $form))->withStatus(302);
        });

        // =====================================================================
        // Reports queue
        // =====================================================================
        $g->get('/reports', function (Request $request, Response $response) use ($ctx, $twig, $deps, $viewData): Response {
            $d = $deps();
            $params = $request->getQueryParams();
            $filter = [
                'status' => (string) ($params['status'] ?? 'open'),
                'reason' => (string) ($params['reason'] ?? ''),
                'q'      => (string) ($params['q'] ?? ''),
            ];
            $perPage = 25;
            $total   = $d['reports']->countForAdmin($filter);
            $pages   = max(1, (int) ceil($total / $perPage));
            $page    = max(1, min($pages, (int) ($params['page'] ?? 1)));
            $rows    = $d['reports']->listForAdmin($filter, $perPage, ($page - 1) * $perPage);
            $counts  = $d['reports']->counts();

            // Bulk-load user data for reporters + post authors.
            $userIds = [];
            foreach ($rows as $r) {
                if (!empty($r['reporter_user_id']))   { $userIds[] = (int) $r['reporter_user_id']; }
                if (!empty($r['post_author_id']))    { $userIds[] = (int) $r['post_author_id']; }
                if (!empty($r['resolved_by_user_id'])) { $userIds[] = (int) $r['resolved_by_user_id']; }
            }
            $userIds = array_values(array_unique($userIds));
            $d['users']->preload($userIds);
            $usersById = [];
            foreach ($userIds as $uid) {
                $u = $d['users']->find($uid);
                if ($u !== null) { $usersById[$uid] = $u; }
            }

            return $twig->render($response, '@plugin_forum_plugin/admin/reports/list.twig',
                $viewData($request, 'reports', 'Reports', [
                    'forum_reports'         => $rows,
                    'forum_report_filter'   => $filter,
                    'forum_report_counts'   => $counts,
                    'forum_report_reasons'  => ReportRepository::REASONS,
                    'users_by_id'           => $usersById,
                    'forum_page'            => $page,
                    'forum_pages'           => $pages,
                    'forum_total'           => $total,
                ]));
        })->setName('plugin.forum.admin.reports');

        $g->post('/reports/{id:[0-9]+}/resolve', function (Request $request, Response $response, array $args) use ($deps): Response {
            $d = $deps();
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $cmsUser = $request->getAttribute('cms_user') ?? [];
            $resolverId = is_array($cmsUser) ? (int) ($cmsUser['id'] ?? 0) : 0;

            $form = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
            $status = (string) ($form['status'] ?? 'resolved');
            $note   = (string) ($form['resolution_note'] ?? '');
            $d['reports']->setStatus((int) $args['id'], $status, $resolverId, $note);
            Flash::set('success', 'Report ' . $status . '.');
            return $response->withHeader('Location', $parser->urlFor('plugin.forum.admin.reports'))->withStatus(302);
        });

        // =====================================================================
        // Settings
        // =====================================================================
        $g->get('/settings', function (Request $request, Response $response) use ($ctx, $twig, $deps, $viewData): Response {
            $d = $deps();
            return $twig->render($response, '@plugin_forum_plugin/admin/settings/form.twig',
                $viewData($request, 'settings', 'Settings', [
                    'forum_settings' => $d['settings']->all(),
                ]));
        })->setName('plugin.forum.admin.settings');

        $g->post('/settings', function (Request $request, Response $response) use ($deps): Response {
            $d = $deps();
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $form = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
            $allowed = [
                'threads_per_page', 'posts_per_page',
                'edit_window_minutes', 'online_now_window_minutes',
                'min_post_length', 'min_thread_title_length',
                'min_post_words',
            ];
            $kv = [];
            foreach ($allowed as $k) {
                if (array_key_exists($k, $form)) {
                    $kv[$k] = max(0, (int) $form[$k]);
                }
            }
            $d['settings']->setMany($kv);
            Flash::set('success', 'Settings saved.');
            return $response->withHeader('Location', $parser->urlFor('plugin.forum.admin.settings'))->withStatus(302);
        });
    })->add($contentMw)->add($authMw);
};
