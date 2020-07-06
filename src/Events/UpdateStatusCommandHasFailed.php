<?php

declare(strict_types=1);

namespace Avlyalin\SberbankAcquiring\Events;

use Avlyalin\SberbankAcquiring\Traits\PaymentEvent;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Event;

class UpdateStatusCommandHasFailed extends Event
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;
    use PaymentEvent;

    /**
     * @var array
     */
    private $exceptions;

    /**
     * AcquiringPaymentWasCreated constructor.
     *
     * @param array $exceptions
     */
    public function __construct(array $exceptions)
    {
        $this->exceptions = $exceptions;
    }
}
