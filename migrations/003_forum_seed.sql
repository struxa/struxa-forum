-- Seed a starter category + a handful of forums so the plugin is
-- immediately useful after activation. All inserts are idempotent
-- (WHERE NOT EXISTS on slug) so re-running the migration leaves the
-- existing rows untouched.

INSERT INTO forum_categories (name, slug, description, sort_order)
SELECT 'Community', 'community', 'Welcome to the community — introductions, news and chat.', 10
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM forum_categories WHERE slug = 'community');

INSERT INTO forum_categories (name, slug, description, sort_order)
SELECT 'Discussion', 'discussion', 'Questions, ideas and longer conversations.', 20
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM forum_categories WHERE slug = 'discussion');

INSERT INTO forum_forums (category_id, name, slug, description, icon, sort_order)
SELECT c.id, 'Announcements', 'announcements',
       'Site news and updates from the team.',
       'bullhorn', 10
FROM forum_categories c
WHERE c.slug = 'community'
  AND NOT EXISTS (SELECT 1 FROM forum_forums WHERE slug = 'announcements');

INSERT INTO forum_forums (category_id, name, slug, description, icon, sort_order)
SELECT c.id, 'Introductions', 'introductions',
       'Say hello and tell us a bit about yourself.',
       'hand-wave', 20
FROM forum_categories c
WHERE c.slug = 'community'
  AND NOT EXISTS (SELECT 1 FROM forum_forums WHERE slug = 'introductions');

INSERT INTO forum_forums (category_id, name, slug, description, icon, sort_order)
SELECT c.id, 'General chat', 'general-chat',
       'Anything that does not fit elsewhere.',
       'comments', 30
FROM forum_categories c
WHERE c.slug = 'community'
  AND NOT EXISTS (SELECT 1 FROM forum_forums WHERE slug = 'general-chat');

INSERT INTO forum_forums (category_id, name, slug, description, icon, sort_order)
SELECT c.id, 'Open discussion', 'open',
       'Start a thread on any topic.',
       'comments', 10
FROM forum_categories c
WHERE c.slug = 'discussion'
  AND NOT EXISTS (SELECT 1 FROM forum_forums WHERE slug = 'open');

INSERT INTO forum_forums (category_id, name, slug, description, icon, sort_order)
SELECT c.id, 'Questions', 'questions',
       'Ask the community for help or advice.',
       'circle-question', 20
FROM forum_categories c
WHERE c.slug = 'discussion'
  AND NOT EXISTS (SELECT 1 FROM forum_forums WHERE slug = 'questions');

INSERT INTO forum_forums (category_id, name, slug, description, icon, sort_order)
SELECT c.id, 'Feedback', 'feedback',
       'Suggestions and feedback about the site.',
       'lightbulb', 30
FROM forum_categories c
WHERE c.slug = 'discussion'
  AND NOT EXISTS (SELECT 1 FROM forum_forums WHERE slug = 'feedback');
