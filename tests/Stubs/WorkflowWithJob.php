<?php

declare(strict_types=1);

/**
 * Copyright (c) 2021 Kai Sassnowski
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/ksassnowski/venture
 */

namespace Stubs;

use Sassnowski\Venture\AbstractWorkflow;
use Sassnowski\Venture\WorkflowDefinition;

class WorkflowWithJob extends AbstractWorkflow
{
    public function definition(): WorkflowDefinition
    {
        return (new WorkflowDefinition)->addJob(
            new TestJobWithHandle
        );
    }
}
