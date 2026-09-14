<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\Lsp;

final class LspRpcException extends LspException
{
    public function __construct(public readonly int $rpcCode)
    {
        parent::__construct('Phpactor returned a JSON-RPC error', $rpcCode);
    }
}
