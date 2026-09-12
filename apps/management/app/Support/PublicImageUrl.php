<?php

namespace App\Support;

final class PublicImageUrl
{
    /**
     * Convert a Google Drive share link into Drive's public image delivery URL.
     *
     * This is a syntactic conversion only: the file owner must still set Drive
     * sharing to "Anyone with the link". We deliberately do not fetch the URL
     * here, since server-side fetching would introduce an SSRF surface.
     */
    public static function normalize(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        if ($value === null || $value === '') {
            return null;
        }

        $parts = parse_url($value);
        $host = isset($parts['host']) ? strtolower($parts['host']) : null;

        if (! in_array($host, ['drive.google.com', 'www.drive.google.com'], true)) {
            return $value;
        }

        $path = $parts['path'] ?? '';
        $id = null;
        if (preg_match('#^/file/d/([A-Za-z0-9_-]{10,})/#', $path, $matches)) {
            $id = $matches[1];
        } else {
            parse_str($parts['query'] ?? '', $query);
            $candidate = $query['id'] ?? null;
            if (is_string($candidate) && preg_match('/^[A-Za-z0-9_-]{10,}$/', $candidate)) {
                $id = $candidate;
            }
        }

        if ($id === null) {
            return $value;
        }

        return 'https://drive.usercontent.google.com/download?id='.rawurlencode($id).'&export=view';
    }
}
