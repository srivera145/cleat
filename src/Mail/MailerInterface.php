<?php

declare(strict_types=1);

namespace Cleat\Mail;

/**
 * The only thing Cleat needs from a mail system. Wrap PHPMailer, Symfony
 * Mailer, Keel's mailer, or an API client in a ten-line adapter.
 */
interface MailerInterface
{
    public function send(string $to, string $subject, string $html, ?string $text = null): void;
}
