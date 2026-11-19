<?php

namespace Ernestdefoe\Kiosk\Tests\integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every file and chunk folder extend.php hands Flarum must ship in the
 * package. A missing chunk folder made enabling Kiosk fail ("Unable to create
 * a directory … js/dist/admin") wherever vendor isn't writable by PHP.
 */
class ShippedAssetsTest extends TestCase
{
    #[Test]
    public function every_asset_path_in_extend_php_exists(): void
    {
        $root = dirname(__DIR__, 2);
        preg_match_all("~->(js|jsDirectory|css)\\(__DIR__\\.'([^']+)'\\)~", (string) file_get_contents($root.'/extend.php'), $m, PREG_SET_ORDER);
        $this->assertNotEmpty($m);

        foreach ($m as [, $kind, $path]) {
            // A folder with no files in it doesn't ship either: git keeps no empty
            // folders, and Flarum makes one here itself when vendor is writable.
            $kind === 'jsDirectory'
                ? $this->assertNotEmpty(glob($root.$path.'/*'), "jsDirectory $path doesn't ship")
                : $this->assertFileExists($root.$path, "$kind $path doesn't ship");
        }
    }
}
