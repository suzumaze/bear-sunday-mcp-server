<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp;

final class PhpactorCommandResolver
{
    /** @return non-empty-list<string> */
    public static function resolve(Workspace $workspace, ?string $explicit = null): array
    {
        $configured = $explicit;
        if ($configured === null || $configured === '') {
            $environment = getenv('PHPACTOR_BIN');
            $configured = is_string($environment) && $environment !== '' ? $environment : null;
        }

        if ($configured !== null) {
            return [self::validate($configured)];
        }

        $bundledBinary = dirname(__DIR__) . '/vendor/bin/phpactor';
        if (is_file($bundledBinary) && is_executable($bundledBinary)) {
            return [$bundledBinary];
        }

        $workspaceBinary = $workspace->root . '/vendor/bin/phpactor';
        if (is_file($workspaceBinary) && is_executable($workspaceBinary)) {
            return [$workspaceBinary];
        }

        return ['phpactor'];
    }

    private static function validate(string $command): string
    {
        if ($command === '' || str_contains($command, "\0")) {
            throw new \InvalidArgumentException('Phpactor command is invalid');
        }

        if (!str_contains($command, '/') && !str_contains($command, '\\')) {
            if (preg_match('/^[A-Za-z0-9._-]+$/', $command) !== 1) {
                throw new \InvalidArgumentException('Phpactor command name is invalid');
            }

            return $command;
        }

        $canonical = realpath($command);
        if ($canonical === false || !is_file($canonical) || !is_executable($canonical)) {
            throw new \InvalidArgumentException('Phpactor command must be an executable file');
        }

        return $canonical;
    }
}
