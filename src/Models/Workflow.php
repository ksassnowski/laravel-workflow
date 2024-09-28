<?php

declare(strict_types=1);

/**
 * Copyright (c) 2023 Kai Sassnowski
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/ksassnowski/venture
 */

namespace Sassnowski\Venture\Models;

use Carbon\Carbon;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use Sassnowski\Venture\Events\JobCreated;
use Sassnowski\Venture\Events\JobCreating;
use Sassnowski\Venture\Serializer\WorkflowJobSerializer;
use Sassnowski\Venture\State\WorkflowState;
use Sassnowski\Venture\Venture;
use Sassnowski\Venture\WorkflowableJob;

/**
 * @method Workflow create(array $attributes)
 *
 * @property ?Carbon                              $cancelled_at
 * @property ?string                              $catch_callback
 * @property ?Carbon                              $finished_at
 * @property array<int, string>                   $finished_jobs
 * @property int                                  $id
 * @property int                                  $job_count
 * @property EloquentCollection<int, WorkflowJob> $jobs
 * @property int                                  $jobs_failed
 * @property int                                  $jobs_processed
 * @property string                               $name
 * @property ?string                              $then_callback
 * @property ?Model                               $workflowable
 */
class Workflow extends Model
{
    use Prunable;

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'finished_jobs' => 'json',
        'job_count' => 'int',
        'jobs_failed' => 'int',
        'jobs_processed' => 'int',
        'finished_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    private ?WorkflowState $state = null;

    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes = [])
    {
        $this->table = config('venture.workflow_table');

        parent::__construct($attributes);
    }

    /**
     * @return HasMany<WorkflowJob>
     */
    public function jobs(): HasMany
    {
        return $this->hasMany(Venture::$workflowJobModel, 'workflow_id');
    }

    /**
     * @return MorphTo<Model, Workflow>
     */
    public function workflowable(): MorphTo
    {
        return $this->morphTo('workflowable');
    }

    /**
     * @param array<array-key, WorkflowableJob> $jobs
     */
    public function addJobs(array $jobs): void
    {
        (new Collection($jobs))
            ->map(function (WorkflowableJob $job): WorkflowJob {
                return new Venture::$workflowJobModel([
                    'job' => $this->serializer()->serialize(clone $job),
                    'name' => $job->getName(),
                    'uuid' => $job->getStepId(),
                    'edges' => $job->getDependantJobs(),
                    'gated' => $job->isGated(),
                ]);
            })
            ->each(function (WorkflowJob $job): void {
                event(new JobCreating($this, $job));
            })
            ->pipe(function ($jobs) {
                $this->jobs()->saveMany($jobs);

                return $jobs;
            })
            ->each(function (WorkflowJob $job): void {
                event(new JobCreated($job));
            });
    }

    public function allJobsHaveFinished(): bool
    {
        return $this->getState()->allJobsHaveFinished();
    }

    public function markJobAsFinished(WorkflowableJob $job): void
    {
        $this->getState()->markJobAsFinished($job);
    }

    public function markJobAsFailed(WorkflowableJob $job, \Throwable $exception): void
    {
        $this->getState()->markJobAsFailed($job, $exception);
    }

    public function markAsFinished(): void
    {
        $this->getState()->markAsFinished();
    }

    public function isFinished(): bool
    {
        return $this->getState()->isFinished();
    }

    public function isCancelled(): bool
    {
        return $this->getState()->isCancelled();
    }

    public function hasRan(): bool
    {
        return $this->getState()->hasRan();
    }

    public function cancel(): void
    {
        $this->getState()->markAsCancelled();
    }

    public function remainingJobs(): int
    {
        return $this->getState()->remainingJobs();
    }

    /**
     * @return EloquentCollection<int, WorkflowJob>
     */
    public function failedJobs(): EloquentCollection
    {
        return $this->jobs()
            ->whereNotNull('failed_at')
            ->get();
    }

    /**
     * @return EloquentCollection<int, WorkflowJob>
     */
    public function pendingJobs(): EloquentCollection
    {
        return $this->jobs()
            ->whereNull('finished_at')
            ->whereNull('failed_at')
            ->get();
    }

    /**
     * @return EloquentCollection<int, WorkflowJob>
     */
    public function finishedJobs(): EloquentCollection
    {
        return $this->jobs()
            ->whereNotNull('finished_at')
            ->get();
    }

    public function runThenCallback(): void
    {
        $this->runCallback($this->then_callback, $this);
    }

    public function runCatchCallback(WorkflowableJob $failedStep, \Throwable $exception): void
    {
        $this->runCallback($this->catch_callback, $this, $failedStep, $exception);
    }

    /**
     * @return array<string, array{
     *     name: string,
     *     failed_at: Carbon|null,
     *     finished_at: Carbon|null,
     *     exception: string|null,
     *     edges: array<int, string>,
     * }>
     */
    public function asAdjacencyList(): array
    {
        return $this->jobs->mapWithKeys(fn (WorkflowJob $job) => [
            $job->uuid => [
                'name' => $job->name,
                'failed_at' => $job->failed_at,
                'finished_at' => $job->finished_at,
                'exception' => $job->exception,
                'edges' => $job->edges ?: [],
            ],
        ])->all();
    }

    protected function getState(): WorkflowState
    {
        if (null === $this->state) {
            $this->state = app(Venture::$workflowState, ['workflow' => $this]);
        }

        return $this->state;
    }

    private function runCallback(?string $serializedCallback, mixed ...$args): void
    {
        if (null === $serializedCallback) {
            return;
        }

        /** @var callable $callback */
        $callback = \unserialize($serializedCallback);

        $callback(...$args);
    }

    private function serializer(): WorkflowJobSerializer
    {
        return Container::getInstance()->make(WorkflowJobSerializer::class);
    }

    public function prunable()
    {
        return static::where('created_at', '<=', now()->subDays(config('venture.prune_days', 7)));
    }
}
