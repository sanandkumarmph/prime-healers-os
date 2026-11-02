<?php

namespace App\Support;

final class LocalRedirect
{
    /** Accept application paths and this application's own absolute URLs only. */
    public static function path(mixed $candidate, string $fallback = '/'): string
    {
        if (!is_string($candidate) || $candidate === '' || strlen($candidate) > 4096) {
            return $fallback;
        }
        $decoded = rawurldecode($candidate);
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $candidate.$decoded)) {
            return $fallback;
        }
        if (str_starts_with($candidate, '/')) {
            return str_starts_with($decoded, '//') ? $fallback : $candidate;
        }
        $target = parse_url($candidate);
        $application = parse_url((string) config('app.url'));
        if (!$target || !$application || isset($target['user']) || isset($target['pass'])
            || !in_array(strtolower($target['scheme'] ?? ''), ['http', 'https'], true)
            || strtolower($target['scheme']) !== strtolower($application['scheme'] ?? '')
            || strtolower($target['host'] ?? '') !== strtolower($application['host'] ?? '')
            || ($target['port'] ?? (strtolower($target['scheme']) === 'https' ? 443 : 80))
                !== ($application['port'] ?? (strtolower($application['scheme'] ?? '') === 'https' ? 443 : 80))) {
            return $fallback;
        }
        $path = $target['path'] ?? '/';
        if (!str_starts_with($path, '/') || str_starts_with(rawurldecode($path), '//')) {
            return $fallback;
        }

        return $path.(isset($target['query']) ? '?'.$target['query'] : '').(isset($target['fragment']) ? '#'.$target['fragment'] : '');
    }
}
