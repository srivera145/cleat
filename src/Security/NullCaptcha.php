<?php

declare(strict_types=1);

namespace Cleat\Security;

/** No captcha: every submission passes, nothing is rendered. */
final class NullCaptcha implements CaptchaInterface
{
    public function verify(array $post, string $ip): bool
    {
        return true;
    }

    public function widgetHtml(): string
    {
        return '';
    }

    public function cspSources(): array
    {
        return [];
    }
}
