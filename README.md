# Struxa Forum

Community forum plugin for [Struxa CMS](https://github.com/struxa/struxa).

Organise discussions into **categories → forums → threads → posts**, with Markdown, likes, subscriptions, tags, search, member profiles, rate limiting, reporting, and a full admin moderation panel. Threads sync to a CMS content type so you get SEO metadata, slugs, and featured images.

**Requirements:** Struxa CMS 1.0.0+, PHP 8.1+, MySQL/MariaDB.

---

## 1. Install the plugin files

Choose one method.

### Option A — Git clone (recommended)

From your Struxa site root:

```bash
git clone https://github.com/struxa/struxa-forum.git plugins/forum-plugin
```

To update later:

```bash
cd plugins/forum-plugin
git pull
```

### Option B — Download ZIP

1. Download the latest source from [github.com/struxa/struxa-forum](https://github.com/struxa/struxa-forum).
2. Extract so the plugin lives at **`plugins/forum-plugin/`** (the folder must contain `plugin.json`, `src/`, `routes/`, etc.).
3. Do **not** nest an extra directory (e.g. avoid `plugins/forum-plugin/struxa-forum-main/...`).

Expected layout:

```
your-site/
  plugins/
    forum-plugin/
      plugin.json
      routes/
      src/
      views/
      migrations/
      assets/
      uninstall.sql
```

---

## 2. Enable the plugin

1. Log in to **Admin**.
2. Open **Plugins** (or **Extensions → Plugins**, depending on your Struxa version).
3. Find **Forum** in the list.
4. Click **Enable**.

Struxa runs pending migrations automatically when a plugin is enabled. If your install prompts you to run migrations manually, use the admin migration tool or your usual Struxa update workflow.

---

## 3. Verify the database

The plugin creates these tables (all prefixed `forum_`):

| Table | Purpose |
|---|---|
| `forum_categories` | Top-level groups |
| `forum_forums` | Discussion boards (can nest as sub-forums) |
| `forum_threads` | Threads |
| `forum_posts` | Posts and replies |
| `forum_post_likes` | Per-post likes |
| `forum_subscriptions` | Watched threads |
| `forum_user_activity` | Online-now and post counts |
| `forum_settings` | Plugin configuration |
| `forum_reports` | User reports |

Migration `002_forum_content_type.sql` also registers a **`forum-thread`** CMS content type linked to each thread row.

No sample categories or forums are shipped — create your structure under **Admin → Forum** after install.

---

## 4. Configure the forum

### Admin → Forum → Settings

| Setting | Default | Notes |
|---|---|---|
| Threads per page | 20 | Forum thread listings |
| Posts per page | 15 | Replies shown per thread page |
| Edit window (minutes) | 15 | How long members can edit their own posts; moderators can always edit |
| Online-now window (minutes) | 5 | “Online now” stat on `/forum` |
| Minimum post length (chars) | 4 | Spam floor |
| Minimum thread title length | 4 | |
| Minimum post words | 15 | Set to `0` to disable word-count validation |

### Categories and forums

1. **Admin → Forum → Categories** — create top-level groups (e.g. Announcements, General).
2. **Admin → Forum → Forums** — create boards inside each category.
   - Set **icon** to a Font Awesome slug (e.g. `comments`, `plane`).
   - Optionally upload a custom **icon image**.
   - Use **parent forum** to create sub-forums.
   - **Locked** forums are read-only for members; **hidden** forums are omitted from public listings.

### Site name and theme

Public pages use your Struxa **site name** (`settings.site_name`) in titles and hero copy. Set it under **Admin → Settings → General**.

Forum templates extend your active theme’s `layouts/base.twig`, so the forum matches your storefront header, footer, and CSS. Use a theme that defines that layout (the bundled Struxa themes do).

---

## 5. Permissions and accounts

The plugin reuses existing Struxa CMS permissions:

| Action | Who |
|---|---|
| Read forums, threads, search | Everyone (guests) |
| Create threads, reply, like, subscribe | Logged-in CMS users |
| Edit/delete own posts | Post author (within edit window) |
| Edit/delete any post, sticky/lock threads | Users with `edit_content` |
| Admin forum panel | `access_admin` + `edit_content` |

Members need a Struxa CMS account. Enable **registration** under your site’s auth settings if you want public sign-ups, or create users in **Admin → Users**.

---

## 6. Public URLs

| URL | Description |
|---|---|
| `/forum` | Forum home — categories, stats, search |
| `/forum/{forumSlug}` | Thread list for one forum |
| `/forum/{forumSlug}/{threadSlug}` | Thread view |
| `/forum/{forumSlug}/new` | New thread form (login required) |
| `/forum/search?q=…` | Search (noindex) |
| `/forum/tag/{slug}` | Threads by tag |
| `/forum/user/{id}` | Member profile |

Sitemap entries are registered via `ForumSitemapService` when your site generates sitemaps.

---

## 7. Homepage widget (optional)

To show latest discussions on your homepage, include this in a theme template:

```twig
{% include '@plugin_forum_plugin/widgets/hot-topics.twig' ignore missing %}
```

Twig helpers registered by the plugin:

```twig
{{ forum_stats() }}
{{ forum_latest_threads(4) }}
{{ forum_hot_topics(24, 5) }}
{{ forum_relative_time(thread.last_post_at) }}
{{ forum_user_rank(user.posts) }}
```

Filter: `{{ post.body_markdown|forum_markdown }}`

---

## 8. After install checklist

- [ ] Plugin enabled and migrations applied
- [ ] At least one category and forum visible on `/forum`
- [ ] Test user can register/log in and post a reply
- [ ] **Admin → Forum → Dashboard** shows sensible counts
- [ ] Edit window and minimum word count feel right for your community
- [ ] Theme renders `/forum` with your site header/footer

---

## 9. Uninstall

1. Disable the plugin in **Admin → Plugins** (optional but recommended).
2. Run `uninstall.sql` against your database **or** use Struxa’s plugin uninstall action if available.

This drops all `forum_*` tables and removes the `forum-thread` content type. **Thread and post data is deleted permanently.**

---

## 10. Development

Plugin assets are cache-busted via `forum_asset('css/forum.css')`. After editing CSS or JS, hard-refresh the browser.

Admin styles live in `assets/css/forum-admin.css`. Public styles in `assets/css/forum.css`.

---

## License

MIT — see [plugin.json](plugin.json).

## Support

Open an issue: [github.com/struxa/struxa-forum/issues](https://github.com/struxa/struxa-forum/issues)
