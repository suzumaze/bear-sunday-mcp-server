<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\Lsp;

final class FileUri
{
    public static function fromPath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $encoded = implode('/', array_map('rawurlencode', explode('/', $normalized)));
        $encoded = preg_replace('/^([A-Za-z])%3A/', '$1:', $encoded) ?? $encoded;

        return 'file://' . (str_starts_with($encoded, '/') ? '' : '/') . $encoded;
    }

    public static function toPath(string $uri): ?string
    {
        $parts = parse_url($uri);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'file') {
            return null;
        }
        $host = $parts['host'] ?? '';
        if ($host !== '' && $host !== 'localhost') {
            return null;
        }
        $path = $parts['path'] ?? null;
        if (!is_string($path)) {
            return null;
        }

        $path = rawurldecode($path);
        if (DIRECTORY_SEPARATOR === '\\' && preg_match('/^\/[A-Za-z]:\//', $path) === 1) {
            $path = substr($path, 1);
        }

        return DIRECTORY_SEPARATOR === '\\' ? str_replace('/', '\\', $path) : $path;
    }

    private function __construct()
    {
    }
}
