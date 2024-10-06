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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Sassnowski\Venture\Exceptions\CannotRetryJobException;
use Sassnowski\Venture\Exceptions\JobAlreadyStartedException;
use Sassnowski\Venture\Serializer\WorkflowJobSerializer;
use Sassnowski\Venture\State\WorkflowJobState;
use Sassnowski\Venture\Venture;
use Sassnowski\Venture\WorkflowableJob;

/**
 * @property array<int, string> $edges
 * @property ?string            $exception
 * @property ?Carbon            $failed_at
 * @property ?Carbon            $finished_at
 * @property bool               $gated
 * @property ?Carbon            $gated_at
 * @property string             $job
 * @property string             $name
 * @property ?Carbon            $started_at
 * @property string             $uuid
 * @property Workflow           $workflow
 * @property int                $workflow_id
 */
class WorkflowJob extends Model
{
    public $timestamps = false;

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'failed_at' => 'datetime',
        'finished_at' => 'datetime',
        'started_at' => 'datetime',
        'gated_at' => 'datetime',
        'edges' => 'array',
        'manual' => 'bool',
    ];

    private ?WorkflowJobState $state = null;

    private ?WorkflowableJob $step = null;

    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes = [])
    {
        $this->table = config('venture.jobs_table');

        parent::__construct($attributes);
    }

    /**
     * @return BelongsTo<Workflow, WorkflowJob>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Venture::$workflowModel, 'workflow_id');
    }

    public function step(): WorkflowableJob
    {
        if (null === $this->step) {
            /** @var WorkflowJobSerializer $serializer */
            $serializer = \app(WorkflowJobSerializer::class);

            $step = $serializer->unserialize($this->job);

            if (null === $step) {
                throw new \RuntimeException('Unable to unserialize job');
            }

            $this->step = $step;
        }

        return $this->step;
    }

    public function isPending(): bool
    {
        return $this->getState()->isPending();
    }

    public function isFinished(): bool
    {
        return $this->getState()->hasFinished();
    }

    public function markAsFinished(): void
    {
        $this->getState()->markAsFinished();
    }

    public function hasFailed(): bool
    {
        return $this->getState()->hasFailed();
    }

    public function markAsFailed(\Throwable $exception): void
    {
        $this->getState()->markAsFailed($exception);
    }

    public function isProcessing(): bool
    {
        return $this->getState()->isProcessing();
    }

    public function markAsProcessing(): void
    {
        $this->getState()->markAsProcessing();
    }

    public function canRun(): bool
    {
        return $this->getState()->canRun();
    }

    public function isGated(): bool
    {
        return $this->getState()->isGated();
    }

    public function markAsGated(): void
    {
        $this->getState()->markAsGated();
    }

    public function transition(): void
    {
        $this->getState()->transition();
    }

    public function start(): void
    {
        if ($this->isProcessing()) {
            throw new JobAlreadyStartedException();
        }

        \dispatch($this->step());

        $this->markAsProcessing();
    }

    public function retry(): void
    {
        if (!$this->hasFailed()) {
            throw new CannotRetryJobException();
        }

        if (config('queue.failed.driver') == 'database-uuids') {
            $job = DB::connection(config('queue.failed.database'))
                ->table(config('queue.failed.table'))
                ->select('uuid')
                ->where('payload', 'LIKE', "%{$this->uuid}%")
                ->first();
            if ($job) {
                Artisan::call('queue:retry', [
                    'id' => $job->uuid
                ]);
                return;
            }
        }

        \dispatch($this->step());
    }

    protected function getState(): WorkflowJobState
    {
        if (null === $this->state) {
            $this->state = \app(Venture::$workflowJobState, ['job' => $this]);
        }

        return $this->state;
    }
}
