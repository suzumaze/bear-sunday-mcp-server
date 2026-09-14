<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\Lsp;

final class LspFrameCodec
{
    private const MAX_HEADER_BYTES = 16 * 1024;

    public function __construct(private readonly int $maxFrameBytes = 4 * 1024 * 1024)
    {
        if ($maxFrameBytes < 1) {
            throw new \InvalidArgumentException('Maximum LSP frame size must be positive');
        }
    }

    /** @param array<string, mixed> $message */
    public function encode(array $message): string
    {
        $body = json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($body) > $this->maxFrameBytes) {
            throw new LspException('LSP request exceeds the configured size limit');
        }

        return sprintf("Content-Length: %d\r\n\r\n%s", strlen($body), $body);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function decode(string &$buffer): ?array
    {
        $separator = strpos($buffer, "\r\n\r\n");
        if ($separator === false) {
            if (strlen($buffer) > self::MAX_HEADER_BYTES) {
                throw new LspException('LSP response header exceeds the configured size limit');
            }

            return null;
        }

        if ($separator > self::MAX_HEADER_BYTES) {
            throw new LspException('LSP response header exceeds the configured size limit');
        }

        $header = substr($buffer, 0, $separator);
        if (preg_match('/(?:^|\r\n)Content-Length:\s*(\d+)\s*(?:\r\n|$)/i', $header, $matches) !== 1) {
            throw new LspException('Invalid LSP response header');
        }

        $length = (int) $matches[1];
        if ($length > $this->maxFrameBytes) {
            throw new LspException('LSP response exceeds the configured size limit');
        }

        $bodyOffset = $separator + 4;
        if (strlen($buffer) < $bodyOffset + $length) {
            return null;
        }

        $body = substr($buffer, $bodyOffset, $length);
        $buffer = substr($buffer, $bodyOffset + $length);

        try {
            $message = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new LspException('Invalid JSON in LSP response', 0, $exception);
        }
        if (!is_array($message) || array_is_list($message)) {
            throw new LspException('LSP response is not a JSON object');
        }

        return $message;
    }
}
