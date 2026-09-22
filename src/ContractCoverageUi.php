<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp;

use RuntimeException;

final class ContractCoverageUi
{
    public const URI = 'ui://bear-sunday/contract-coverage';

    public static function html(): string
    {
        $html = file_get_contents(dirname(__DIR__) . '/resources/contract-coverage.html');
        if ($html === false) {
            throw new RuntimeException('The contract coverage UI resource could not be loaded.');
        }

        return $html;
    }

    private function __construct()
    {
    }
}
