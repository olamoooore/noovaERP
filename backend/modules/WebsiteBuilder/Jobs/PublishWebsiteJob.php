<?php

namespace Modules\WebsiteBuilder\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\WebsiteBuilder\Models\Website;
use Modules\WebsiteBuilder\Services\PublishingService;

class PublishWebsiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Website $website,
        public int $userId,
        public ?int $rollbackVersion = null
    ) {}

    public function handle(PublishingService $publishingService): void
    {
        $publishingService->publish(
            $this->website,
            $this->userId,
            $this->rollbackVersion
        );
    }
}
