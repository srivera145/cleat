<?php

declare(strict_types=1);

namespace Cleat\Security;

/**
 * Optional bot check on the pay and checkout forms. Plug in Turnstile,
 * hCaptcha or anything else by implementing this and passing it to the page
 * handler. Cleat ships only NullCaptcha.
 */
interface CaptchaInterface
{
    /** Verify the submitted form (e.g. $post['cf-turnstile-response']) server side. */
    public function verify(array $post, string $ip): bool;

    /** Widget markup placed inside the form. Trusted HTML from the host; printed unescaped. */
    public function widgetHtml(): string;

    /**
     * Extra origins the Content-Security-Policy must allow for the widget,
     * keyed by directive, e.g. ['script-src' => ['https://challenges.cloudflare.com'],
     * 'frame-src' => ['https://challenges.cloudflare.com']].
     *
     * @return array<string, list<string>>
     */
    public function cspSources(): array;
}
