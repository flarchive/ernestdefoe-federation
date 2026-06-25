<?php

namespace ErnestDefoe\Federation\Job;

use ErnestDefoe\Federation\Service\InboxProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Verify + process one inbound inbox activity off the web worker. The inbox
 * endpoint does only a syntactic Signature-header check, then queues this and
 * returns 202 immediately, so a slow/unreachable remote (the signature check
 * fetches the actor) can't tie up PHP-FPM workers under a flood.
 *
 * Only plain, serializable request parts are carried — never the PSR-7 request.
 * On Flarum's default `sync` driver this still runs inline (no behaviour change);
 * operators with a real queue driver get the protection for free.
 */
class ProcessInboxActivity implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    /**
     * @param  array<string,string>  $headers  lower-cased header name => value
     * @param  int|null  $targetUserId  the addressed member, or null = community
     * @param  int  $receivedAt  unix time the request arrived (for freshness)
     */
    public function __construct(
        public string $method,
        public string $requestTarget,
        public array $headers,
        public string $rawBody,
        public ?int $targetUserId = null,
        public int $receivedAt = 0,
    ) {}

    public function handle(InboxProcessor $processor): void
    {
        $processor->process($this->method, $this->requestTarget, $this->headers, $this->rawBody, $this->targetUserId, $this->receivedAt);
    }
}
