<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Sassnowski\Venture\Models\Workflow;

use function Pest\Laravel\assertModelExists;
use function Pest\Laravel\assertModelMissing;

/**
 * Copyright (c) 2023 Kai Sassnowski
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/ksassnowski/venture
 */

 uses(TestCase::class);

 it('can prune Workflow models and WorkflowJob models are cascade deleted', function (): void {
    Carbon::setTestNow('2024-02-28 11:11:00');
    config()->set('venture.prune_days', 3);

    // Create two workflows, one to be pruned and one that's not within the prune window.

    $pruneWorkflow = createWorkflow();
    $pruneWorkflowJob1 = createWorkflowJob($pruneWorkflow, ['failed_at' => now()]);
    $pruneWorkflowJob2 = createWorkflowJob($pruneWorkflow, ['finished_at' => now()]);

    expect($pruneWorkflow->created_at)->toEqual(now());
    expect($pruneWorkflow->jobs)->toHaveCount(2);

    $this->travel(1)->day();

    $workflow = createWorkflow();
    $workflowJob1 = createWorkflowJob($workflow);

    expect($workflow->created_at)->toEqual(now());
    expect($workflow->jobs)->toHaveCount(1);

    // There should be 2 workflows and 3 workflow jobs.

    expect(DB::table(config('venture.workflow_table'))->count())->toEqual(2);
    expect(DB::table(config('venture.jobs_table'))->count())->toEqual(3);

    // Travel to exactly three days from the start of the test, which is the number of days
    // the prune_days config is set at.

    $this->travelTo('2024-03-02 11:11:00');

    // Run the prune artisan command and expect the $pruneWorkflow and its jobs to be deleted
    // but the other workflow is kept as it was created within the prune window.

    $this->artisan('model:prune', ['--model' => Workflow::class]);

    assertModelMissing($pruneWorkflow);
    assertModelMissing($pruneWorkflowJob1);
    assertModelMissing($pruneWorkflowJob2);

    assertModelExists($workflow);
    assertModelExists($workflowJob1);

    expect(DB::table(config('venture.workflow_table'))->count())->toEqual(1);
    expect(DB::table(config('venture.jobs_table'))->count())->toEqual(1);
 });
