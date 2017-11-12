<?php

namespace yiidreamteam\perfectmoney\events;

use yii\base\Event;
use yii\db\ActiveRecord;

/**
 * @author Alexey Samoylov <alexey.samoylov@gmail.com>
 */
class GatewayEvent extends Event
{
    const EVENT_PAYMENT_REQUEST = 'eventPaymentRequest';
    const EVENT_PAYMENT_SUCCESS = 'eventPaymentSuccess';

    /** @var ActiveRecord|null */
    public $invoice;

    /** @var array */
    public $gatewayData = [];
}
