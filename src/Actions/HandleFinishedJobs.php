<?php

declare(strict_types=1);

/**
 * Copyright (c) 2022 Kai Sassnowski
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/ksassnowski/venture
 */

namespace Sassnowski\Venture\Actions;

use Sassnowski\Venture\Dispatcher\JobDispatcherInterface;
use Sassnowski\Venture\Events\WorkflowFinished;
use Sassnowski\Venture\WorkflowStepInterface;

final class HandleFinishedJobs implements HandlesFinishedJobsInterface
{
    public function __construct(private JobDispatcherInterface $dispatcher)
    {
    }

    public function __invoke(WorkflowStepInterface $step): void
    {
        $workflow = $step->workflow();

        if (null === $workflow) {
            return;
        }

        $workflow->markJobAsFinished($step);

        if ($workflow->isCancelled()) {
            return;
        }

        if ($workflow->allJobsHaveFinished()) {
            $workflow->markAsFinished();
            $workflow->runThenCallback();

            \event(new WorkflowFinished($workflow));

            return;
        }

        $this->dispatcher->dispatchDependentJobs($step);
    }
}
