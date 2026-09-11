<?php

namespace App\Support;

class YandexOrganizationUrl
{
    private const ORG_PATH_PATTERN = '#^/maps/org/[^/]+/(\d+)/?$#';

    private const SHORT_LINK_PATTERN = '#^/maps/-/[^/?]+/?$#';

    private const HOST_PATTERN = '/^(www\.)?yandex\.(ru|com|by|kz|ua|com\.tr)$/i';

    /**
     * Whether the URL looks like a Yandex Maps organization page: the
     * canonical /maps/org/{slug}/{id}/ path, a link carrying ?oid={id},
     * or a short link (/maps/-/...) that only resolves to one at parse time.
     */
    public static function isOrganizationUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (! $parts || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        if (! in_array($parts['scheme'], ['http', 'https'], true)) {
            return false;
        }

        if (! preg_match(self::HOST_PATTERN, $parts['host'])) {
            return false;
        }

        $path = $parts['path'] ?? '';

        if (! str_starts_with($path, '/maps/')) {
            return false;
        }

        if (preg_match(self::ORG_PATH_PATTERN, $path) || preg_match(self::SHORT_LINK_PATTERN, $path)) {
            return true;
        }

        return self::extractId($url) !== null;
    }

    /**
     * Extracts the organization id from an /org/.../{id}/ path or an
     * ?oid={id} query parameter. Returns null for short links, which can
     * only be resolved to an id after following their redirect.
     */
    public static function extractId(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';

        if (preg_match(self::ORG_PATH_PATTERN, $path, $matches)) {
            return $matches[1];
        }

        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        if (! empty($query['oid']) && is_numeric($query['oid'])) {
            return (string) $query['oid'];
        }

        return null;
    }

    /**
     * A stable form of the URL used to detect duplicate organizations:
     * lower-cased host, no trailing slash, no query string beyond `oid`.
     */
    public static function normalize(string $url): string
    {
        $parts = parse_url($url);

        if (! $parts) {
            return $url;
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host'] ?? '');
        $path = rtrim($parts['path'] ?? '', '/');

        $query = [];
        parse_str($parts['query'] ?? '', $query);
        $keptQuery = array_key_exists('oid', $query) ? ['oid' => $query['oid']] : [];

        $normalized = "{$scheme}://{$host}{$path}";

        if (! empty($keptQuery)) {
            $normalized .= '?'.http_build_query($keptQuery);
        }

        return $normalized;
    }
}
