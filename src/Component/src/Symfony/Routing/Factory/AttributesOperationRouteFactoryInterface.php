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

namespace Sylius\Resource\Symfony\Routing\Factory;

use Symfony\Component\Routing\RouteCollection;

interface AttributesOperationRouteFactoryInterface
{
    /** @psalm-param class-string $className */
    public function createRouteForClass(RouteCollection $routeCollection, string $className): void;
}
