<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Exception;

/** Thrown when Config::set() has not been called before use. */
class ConfigException extends MailerException {}
