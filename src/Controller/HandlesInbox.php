<?php

namespace ErnestDefoe\Federation\Controller;

use ErnestDefoe\Federation\Job\ProcessInboxActivity;
use ErnestDefoe\Federation\Service\SignatureVerifier;
use Flarum\User\User;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Shared by the community and per-member inboxes. The endpoint does the bare
 * minimum synchronously — confirm a Signature header is present — then queues the
 * activity for verification + processing and returns 202 immediately, so a slow
 * or hostile remote can't tie up web workers. All the real work (signature
 * verification, actor fetch, follow/create/delete) happens in {@see \ErnestDefoe\
 * Federation\Service\InboxProcessor} via {@see ProcessInboxActivity}.
 */
trait HandlesInbox
{
    /** Cap on the inbox body we'll buffer + queue (AP activities are tiny). */
    private const MAX_BODY = 65536; // 64 KB

    // Bus is injected by the using controller's own constructor (the trait no
    // longer defines a constructor, so it isn't coupled to a class hierarchy).
    protected Bus $bus;

    protected function processInbox(ServerRequestInterface $request, ?User $target): ResponseInterface
    {
        // Cheap pre-queue gate: a missing Signature is rejected outright; full
        // verification (and any drop of an invalid signature) happens in the job.
        if ($request->getHeaderLine('Signature') === '') {
            return new EmptyResponse(401);
        }

        // Reject oversized bodies before buffering them into a queue payload — a
        // hostile peer could otherwise bloat the job table with multi-MB rows.
        if ((int) $request->getHeaderLine('Content-Length') > self::MAX_BODY) {
            return new EmptyResponse(413);
        }
        $body = $request->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $raw = $body->read(self::MAX_BODY); // bounded read for chunked/no-length bodies

        $this->bus->dispatch(new ProcessInboxActivity(
            strtolower($request->getMethod()),
            $request->getRequestTarget(),
            SignatureVerifier::normaliseHeaders($request),
            $raw,
            $target?->id,
            time(), // receipt time — freshness is checked against this, not job-run time
        ));

        return new EmptyResponse(202);
    }
}
