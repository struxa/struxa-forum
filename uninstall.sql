-- Forum plugin uninstall — drops every table the plugin owns and
-- removes the forum-thread CMS content type it created on install.
--
-- We delete the content type *last* so that any leftover entries are
-- cascaded by the FK on cms_content_entry_values.content_entry_id (set
-- in the CMS core migrations). cms_content_entries does not enforce a
-- cascade itself, so we delete entries explicitly first.

DELETE FROM cms_content_entries WHERE content_type_id IN (
    SELECT id FROM cms_content_types WHERE slug = 'forum-thread'
);

DELETE FROM cms_content_fields WHERE content_type_id IN (
    SELECT id FROM cms_content_types WHERE slug = 'forum-thread'
);

-- Remove the forum-tags taxonomy + terms before deleting the content
-- type. cms_taxonomies/terms cascade via FK to content_type_id, but the
-- forum-tags taxonomy is also referenced from cms_content_entry_taxonomy_terms
-- via taxonomy_term_id (ON DELETE CASCADE), so deleting the taxonomy
-- row is enough to clean every related row.
DELETE FROM cms_taxonomies WHERE content_type_id IN (
    SELECT id FROM cms_content_types WHERE slug = 'forum-thread'
) AND slug = 'forum-tags';

DELETE FROM cms_content_types WHERE slug = 'forum-thread';

DROP TABLE IF EXISTS forum_reports;
DROP TABLE IF EXISTS forum_post_likes;
DROP TABLE IF EXISTS forum_subscriptions;
DROP TABLE IF EXISTS forum_user_activity;
DROP TABLE IF EXISTS forum_posts;
DROP TABLE IF EXISTS forum_threads;
DROP TABLE IF EXISTS forum_forums;
DROP TABLE IF EXISTS forum_categories;
DROP TABLE IF EXISTS forum_settings;
