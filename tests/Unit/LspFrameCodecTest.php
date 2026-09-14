<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Suzumaze\BearSundayMcp\Lsp\LspException;
use Suzumaze\BearSundayMcp\Lsp\LspFrameCodec;

#[CoversClass(LspFrameCodec::class)]
final class LspFrameCodecTest extends TestCase
{
    public function testDecodesAFrameOnlyAfterTheCompleteBodyArrives(): void
    {
        $codec = new LspFrameCodec();
        $frame = $codec->encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['status' => 'ok']]);
        $split = intdiv(strlen($frame), 2);
        $buffer = substr($frame, 0, $split);

        self::assertNull($codec->decode($buffer));

        $buffer .= substr($frame, $split);
        self::assertSame(
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['status' => 'ok']],
            $codec->decode($buffer),
        );
        self::assertSame('', $buffer);
    }

    public function testLeavesTheNextFrameBuffered(): void
    {
        $codec = new LspFrameCodec();
        $buffer = $codec->encode(['jsonrpc' => '2.0', 'id' => 1])
            . $codec->encode(['jsonrpc' => '2.0', 'id' => 2]);

        $first = $codec->decode($buffer);
        $second = $codec->decode($buffer);
        self::assertSame(1, $first['id'] ?? null);
        self::assertSame(2, $second['id'] ?? null);
        self::assertSame('', $buffer);
    }

    public function testRejectsAnOversizedOutgoingFrame(): void
    {
        $this->expectException(LspException::class);
        (new LspFrameCodec(16))->encode(['value' => str_repeat('x', 32)]);
    }

    public function testRejectsAResponseWithoutContentLength(): void
    {
        $buffer = "Content-Type: application/json\r\n\r\n{}";
        $this->expectException(LspException::class);
        (new LspFrameCodec())->decode($buffer);
    }
}
