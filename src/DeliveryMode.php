<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer;

/** Each deliverable recipient gets an independent provider message. */
enum DeliveryMode: string
{
  case INDIVIDUAL = 'individual';
}
