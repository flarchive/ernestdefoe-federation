<?php

namespace ErnestDefoe\Federation\Controller;

use ErnestDefoe\Federation\Job\ProcessInboxActivity;
use ErnestDefoe\Federation\Service\Settings;
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
    protected Bus $bus;

    public function __construct(Settings $settings, Bus $bus)
    {
        parent::__construct($settings);
        $this->bus = $bus;
    }

    protected function processInbox(ServerRequestInterface $request, ?User $target): ResponseInterface
    {
        // Cheap pre-queue gate: a missing Signature is rejected outright; full
        // verification (and any drop of an invalid signature) happens in the job.
        if ($request->getHeaderLine('Signature') === '') {
            return new EmptyResponse(401);
        }

        $this->bus->dispatch(new ProcessInboxActivity(
            strtolower($request->getMethod()),
            $request->getRequestTarget(),
            SignatureVerifier::normaliseHeaders($request),
            (string) $request->getBody(),
            $target?->id,
            time(), // receipt time — freshness is checked against this, not job-run time
        ));

        return new EmptyResponse(202);
    }
}
