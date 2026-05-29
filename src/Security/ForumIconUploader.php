<?php

declare(strict_types=1);

namespace ForumPlugin\Security;

use Psr\Http\Message\UploadedFileInterface;

/**
 * Validates and persists forum icon uploads.
 *
 * Storage layout:
 *   public/uploads/forum/icons/<slug>-<short-hash>.<ext>
 *
 * The slug is sanitised by the caller (typically the forum slug) and we
 * append a short content hash so re-uploads don't collide and stale CDN
 * caches expire naturally. We deliberately accept a tight allow-list of
 * image MIME types and re-derive the extension from the *detected* MIME
 * (never the client-supplied filename) to avoid `evil.php.png` style
 * tricks.
 *
 * Returns the public URL (root-relative) on success, or null when no
 * usable upload was attached. Throws a RuntimeException for *real*
 * problems (oversize, unsupported type, write failure) so the caller
 * can show a flash error.
 */
final class ForumIconUploader
{
    /** @var array<string, string> MIME → file extension */
    private const ALLOWED_MIME = [
        'image/png'     => 'png',
        'image/jpeg'    => 'jpg',
        'image/webp'    => 'webp',
        'image/gif'     => 'gif',
        'image/svg+xml' => 'svg',
    ];

    private const MAX_BYTES = 2 * 1024 * 1024;

    public function __construct(
        private readonly string $publicRoot,
        private readonly string $publicSubdir = 'uploads/forum/icons',
    ) {
    }

    /**
     * Persist the upload and return its public URL, or null when the
     * field wasn't filled in / was a clean no-op.
     *
     * @throws \RuntimeException on invalid uploads
     */
    public function persist(?UploadedFileInterface $file, string $slug): ?string
    {
        if ($file === null) {
            return null;
        }
        $err = $file->getError();
        if ($err === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($err !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Upload failed (code ' . $err . ').');
        }
        $size = $file->getSize();
        if ($size === null || $size <= 0) {
            throw new \RuntimeException('Uploaded file is empty.');
        }
        if ($size > self::MAX_BYTES) {
            throw new \RuntimeException(sprintf(
                'Image is %s — max upload is %s.',
                self::humanBytes((int) $size),
                self::humanBytes(self::MAX_BYTES),
            ));
        }

        $stream = $file->getStream();
        $stream->rewind();
        $bytes = $stream->getContents();
        if ($bytes === '') {
            throw new \RuntimeException('Uploaded file is empty.');
        }

        $mime = $this->detectMime($bytes, $file->getClientMediaType());
        if (!isset(self::ALLOWED_MIME[$mime])) {
            throw new \RuntimeException('Unsupported image type: ' . $mime);
        }
        $ext = self::ALLOWED_MIME[$mime];

        $slug = $this->safeSlug($slug);
        $hash = substr(hash('sha256', $bytes), 0, 8);
        $name = ($slug !== '' ? $slug : 'forum-icon') . '-' . $hash . '.' . $ext;

        $dirAbs = rtrim($this->publicRoot, '/') . '/' . trim($this->publicSubdir, '/');
        if (!is_dir($dirAbs) && !mkdir($dirAbs, 0775, true) && !is_dir($dirAbs)) {
            throw new \RuntimeException('Could not create upload directory.');
        }

        $absPath = $dirAbs . '/' . $name;
        if (file_put_contents($absPath, $bytes) === false) {
            throw new \RuntimeException('Failed to save uploaded file.');
        }
        @chmod($absPath, 0644);

        // Return a root-relative URL so it's portable across hostnames.
        return '/' . trim($this->publicSubdir, '/') . '/' . $name;
    }

    /**
     * Validate a manually-typed URL. Accepts root-relative ("/...") or
     * absolute http(s):// only. Anything else is rejected.
     */
    public function normaliseUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return $url;
        }
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }
        throw new \RuntimeException('Image URL must start with "/" or "https://".');
    }

    private function detectMime(string $bytes, ?string $clientHint): string
    {
        // Detect from bytes first (authoritative), fall back to client
        // hint only for SVG which finfo doesn't always identify.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->buffer($bytes) ?: '';
        if ($detected === 'image/svg' || $detected === 'text/xml' || $detected === 'application/xml') {
            $detected = 'image/svg+xml';
        }
        if ($detected === '' && $clientHint !== null && isset(self::ALLOWED_MIME[$clientHint])) {
            return $clientHint;
        }
        return $detected;
    }

    private function safeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9\-]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        return substr($slug, 0, 60);
    }

    private static function humanBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / 1024 / 1024, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }
}
