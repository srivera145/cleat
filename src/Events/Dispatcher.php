<?php

declare(strict_types=1);

namespace Cleat\Events;

use Cleat\Support\Log;
use Throwable;

/**
 * A minimal event dispatcher. listen() an event class (or '*' for all of
 * them, which is how a host bridges into its own event system).
 *
 * Events fire after the database work they describe has committed: an event
 * dispatched inside a Cleat transaction is queued and released on commit, or
 * dropped on rollback. A listener that throws is logged and skipped by
 * default, because by then money has already moved and an exception would
 * only hide that from the caller. Call throwListenerExceptions(true) to
 * surface them instead (useful in tests).
 */
final class Dispatcher
{
    /** @var array<string, list<callable(object): void>> */
    private array $listeners = [];
    private bool $throw = false;
    private bool $deferring = false;
    /** @var list<object> */
    private array $deferred = [];

    /** @param callable(object): void $listener */
    public function listen(string $eventClass, callable $listener): void
    {
        $this->listeners[$eventClass][] = $listener;
    }

    public function dispatch(object $event): void
    {
        if ($this->deferring) {
            $this->deferred[] = $event;
            return;
        }
        $this->fire($event);
    }

    /** @internal Db::transaction() calls these around the outermost Cleat transaction. */
    public function beginDeferring(): void
    {
        $this->deferring = true;
    }

    /** @internal */
    public function deferredCount(): int
    {
        return count($this->deferred);
    }

    /** @internal Drop events queued after $keep, e.g. those raised inside a rolled-back savepoint. */
    public function discardDeferred(int $keep = 0): void
    {
        $this->deferred = array_slice($this->deferred, 0, $keep);
    }

    /** @internal */
    public function flushDeferred(): void
    {
        $this->deferring = false;
        $events = $this->deferred;
        $this->deferred = [];
        foreach ($events as $event) {
            $this->fire($event);
        }
    }

    private function fire(object $event): void
    {
        $listeners = [...($this->listeners[$event::class] ?? []), ...($this->listeners['*'] ?? [])];
        foreach ($listeners as $listener) {
            try {
                $listener($event);
            } catch (Throwable $e) {
                if ($this->throw) {
                    throw $e;
                }
                Log::error('Cleat event listener failed', [
                    'event' => $event::class,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    public function throwListenerExceptions(bool $throw = true): void
    {
        $this->throw = $throw;
    }

    public function forget(?string $eventClass = null): void
    {
        if ($eventClass === null) {
            $this->listeners = [];
        } else {
            unset($this->listeners[$eventClass]);
        }
    }
}
