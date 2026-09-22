<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\Lsp;

/**
 * Apply the generated extension list only to the Phpactor bundled with this server.
 * An explicitly selected external Phpactor keeps its own configuration.
 */
final class BundledPhpactorConfiguration
{
    private const EXTENSION_CLASS = 'Suzumaze\\BearPhpactor\\BearSundayExtension';

    /**
     * @param non-empty-list<string> $commandPrefix
     * @return array{'container.extension_classes': non-empty-list<string>}|array{}
     */
    public static function forCommand(array $commandPrefix, ?string $serverRoot = null): array
    {
        $root = $serverRoot ?? dirname(__DIR__, 2);
        $bundled = realpath($root . '/vendor/bin/phpactor');
        $selected = realpath($commandPrefix[0]);
        if ($bundled === false || $selected === false || $selected !== $bundled) {
            return [];
        }

        $configPath = $root . '/.phpactor.json';
        if (!is_file($configPath)) {
            throw new LspException('Bundled Phpactor is not initialized; run composer run phpactor:init');
        }

        $json = file_get_contents($configPath);
        $config = is_string($json) ? json_decode($json, true) : null;
        $classes = is_array($config) ? ($config['container.extension_classes'] ?? null) : null;
        if (
            !is_array($classes) || !array_is_list($classes) || $classes === [] ||
            array_filter($classes, static fn (mixed $class): bool => !is_string($class) || $class === '') !== [] ||
            !in_array(self::EXTENSION_CLASS, $classes, true)
        ) {
            throw new LspException(
                'Bundled Phpactor extension configuration is invalid; run composer run phpactor:init',
            );
        }

        /** @var non-empty-list<string> $classes */
        return ['container.extension_classes' => $classes];
    }
}
