<?php

namespace ErnestDefoe\Federation\Job;

use ErnestDefoe\Federation\Service\ActorFetcher;
use Flarum\User\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sign and POST an ActivityPub activity to one or more remote inboxes. Delivery
 * errors are swallowed per-inbox — a dead remote must never fail the whole job.
 *
 * On Flarum's default `sync` queue driver this runs inline; operators with a
 * real queue driver (database/redis) get out-of-band delivery for free.
 */
class DeliverActivity implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 30;

    /**
     * @param  array<string,mixed>  $activity
     * @param  string[]  $inboxes
     * @param  int|null  $signerId  Local member id to sign as; null = community actor.
     */
    public function __construct(
        public array $activity,
        public array $inboxes,
        public ?int $signerId = null,
    ) {}

    /** Queue delivery (runs inline on the default `sync` driver). */
    public static function send(array $activity, array $inboxes, ?int $signerId = null): void
    {
        if (! $inboxes) {
            return;
        }
        resolve(Dispatcher::class)->dispatch(new self($activity, $inboxes, $signerId));
    }

    public function handle(ActorFetcher $fetcher): void
    {
        $inboxes = array_values(array_unique(array_filter($this->inboxes)));
        if (! $inboxes) {
            return;
        }

        $signer = $this->signerId ? User::find($this->signerId) : null;

        foreach ($inboxes as $inbox) {
            $fetcher->deliver($signer, $inbox, $this->activity);
        }
    }
}
