<?php

declare(strict_types=1);

namespace Acme\Demo\Resource\Page\Thing;

use BEAR\Resource\ResourceObject;

final class Detail extends ResourceObject
{
    public function onGet(string $id): static
    {
        return $this;
    }
}
