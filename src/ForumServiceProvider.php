<?php

declare(strict_types=1);

namespace ForumPlugin;

use App\Plugin\PluginBootContext;
use App\Plugin\PluginServiceProviderInterface;
use Twig\Environment;

/**
 * Forum plugin main service provider.
 *
 * Boots on every request once the plugin is enabled:
 *   - Registers the admin sidebar entries (Dashboard / Categories /
 *     Forums / Threads / Posts / Settings).
 *   - Adds the {@see ForumTwigExtension} so templates can call
 *     `forum_stats()`, `forum_user_rank()`, `markdown` filter, etc.
 *
 * The heavy lifting (routes) lives in `routes/public.php` and
 * `routes/admin.php`, which are loaded by the CMS's PluginManager
 * *before* boot() runs (see bootstrap/web_app.php phases).
 */
final class ForumServiceProvider implements PluginServiceProviderInterface
{
    public function boot(PluginBootContext $context): void
    {
        // --- Admin sidebar links (under the "Extensions" group) -----------
        $context->registerAdminNavItem('Dashboard',  'plugin.forum.admin.dashboard');
        $context->registerAdminNavItem('Categories', 'plugin.forum.admin.categories');
        $context->registerAdminNavItem('Forums',     'plugin.forum.admin.forums');
        $context->registerAdminNavItem('Threads',    'plugin.forum.admin.threads');
        $context->registerAdminNavItem('Posts',      'plugin.forum.admin.posts');
        $context->registerAdminNavItem('Reports',    'plugin.forum.admin.reports');
        $context->registerAdminNavItem('Settings',   'plugin.forum.admin.settings');

        // --- Twig extension --------------------------------------------------
        $env = $context->twig()->getEnvironment();
        if ($env instanceof Environment) {
            $ext = new ForumTwigExtension($context->pdo(), new MarkdownRenderer());
            if (!$env->hasExtension(ForumTwigExtension::class)) {
                $env->addExtension($ext);
            }
        }
    }
}
