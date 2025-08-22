<?php

declare(strict_types=1);

namespace Converge\Routing;

use BackedEnum;
use Converge\Clusters\Cluster;
use Converge\Facades\Converge;
use Converge\Http\Controllers\FileController;
use Converge\Http\Controllers\ModuleController;
use Converge\Http\Controllers\SearchController;
use Converge\Http\Middleware\UseCluster;
use Converge\Http\Middleware\UseModule;
use Converge\Http\Middleware\UseVersion;
use Converge\Module;
use Converge\Versions\Version;
use Illuminate\Support\Facades\Route;

final class RouteManager
{
    public function generateRoutes(): void
    {

        // routes related to modules
        /** @var Module $module */
        foreach (Converge::getModules() as $module) {

            // each module can have a latest version
            $rawModuleUri = $module->getRawRoutePath();

            $quietedModuleUri = $module->getRoutePath(); // can be route path or quieted version for versionned modules

            $moduleId = $module->getId();

            $pattern = '.*';

            if ($module->hasVersions()) {
                foreach ($module->getVersions() as $version) {
                    if (! $version instanceof Version) {
                        continue;
                    }
                    if ($version->hasClusters()) {
                        foreach ($version->getClusters() as $scopedCluster) {

                            if (! $scopedCluster instanceof Cluster) {
                                continue;
                            }

                            if ($scopedCluster->isDefault()) {
                                continue;
                            }

                            $urlGenerator = $scopedCluster->getUrlGenerator();

                            $clusterUri = $urlGenerator->generate($rawModuleUri, $version->getRoute(), clusterUri: $scopedCluster->getRoute());

                            $clusterName = $moduleId.'.'.$version->getRoute().'.'.$scopedCluster->getRoute();

                            $this->registerRoutes($clusterUri, $moduleId, $clusterName, versionId: $version->getRoute(), clusterId: $scopedCluster->getRoute());
                        }
                    }

                    $urlGenerator = $version->getUrlGenerator();

                    $versionUri = $urlGenerator->generate($rawModuleUri, $version->getRoute());

                    $versionName = $moduleId.'.'.$version->getRoute();

                    $this->registerRoutes($versionUri, $moduleId, $versionName, versionId: $version->getRoute(), domain: $module->getDomain());
                }
            }

            if ($module->hasClusters()) {
                foreach ($module->getClusters() as $cluster) {
                    // it can be a ClusterLink so we need to skip that
                    if (! $cluster instanceof Cluster) {
                        continue;
                    }

                    if ($cluster->isDefault()) {
                        continue;
                    }

                    $urlGenerator = $cluster->getUrlGenerator();

                    $clusterUri = $urlGenerator->generate($quietedModuleUri, null, clusterUri: $cluster->getRoute());

                    $clusterName = $moduleId.'.'.$cluster->getRoute();

                    $this->registerRoutes($clusterUri, $moduleId, $clusterName, clusterId: $cluster->getRoute(), domain: $cluster->getDomain());
                }
            }

            $excludedVersions = implode('|', array_map(
                fn ($v) => preg_quote($v->getRoute(), '/'),
                $module->getVersions()
                    ->filter(
                        fn ($version) => $version instanceof Version
                    )->toArray()
            ));

            if ($excludedVersions) {
                $pattern = "^(?!($excludedVersions))(.*)$";
            }

            $this->registerRoutes($quietedModuleUri, $moduleId, $moduleId, $pattern, domain: $module->getDomain());
        }
    }

    private function registerRoutes(
        string $uri,
        string $moduleId,
        string $name,
        string $pattern = '.*',
        ?string $versionId = null,
        ?string $clusterId = null,
        string|BackedEnum|null $domain = null,
    ): void {

        $versionsParams = $versionId ? "$moduleId,$versionId" : $moduleId;

        $clustersParams = $clusterId ? "$moduleId,$clusterId" : $moduleId;

        Route::middleware([
            UseModule::class.':'.$moduleId,
            UseVersion::class.':'.$versionsParams,
            UseCluster::class.':'.$clustersParams,
        ])->domain($domain)
            ->group(function () use ($uri, $name, $pattern) {

                Route::get("{$uri}/converge/search/enpoint", SearchController::class)->name("{$name}.search");

                Route::get($uri, ModuleController::class)->name($name);

                Route::get("{$uri}/{url}", FileController::class)
                    ->where('url', $pattern)
                    ->name("{$name}.show");

            });
    }
}
