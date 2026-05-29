<?php

declare(strict_types=1);

namespace ForumPlugin;

/**
 * Minimal Markdown-to-HTML renderer for forum posts.
 *
 * Intentionally tiny: we don't pull in CommonMark / Parsedown so the
 * plugin has zero composer dependencies. This is *not* a full Markdown
 * implementation — it covers the punch-list of things forum posts
 * actually use:
 *
 *   - Paragraphs (blank-line separated)
 *   - Block quotes: lines beginning with "> "
 *   - Unordered lists: lines beginning with "- " or "* "
 *   - Ordered lists: lines beginning with "1." (any leading digits)
 *   - Inline code: `code`
 *   - Fenced code blocks: ```lang … ```
 *   - Bold: **text**
 *   - Italic: _text_
 *   - Links: [label](https://example.com)
 *   - Auto-links: bare http(s):// URLs are linkified
 *   - Bare image-only paragraphs: ![alt](url) → <img>
 *   - Soft line-breaks within a paragraph become <br>
 *
 * Everything else is treated as plain text. All inputs are HTML-escaped
 * *before* the markdown rules run, then we re-inject markup, so a post
 * containing literal HTML is safe.
 */
final class MarkdownRenderer
{
    /** Match a bare URL we should auto-link. Kept conservative — no FTP, no mailtos. */
    private const URL_REGEX = '~(?<![\w/=\.\-])(https?://[^\s<>"\']+[^\s<>"\'\.,;:!\?\)])~i';

    public function render(string $markdown): string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);

        // Pull fenced code blocks out first so their contents don't get
        // mangled by the inline replacers below. We swap them for an
        // unlikely placeholder, then restore at the end.
        $codeBlocks = [];
        $markdown = preg_replace_callback(
            '/```([a-zA-Z0-9_+\-]*)\n(.*?)```/s',
            static function (array $m) use (&$codeBlocks): string {
                $lang = trim($m[1]);
                $body = htmlspecialchars($m[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $codeBlocks[] = '<pre class="forum-code"' . ($lang !== '' ? ' data-lang="' . htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') . '"' : '') . '><code>' . $body . '</code></pre>';
                return "\0FORUM_CODE_" . (count($codeBlocks) - 1) . "\0";
            },
            $markdown
        ) ?? $markdown;

        // Escape the rest of the content as HTML, *then* we'll selectively
        // re-introduce markup. This means even a post containing "<script>"
        // renders as visible text, never as live HTML.
        $escaped = htmlspecialchars($markdown, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $lines = explode("\n", $escaped);
        $html  = '';
        $i     = 0;
        $count = count($lines);

        while ($i < $count) {
            $line = $lines[$i];
            $trim = trim($line);

            // Skip blank lines between blocks
            if ($trim === '') {
                $i++;
                continue;
            }

            // ---- Block quote: a run of "> " prefixed lines --------------
            if (preg_match('/^&gt;\s?/', $line)) {
                $quoteLines = [];
                while ($i < $count && preg_match('/^&gt;\s?(.*)$/', $lines[$i], $m)) {
                    $quoteLines[] = $m[1];
                    $i++;
                }
                // Recursively render the inner content (so quotes can
                // contain bold/italic/links/etc.).
                $inner = $this->renderInlineBlock(implode("\n", $quoteLines));
                $html .= '<blockquote class="forum-quote">' . $inner . '</blockquote>';
                continue;
            }

            // ---- Unordered list: -, * --------------------------------------
            if (preg_match('/^([\-\*])\s+(.*)$/', $line, $m)) {
                $items = [];
                while ($i < $count && preg_match('/^([\-\*])\s+(.*)$/', $lines[$i], $m)) {
                    $items[] = $this->renderInline($m[2]);
                    $i++;
                }
                $html .= '<ul class="forum-list">';
                foreach ($items as $it) {
                    $html .= '<li>' . $it . '</li>';
                }
                $html .= '</ul>';
                continue;
            }

            // ---- Ordered list: 1., 2., 3. (any digits) ---------------------
            if (preg_match('/^\d+\.\s+(.*)$/', $line, $m)) {
                $items = [];
                while ($i < $count && preg_match('/^\d+\.\s+(.*)$/', $lines[$i], $m)) {
                    $items[] = $this->renderInline($m[1]);
                    $i++;
                }
                $html .= '<ol class="forum-list forum-list--ordered">';
                foreach ($items as $it) {
                    $html .= '<li>' . $it . '</li>';
                }
                $html .= '</ol>';
                continue;
            }

            // ---- Paragraph: gather contiguous non-blank, non-list, non-
            //      quote lines, then join with <br> for soft line breaks.
            $paraLines = [];
            while ($i < $count) {
                $cur = $lines[$i];
                $curTrim = trim($cur);
                if ($curTrim === '') {
                    break;
                }
                if (preg_match('/^&gt;\s?/', $cur)
                    || preg_match('/^[\-\*]\s+/', $cur)
                    || preg_match('/^\d+\.\s+/', $cur)
                ) {
                    break;
                }
                $paraLines[] = $cur;
                $i++;
            }
            $paragraph = implode("\n", $paraLines);

            // Standalone image paragraph → render as <img>, not wrapped
            // in <p>, so it can size correctly.
            if (preg_match('/^!\[([^\]]*)\]\(([^)\s]+)\)$/', trim($paragraph), $m)) {
                $alt = $m[1];
                $url = $this->sanitiseUrl($m[2]);
                if ($url !== null) {
                    $html .= '<p class="forum-image"><img src="' . $url . '" alt="' . $alt . '" loading="lazy" /></p>';
                    continue;
                }
            }

            $html .= '<p>' . $this->renderInline($paragraph) . '</p>';
        }

        // Restore the protected code blocks.
        foreach ($codeBlocks as $idx => $block) {
            $html = str_replace("\0FORUM_CODE_{$idx}\0", $block, $html);
        }

        return $html;
    }

    /**
     * Render an already-escaped block of content that may itself contain
     * paragraphs / lists / quotes. Used for the bodies of block quotes.
     */
    private function renderInlineBlock(string $escapedContent): string
    {
        // Recurse by un-escaping (since render() will re-escape). Cheap
        // and keeps the rules identical to a top-level render.
        $raw = html_entity_decode($escapedContent, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return $this->render($raw);
    }

    /**
     * Apply inline rules to a single paragraph's contents (already
     * HTML-escaped). Replaces single newlines with <br> at the very end
     * so soft wraps survive.
     */
    private function renderInline(string $escaped): string
    {
        // Inline code: `…`. Done first so e.g. **`code`** still renders
        // the code as code.
        $escaped = preg_replace_callback(
            '/`([^`]+)`/',
            static fn (array $m): string => '<code class="forum-code-inline">' . $m[1] . '</code>',
            $escaped
        ) ?? $escaped;

        // Markdown links: [label](url). The url is unescaped (the
        // pre-escape step has already encoded any quotes etc.).
        $escaped = preg_replace_callback(
            '/\[([^\]]+)\]\(([^)\s]+)\)/',
            function (array $m): string {
                $label = $m[1];
                $url = html_entity_decode($m[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $safe = $this->sanitiseUrl($url);
                if ($safe === null) {
                    return $label;
                }
                $rel = $this->relForUrl($url);
                return '<a href="' . $safe . '"' . $rel . '>' . $label . '</a>';
            },
            $escaped
        ) ?? $escaped;

        // Bold: **text** (non-greedy)
        $escaped = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $escaped) ?? $escaped;

        // Italic: _text_ — using underscores rather than * to avoid
        // colliding with bold and bare-word patterns.
        $escaped = preg_replace('/(^|\s)_([^_]+)_(?=\s|$|[\.,!\?])/u', '$1<em>$2</em>', $escaped) ?? $escaped;

        // Auto-link bare http(s) URLs that haven't already been linked.
        $escaped = preg_replace_callback(
            self::URL_REGEX,
            function (array $m): string {
                $url = html_entity_decode($m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $safe = $this->sanitiseUrl($url);
                if ($safe === null) {
                    return $m[1];
                }
                $rel = $this->relForUrl($url);
                return '<a href="' . $safe . '"' . $rel . '>' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '</a>';
            },
            $escaped
        ) ?? $escaped;

        // Soft line breaks within a paragraph: keep them as <br>.
        return nl2br($escaped, false);
    }

    /**
     * Validate that a URL is safe to render as an href. Only http(s) and
     * relative URLs (starting with /) are allowed; anything else (e.g.
     * javascript:) is dropped to avoid XSS.
     */
    private function sanitiseUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        }
        if (preg_match('#^https?://#i', $url)) {
            return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        }
        return null;
    }

    /**
     * Every user-submitted hyperlink renders with rel="nofollow ugc" so
     * search engines don't pass link equity from forum content and we
     * comply with Google's "user-generated content" guidance.
     *
     * External (absolute http/https) links additionally get target=_blank
     * + noopener + external to limit referrer leak and prevent
     * tabnabbing. Internal (relative `/...`) links are still flagged as
     * nofollow + ugc but stay in the same tab so navigation feels native.
     */
    private function relForUrl(string $url): string
    {
        $isInternal = str_starts_with($url, '/') && !str_starts_with($url, '//');
        if ($isInternal) {
            return ' rel="nofollow ugc"';
        }
        return ' target="_blank" rel="nofollow ugc noopener external"';
    }

    /**
     * Convenience plain-text excerpt for SEO descriptions / list pages.
     * Strips markdown punctuation and clamps to $maxChars (with ellipsis).
     */
    public function excerpt(string $markdown, int $maxChars = 220): string
    {
        $clean = preg_replace('/```.+?```/s', '', $markdown) ?? $markdown;
        $clean = preg_replace('/`([^`]+)`/', '$1', $clean) ?? $clean;
        $clean = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $clean) ?? $clean;
        $clean = preg_replace('/^&gt;\s?/m', '', $clean) ?? $clean;
        $clean = preg_replace('/^[\-\*]\s+/m', '', $clean) ?? $clean;
        $clean = preg_replace('/\*\*|__|_/', '', $clean) ?? $clean;
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? '');

        if (mb_strlen($clean) <= $maxChars) {
            return $clean;
        }

        return rtrim(mb_substr($clean, 0, $maxChars - 1), " \t\n\r\0\x0B.,;:!\?") . '…';
    }
}
