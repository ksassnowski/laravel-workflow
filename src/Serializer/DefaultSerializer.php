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

namespace Sassnowski\Venture\Serializer;

use Sassnowski\Venture\WorkflowableJob;
use Sassnowski\Venture\WorkflowStepAdapter;

final class DefaultSerializer implements WorkflowJobSerializer
{
    public function serialize(WorkflowableJob $job): string
    {
        return \serialize($job);
    }

    public function unserialize(string $serializedJob): ?WorkflowableJob
    {
        $result = @\unserialize($serializedJob);

        if (!\is_object($result)) {
            throw new UnserializeException('Unable to unserialize job');
        }

        try {
            return WorkflowStepAdapter::fromJob($result);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
