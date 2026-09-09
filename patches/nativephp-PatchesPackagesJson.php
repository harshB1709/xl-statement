<?php

namespace Native\Desktop\Drivers\Electron\Traits;

use Native\Desktop\Drivers\Electron\ElectronServiceProvider;

/**
 * XL Statement patch: electronPath('package.json') checks for
 * package.json/package.json and misses a published Electron project, so the
 * installer keeps NativePHP's default name/description ("A NativePHP…").
 * Always resolve the Electron project root first.
 *
 * Restored by bin/sync-nativephp-icons.php on composer post-autoload-dump.
 */
trait PatchesPackagesJson
{
    protected function setAppNameAndVersion($developmentMode = false): string
    {
        $packageJsonPath = rtrim(ElectronServiceProvider::electronPath(), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .'package.json';

        $packageJson = json_decode(file_get_contents($packageJsonPath), true);

        $name = str(config('app.name'))->slug();

        /*
         * Suffix the app name with '-dev' if it's a development build
         * this way, when the developer test his freshly built app,
         * configs, migrations won't be mixed up with the production app
         */
        if ($developmentMode) {
            $name .= '-dev';
        }

        $packageJson['name'] = $name;
        $packageJson['version'] = config('nativephp.version');
        $packageJson['description'] = config('nativephp.description');
        $packageJson['author'] = config('nativephp.author') ?: config('app.name');
        $packageJson['homepage'] = config('nativephp.website');

        file_put_contents($packageJsonPath, json_encode($packageJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $name;
    }
}
