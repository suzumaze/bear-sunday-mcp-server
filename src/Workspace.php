<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp;

use Suzumaze\BearSundayMcp\Lsp\FileUri;

final class Workspace
{
    private const MAX_DOCUMENT_BYTES = 1_048_576;

    private function __construct(public readonly string $root)
    {
    }

    public static function fromPath(string $path): self
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new \InvalidArgumentException('Workspace must be an existing directory');
        }

        $canonical = realpath($path);
        if ($canonical === false || !is_dir($canonical)) {
            throw new \InvalidArgumentException('Workspace must be an existing directory');
        }

        return new self($canonical);
    }

    public function readDocument(string $relativePath): WorkspaceDocument
    {
        $normalized = str_replace('\\', '/', $relativePath);
        if (!$this->validRelativePath($normalized)) {
            throw new WorkspaceFileException('invalid_input', 'Document path must be workspace-relative.');
        }

        $absolute = realpath($this->root . '/' . $normalized);
        if ($absolute === false || !is_file($absolute)) {
            throw new WorkspaceFileException('not_found', 'Document does not exist.');
        }
        $relative = $this->relativeExistingFile($absolute);
        if ($relative === null) {
            throw new WorkspaceFileException('outside_workspace', 'Document resolves outside the workspace.');
        }
        $size = filesize($absolute);
        if ($size === false || $size > self::MAX_DOCUMENT_BYTES) {
            throw new WorkspaceFileException('unsupported', 'Document exceeds the supported size.');
        }
        $contents = file_get_contents($absolute);
        if ($contents === false) {
            throw new WorkspaceFileException('not_found', 'Document could not be read.');
        }

        return new WorkspaceDocument(
            $relative,
            $absolute,
            FileUri::fromPath($absolute),
            $this->languageId($relative),
            $contents,
        );
    }

    public function relativeExistingFile(string $absolutePath): ?string
    {
        $canonical = realpath($absolutePath);
        if ($canonical === false || !is_file($canonical)) {
            return null;
        }
        $prefix = rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($canonical, $prefix)) {
            return null;
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', substr($canonical, strlen($prefix)));
    }

    public function configuredDirectory(string $relativePath): string
    {
        return $this->configuredPath($relativePath, true);
    }

    public function configuredFile(string $relativePath): string
    {
        return $this->configuredPath($relativePath, false);
    }

    private function configuredPath(string $relativePath, bool $directory): string
    {
        $normalized = str_replace('\\', '/', $relativePath);
        if (!$this->validRelativePath($normalized)) {
            throw new \InvalidArgumentException('Configured paths must be workspace-relative');
        }
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || $segment === '.') {
                throw new \InvalidArgumentException(
                    'Configured paths must not contain empty or current-directory segments',
                );
            }
        }
        $absolute = realpath($this->root . '/' . $normalized);
        if ($absolute === false || ($directory ? !is_dir($absolute) : !is_file($absolute))) {
            throw new \InvalidArgumentException('Configured query log path does not exist');
        }
        $prefix = rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($absolute, $prefix)) {
            throw new \InvalidArgumentException('Configured query log path resolves outside the workspace');
        }

        return $absolute;
    }

    private function validRelativePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0") || str_starts_with($path, '/')) {
            return false;
        }
        if (preg_match('/^[A-Za-z]:\//', $path) === 1) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
    }

    private function languageId(string $path): string
    {
        if (str_ends_with($path, '.html.twig') || str_ends_with($path, '.twig')) {
            return 'twig';
        }

        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'php' => 'php',
            'json' => 'json',
            'xml' => 'xml',
            'sql' => 'sql',
            default => 'plaintext',
        };
    }
}
