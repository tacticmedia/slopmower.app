<?php

declare(strict_types=1);

namespace App\Tests\Support\Environment;

use App\Kernel;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The `prod` container, compiled as `debug:config` does: the test kernel cannot reach private services of another
 * environment. `get()` reaches only the services that `$publicIds` names.
 */
final class ProdContainer
{
    public static function build(string ...$publicIds): ContainerBuilder
    {
        $build = \Closure::bind(function (): ContainerBuilder {
            $this->initializeBundles();

            return $this->buildContainer();
        }, new Kernel('prod', false), Kernel::class) ?? throw new \LogicException('Cannot bind the kernel.');

        $container = $build();
        // The removing passes would inline or remove the private services.
        $container->getCompilerPassConfig()->setRemovingPasses([]);
        $container->getCompilerPassConfig()->setAfterRemovingPasses([]);
        $container->addCompilerPass(new class($publicIds) implements CompilerPassInterface {
            /**
             * @param array<string> $ids
             */
            public function __construct(private readonly array $ids)
            {
            }

            public function process(ContainerBuilder $container): void
            {
                foreach ($this->ids as $id) {
                    $container->getDefinition($id)->setPublic(true);
                }
            }
        }, PassConfig::TYPE_BEFORE_REMOVING);
        $container->compile();

        return $container;
    }
}
