<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Exception;

/** The provider may have accepted the message; automatic retry is unsafe. */
class UnknownDeliveryException extends DriverException {}
