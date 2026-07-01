<?php

namespace App\Features\ActivityLogging\Contracts;

use Illuminate\Database\Eloquent\Model;

interface ActivityLoggerInterface
{
    public function log(Model $subject, array $properties = [], ?string $event = null): void;

    public function for(string $causerType, int $causerId): static;

    public function on(Model $subject): static;

    public function withProperties(array $properties): static;

    public function useEvent(string $event): static;

    public function performedOn(Model $subject): static;
}
