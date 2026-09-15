<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp;

use Mcp\Server\Transport\StdioTransport;
use Suzumaze\BearSundayMcp\Lsp\LazyPhpactorClient;

final class Application
{
    /** @param list<string> $arguments */
    public static function run(array $arguments): int
    {
        try {
            $options = CliOptions::parse($arguments);
            $workspace = Workspace::fromPath($options->workspace);
            $command = PhpactorCommandResolver::resolve($workspace, $options->phpactor);
        } catch (\InvalidArgumentException $exception) {
            fwrite(STDERR, $exception->getMessage() . "\n\n" . self::usage());

            return 2;
        }

        $client = new LazyPhpactorClient($workspace, $command, $options->timeout);
        try {
            return McpServerFactory::create(
                new SemanticTools($client),
                new StandardLspTools($client, $workspace),
            )->run(new StdioTransport());
        } finally {
            $client->close();
        }
    }

    private static function usage(): string
    {
        return "Usage: bear-sunday-mcp --workspace PATH [--phpactor PATH_OR_NAME] [--timeout SECONDS]\n";
    }
}
