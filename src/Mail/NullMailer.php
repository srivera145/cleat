<?php

declare(strict_types=1);

namespace Cleat\Mail;

/** Discards everything. The default until the host passes a real mailer. */
final class NullMailer implements MailerInterface
{
    public function send(string $to, string $subject, string $html, ?string $text = null): void
    {
    }
}
