<?php declare(strict_types=1);

namespace Sassnowski\Venture\Graph;

use Sassnowski\Venture\Exceptions\DuplicateJobException;
use Sassnowski\Venture\Exceptions\DuplicateWorkflowException;
use Sassnowski\Venture\Exceptions\UnresolvableDependenciesException;

class DependencyGraph
{
    private array $nestedGraphs = [];

    public function __construct(protected array $graph = [])
    {
    }

    /**
     * @throws DuplicateJobException
     */
    public function addDependantJob(object $job, array $dependencies, string $id): void
    {
        if (isset($this->graph[$id])) {
            throw new DuplicateJobException(sprintf('A job with id "%s" already exists in this workflow.', $id));
        }

        $resolvedDependencies = $this->resolveDependencies($dependencies);

        $this->graph[$id]['instance'] = $job;
        $this->graph[$id]['in_edges'] = $resolvedDependencies;
        $this->graph[$id]['out_edges'] ??= [];

        foreach ($resolvedDependencies as $dependency) {
            $this->graph[$dependency]['out_edges'][] = $id;
        }
    }

    public function getDependantJobs(string $jobId): array
    {
        return collect($this->graph[$jobId]['out_edges'])
            ->map(fn (string $dependantJob): object => $this->graph[$dependantJob]['instance'])
            ->toArray();
    }

    public function getDependencies(string $jobId): array
    {
        return $this->graph[$jobId]['in_edges'];
    }

    public function getJobsWithoutDependencies(): array
    {
        return collect($this->graph)
            ->filter(fn (array $node): bool => count($node['in_edges']) === 0)
            ->map(fn (array $node): object => $node['instance'])
            ->values()
            ->toArray();
    }

    /**
     * @throws DuplicateWorkflowException
     * @throws DuplicateJobException
     */
    public function connectGraph(DependencyGraph $otherGraph, string $id, array $dependencies): void
    {
        if (isset($this->nestedGraphs[$id])) {
            throw new DuplicateWorkflowException(sprintf('A nested workflow with id "%s" already exists', $id));
        }

        $this->nestedGraphs[$id] = $otherGraph->graph;

        foreach ($otherGraph->graph as $nodeId => $node) {
            if (count($node['in_edges']) === 0) {
                // The root nodes of the nested graph should be connected to
                // the provided dependencies. If the dependency happens to be
                // another graph, it will be resolved inside `addDependantJob`.
                $node['in_edges'] = $dependencies;
            } else {
                // All dependencies inside the nested graph get namespaced
                // to avoid any ambiguity with the jobs from the outer workflow.
                $node['in_edges'] = collect($node['in_edges'])->map(fn (string $edgeId) => $id . '.' . $edgeId)->toArray();
            }

            $this->addDependantJob($node['instance'], $node['in_edges'], $id . '.' . $nodeId);
        }
    }

    private function resolveDependencies(array $dependencies): array
    {
        return collect($dependencies)->flatMap(function (string $dependency) {
            return $this->resolveDependency($dependency);
        })->all();
    }

    private function resolveDependency(string $dependency): array
    {
        if (array_key_exists($dependency, $this->graph)) {
            return [$dependency];
        }

        // Depending on a nested graph means depending on each of the graph's
        // leaf nodes, i.e. nodes with an out-degree of 0.
        if (array_key_exists($dependency, $this->nestedGraphs)) {
            return collect($this->nestedGraphs[$dependency])
                ->filter(fn (array $node) => count($node['out_edges']) === 0)
                ->keys()
                ->map(fn (string $key) => $dependency . '.' . $key)
                ->all();
        }

        throw new UnresolvableDependenciesException(sprintf(
            'Unable to resolve dependency [%s]. Make sure it was added before declaring it as a dependency.',
            $dependency
        ));
    }
}
