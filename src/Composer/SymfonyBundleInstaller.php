<?php

namespace ErrorWatch\Sdk\Composer;

use Composer\Script\Event;

class SymfonyBundleInstaller
{
    public static function postInstall(Event $event): void
    {
        // Only run if Symfony is detected
        if (!class_exists(\Symfony\Component\HttpKernel\Kernel::class)) {
            return;
        }

        $bundlesFile = self::findBundlesFile();
        if ($bundlesFile === null) {
            return;
        }

        $contents = file_get_contents($bundlesFile);
        $bundleClass = 'ErrorWatch\\Symfony\\ErrorWatchBundle';

        // Already registered
        if (str_contains($contents, $bundleClass)) {
            return;
        }

        // Add the bundle before the closing ];
        $newLine = "    {$bundleClass}::class => ['all' => true],\n";
        $contents = str_replace('];', $newLine . '];', $contents);
        file_put_contents($bundlesFile, $contents);

        $event->getIO()->write('<info>ErrorWatch: Symfony bundle auto-registered in config/bundles.php</info>');
    }

    private static function findBundlesFile(): ?string
    {
        $paths = [
            getcwd() . '/config/bundles.php',
            dirname(getcwd()) . '/config/bundles.php',
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }
}
