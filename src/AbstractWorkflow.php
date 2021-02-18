<?php declare(strict_types=1);

namespace Sassnowski\Venture;

use Illuminate\Container\Container;
use Sassnowski\Venture\Models\Workflow;

abstract class AbstractWorkflow
{
    public static function start(): Workflow
    {
        return (new static(...func_get_args()))->run();
    }

    protected function run(): Workflow
    {
        return Container::getInstance()
            ->make('venture.manager')
            ->startWorkflow($this);
    }

    abstract public function definition(): WorkflowDefinition;

    public function beforeCreate(Workflow $workflow): void
    {
    }

    public function beforeNesting(array $jobs): void
    {
    }
}
