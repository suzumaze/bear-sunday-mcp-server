<?php

declare(strict_types=1);

function uri(string $uri): string
{
    return $uri;
}

function completionTarget(): string
{
    return uri('app://self/u');
}
