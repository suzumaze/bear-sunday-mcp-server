<?php

declare(strict_types=1);

namespace Acme\Demo\Resource\App;

use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\Annotation\Link;
use BEAR\Resource\ResourceObject;

final class Dashboard extends ResourceObject
{
    #[Embed(rel: 'user', src: 'app://self/user{?id}')]
    #[Link(rel: 'edit', href: 'app://self/user', method: 'patch')]
    public function onGet(): static
    {
        return $this;
    }
}
