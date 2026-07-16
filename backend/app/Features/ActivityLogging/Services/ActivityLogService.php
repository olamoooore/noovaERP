<?php

namespace App\Features\ActivityLogging\Services;

use App\Features\ActivityLogging\Contracts\ActivityLoggerInterface;
use App\Features\ActivityLogging\Exceptions\ActivityLoggingException;
use App\Features\ActivityLogging\Models\ActivityLogEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ActivityLogService implements ActivityLoggerInterface
{
    private ?string $causerType = null;

    private ?int $causerId = null;

    private ?Model $subject = null;

    private array $properties = [];

    private ?string $event = null;

    public function log(Model $subject, array $properties = [], ?string $event = null): void
    {
        try {
            $causer = $this->resolveCauser();
            $subjectType = get_class($subject);
            $subjectId = $subject->getKey();

            $mergedProperties = array_merge($this->properties, $properties);

            ActivityLogEntry::create([
                'causer_type' => $causer ? get_class($causer) : null,
                'causer_id' => $causer?->getKey(),
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'event' => $event ?? $this->event ?? $this->inferEvent($subject),
                'properties' => $mergedProperties,
            ]);

            $this->reset();
        } catch (\Throwable $e) {
            Log::error('Activity log service failed', [
                'error' => $e->getMessage(),
                'subject_type' => get_class($subject),
                'subject_id' => $subject->getKey(),
                'event' => $event ?? $this->event,
            ]);

            throw new ActivityLoggingException(
                message: 'Failed to log activity',
                context: [
                    'subject_type' => get_class($subject),
                    'subject_id' => $subject->getKey(),
                    'error' => $e->getMessage(),
                ],
                previous: $e,
            );
        }
    }

    public function for(string $causerType, int $causerId): static
    {
        $this->causerType = $causerType;
        $this->causerId = $causerId;

        return $this;
    }

    public function on(Model $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    public function withProperties(array $properties): static
    {
        $this->properties = $properties;

        return $this;
    }

    public function useEvent(string $event): static
    {
        $this->event = $event;

        return $this;
    }

    public function performedOn(Model $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    private function resolveCauser(): ?Model
    {
        if ($this->causerType && $this->causerId) {
            $modelClass = $this->causerType;

            if (class_exists($modelClass)) {
                return $modelClass::find($this->causerId);
            }
        }

        return Auth::user();
    }

    private function inferEvent(Model $subject): string
    {
        $className = class_basename($subject);

        return match (true) {
            str_contains($className, 'Invoice') => 'created',
            str_contains($className, 'Order') => 'placed',
            str_contains($className, 'Payment') => 'paid',
            default => 'updated',
        };
    }

    private function reset(): void
    {
        $this->causerType = null;
        $this->causerId = null;
        $this->subject = null;
        $this->properties = [];
        $this->event = null;
    }
}
