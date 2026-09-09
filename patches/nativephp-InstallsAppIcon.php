<?php

namespace Native\Desktop\Drivers\Electron\Traits;

use Native\Desktop\Drivers\Electron\ElectronServiceProvider;

/**
 * XL Statement patch: electronPath('build/icon.png') wrongly checks for
 * package.json under the icon path, so published Electron projects never
 * receive custom icons. Always resolve the Electron project root first.
 *
 * Restored by bin/sync-nativephp-icons.php on composer post-autoload-dump.
 */
trait InstallsAppIcon
{
    public function installIcon()
    {
        $electronBuild = rtrim(ElectronServiceProvider::electronPath(), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .'build';

        if (! is_dir($electronBuild)) {
            mkdir($electronBuild, 0777, true);
        }

        @copy(public_path('icon.png'), $electronBuild.DIRECTORY_SEPARATOR.'icon.png');
        @copy(public_path('icon.ico'), $electronBuild.DIRECTORY_SEPARATOR.'icon.ico');
        @copy(public_path('icon.icns'), $electronBuild.DIRECTORY_SEPARATOR.'icon.icns');

        // Copy into asar / resources build tree (window + tray icon source)
        @copy(public_path('icon.png'), ElectronServiceProvider::buildPath('icon.png'));
        @copy(public_path('icon.ico'), ElectronServiceProvider::buildPath('icon.ico'));
        @copy(public_path('icon.icns'), ElectronServiceProvider::buildPath('icon.icns'));

        @copy(public_path('IconTemplate.png'), ElectronServiceProvider::buildPath('IconTemplate.png'));
        @copy(public_path('IconTemplate@2x.png'), ElectronServiceProvider::buildPath('IconTemplate@2x.png'));
    }
}
