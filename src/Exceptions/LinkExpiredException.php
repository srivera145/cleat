<?php

declare(strict_types=1);

namespace Cleat\Exceptions;

/** A hosted invoice link or payment link is past its expiry, used up, or no longer payable. */
final class LinkExpiredException extends CleatException
{
}
