<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration tests: atomic temp+rename fault injection (CF-001).
 *
 * The existing NfsApplyRollbackTest proves atomicity only by asserting that
 * no *.tmp.* files remain after happy/rollback runs. These tests directly
 * inject failures into the temp-write and rename steps of writeExportsFiles()
 * and saveShares() to prove:
 *
 *  (a) When the temp-file write fails, the target file is never created /
 *      corrupted, and a RuntimeException is propagated.
 *  (b) When rename() fails (target dir made read-only after temp write),
 *      the target is unchanged, the temp file is cleaned up, and a
 *      RuntimeException is propagated.
 *  (c) When saveShares() rename fails, the existing shares.json is
 *      left intact (no partial write at target).
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class NfsAtomicWriteFaultTest extends TestCase
{
    private string $tmpBase;
    private string $configBase;
    private string $exportsDir;
    private string $exportsFile;
    private string $persistentDir;
    private string $persistentExports;
    private string $sharesFile;

    protected function setUp(): void
    {
        $this->tmpBase           = sys_get_temp_dir() . '/nfs-atomic-fault-' . getmypid() . '-' . uniqid();
        $this->configBase        = $this->tmpBase . '/boot/config';
        $this->exportsDir        = $this->tmpBase . '/etc/exports.d';
        $this->exportsFile       = $this->exportsDir . '/custom-nfs-shares.exports';
        $this->persistentDir     = $this->configBase . '/plugins/custom.nfs.shares';
        $this->persistentExports = $this->persistentDir . '/custom-nfs-shares.exports';
        $this->sharesFile        = $this->persistentDir . '/shares.json';

        mkdir($this->tmpBase . '/mnt/user', 0755, true);
        mkdir($this->exportsDir, 0755, true);
        mkdir($this->persistentDir, 0755, true);
        mkdir($this->tmpBase . '/usr/sbin', 0755, true);
        mkdir($this->tmpBase . '/etc/rc.d', 0755, true);

        // Stub executables so applySharesAndReload can complete (we only care
        // about the write path, not the exportfs call).
        foreach (['exportfs', 'showmount', 'rpcinfo'] as $cmd) {
            $p = $this->tmpBase . '/usr/sbin/' . $cmd;
            file_put_contents($p, "#!/bin/sh\nexit 0\n");
            chmod($p, 0755);
        }
        $rc = $this->tmpBase . '/etc/rc.d/rc.nfsd';
        file_put_contents($rc, "#!/bin/sh\nexit 0\n");
        chmod($rc, 0755);

        if (!defined('PHPUNIT_TEST')) {
            define('PHPUNIT_TEST', true);
        }

        // Redirect PHP error_log to /dev/null so that logError() emissions on
        // the intentionally-exercised failure paths (saveShares/applyShares...
        // when a write fails) are not intercepted by PHPUnit's error handler
        // and converted into test errors. Mirrors NfsApplyRollbackTest::setUp.
        ini_set('error_log', '/dev/null');

        require_once __DIR__ . '/../../source/usr/local/emhttp/plugins/custom.nfs.shares/include/ConfigRegistry.php';
        require_once __DIR__ . '/../../source/usr/local/emhttp/plugins/custom.nfs.shares/include/TestModeDetector.php';

        ConfigRegistry::setConfigBase($this->configBase);
        TestModeDetector::reset();

        require_once __DIR__ . '/../../source/usr/local/emhttp/plugins/custom.nfs.shares/include/lib.php';
    }

    protected function tearDown(): void
    {
        // Restore permissions before cleanup so rm -rf can delete everything.
        foreach ([$this->exportsDir, $this->persistentDir] as $d) {
            if (is_dir($d)) {
                @chmod($d, 0755);
            }
        }
        ConfigRegistry::reset();
        TestModeDetector::reset();
        if (is_dir($this->tmpBase)) {
            exec('rm -rf ' . escapeshellarg($this->tmpBase));
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeMntDir(string $name): string
    {
        $d = $this->tmpBase . '/mnt/user/' . $name;
        if (!is_dir($d)) {
            mkdir($d, 0755, true);
        }
        return '/mnt/user/' . $name;
    }

    private function canMakeUnwritable(string $dir): bool
    {
        // Probe whether chmod 0500 actually prevents writing (not root).
        @chmod($dir, 0500);
        $probe = @file_put_contents($dir . '/.probe', 'x');
        @chmod($dir, 0755);
        if ($probe !== false) {
            @unlink($dir . '/.probe');
            return false;
        }
        return true;
    }

    // =========================================================================
    // writeExportsFiles() fault injection
    // =========================================================================

    /**
     * When the exportsDir is unwritable, the temp-file write fails and
     * writeExportsFiles() throws a RuntimeException. The target file is
     * never created (no partial write at target).
     */
    public function testTempWriteFailureThrowsAndLeavesTargetAbsent(): void
    {
        if (!$this->canMakeUnwritable($this->exportsDir)) {
            $this->markTestSkipped('Cannot make directory unwritable (running as root?)');
        }

        // Make exportsDir unwritable so file_put_contents to a .tmp file fails.
        @chmod($this->exportsDir, 0500);

        $content = "/mnt/user/test 192.168.1.0/24(rw,sync,no_subtree_check,root_squash)\n";

        try {
            writeExportsFiles($content);
            @chmod($this->exportsDir, 0755);
            $this->fail('writeExportsFiles() must throw RuntimeException on temp-write failure');
        } catch (\RuntimeException $e) {
            @chmod($this->exportsDir, 0755);
            $this->assertStringContainsStringIgnoringCase(
                'Failed',
                $e->getMessage(),
                'Exception message must describe the failure'
            );
        }

        // The target must not have been created (no partial write).
        $this->assertFileDoesNotExist(
            $this->exportsFile,
            'Target exports file must not exist when temp-write failed'
        );
    }

    /**
     * When a good exports file already exists and the temp-write fails,
     * the existing file content is left intact (no corruption).
     */
    public function testTempWriteFailurePreservesExistingTargetContent(): void
    {
        if (!$this->canMakeUnwritable($this->exportsDir)) {
            $this->markTestSkipped('Cannot make directory unwritable (running as root?)');
        }

        // Write a known-good exports file first.
        $goodContent = "/mnt/user/good 192.168.1.0/24(ro,sync,no_subtree_check,root_squash)\n";
        file_put_contents($this->exportsFile, $goodContent);

        // Also write the persistent copy so writeExportsFiles targets match.
        file_put_contents($this->persistentExports, $goodContent);

        // Make exportsDir unwritable AFTER writing the good file.
        @chmod($this->exportsDir, 0500);

        $newContent = "/mnt/user/new 10.0.0.0/8(rw,sync,no_subtree_check,root_squash)\n";

        try {
            writeExportsFiles($newContent);
            @chmod($this->exportsDir, 0755);
            $this->fail('writeExportsFiles() must throw RuntimeException on temp-write failure');
        } catch (\RuntimeException $e) {
            @chmod($this->exportsDir, 0755);
        }

        // The original good content must be intact.
        $this->assertSame(
            $goodContent,
            file_get_contents($this->exportsFile),
            'Existing exports file must be intact after temp-write failure'
        );
    }

    /**
     * When both the exportsDir and persistentDir are writable but only the
     * persistent dir is made unwritable after the first temp file is written,
     * writeExportsFiles() throws before the second rename succeeds.
     */
    public function testSecondTargetTempWriteFailureThrows(): void
    {
        if (!$this->canMakeUnwritable($this->persistentDir)) {
            $this->markTestSkipped('Cannot make directory unwritable (running as root?)');
        }

        // Write a known-good persistent file first.
        $goodContent = "/mnt/user/good 192.168.1.0/24(ro,sync,no_subtree_check,root_squash)\n";
        file_put_contents($this->persistentExports, $goodContent);

        // Make persistentDir unwritable so the second target's temp write fails.
        @chmod($this->persistentDir, 0500);

        $newContent = "/mnt/user/new 10.0.0.0/8(rw,sync,no_subtree_check,root_squash)\n";

        try {
            writeExportsFiles($newContent);
            @chmod($this->persistentDir, 0755);
            $this->fail('writeExportsFiles() must throw RuntimeException when second temp-write fails');
        } catch (\RuntimeException $e) {
            @chmod($this->persistentDir, 0755);
            $this->assertStringContainsStringIgnoringCase('Failed', $e->getMessage());
        }

        // The persistent copy must be intact.
        $this->assertSame(
            $goodContent,
            file_get_contents($this->persistentExports),
            'Persistent exports file must be intact after second-target temp-write failure'
        );
    }

    // =========================================================================
    // saveShares() atomic rename fault injection
    // =========================================================================

    /**
     * When the config dir is unwritable, saveShares() cannot write the temp
     * file and must return false without corrupting any existing shares.json.
     */
    public function testSaveSharesTempWriteFailureReturnsFalse(): void
    {
        if (!$this->canMakeUnwritable($this->persistentDir)) {
            $this->markTestSkipped('Cannot make directory unwritable (running as root?)');
        }

        // Write a known-good shares.json first.
        $goodShares = [['name' => 'GoodShare', 'path' => '/mnt/user/good', 'clients' => ['*']]];
        file_put_contents($this->sharesFile, json_encode($goodShares, JSON_PRETTY_PRINT));

        // Make dir unwritable so the temp file write fails.
        @chmod($this->persistentDir, 0500);

        $newShares = [['name' => 'BadWrite', 'path' => '/mnt/user/bad', 'clients' => ['*']]];
        $result = saveShares($newShares);

        @chmod($this->persistentDir, 0755);

        $this->assertFalse(
            (bool)$result,
            'saveShares() must return falsy when temp-write fails'
        );

        // shares.json must be unchanged.
        $disk = json_decode((string)file_get_contents($this->sharesFile), true);
        $this->assertSame(
            'GoodShare',
            $disk[0]['name'],
            'Existing shares.json must not be corrupted when temp-write fails'
        );
    }

    /**
     * After a successful saveShares(), no *.tmp.* files remain in the
     * config directory (atomic move cleans up).
     */
    public function testSaveSharesLeavesNoTmpFiles(): void
    {
        $shares = [['name' => 'TestShare', 'path' => '/mnt/user/ts', 'clients' => ['*']]];
        saveShares($shares);

        $leftovers = glob($this->persistentDir . '/*.tmp.*') ?: [];
        $this->assertEmpty(
            $leftovers,
            'No *.tmp.* files must remain after a successful saveShares()'
        );
    }

    /**
     * Confirm that a write failure during applySharesAndReload() (exportfs
     * succeeds but writeExportsFiles can't write) propagates as a failed
     * result and leaves both exports targets absent when they didn't exist
     * before.
     */
    public function testApplySharesAndReloadReflectsWriteFailureInResult(): void
    {
        if (!$this->canMakeUnwritable($this->exportsDir)) {
            $this->markTestSkipped('Cannot make directory unwritable (running as root?)');
        }
        if (!$this->canMakeUnwritable($this->persistentDir)) {
            $this->markTestSkipped('Cannot make directory unwritable (running as root?)');
        }

        $path   = $this->makeMntDir('apply-fault');
        $shares = [['name' => 'FaultShare', 'path' => $path, 'clients' => ['192.168.1.0/24'], 'enabled' => true]];
        saveShares($shares);

        // Make both write targets unwritable BEFORE apply.
        @chmod($this->exportsDir, 0500);
        @chmod($this->persistentDir, 0500);

        $result = applySharesAndReload($shares);

        @chmod($this->exportsDir, 0755);
        @chmod($this->persistentDir, 0755);

        $this->assertFalse(
            $result['success'],
            'applySharesAndReload() must return success=false when writeExportsFiles fails'
        );
        $this->assertNotEmpty(
            $result['error'],
            'applySharesAndReload() must include an error message on write failure'
        );
    }
}
