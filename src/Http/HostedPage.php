<?php

declare(strict_types=1);

namespace Cleat\Http;

use Cleat\Cleat;
use Cleat\Gateway\FakeGateway;
use Cleat\Security\CaptchaInterface;
use Cleat\Security\NullCaptcha;
use Cleat\Security\RateLimiter;
use Cleat\Support\View;
use DateTimeImmutable;

/**
 * Shared plumbing for the two public, unauthenticated pages: security
 * headers and CSP, rate limits, CSRF, captcha, and rendering. Construct one
 * per request with the host's session id (Cleat keeps no session itself).
 */
abstract class HostedPage
{
    protected readonly CaptchaInterface $captcha;
    protected readonly RateLimiter $limiter;
    protected readonly Csrf $csrf;
    protected readonly string $nonce;

    public function __construct(
        protected readonly string $sessionId = '',
        ?CaptchaInterface $captcha = null,
        ?RateLimiter $limiter = null,
        ?Csrf $csrf = null,
    ) {
        $this->captcha = $captcha ?? new NullCaptcha();
        $this->limiter = $limiter ?? new RateLimiter();
        $this->csrf = $csrf ?? Csrf::fromConfig();
        $this->nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    }

    /** @param array<string, mixed> $data */
    protected function page(string $template, array $data, int $status = 200, ?string $extraFormAction = null): Response
    {
        return Response::html(View::render($template, $data + $this->baseData()), $status, $this->securityHeaders($extraFormAction));
    }

    /** @return array<string, mixed> */
    protected function baseData(): array
    {
        return [
            'brand' => Cleat::config('brand'),
            'deckCss' => (string) Cleat::config('deck_css'),
            'cspNonce' => $this->nonce,
            'fakeMode' => Cleat::isFake(),
            'acceptJsUrl' => Cleat::acceptJsUrl(),
            'apiLoginId' => (string) Cleat::config('authorizenet.login_id'),
            'clientKey' => (string) Cleat::config('authorizenet.client_key'),
            'captchaHtml' => $this->captcha->widgetHtml(),
            'testTokens' => FakeGateway::TEST_TOKENS,
            'errors' => [],
        ];
    }

    /** @return array<string, string> */
    protected function securityHeaders(?string $extraFormAction = null): array
    {
        return [
            'Cache-Control' => 'no-store',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => $this->csp($extraFormAction),
        ];
    }

    /**
     * Only self, the Deck CDN and the Authorize.net Accept.js hosts, plus a
     * per-response nonce for Cleat's own inline <script> and <style>, the
     * brand logo's origin, and whatever a configured captcha declares.
     */
    protected function csp(?string $extraFormAction = null): string
    {
        $sandbox = Cleat::isSandbox();
        $acceptJs = $sandbox ? 'https://jstest.authorize.net' : 'https://js.authorize.net';
        $acceptApi = $sandbox ? ['https://apitest.authorize.net'] : ['https://api2.authorize.net', 'https://api.authorize.net'];
        $deck = self::origin((string) Cleat::config('deck_css'));
        $logo = self::origin((string) (Cleat::config('brand.logo_url') ?? ''));
        $extra = $this->captcha->cspSources();
        $nonce = "'nonce-{$this->nonce}'";

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", $nonce, $acceptJs, ...($extra['script-src'] ?? [])],
            'style-src' => ["'self'", $nonce, $deck, ...($extra['style-src'] ?? [])],
            'img-src' => ["'self'", 'data:', $logo, ...($extra['img-src'] ?? [])],
            'font-src' => ["'self'", $deck],
            'connect-src' => ["'self'", ...$acceptApi, ...($extra['connect-src'] ?? [])],
            'frame-src' => [$acceptJs, ...($extra['frame-src'] ?? [])],
            'form-action' => ["'self'", $extraFormAction],
            'frame-ancestors' => ["'none'"],
            'base-uri' => ["'none'"],
            'object-src' => ["'none'"],
        ];
        $parts = [];
        foreach ($directives as $name => $sources) {
            $parts[] = $name . ' ' . implode(' ', array_values(array_unique(array_filter($sources, static fn ($s) => $s !== null && $s !== ''))));
        }
        return implode('; ', $parts);
    }

    /**
     * Rate limits, in the order the spec gives: failed card attempts per IP,
     * submissions per IP, submissions per link. Returns a 429 or null.
     */
    protected function rateLimit(string $linkToken, string $ip, DateTimeImmutable $now): ?Response
    {
        $limits = Cleat::config('rate_limits');
        [$failMax, $failWindow] = $limits['failures_per_ip'];
        if ($this->limiter->tooMany('fail:ip:' . $ip, $failMax, $failWindow, $now)) {
            return $this->tooMany($failWindow, $now);
        }
        [$ipMax, $ipWindow] = $limits['per_ip'];
        if (!$this->limiter->hit('submit:ip:' . $ip, $ipMax, $ipWindow, $now)) {
            return $this->tooMany($ipWindow, $now);
        }
        [$linkMax, $linkWindow] = $limits['per_link'];
        if (!$this->limiter->hit('submit:link:' . $linkToken, $linkMax, $linkWindow, $now)) {
            return $this->tooMany($linkWindow, $now);
        }
        return null;
    }

    protected function recordFailure(string $ip, DateTimeImmutable $now): void
    {
        [$failMax, $failWindow] = Cleat::config('rate_limits.failures_per_ip');
        $this->limiter->hit('fail:ip:' . $ip, $failMax, $failWindow, $now);
    }

    protected function verifyCsrf(array $post, string $linkToken, DateTimeImmutable $now): bool
    {
        return $this->csrf->verify($post['_csrf'] ?? null, $linkToken, $this->sessionId, $now);
    }

    protected function csrfToken(string $linkToken, DateTimeImmutable $now): string
    {
        return $this->csrf->issue($linkToken, $this->sessionId, $now);
    }

    /**
     * Opaque data from the posted hidden fields, or null when missing.
     *
     * @return array{dataDescriptor: string, dataValue: string}|null
     */
    protected function opaqueFrom(array $post): ?array
    {
        $descriptor = $post['dataDescriptor'] ?? null;
        $value = $post['dataValue'] ?? null;
        if (!is_string($descriptor) || !is_string($value) || trim($descriptor) === '' || trim($value) === '') {
            return null;
        }
        return ['dataDescriptor' => $descriptor, 'dataValue' => $value];
    }

    protected static function cleanIp(string $ip): string
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : 'unknown';
    }

    protected function notFound(): Response
    {
        return $this->error(404, 'Page not found', 'This link is not valid. Check the address, or contact the sender for a new one.');
    }

    protected function gone(string $title, string $message): Response
    {
        return $this->error(410, $title, $message);
    }

    protected function forbidden(): Response
    {
        return $this->error(403, 'Session expired', 'This form expired or was opened in another browser. Reload the page and try again.');
    }

    protected function error(int $status, string $title, string $message): Response
    {
        return $this->page('error', ['status' => $status, 'title' => $title, 'message' => $message], $status);
    }

    private function tooMany(int $window, DateTimeImmutable $now): Response
    {
        return Response::text('Too many attempts. Please wait a few minutes and try again.', 429, [
            'Retry-After' => (string) $this->limiter->retryAfter($window, $now),
        ] + $this->securityHeaders());
    }

    private static function origin(string $url): ?string
    {
        if (preg_match('#^(https?://[^/?\#]+)#i', $url, $m) === 1) {
            return strtolower($m[1]);
        }
        return null;
    }
}
