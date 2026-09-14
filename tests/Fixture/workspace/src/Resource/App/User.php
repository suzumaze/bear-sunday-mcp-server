<?php

declare(strict_types=1);

namespace Acme\Demo\Resource\App;

use BEAR\Resource\ResourceObject;

final class User extends ResourceObject
{
    public function onGet(): static
    {
        return $this;
    }
}
