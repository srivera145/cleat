<?php

declare(strict_types=1);

namespace Cleat\Mail;

/** Keeps sent messages in memory, for tests and local previews. */
final class ArrayMailer implements MailerInterface
{
    /** @var list<array{to: string, subject: string, html: string, text: ?string}> */
    public array $sent = [];

    public function send(string $to, string $subject, string $html, ?string $text = null): void
    {
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'html' => $html, 'text' => $text];
    }

    /** @return list<array{to: string, subject: string, html: string, text: ?string}> */
    public function to(string $address): array
    {
        return array_values(array_filter($this->sent, static fn (array $m) => strcasecmp($m['to'], $address) === 0));
    }

    public function last(): ?array
    {
        return $this->sent === [] ? null : $this->sent[array_key_last($this->sent)];
    }

    public function clear(): void
    {
        $this->sent = [];
    }
}
