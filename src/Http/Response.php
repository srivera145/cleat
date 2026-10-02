<?php

declare(strict_types=1);

namespace Cleat\Http;

/**
 * A plain value object. Cleat's handlers return one and never send output
 * themselves; the host turns it into a real response (Keel, Laravel, Slim,
 * or three lines of plain PHP).
 */
final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status = 200,
        public readonly array $headers = [],
        public readonly string $body = '',
    ) {
    }

    /** @param array<string, string> $headers */
    public static function html(string $body, int $status = 200, array $headers = []): self
    {
        return new self($status, ['Content-Type' => 'text/html; charset=utf-8'] + $headers, $body);
    }

    /** @param array<string, string> $headers */
    public static function text(string $body, int $status = 200, array $headers = []): self
    {
        return new self($status, ['Content-Type' => 'text/plain; charset=utf-8'] + $headers, $body);
    }

    /** @param array<string, string> $headers */
    public static function redirect(string $url, int $status = 303, array $headers = []): self
    {
        return new self($status, ['Location' => $url] + $headers, '');
    }

    /** @param array<string, mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'application/json'], (string) json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    public function withHeader(string $name, string $value): self
    {
        $headers = array_filter($this->headers, static fn (string $k) => strcasecmp($k, $name) !== 0, ARRAY_FILTER_USE_KEY);
        $headers[$name] = $value;
        return new self($this->status, $headers, $this->body);
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self
    {
        $response = $this;
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        return $response;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }
        return null;
    }
}
