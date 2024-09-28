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

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Sassnowski\Venture\State\WorkflowStateStore;
use Sassnowski\Venture\VentureServiceProvider;

class TestCase extends OrchestraTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDatabase();
    }

    protected function tearDown(): void
    {
        WorkflowStateStore::restore();

        parent::tearDown();
    }

    /**
     * @param mixed $app
     *
     * @return array<int, class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [
            VentureServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        config()->set('venture.workflow_table', 'workflows');
        config()->set('venture.jobs_table', 'workflow_jobs');
    }

    private function setUpDatabase(): void
    {
        $ventureMigration = require __DIR__ . '/../database/migrations/create_workflow_tables.php.stub';

        $ventureMigration->up();
    }
}
