<?php

declare(strict_types=1);

namespace Acme\Demo\Module;

use Acme\Demo\Interceptor\AuditInterceptor;
use Acme\Demo\Service\Clock;
use Acme\Demo\Service\ClockInterface;
use Ray\Di\AbstractModule;

final class AppModule extends AbstractModule
{
    protected function configure(): void
    {
        $this->bind(ClockInterface::class)->to(Clock::class);
        $this->bindInterceptor(
            $this->matcher->any(),
            $this->matcher->startsWith('on'),
            [AuditInterceptor::class],
        );
    }
}
