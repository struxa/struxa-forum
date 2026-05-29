-- Forum plugin schema. All tables are prefixed `forum_*` so the plugin is
-- self-contained and uninstall-safe. We deliberately do not declare cross-
-- table FK constraints with CASCADE because:
--
--   1. The plugin sits next to CMS-owned tables (cms_users) we don't want
--      to lock into a plugin-defined relationship.
--   2. We want soft-delete semantics on posts/threads (so a CASCADE would
--      hide moderation history).
--
-- Integrity is enforced at the repository layer instead.

-- -----------------------------------------------------------------------
-- Categories: top-level groups of forums. Think MyBB "category" rows.
-- -----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS forum_categories (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(160) NOT NULL,
    slug         VARCHAR(160) NOT NULL,
    description  TEXT NULL,
    sort_order   INT NOT NULL DEFAULT 0,
    is_hidden    TINYINT(1) NOT NULL DEFAULT 0,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_forum_categories_slug (slug),
    KEY idx_forum_categories_sort (sort_order)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------
-- Forums: individual discussion areas within a category. Supports
-- sub-forums via parent_id (nullable). Each forum tracks a last-post
-- pointer so the index page can render "Last post by Alice 2h ago"
-- without expensive joins on every render.
-- -----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS forum_forums (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id     INT UNSIGNED NOT NULL,
    parent_id       INT UNSIGNED NULL,
    name            VARCHAR(191) NOT NULL,
    slug            VARCHAR(191) NOT NULL,
    description     TEXT NULL,
    icon            VARCHAR(80) NULL,
    icon_image      VARCHAR(500) NULL DEFAULT NULL,
    sort_order      INT NOT NULL DEFAULT 0,
    is_locked       TINYINT(1) NOT NULL DEFAULT 0,
    is_hidden       TINYINT(1) NOT NULL DEFAULT 0,
    threads_count   INT UNSIGNED NOT NULL DEFAULT 0,
    posts_count     INT UNSIGNED NOT NULL DEFAULT 0,
    last_thread_id  INT UNSIGNED NULL,
    last_post_id    INT UNSIGNED NULL,
    last_post_at    TIMESTAMP NULL DEFAULT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_forum_forums_slug (slug),
    KEY idx_forum_forums_category (category_id, sort_order),
    KEY idx_forum_forums_parent (parent_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------
-- Threads: every conversation. Linked to cms_content_entries via
-- entry_id (populated by migration 002 once the content type exists)
-- so threads inherit CMS SEO + featured image without re-implementing
-- them. Plugin-owned columns track forum-specific state (sticky/locked,
-- reply counters, last-post pointer).
-- -----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS forum_threads (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    forum_id        INT UNSIGNED NOT NULL,
    entry_id        INT UNSIGNED NULL,                -- → cms_content_entries.id
    author_user_id  INT UNSIGNED NULL,                -- → cms_users.id (nullable so we keep history when user deleted)
    title           VARCHAR(255) NOT NULL,
    slug            VARCHAR(255) NOT NULL,
    is_sticky       TINYINT(1) NOT NULL DEFAULT 0,
    is_locked       TINYINT(1) NOT NULL DEFAULT 0,
    is_deleted      TINYINT(1) NOT NULL DEFAULT 0,
    views_count     INT UNSIGNED NOT NULL DEFAULT 0,
    replies_count   INT UNSIGNED NOT NULL DEFAULT 0,
    likes_count     INT UNSIGNED NOT NULL DEFAULT 0,  -- total likes across all posts in this thread
    last_post_id    INT UNSIGNED NULL,
    last_post_at    TIMESTAMP NULL DEFAULT NULL,
    last_poster_id  INT UNSIGNED NULL,                -- denormalised → cms_users.id
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_forum_threads_slug (slug),
    KEY idx_forum_threads_forum (forum_id, is_deleted, is_sticky DESC, last_post_at DESC),
    KEY idx_forum_threads_author (author_user_id),
    KEY idx_forum_threads_entry (entry_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------
-- Posts: replies inside a thread. The first post in a thread has
-- is_first_post = 1; we keep it in the same table (instead of a
-- separate "first post" column on threads) so editing/moderation tools
-- can treat the OP and replies uniformly.
-- -----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS forum_posts (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    thread_id       INT UNSIGNED NOT NULL,
    forum_id        INT UNSIGNED NOT NULL,            -- denormalised so we can list a forum's posts directly
    author_user_id  INT UNSIGNED NULL,                -- → cms_users.id (nullable to preserve history)
    is_first_post   TINYINT(1) NOT NULL DEFAULT 0,
    is_deleted      TINYINT(1) NOT NULL DEFAULT 0,
    body_markdown   MEDIUMTEXT NOT NULL,
    body_html       MEDIUMTEXT NOT NULL,              -- rendered HTML, cached at write time
    likes_count     INT UNSIGNED NOT NULL DEFAULT 0,
    edited_at       TIMESTAMP NULL DEFAULT NULL,
    edited_by_user_id INT UNSIGNED NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_forum_posts_thread (thread_id, is_deleted, created_at),
    KEY idx_forum_posts_forum (forum_id, is_deleted, created_at),
    KEY idx_forum_posts_author (author_user_id, is_deleted, created_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------
-- Subscriptions: "watch this thread" toggles. The UI is a single
-- subscribe button on the thread header; when set we'll later notify
-- the user on new replies (v1 just tracks the relationship — no
-- background notifier yet).
-- -----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS forum_subscriptions (
    user_id    INT UNSIGNED NOT NULL,                 -- → cms_users.id
    thread_id  INT UNSIGNED NOT NULL,                 -- → forum_threads.id
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, thread_id),
    KEY idx_forum_subscriptions_thread (thread_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------
-- Post likes (MyBB calls this "thanks"). One row per (user, post). The
-- post's likes_count is denormalised in forum_posts.
-- -----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS forum_post_likes (
    user_id    INT UNSIGNED NOT NULL,
    post_id    INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, post_id),
    KEY idx_forum_post_likes_post (post_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------
-- User activity: rolling "last seen" timestamp per CMS user, used by
-- the StatsService to compute online-now counts and the homepage
-- widget. Touch-only on public forum hits (writes are batched in the
-- repository to avoid hammering the DB on every page render).
-- -----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS forum_user_activity (
    user_id     INT UNSIGNED NOT NULL PRIMARY KEY,
    last_seen   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_path   VARCHAR(255) NULL,
    posts_count INT UNSIGNED NOT NULL DEFAULT 0,      -- denormalised post total per user
    KEY idx_forum_user_activity_seen (last_seen)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------
-- Settings: small key/value store for plugin config (per-page sizes,
-- edit window, etc.). Mirrors the avios-destination-review pattern.
-- -----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS forum_settings (
    setting_key   VARCHAR(80) NOT NULL PRIMARY KEY,
    setting_value MEDIUMTEXT NULL,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Default settings — inserted via INSERT IGNORE so re-run is a no-op.
INSERT IGNORE INTO forum_settings (setting_key, setting_value) VALUES
    ('threads_per_page', '20'),
    ('posts_per_page',   '15'),
    ('edit_window_minutes', '15'),
    ('online_now_window_minutes', '5'),
    ('min_post_length', '4'),
    ('min_thread_title_length', '4'),
    ('min_post_words', '15');
