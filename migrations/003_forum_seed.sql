-- Seed a starter category + a handful of forums so the plugin is
-- immediately useful after activation. All inserts are idempotent
-- (WHERE NOT EXISTS on slug) so re-running the migration leaves the
-- existing rows untouched.

INSERT INTO forum_categories (name, slug, description, sort_order)
SELECT 'Community', 'community', 'Welcome to the community — introductions, news and chat.', 10
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM forum_categories WHERE slug = 'community');

INSERT INTO forum_categories (name, slug, description, sort_order)
SELECT 'Avios discussion', 'avios', 'Tips, trip reports and questions about Avios redemptions, status and partners.', 20
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM forum_categories WHERE slug = 'avios');

-- Forums in "Community" ---------------------------------------------------

INSERT INTO forum_forums (category_id, name, slug, description, icon, sort_order)
SELECT c.id, 'Announcements', 'announcements',
       'Site news and updates from the team.',
       'bullhorn', 10
FROM forum_categories c
WHERE c.slug = 'community'
  AND NOT EXISTS (SELECT 1 FROM forum_forums WHERE slug = 'announcements');

INSERT INTO forum_forums (category_id, name, slug, description, icon, sort_order)
SELECT c.id, 'Introductions', 'introductions',
       'Say hello! Tell us where you''re flying next.',
       'hand-wave', 20
FROM forum_categories c
WHERE c.slug = 'community'
  AND NOT EXISTS (SELECT 1 FROM forum_forums WHERE slug = 'introductions');

INSERT INTO forum_forums (category_id, name, slug, description, icon, sort_order)
SELECT c.id, 'General chat', 'general-chat',
       'Anything that doesn''t fit elsewhere — travel news, off-topic conversation.',
       'comments', 30
FROM forum_categories c
WHERE c.slug = 'community'
  AND NOT EXISTS (SELECT 1 FROM forum_forums WHERE slug = 'general-chat');

-- Forums in "Avios discussion" --------------------------------------------

INSERT INTO forum_forums (category_id, name, slug, description, icon, sort_order)
SELECT c.id, 'Redemptions', 'redemptions',
       'Share your best (and worst) Avios redemption stories.',
       'plane-departure', 10
FROM forum_categories c
WHERE c.slug = 'avios'
  AND NOT EXISTS (SELECT 1 FROM forum_forums WHERE slug = 'redemptions');

INSERT INTO forum_forums (category_id, name, slug, description, icon, sort_order)
SELECT c.id, 'Earning Avios', 'earning',
       'Cards, partners, promotions — anything that earns points.',
       'credit-card', 20
FROM forum_categories c
WHERE c.slug = 'avios'
  AND NOT EXISTS (SELECT 1 FROM forum_forums WHERE slug = 'earning');

INSERT INTO forum_forums (category_id, name, slug, description, icon, sort_order)
SELECT c.id, 'Help & support', 'help',
       'Stuck booking a flight or transferring points? Ask here.',
       'circle-question', 30
FROM forum_categories c
WHERE c.slug = 'avios'
  AND NOT EXISTS (SELECT 1 FROM forum_forums WHERE slug = 'help');
