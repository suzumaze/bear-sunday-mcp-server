<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp;

final class Workspace
{
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
}
