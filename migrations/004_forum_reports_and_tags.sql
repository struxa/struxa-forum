-- Migration 004: reports queue + tags taxonomy.
--
-- This adds two orthogonal capabilities:
--
--   1. A moderation reporting flow: members can flag a post (spam,
--      harassment, off-topic, other), and admins triage the queue from
--      /admin/forum/reports.
--
--   2. Tags on threads. We wire into the existing cms_taxonomies /
--      cms_taxonomy_terms / cms_content_entry_taxonomy_terms tables so
--      tags are first-class CMS metadata: a thread's tags are visible
--      from the CMS Content browser and can be used in future content
--      queries (related posts, etc.).

-- -----------------------------------------------------------------------
-- Reports queue. We keep the row even after resolution so we have an
-- audit trail; the `status` column drives the queue view.
--
-- One report per (reporter, post) — duplicates are upserted to refresh
-- the timestamp / reason instead of stacking.
-- -----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS forum_reports (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_id              INT UNSIGNED NOT NULL,
    thread_id            INT UNSIGNED NOT NULL,
    reporter_user_id     INT UNSIGNED NOT NULL,
    reason_key           VARCHAR(40) NOT NULL DEFAULT 'other',
    reason_text          VARCHAR(500) NULL,
    status               ENUM('open','resolved','dismissed') NOT NULL DEFAULT 'open',
    resolved_at          TIMESTAMP NULL DEFAULT NULL,
    resolved_by_user_id  INT UNSIGNED NULL,
    resolution_note      VARCHAR(500) NULL,
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_forum_reports_unique (reporter_user_id, post_id),
    KEY idx_forum_reports_status (status, created_at),
    KEY idx_forum_reports_post   (post_id),
    KEY idx_forum_reports_thread (thread_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------
-- Register the "Forum tags" taxonomy on the forum-thread content type so
-- threads can carry CMS-native tags. Terms live in cms_taxonomy_terms
-- and are linked to each thread's content entry via
-- cms_content_entry_taxonomy_terms.
--
-- Guarded with NOT EXISTS so re-running the migration is idempotent.
-- -----------------------------------------------------------------------
INSERT INTO cms_taxonomies
    (content_type_id, name, slug, description, taxonomy_type, is_hierarchical)
SELECT t.id, 'Forum tags', 'forum-tags',
       'Free-text tags applied to forum threads. Editors and thread authors can add tags; the public /forum/tag/{slug} route lists every thread carrying a tag.',
       'tag', 0
FROM cms_content_types t
WHERE t.slug = 'forum-thread'
  AND NOT EXISTS (
    SELECT 1 FROM cms_taxonomies x
     WHERE x.content_type_id = t.id AND x.slug = 'forum-tags'
  );
