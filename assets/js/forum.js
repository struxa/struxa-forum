/* ============================================================
   Forum plugin — client behaviour
   - Quote button: copies the selected text (or the whole post)
     into the reply textarea, prefixed with "> " and the author.
   - Like button: optimistic update + XHR; falls back to the
     plain form POST if JS errors.
   - Inline edit toggle: shows the edit form inline, hides the
     post body, allows cancel.
   ============================================================ */
(function () {
  if (window.__forumPluginInit) {
    return;
  }
  window.__forumPluginInit = true;

  /** Resolve the reply textarea (id="reply-body") and focus it. */
  function getReplyBox() {
    return document.getElementById('reply-body');
  }

  /** Strip HTML from a node and return its plain text content. */
  function plainTextFrom(node) {
    return (node.innerText || node.textContent || '').trim();
  }

  /** Wrap each line of `text` with the markdown quote prefix. */
  function quoteWrap(author, text) {
    if (!text) {
      return '';
    }
    const cleaned = text
      .replace(/\u00a0/g, ' ')
      .split('\n')
      .map((line) => '> ' + line)
      .join('\n');
    return `> **${author} wrote:**\n${cleaned}\n\n`;
  }

  function attachQuoteButtons() {
    const buttons = document.querySelectorAll('.forum-quote-btn');
    if (!buttons.length) {
      return;
    }
    buttons.forEach((btn) => {
      btn.addEventListener('click', (ev) => {
        ev.preventDefault();
        const postId = btn.getAttribute('data-post-id');
        const author = btn.getAttribute('data-post-author') || 'user';
        const post = document.querySelector(`.forum-post-body[data-post-id="${postId}"]`);
        if (!post) {
          return;
        }
        // If the user has a text selection inside this post, prefer it.
        let snippet = '';
        const sel = window.getSelection ? window.getSelection() : null;
        if (sel && sel.toString().trim().length > 0 && post.contains(sel.anchorNode)) {
          snippet = sel.toString().trim();
        } else {
          snippet = plainTextFrom(post);
          // Truncate enormous quotes so we don't drown the reply box.
          if (snippet.length > 1000) {
            snippet = snippet.slice(0, 1000) + '…';
          }
        }
        const reply = getReplyBox();
        if (!reply) {
          return;
        }
        const before = reply.value ? reply.value.replace(/\s+$/, '') + '\n\n' : '';
        reply.value = before + quoteWrap(author, snippet);
        reply.scrollIntoView({ behavior: 'smooth', block: 'center' });
        reply.focus();
        // Place cursor at end.
        reply.selectionStart = reply.selectionEnd = reply.value.length;
      });
    });
  }

  /* Tiny, dependency-free toast for transient feedback (rate-limit
     warnings, etc.). Auto-dismisses after `ms`; multiple toasts stack. */
  function showForumToast(message, ms) {
    let host = document.getElementById('forum-toast-host');
    if (!host) {
      host = document.createElement('div');
      host.id = 'forum-toast-host';
      host.className = 'forum-toast-host';
      host.setAttribute('role', 'status');
      host.setAttribute('aria-live', 'polite');
      document.body.appendChild(host);
    }
    const t = document.createElement('div');
    t.className = 'forum-toast';
    t.textContent = message;
    host.appendChild(t);
    const ttl = typeof ms === 'number' && ms > 0 ? ms : 3000;
    setTimeout(() => {
      t.classList.add('is-leaving');
      setTimeout(() => {
        if (t.parentNode) t.parentNode.removeChild(t);
      }, 240);
    }, ttl);
  }

  function attachLikeForms() {
    /* Per-button cooldown: when the server returns 429 we lock that
       button locally for `retry_after` seconds so a) the user gets
       immediate visual feedback and b) we don't pile more requests
       into a bucket that's already over its limit. */
    const lockButton = (btn, seconds) => {
      const ms = Math.max(1, seconds) * 1000;
      btn.setAttribute('disabled', 'disabled');
      btn.classList.add('is-cooldown');
      setTimeout(() => {
        btn.removeAttribute('disabled');
        btn.classList.remove('is-cooldown');
      }, ms);
    };

    document.querySelectorAll('.forum-like-form').forEach((form) => {
      form.addEventListener('submit', (ev) => {
        const btn = form.querySelector('button[type="submit"]');
        if (!btn || btn.hasAttribute('disabled')) {
          return;
        }
        ev.preventDefault();
        const data = new FormData(form);
        fetch(form.action, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          body: data,
        })
          .then((r) => {
            if (r.status === 429) {
              return r.json().catch(() => ({ retry_after: parseInt(r.headers.get('Retry-After') || '5', 10) }))
                .then((j) => {
                  const wait = Math.max(1, parseInt((j && j.retry_after) || 5, 10));
                  lockButton(btn, wait);
                  showForumToast('Slow down — try again in ' + wait + 's', Math.min(wait * 1000, 5000));
                  /* Throw a sentinel so the success branch doesn't run.
                     Caught silently below — the toast IS the feedback. */
                  throw new Error('rate_limited');
                });
            }
            return r.ok ? r.json() : Promise.reject(r.status);
          })
          .then((j) => {
            if (!j || !j.ok) {
              throw new Error('bad response');
            }
            btn.classList.toggle('is-liked', !!j.liked);
            btn.setAttribute('aria-pressed', j.liked ? 'true' : 'false');
            const icon = btn.querySelector('i');
            if (icon) {
              icon.classList.toggle('fa-solid', !!j.liked);
              icon.classList.toggle('fa-regular', !j.liked);
            }
            const count = btn.querySelector('.forum-like-count');
            if (count) {
              count.textContent = new Intl.NumberFormat().format(j.count || 0);
            }
          })
          .catch((err) => {
            /* Rate-limit is already surfaced via toast; only fall back
               to the full-page POST for other transport errors. */
            if (err && err.message === 'rate_limited') {
              return;
            }
            form.submit();
          });
      });
    });
  }

  function attachEditToggles() {
    document.querySelectorAll('.forum-edit-toggle').forEach((btn) => {
      btn.addEventListener('click', (ev) => {
        ev.preventDefault();
        const id = btn.getAttribute('data-post-id');
        const form = document.querySelector(`.forum-edit-form[data-post-id="${id}"]`);
        const body = document.querySelector(`.forum-post-body[data-post-id="${id}"]`);
        if (!form || !body) {
          return;
        }
        form.hidden = false;
        body.style.display = 'none';
        const ta = form.querySelector('textarea');
        if (ta) {
          ta.focus();
          ta.selectionStart = ta.selectionEnd = ta.value.length;
        }
      });
    });
    document.querySelectorAll('.forum-edit-cancel').forEach((btn) => {
      btn.addEventListener('click', (ev) => {
        ev.preventDefault();
        const id = btn.getAttribute('data-post-id');
        const form = document.querySelector(`.forum-edit-form[data-post-id="${id}"]`);
        const body = document.querySelector(`.forum-post-body[data-post-id="${id}"]`);
        if (form) form.hidden = true;
        if (body) body.style.display = '';
      });
    });
  }

  /**
   * Report flow: opens a small modal pre-filled with the post id and
   * lets the user pick a reason + optional context. The modal is
   * static markup in thread.twig; we just route the click through to
   * the form's action.
   */
  /**
   * Delete-post confirmation modal.
   *
   * Each per-post Delete button is a plain `<button class="forum-delete-btn"
   * data-post-id="..." data-post-author="..." data-post-is-op="0|1">`.
   * Clicking it points the shared `#forum-delete-modal` at the right
   * post (rewrites the form action), tweaks the body copy for the
   * thread's first post (a destructive op — soft-deletes the whole
   * thread), then opens the modal.
   *
   * Submitting the form POSTs to /forum/post/{id}/delete with the CSRF
   * token already in the markup; the server enforces moderator/owner
   * authorisation.
   */
  function attachDeletePostButtons() {
    const modal = document.getElementById('forum-delete-modal');
    const form  = document.getElementById('forum-delete-form');
    if (!modal || !form) {
      return;
    }
    const titleEl   = modal.querySelector('#forum-delete-title');
    const subEl     = modal.querySelector('#forum-delete-sub');
    const labelEl   = modal.querySelector('#forum-delete-confirm-label');
    const warnEl    = modal.querySelector('#forum-delete-warning');
    const submitBtn = form.querySelector('button[type="submit"]');

    const closeModal = () => { modal.hidden = true; };
    modal.querySelectorAll('[data-forum-modal-close]').forEach((el) => {
      el.addEventListener('click', closeModal);
    });
    document.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape' && !modal.hidden) {
        closeModal();
      }
    });

    document.querySelectorAll('.forum-delete-btn').forEach((btn) => {
      btn.addEventListener('click', (ev) => {
        ev.preventDefault();
        const postId = btn.getAttribute('data-post-id');
        if (!postId) return;
        const author = btn.getAttribute('data-post-author') || 'this member';
        const isOp   = btn.getAttribute('data-post-is-op') === '1';

        form.action = '/forum/post/' + encodeURIComponent(postId) + '/delete';

        if (isOp) {
          if (titleEl)   titleEl.textContent = 'Delete this thread?';
          if (subEl)     subEl.textContent = 'This is the first post in the thread. Deleting it will hide the entire thread (replies and all) from public view.';
          if (labelEl)   labelEl.textContent = 'Delete thread';
          if (warnEl)    warnEl.hidden = false;
        } else {
          if (titleEl)   titleEl.textContent = 'Delete this post by ' + author + '?';
          if (subEl)     subEl.textContent   = 'This soft-deletes the post — moderators can restore it from /admin/forum/posts if it was a mistake.';
          if (labelEl)   labelEl.textContent = 'Delete post';
          if (warnEl)    warnEl.hidden = true;
        }

        modal.hidden = false;
        if (submitBtn) submitBtn.focus();
      });
    });
  }

  function attachReportButtons() {
    const modal = document.getElementById('forum-report-modal');
    const form = document.getElementById('forum-report-form');
    if (!modal || !form) {
      return;
    }
    const closeModal = () => { modal.hidden = true; };
    modal.querySelectorAll('[data-forum-modal-close]').forEach((el) => {
      el.addEventListener('click', closeModal);
    });
    document.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape' && !modal.hidden) {
        closeModal();
      }
    });

    document.querySelectorAll('.forum-report-btn').forEach((btn) => {
      btn.addEventListener('click', (ev) => {
        ev.preventDefault();
        if (btn.hasAttribute('disabled')) {
          return;
        }
        const postId = btn.getAttribute('data-post-id');
        if (!postId) {
          return;
        }
        form.action = '/forum/post/' + encodeURIComponent(postId) + '/report';
        form.reset();
        modal.hidden = false;
        const sel = form.querySelector('select');
        if (sel) sel.focus();
      });
    });
  }

  /**
   * Share popovers + copy-link buttons.
   *
   * Each per-post Share button is `<button data-share-popover>` followed
   * by a `<div class="forum-share-popover" hidden>` sibling containing
   * the share strip. Clicking the trigger toggles `hidden` and the
   * `aria-expanded` state; clicking anywhere else (or Escape) closes any
   * open popover.
   *
   * Position math
   * -------------
   * The popover renders as `position: fixed` (see forum.css) so it can
   * escape ancestor `overflow: hidden` containers (e.g. `.forum-post`
   * which clips us at the card boundary). That means CSS can't anchor it
   * to the trigger — we compute the inline `top` / `left` here at open
   * time and flip the placement when the natural spot would overflow the
   * viewport edges.
   *
   * Strategy:
   *   - Anchor below the trigger by default, right-aligned to it.
   *   - If that overflows the bottom edge → place above the trigger.
   *   - If right-anchoring overflows the right edge → switch to left-anchor.
   *   - Always clamp to an 8px gutter from every edge.
   *   - Set `data-share-anchor` so the CSS arrow + transform-origin flip.
   *
   * Inside every share strip we also intercept clicks on `[data-share-popup]`
   * anchors so X / Facebook / LinkedIn / Reddit / WhatsApp / Telegram open
   * in a centred popup window instead of a full-screen tab — the standard
   * UX users expect from "Share" buttons. Plain anchors still work without
   * JS via the `href`.
   *
   * `[data-share-copy]` runs `navigator.clipboard.writeText(target)`,
   * flips the button into a green "Copied!" state for ~1.8s, then resets.
   * Falls back to a hidden textarea + execCommand on older browsers so
   * even pre-2019 Safari doesn't break.
   */
  function positionSharePopover(trigger, popover) {
    // Reset any previous inline state so measurements are clean.
    popover.style.top = '';
    popover.style.left = '';
    popover.removeAttribute('data-share-anchor');

    const rect = trigger.getBoundingClientRect();
    const popRect = popover.getBoundingClientRect();
    const margin = 8;
    const gap = 8;
    const vw = window.innerWidth || document.documentElement.clientWidth;
    const vh = window.innerHeight || document.documentElement.clientHeight;

    // Vertical: try below the trigger, fall back to above if no room.
    let top = rect.bottom + gap;
    let anchorAbove = false;
    if (top + popRect.height > vh - margin && rect.top - gap - popRect.height >= margin) {
      top = rect.top - gap - popRect.height;
      anchorAbove = true;
    }

    // Horizontal: prefer right-aligned to the trigger so the menu hangs to
    // the left under the button. Switch to left-anchor when that would
    // overflow the left edge of the viewport.
    let left = rect.right - popRect.width;
    let anchorLeft = false;
    if (left < margin) {
      left = rect.left;
      anchorLeft = true;
    }
    // Final clamp so the menu never escapes the viewport on either side.
    if (left + popRect.width > vw - margin) {
      left = vw - margin - popRect.width;
    }
    if (left < margin) {
      left = margin;
    }

    popover.style.top = Math.round(top) + 'px';
    popover.style.left = Math.round(left) + 'px';
    if (anchorAbove) {
      popover.setAttribute('data-share-anchor', 'above');
    } else if (anchorLeft) {
      popover.setAttribute('data-share-anchor', 'left');
    }
  }

  function attachSharePopovers() {
    const triggers = document.querySelectorAll('[data-share-popover]');
    if (triggers.length === 0) {
      return;
    }
    let openPopover = null;
    let openTrigger = null;

    const closeAll = (exceptTrigger) => {
      document.querySelectorAll('[data-share-popover]').forEach((btn) => {
        if (btn === exceptTrigger) {
          return;
        }
        const popover = btn.nextElementSibling;
        if (popover && popover.classList && popover.classList.contains('forum-share-popover')) {
          popover.hidden = true;
          btn.setAttribute('aria-expanded', 'false');
          // Clear inline position so the next open recomputes from scratch.
          popover.style.top = '';
          popover.style.left = '';
          popover.removeAttribute('data-share-anchor');
        }
      });
      if (exceptTrigger === null) {
        openPopover = null;
        openTrigger = null;
      }
    };

    triggers.forEach((btn) => {
      btn.addEventListener('click', (ev) => {
        ev.preventDefault();
        ev.stopPropagation();
        const popover = btn.nextElementSibling;
        if (!popover || !popover.classList.contains('forum-share-popover')) {
          return;
        }
        const opening = popover.hidden;
        closeAll(btn);
        if (opening) {
          // Reveal first so getBoundingClientRect can measure the popover.
          popover.hidden = false;
          btn.setAttribute('aria-expanded', 'true');
          // Two passes — first measure, then position. The fixed-position
          // popover starts off-screen via CSS so this is invisible.
          positionSharePopover(btn, popover);
          openPopover = popover;
          openTrigger = btn;
        } else {
          popover.hidden = true;
          btn.setAttribute('aria-expanded', 'false');
          openPopover = null;
          openTrigger = null;
        }
      });
    });

    // Click anywhere outside an open popover → close.
    document.addEventListener('click', (ev) => {
      const inside = ev.target.closest('.forum-share-popover, [data-share-popover]');
      if (!inside) {
        closeAll(null);
      }
    });
    document.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape') {
        closeAll(null);
      }
    });

    // Re-position while a popover is open (scroll/resize). Throttled with
    // requestAnimationFrame so we don't thrash layout. `passive: true` on
    // scroll keeps scroll perf snappy.
    let raf = 0;
    const reposition = () => {
      if (!openPopover || !openTrigger || openPopover.hidden) {
        return;
      }
      if (raf) cancelAnimationFrame(raf);
      raf = requestAnimationFrame(() => {
        raf = 0;
        positionSharePopover(openTrigger, openPopover);
      });
    };
    window.addEventListener('scroll', reposition, { passive: true });
    window.addEventListener('resize', reposition);
  }

  function attachSharePopupLinks() {
    document.querySelectorAll('[data-share-popup]').forEach((a) => {
      a.addEventListener('click', (ev) => {
        const url = a.getAttribute('href');
        if (!url || url.startsWith('mailto:')) {
          return;
        }
        // Skip the popup if the user is using a modifier key (cmd/ctrl-click
        // to open in a new tab, shift-click for a new window, etc.).
        if (ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) {
          return;
        }
        ev.preventDefault();
        const w = 600;
        const h = 540;
        const dualScreenLeft = window.screenLeft !== undefined ? window.screenLeft : window.screenX || 0;
        const dualScreenTop = window.screenTop !== undefined ? window.screenTop : window.screenY || 0;
        const winWidth = window.innerWidth || document.documentElement.clientWidth || screen.width;
        const winHeight = window.innerHeight || document.documentElement.clientHeight || screen.height;
        const left = dualScreenLeft + Math.max(0, (winWidth - w) / 2);
        const top = dualScreenTop + Math.max(0, (winHeight - h) / 2);
        const features = `noopener,noreferrer,scrollbars=yes,resizable=yes,width=${w},height=${h},top=${top},left=${left}`;
        const popup = window.open(url, 'forum-share', features);
        if (!popup) {
          // Popup-blocker bounced us — fall back to a regular new tab.
          window.location.href = url;
        }
      });
    });
  }

  /** Copy to clipboard with a friendly visual cue. */
  function copyToClipboard(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text);
    }
    return new Promise((resolve, reject) => {
      try {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        const ok = document.execCommand('copy');
        document.body.removeChild(ta);
        ok ? resolve() : reject();
      } catch (e) {
        reject(e);
      }
    });
  }

  function attachShareCopy() {
    document.querySelectorAll('[data-share-copy]').forEach((btn) => {
      btn.addEventListener('click', (ev) => {
        ev.preventDefault();
        const target = btn.getAttribute('data-share-target') || window.location.href;
        const label = btn.querySelector('.forum-share-btn-label');
        const originalLabel = label ? label.textContent : 'Copy link';
        copyToClipboard(target)
          .then(() => {
            btn.classList.add('is-copied');
            if (label) label.textContent = 'Copied!';
            setTimeout(() => {
              btn.classList.remove('is-copied');
              if (label) label.textContent = originalLabel;
            }, 1800);
          })
          .catch(() => {
            if (label) label.textContent = 'Copy failed';
            setTimeout(() => {
              if (label) label.textContent = originalLabel;
            }, 1800);
          });
      });
    });
  }

  /**
   * Live word counter for any textarea that opts in via
   * `data-forum-wordcount="<panel-id>"` + `data-min-words="N"`.
   *
   * The counted body is sanitised in the same spirit as the
   * server-side `$countWords` helper in `routes/public.php`:
   *
   *   - fenced ```code``` blocks → stripped
   *   - inline `code` → stripped
   *   - markdown link syntax → counts the label only
   *   - bare URLs → counted as a single "word"
   *   - markdown punctuation (*, _, #, >, -, list bullets) → stripped
   *
   * That keeps the client + server in agreement so a user never sees
   * "16 words" client-side but gets rejected as 14 on submit.
   *
   * Side-effects:
   *   - updates `.forum-wordcount__current` text
   *   - toggles `is-ok` / `is-warn` classes on the panel + form
   *   - leaves submission enabled (HTML5 `required` still catches blanks);
   *     the server is the source of truth for the hard floor.
   */
  function attachWordCounters() {
    const textareas = document.querySelectorAll('textarea[data-forum-wordcount]');
    if (!textareas.length) return;

    function countWords(raw) {
      let b = (raw || '').trim();
      if (!b) return 0;
      b = b.replace(/```[\s\S]*?```/g, ' ');
      b = b.replace(/`[^`]+`/g, ' ');
      b = b.replace(/!\[[^\]]*\]\([^)\s]+\)/g, ' ');
      b = b.replace(/\[([^\]]+)\]\([^)\s]+\)/g, '$1');
      b = b.replace(/https?:\/\/\S+/gi, ' ');
      b = b.replace(/[#>*_~\-]+/g, ' ');
      b = b.replace(/^\s*\d+\.\s+/gm, ' ');
      const tokens = b.trim().split(/[\s\u00A0]+/u).filter(Boolean);
      return tokens.length;
    }

    textareas.forEach(function (ta) {
      const panelId = ta.getAttribute('data-forum-wordcount');
      const panel = panelId ? document.getElementById(panelId) : null;
      const min = parseInt(ta.getAttribute('data-min-words') || '0', 10) || 0;
      const cur = panel ? panel.querySelector('.forum-wordcount__current') : null;
      const form = ta.closest('form');

      function update() {
        const n = countWords(ta.value);
        if (cur) cur.textContent = String(n);
        if (panel) {
          panel.classList.toggle('is-ok', min > 0 && n >= min);
          panel.classList.toggle('is-warn', min > 0 && n > 0 && n < min);
        }
        if (form) {
          form.classList.toggle('has-min-words-met', min > 0 && n >= min);
        }
      }

      ta.addEventListener('input', update);
      ta.addEventListener('blur', update);
      update();
    });
  }

  function init() {
    attachQuoteButtons();
    attachLikeForms();
    attachEditToggles();
    attachReportButtons();
    attachDeletePostButtons();
    attachSharePopovers();
    attachSharePopupLinks();
    attachShareCopy();
    attachWordCounters();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
