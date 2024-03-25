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

namespace Sassnowski\Venture;

use Ramsey\Uuid\UuidInterface;
use Sassnowski\Venture\Models\Workflow;
use Sassnowski\Venture\Models\WorkflowJob;

/**
 * @property Delay              $delay
 * @property array<int, string> $dependantJobs
 * @property array<int, string> $dependencies
 * @property bool               $gated
 * @property ?string            $jobId
 * @property ?string            $name
 * @property ?string            $stepId
 * @property ?int               $workflowId
 */
final class WorkflowStepAdapter implements WorkflowableJob
{
    private function __construct(private object $job)
    {
    }

    public function __get(string $name): mixed
    {
        return $this->job->{$name} ?? null;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->job->{$name} = $value;
    }

    /**
     * @param array<int, mixed> $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        return $this->job->{$name}(...$arguments);
    }

    public static function fromJob(object $job): WorkflowableJob
    {
        if ($job instanceof WorkflowableJob) {
            return $job;
        }

        $uses = \class_uses_recursive($job);

        if (!\in_array(WorkflowStep::class, $uses, true)) {
            throw new \InvalidArgumentException('Wrapped job instance does not use WorkflowStep trait');
        }

        return new self($job);
    }

    public function handle(): void
    {
        $method = \method_exists($this->job, 'handle') ? 'handle' : '__invoke';

        /** @phpstan-ignore-next-line */
        app()->call([$this->job, $method]);
    }

    public function displayName(): string
    {
        return $this->job::class;
    }

    public function withWorkflowId(int $workflowID): WorkflowableJob
    {
        $this->job->withWorkflowId($workflowID);

        return $this;
    }

    public function workflow(): ?Workflow
    {
        return $this->job->workflow();
    }

    public function withDependantJobs(array $jobs): WorkflowableJob
    {
        $this->job->withDependantJobs($jobs);

        return $this;
    }

    public function getDependantJobs(): array
    {
        return $this->job->getDependantJobs();
    }

    public function withDependencies(array $jobNames): WorkflowableJob
    {
        $this->job->withDependencies($jobNames);

        return $this;
    }

    public function getDependencies(): array
    {
        return $this->job->getDependencies();
    }

    public function withJobId(string $jobID): WorkflowableJob
    {
        $this->job->withJobId($jobID);

        return $this;
    }

    public function getJobId(): string
    {
        return $this->job->getJobId();
    }

    public function withStepId(UuidInterface $stepID): WorkflowableJob
    {
        $this->job->withStepId($stepID);

        return $this;
    }

    public function getStepId(): ?string
    {
        return $this->job->getStepId();
    }

    public function step(): ?WorkflowJob
    {
        return $this->job->step();
    }

    public function withName(string $name): WorkflowableJob
    {
        $this->job->withName($name);

        return $this;
    }

    public function getName(): string
    {
        return $this->job->getName();
    }

    public function withDelay(mixed $delay): WorkflowableJob
    {
        $this->job->withDelay($delay);

        return $this;
    }

    public function getDelay(): mixed
    {
        return $this->job->getDelay();
    }

    /**
     * @param null|string $connection
     */
    public function onConnection($connection): WorkflowableJob
    {
        $this->job->onConnection($connection);

        return $this;
    }

    public function getConnection(): ?string
    {
        return $this->job->connection;
    }

    /**
     * @param null|string $queue
     */
    public function onQueue($queue): WorkflowableJob
    {
        $this->job->onQueue($queue);

        return $this;
    }

    public function getQueue(): ?string
    {
        return $this->job->queue;
    }

    public function withGate(bool $gated = true): WorkflowableJob
    {
        $this->job->gated = $gated;

        return $this;
    }

    public function isGated(): bool
    {
        return $this->job->gated;
    }

    public function unwrap(): object
    {
        return $this->job;
    }
}
