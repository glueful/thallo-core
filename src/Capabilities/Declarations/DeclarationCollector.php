<?php

declare(strict_types=1);

namespace Thallo\Core\Capabilities\Declarations;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ExtensionManager;
use Glueful\Extensions\PackageManifest;
use Thallo\Contracts\Capability\DeclaresCapabilities;

/**
 * Collects every capability declaration (spec §7.4): the always-loaded providers that implement
 * DeclaresCapabilities, in provider order, and the packages that declare capabilities in their
 * composer.json. The extension manager holds every provider instance before it calls any
 * register(), and capabilities() is pure, so the collection is complete whenever it runs.
 */
final class DeclarationCollector
{
    /** @param list<string> $required packages Thallo requires (RequiredPackages::packages()) */
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly PackageCapabilityDeclarations $packages,
        private readonly array $required = [],
    ) {
    }

    public function collect(): DeclarationSet
    {
        $declarations = [];
        $container = $this->context->getContainer();
        if ($container->has(ExtensionManager::class)) {
            foreach ($container->get(ExtensionManager::class)->getProviders() as $class => $provider) {
                if ($provider instanceof DeclaresCapabilities) {
                    foreach ($provider->capabilities() as $capability) {
                        $declarations[] = new CapabilityDeclaration($capability, 'provider:' . $class);
                    }
                }
            }
        }
        foreach ($this->packages->all() as $declaration) {
            $declarations[] = $declaration;
        }
        $providers = [];
        foreach ((new PackageManifest($this->context))->getCandidates() as $name => $candidate) {
            $providers[(string) $name] = $candidate->provider;
        }
        return new DeclarationSet($declarations, $providers, $this->packages->errors(), $this->required);
    }
}
