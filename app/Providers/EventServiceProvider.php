<?php

namespace App\Providers;

use App\Events\MediaProcessingCompleted;
use App\Events\MediaUploadFailed;
use App\Listeners\NotifyUserOfProcessingCompletion;
use App\Listeners\NotifyUserOfUploadFailure;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        MediaProcessingCompleted::class => [
            NotifyUserOfProcessingCompletion::class,
        ],

        MediaUploadFailed::class => [
            NotifyUserOfUploadFailure::class,
        ],
    ];

    public function boot(): void
    {
        //
    }

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}