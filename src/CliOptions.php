<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp;

final class CliOptions
{
    private function __construct(
        public readonly string $workspace,
        public readonly ?string $phpactor,
        public readonly float $timeout,
    ) {
    }

    /** @param list<string> $arguments */
    public static function parse(array $arguments): self
    {
        $values = [];
        for ($index = 0; $index < count($arguments); $index++) {
            $argument = $arguments[$index];
            if (!str_starts_with($argument, '--')) {
                throw new \InvalidArgumentException('Unexpected positional argument');
            }

            $parts = explode('=', substr($argument, 2), 2);
            $name = $parts[0];
            if (!in_array($name, ['workspace', 'phpactor', 'timeout'], true) || isset($values[$name])) {
                throw new \InvalidArgumentException('Unknown or repeated option');
            }

            if (isset($parts[1])) {
                $value = $parts[1];
            } else {
                $value = $arguments[++$index] ?? null;
                if ($value === null || str_starts_with($value, '--')) {
                    throw new \InvalidArgumentException('Option value is missing');
                }
            }
            $values[$name] = $value;
        }

        $workspace = $values['workspace'] ?? null;
        if (!is_string($workspace) || $workspace === '') {
            throw new \InvalidArgumentException('The --workspace option is required');
        }

        $timeoutValue = $values['timeout'] ?? '20';
        if (!is_numeric($timeoutValue)) {
            throw new \InvalidArgumentException('Timeout must be numeric');
        }
        $timeout = (float) $timeoutValue;
        if ($timeout <= 0 || $timeout > 60) {
            throw new \InvalidArgumentException('Timeout must be between 0 and 60 seconds');
        }

        $phpactor = $values['phpactor'] ?? null;

        return new self($workspace, $phpactor, $timeout);
    }
}
