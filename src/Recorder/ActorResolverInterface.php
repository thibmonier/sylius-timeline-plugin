<?php

declare(strict_types=1);

namespace Tmonier\SyliusTimelinePlugin\Recorder;

use Tmonier\SyliusTimelinePlugin\Model\Actor;

interface ActorResolverInterface
{
    /**
     * The authenticated admin or shop user of the current request, or `$fallback` (the
     * system by default) for anonymous requests, the console and message consumers.
     */
    public function resolve(?Actor $fallback = null): Actor;
}
