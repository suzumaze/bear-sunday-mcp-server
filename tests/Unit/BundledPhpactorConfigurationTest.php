<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Suzumaze\BearSundayMcp\Lsp\BundledPhpactorConfiguration;
use Suzumaze\BearSundayMcp\Lsp\LspException;

#[CoversClass(BundledPhpactorConfiguration::class)]
final class BundledPhpactorConfigurationTest extends TestCase
{
    private string $root;
    private string $binary;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/bear-mcp-bundle-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/vendor/bin', 0700, true);
        $this->binary = $this->root . '/vendor/bin/phpactor';
        file_put_contents($this->binary, 'fake phpactor');
    }

    protected function tearDown(): void
    {
        if (is_file($this->root . '/.phpactor.json')) {
            unlink($this->root . '/.phpactor.json');
        }
        unlink($this->binary);
        rmdir($this->root . '/vendor/bin');
        rmdir($this->root . '/vendor');
        rmdir($this->root);
    }

    public function testLoadsGeneratedExtensionClassesForBundledBinary(): void
    {
        $classes = [
            'Suzumaze\\BearPhpactor\\BearSundayExtension',
            'Phpactor\\Extension\\Core\\CoreExtension',
        ];
        file_put_contents($this->root . '/.phpactor.json', json_encode([
            'container.extension_classes' => $classes,
        ], JSON_THROW_ON_ERROR));

        self::assertSame(
            ['container.extension_classes' => $classes],
            BundledPhpactorConfiguration::forCommand([$this->binary], $this->root),
        );
        self::assertSame([], BundledPhpactorConfiguration::forCommand([PHP_BINARY], $this->root));
    }

    public function testMissingGeneratedConfigurationFailsClearly(): void
    {
        $this->expectException(LspException::class);
        $this->expectExceptionMessage('composer run phpactor:init');
        BundledPhpactorConfiguration::forCommand([$this->binary], $this->root);
    }

    public function testConfigurationWithoutBearExtensionFailsClearly(): void
    {
        file_put_contents($this->root . '/.phpactor.json', json_encode([
            'container.extension_classes' => ['Phpactor\\Extension\\Core\\CoreExtension'],
        ], JSON_THROW_ON_ERROR));

        $this->expectException(LspException::class);
        BundledPhpactorConfiguration::forCommand([$this->binary], $this->root);
    }
}
