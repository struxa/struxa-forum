-- =====================================================================
-- 005_forum_icon_image.sql
--
-- Adds an optional `icon_image` column to forum_forums. Stores a
-- public-facing URL (absolute or root-relative) to an image used in
-- place of the FontAwesome `icon` value when present.
--
-- The two fields are mutually compatible: templates prefer
-- `icon_image` when set, otherwise fall back to `icon` (FA name),
-- otherwise to a default fa-comments glyph. This lets us migrate
-- forums to bespoke logos gradually without breaking the public UI.
-- =====================================================================
ALTER TABLE forum_forums
  ADD COLUMN icon_image VARCHAR(500) NULL DEFAULT NULL AFTER icon;
