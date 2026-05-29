-- Create the "Forum thread" CMS content type. Threads in forum_threads
-- are linked to a cms_content_entries row via forum_threads.entry_id so
-- they get the CMS slug/SEO/featured-image fields for free.
--
-- has_public_route = 0 because the forum plugin owns the /forum/...
-- URLs; the entry row is purely a metadata container.

INSERT INTO cms_content_types
    (name, slug, icon, description, has_public_route, supports_seo, supports_featured_image)
SELECT 'Forum thread', 'forum-thread', 'comments',
       'Forum threads created by site members. Owned by the Forum plugin — the entry row carries SEO + featured-image metadata, while the live discussion lives in the forum_* tables.',
       0, 1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM cms_content_types WHERE slug = 'forum-thread');

-- Body field: stores the first post's markdown as a "summary" view for
-- the CMS content browser. The authoritative copy lives in forum_posts
-- (with rendered HTML) — this entry-value is kept in sync by the
-- ThreadRepository on create/edit.
INSERT INTO cms_content_fields
    (content_type_id, label, field_key, field_type, placeholder, help_text, is_required, sort_order)
SELECT t.id, 'Opening post', 'body', 'textarea',
       'Markdown body of the opening post.',
       'Mirrored from forum_posts. Editing here only changes the CMS-side copy; the live post must be edited through the forum admin.',
       0, 10
FROM cms_content_types t
WHERE t.slug = 'forum-thread'
  AND NOT EXISTS (
    SELECT 1 FROM cms_content_fields f
    WHERE f.content_type_id = t.id AND f.field_key = 'body'
  );

-- Forum the thread belongs to (slug). Lets editors filter threads by
-- forum from the CMS Content section, without having to dive into the
-- forum admin.
INSERT INTO cms_content_fields
    (content_type_id, label, field_key, field_type, placeholder, help_text, is_required, sort_order)
SELECT t.id, 'Forum slug', 'forum_slug', 'text', 'e.g. general-chat',
       'Slug of the forum_forums row this thread belongs to. Used by the CMS list view as a quick filter.',
       0, 20
FROM cms_content_types t
WHERE t.slug = 'forum-thread'
  AND NOT EXISTS (
    SELECT 1 FROM cms_content_fields f
    WHERE f.content_type_id = t.id AND f.field_key = 'forum_slug'
  );
