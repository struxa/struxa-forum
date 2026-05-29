# Struxa Forum

Community forum plugin for [Struxa CMS](https://github.com/struxa/struxa).

Categories, forums, threads and replies with Markdown, likes, subscriptions, tags, search, member profiles, and a full admin moderation panel. Threads sync to a CMS content type for SEO metadata, slugs and featured images.

## Install

1. Copy this repository into your Struxa site as `plugins/forum-plugin/`.
2. Enable **Forum** under **Admin → Plugins**.
3. Run migrations when prompted.

Requires Struxa CMS **1.0.0+** and PHP **8.1+**.

## Public routes

| Route | What it shows |
|---|---|
| `GET /forum` | Category-grouped forum list with last-post pointers and live stats. |
| `GET /forum/{forumSlug}` | Paginated thread list for a single forum. Sticky threads pinned. |
| `GET /forum/{forumSlug}/{threadSlug}` | Paginated thread view (first post + replies). Reply form inline when logged in. |
| `GET /forum/{forumSlug}/new` | New-thread form. Markdown-friendly textarea + live preview. |
| `POST /forum/post/new` | Create thread (auth required). |
| `POST /forum/post/reply` | Post a reply. |
| `POST /forum/post/{id}/edit` | Edit own post (or any post if moderator). |
| `POST /forum/post/{id}/delete` | Soft-delete a post. |
| `POST /forum/post/{id}/like` | Toggle like on a post. |
| `POST /forum/thread/{id}/subscribe` | Toggle watch on a thread. |

## Admin

Under `/admin/forum` (requires `edit_content` permission):

- **Dashboard** — members, threads, posts, online-now, newest member, recent activity.
- **Categories** — top-level groups.
- **Forums** — sections within a category, with optional sub-forums; locked / hidden flags.
- **Threads** — list across all forums; sticky/locked; soft-delete.
- **Posts** — moderation feed; soft-delete; restore from trash.
- **Settings** — guest reading, posts per page, edit window, and more.

## CMS content type

Migration `002_forum_content_type.sql` creates a **`forum-thread`** content type. Each thread is backed by a row in `forum_threads` **and** a `cms_content_entries` row linked via `forum_threads.entry_id`, so threads inherit CMS slug/SEO fields and featured images.

The content type has `has_public_route = 0` because the forum owns the `/forum/...` URLs.

## Features

| Feature | Notes |
|---|---|
| Categories + forums + sub-forums | `forum_categories.sort_order`, `forum_forums.parent_id`. |
| Threads + posts | `forum_threads`, `forum_posts`. |
| Sticky threads | Pinned in listing order. |
| Locked threads | Blocks new replies (mods can still post). |
| Markdown | Lightweight PHP renderer — no external dependency. |
| Quote reply | Client JS wraps selected text in `> …` in the reply box. |
| Post likes | `forum_post_likes` join table. |
| Subscriptions | Watch toggle per thread. |
| User ranks | Post-count badges via `RankService`. |
| Online now | Last-activity tracking via `StatsService::onlineNow()`. |
| Tags + search | Public tag pages and forum search. |
| Homepage widget | `forum_latest_threads()` Twig helper for latest discussions. |

## Permissions

Reuses Struxa CMS permission slugs:

- Reading: **public**
- Posting: **logged in**
- Editing/deleting own posts: **logged in and own the post** (or `edit_content`)
- Admin panel: `access_admin` + `edit_content`

## Uninstall

`uninstall.sql` drops all `forum_*` tables plus the `forum-thread` content type and its fields.

## License

MIT
