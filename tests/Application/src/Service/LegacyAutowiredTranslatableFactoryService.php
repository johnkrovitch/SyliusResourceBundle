<?php

/*
 * This file is part of the Sylius package.
 *
 * (c) Sylius Sp. z o.o.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Service;

use Sylius\Component\Resource\Factory\TranslatableFactoryInterface;

final class LegacyAutowiredTranslatableFactoryService
{
    public function __construct(
        private TranslatableFactoryInterface $legacyBookFactory,
    ) {
    }
}
