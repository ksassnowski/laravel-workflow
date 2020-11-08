<?php declare(strict_types=1);

use Carbon\Carbon;
use Stubs\TestJob1;
use Stubs\TestJob2;
use Stubs\TestJob3;
use Illuminate\Support\Facades\Bus;
use Opis\Closure\SerializableClosure;
use Sassnowski\Venture\Models\Workflow;
use function PHPUnit\Framework\assertTrue;
use Sassnowski\Venture\WorkflowDefinition;
use function PHPUnit\Framework\assertFalse;
use function Pest\Laravel\assertDatabaseHas;
use function PHPUnit\Framework\assertEquals;
use Sassnowski\Venture\Facades\Workflow as WorkflowFacade;

uses(TestCase::class);

beforeEach(function () {
    Bus::fake();
});

it('creates a workflow', function () {
    (new WorkflowDefinition())
        ->addJob(new TestJob1())
        ->addJob(new TestJob2(), [TestJob1::class])
        ->build();

    assertDatabaseHas('workflows', [
        'job_count' => 2,
        'jobs_processed' => 0,
        'jobs_failed' => 0,
        'finished_jobs' => json_encode([]),
    ]);
});

it('returns the workflow\'s initial batch of jobs', function () {
    $job1 = new TestJob1();
    $job2 = new TestJob2();

    [$workflow, $initialBatch] = (new WorkflowDefinition())
        ->addJob($job1)
        ->addJob($job2)
        ->addJob(new TestJob3(), [TestJob1::class])
        ->build();

    assertEquals([$job1, $job2], $initialBatch);
});

it('returns the workflow', function () {
    $job1 = new TestJob1();
    $job2 = new TestJob2();

    [$workflow, $initialBatch] = (new WorkflowDefinition())
        ->addJob($job1)
        ->addJob($job2)
        ->addJob(new TestJob3(), [TestJob1::class])
        ->build();

    assertTrue($workflow->exists);
    assertTrue($workflow->wasRecentlyCreated);
});

it('sets a reference to the workflow on each job', function () {
    $testJob1 = new TestJob1();
    $testJob2 = new TestJob2();

    (new WorkflowDefinition())
        ->addJob($testJob1)
        ->addJob($testJob2, [TestJob1::class])
        ->build();

    $workflowId = Workflow::first()->id;
    assertEquals($workflowId, $testJob1->workflowId);
    assertEquals($workflowId, $testJob2->workflowId);
});

it('sets the job dependencies on the job instances', function () {
    $testJob1 = new TestJob1();
    $testJob2 = new TestJob2();

    (new WorkflowDefinition())
        ->addJob($testJob1)
        ->addJob($testJob2, [TestJob1::class])
        ->build();

    assertEquals([TestJob1::class], $testJob2->dependencies);
    assertEquals([], $testJob1->dependencies);
});

it('sets the dependants of a job', function () {
    Bus::fake();
    $testJob1 = new TestJob1();
    $testJob2 = new TestJob2();

    (new WorkflowDefinition())
        ->addJob($testJob1)
        ->addJob($testJob2, [TestJob1::class])
        ->build();

    assertEquals([$testJob2], $testJob1->dependantJobs);
    assertEquals([], $testJob2->dependantJobs);
});

it('saves the workflow steps to the database', function () {
    $testJob1 = new TestJob1();
    $testJob2 = new TestJob2();

    (new WorkflowDefinition())
        ->addJob($testJob1)
        ->addJob($testJob2, [TestJob1::class])
        ->build();

    assertDatabaseHas('workflow_jobs', ['job' => serialize($testJob1)]);
    assertDatabaseHas('workflow_jobs', ['job' => serialize($testJob2)]);
});

it('uses the class name as the jobs name if no name was provided', function () {
    (new WorkflowDefinition())
        ->addJob(new TestJob1())
        ->build();

    assertDatabaseHas('workflow_jobs', ['name' => TestJob1::class]);
});

it('uses the nice name if it was provided', function () {
    (new WorkflowDefinition())
        ->addJob(new TestJob1())
        ->addJob(new TestJob2(), [TestJob1::class], '::job-name::')
        ->build();

    assertDatabaseHas('workflow_jobs', ['name' => '::job-name::']);
});

it('creates workflow step records that use the jobs uuid', function () {
    $testJob1 = new TestJob1();
    $testJob2 = new TestJob2();

    (new WorkflowDefinition())
        ->addJob($testJob1)
        ->addJob($testJob2, [TestJob1::class], '::job-name::')
        ->build();

    assertDatabaseHas('workflow_jobs', ['uuid' => $testJob1->stepId]);
    assertDatabaseHas('workflow_jobs', ['uuid' => $testJob2->stepId]);
});

it('creates a workflow with the provided name', function () {
    [$workflow, $initialBatch] = WorkflowFacade::define('::workflow-name::')
        ->addJob(new TestJob1())
        ->build();

    assertEquals('::workflow-name::', $workflow->name);
});

it('allows configuration of a then callback', function () {
    $callback = function (Workflow $wf) {
        echo 'derp';
    };
    [$workflow, $initialBatch] = WorkflowFacade::define('::name::')
        ->then($callback)
        ->build();

    assertEquals($workflow->then_callback, serialize(SerializableClosure::from($callback)));
});

it('allows configuration of an invokable class as then callback', function () {
    $callback = new DummyCallback();

    [$workflow, $initialBatch] = WorkflowFacade::define('::name::')
        ->then($callback)
        ->build();

    assertEquals($workflow->then_callback, serialize($callback));
});

it('allows configuration of a catch callback', function () {
    $callback = function (Workflow $wf) {
        echo 'derp';
    };
    [$workflow, $initialBatch] = WorkflowFacade::define('::name::')
        ->catch($callback)
        ->build();

    assertEquals($workflow->catch_callback, serialize(SerializableClosure::from($callback)));
});

it('allows configuration of an invokable class as catch callback', function () {
    $callback = new DummyCallback();

    [$workflow, $initialBatch] = WorkflowFacade::define('::name::')
        ->catch($callback)
        ->build();

    assertEquals($workflow->catch_callback, serialize($callback));
});

it('can add a job with a delay', function ($delay) {
    Carbon::setTestNow(now());

    [$workflow, $initialBatch] = WorkflowFacade::define('::name::')
        ->addJob(new TestJob1(), [], '::name::', $delay)
        ->build();

    assertEquals($delay, $initialBatch[0]->delay);
})->with('delay provider');

it('returns true if job is part of the workflow', function () {
    $definition = WorkflowFacade::define('::name::')
        ->addJob(new TestJob1());

    assertTrue($definition->hasJob(TestJob1::class));
});

it('returns false if job is not part of the workflow', function () {
    $definition = WorkflowFacade::define('::name::')
        ->addJob(new TestJob2());

    assertFalse($definition->hasJob(TestJob1::class));
});

it('can conditionally add a job to the workflow with a callback', function () {
    $definition = WorkflowFacade::define('::name::')
        ->when(true, 
            fn($workflow) => $workflow->addJob(new TestJob1()),
            fn($workflow) => $workflow->addJob(new TestJob2())
        );

    assertTrue($definition->hasJob(TestJob1::class));
    assertFalse($definition->hasJob(TestJob2::class));
});

it('doesnt add a job if the conditional is false', function () {
    $definition = WorkflowFacade::define('::name::')
        ->when(false, 
            fn($workflow) => $workflow->addJob(new TestJob1()),
            fn($workflow) => $workflow->addJob(new TestJob2())
        );

    assertFalse($definition->hasJob(TestJob1::class));
    assertTrue($definition->hasJob(TestJob2::class));
});

it('returns true if job exists with the correct dependencies', function () {
    $definition = WorkflowFacade::define('::name::')
        ->addJob(new TestJob1())
        ->addJob(new TestJob2(), [TestJob1::class]);

    assertTrue($definition->hasJob(TestJob2::class, [TestJob1::class]));
});

it('returns false if job exists, but with incorrect dependencies', function () {
    $definition = WorkflowFacade::define('::name::')
        ->addJob(new TestJob1())
        ->addJob(new TestJob2())
        ->addJob(new TestJob3(), [TestJob2::class]);

    assertFalse($definition->hasJob(TestJob3::class, [TestJob1::class]));
});

it('returns false if job exists without delay', function () {
    Carbon::setTestNow(now());

    $definition = WorkflowFacade::define('::name::')
        ->addJob(new TestJob1());

    assertFalse($definition->hasJob(TestJob1::class, [], now()->addDay()));
});

it('returns true if job exists with correct delay', function ($delay) {
    Carbon::setTestNow(now());

    $definition = WorkflowFacade::define('::name::')
        ->addJob(new TestJob1(), [], '', $delay);

    assertTrue($definition->hasJob(TestJob1::class, [], $delay));
})->with('delay provider');

dataset('delay provider', [
    'carbon date' => [now()->addHour()],
    'integer' => [2000],
    'date interval' => [new DateInterval('P14D')],
]);

class DummyCallback
{
    public function __invokable()
    {
        echo 'herp';
    }
}
