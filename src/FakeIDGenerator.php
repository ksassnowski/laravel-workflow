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

namespace Sassnowski\Venture;

final class FakeIDGenerator implements StepIdGeneratorInterface
{
    public function __construct(private string $id)
    {
    }

    public function generateId(object $job): string
    {
        return $this->id;
    }
}
