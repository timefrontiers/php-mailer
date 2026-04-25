<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Exception;

/** Base exception for all php-mailer errors. */
class MailerException extends \RuntimeException {}

/** Thrown when Config::set() has not been called before use. */
class ConfigException extends MailerException {}

/** Thrown by a driver when message dispatch fails. */
class DriverException extends MailerException {}

/** Thrown when entity input fails validation. */
class ValidationException extends MailerException {}
