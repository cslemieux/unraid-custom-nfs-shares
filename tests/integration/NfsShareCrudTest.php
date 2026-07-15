<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration tests: NFS share CRUD persistence (task 7.4).
 *
 * AC-NFS-01.1 add, AC-NFS-01.2 update, AC-NFS-01.3 delete, AC-NFS-01.4 toggle,
 * AC-NFS-16.2 (never writes to real /boot/config or /etc/exports.d).
 *
 * Each test reads actual on-disk files from the temp harness — no in-memory
 * return-value assertions accepted as sole proof.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class NfsShareCrudTest extends TestCase
{
    /** Harness root: <tmpBase>/ — contains mnt/, config/, etc/exports.d/. */
    private string $tmpBase;
    private string $configBase;
    private string $sharesFile;
    private string $exportsFile;      // runtime drop-in (under harness)
    private string $persistentExports; // persistent copy (under configBase)

    protected function setUp(): void
    {
        $this->tmpBase    = sys_get_temp_dir() . '/nfs-int-crud-' . getmypid() . '-' . uniqid();
        $this->configBase = $this->tmpBase . '/config';

        // Directory tree the harness needs.
        mkdir($this->tmpBase . '/mnt/user', 0755, true);
        mkdir($this->configBase . '/plugins/custom.nfs.shares', 0755, true);
        mkdir($this->tmpBase . '/etc/exports.d', 0755, true);
        mkdir($this->tmpBase . '/usr/sbin', 0755, true);
        mkdir($this->tmpBase . '/etc/rc.d', 0755, true);

        // Write a no-op exportfs stub that always succeeds.
        $exportfsStub = $this->tmpBase . '/usr/sbin/exportfs';
        file_put_contents($exportfsStub, "#!/bin/sh\nexit 0\n");
        chmod($exportfsStub, 0755);

        // Other stubs required by TestModeDetector::getMockScriptPaths().
        foreach (['showmount', 'rpcinfo'] as $cmd) {
            $p = $this->tmpBase . '/usr/sbin/' . $cmd;
            file_put_contents($p, "#!/bin/sh\nexit 0\n");
            chmod($p, 0755);
        }
        $rcNfsd = $this->tmpBase . '/etc/rc.d/rc.nfsd';
        file_put_contents($rcNfsd, "#!/bin/sh\nexit 0\n");
        chmod($rcNfsd, 0755);

        // Resolve file paths used in assertions.
        $this->sharesFile      = $this->configBase . '/plugins/custom.nfs.shares/shares.json';
        $this->exportsFile     = $this->tmpBase . '/etc/exports.d/custom-nfs-shares.exports';
        $this->persistentExports = $this->configBase . '/plugins/custom.nfs.shares/custom-nfs-shares.exports';

        ini_set('error_log', '/dev/null');
        if (!defined('PHPUNIT_TEST')) {
            define('PHPUNIT_TEST', true);
        }

        require_once __DIR__ . '/../../source/usr/local/emhttp/plugins/custom.nfs.shares/include/ConfigRegistry.php';
        require_once __DIR__ . '/../../source/usr/local/emhttp/plugins/custom.nfs.shares/include/TestModeDetector.php';

        ConfigRegistry::setConfigBase($this->configBase);
        TestModeDetector::reset();

        require_once __DIR__ . '/../../source/usr/local/emhttp/plugins/custom.nfs.shares/include/lib.php';
    }

    protected function tearDown(): void
    {
        ConfigRegistry::reset();
        TestModeDetector::reset();
        if (is_dir($this->tmpBase)) {
            exec('rm -rf ' . escapeshellarg($this->tmpBase));
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Make a real /mnt/user/<name> directory and return its logical path. */
    private function makeMntDir(string $name): string
    {
        $real = $this->tmpBase . '/mnt/user/' . $name;
        if (!is_dir($real)) {
            mkdir($real, 0755, true);
        }
        return '/mnt/user/' . $name;
    }

    private function readSharesFromDisk(): array
    {
        if (!file_exists($this->sharesFile)) {
            return [];
        }
        $data = json_decode(file_get_contents($this->sharesFile), true);
        return is_array($data) ? $data : [];
    }

    private function readExportsFromDisk(): string
    {
        return file_exists($this->exportsFile)
            ? file_get_contents($this->exportsFile)
            : '';
    }

    private function readPersistentExportsFromDisk(): string
    {
        return file_exists($this->persistentExports)
            ? file_get_contents($this->persistentExports)
            : '';
    }

    // -------------------------------------------------------------------------
    // AC-NFS-01.1  Add
    // -------------------------------------------------------------------------

    public function testAddShareAppearsInSharesJsonAndExportsFile(): void
    {
        $path = $this->makeMntDir('media');
        $share = [
            'name'    => 'Media',
            'path'    => $path,
            'clients' => ['192.168.1.0/24'],
            'enabled' => true,
        ];

        $shares = loadShares();
        $shares[] = $share;
        saveShares($shares);
        $result = applySharesAndReload($shares);

        // --- shares.json on disk ---
        $onDisk = $this->readSharesFromDisk();
        $this->assertCount(1, $onDisk, 'shares.json must contain the new share');
        $this->assertSame('Media', $onDisk[0]['name']);
        $this->assertSame($path,   $onDisk[0]['path']);

        // --- .exports file on disk ---
        $exports = $this->readExportsFromDisk();
        $this->assertStringContainsString($path, $exports,
            'Runtime .exports must contain the new share path');

        // --- persistent copy on disk ---
        $persistent = $this->readPersistentExportsFromDisk();
        $this->assertStringContainsString($path, $persistent,
            'Persistent .exports must contain the new share path');

        $this->assertTrue($result['success'], 'applySharesAndReload must succeed');
    }

    // -------------------------------------------------------------------------
    // AC-NFS-01.2  Update
    // -------------------------------------------------------------------------

    public function testUpdateShareReflectsInSharesJsonAndExports(): void
    {
        $path = $this->makeMntDir('docs');
        $shares = [['name' => 'Docs', 'path' => $path, 'clients' => ['*'], 'enabled' => true]];
        saveShares($shares);
        applySharesAndReload($shares);

        // Now update: change clients.
        $shares[0]['clients'] = ['10.0.0.0/8'];
        saveShares($shares);
        applySharesAndReload($shares);

        // shares.json must reflect updated value.
        $onDisk = $this->readSharesFromDisk();
        $this->assertSame(['10.0.0.0/8'], $onDisk[0]['clients'],
            'Updated clients must be persisted to shares.json');

        // .exports must be regenerated with new client.
        $exports = $this->readExportsFromDisk();
        $this->assertStringContainsString('10.0.0.0/8', $exports,
            'Updated client must appear in .exports file');
        $this->assertStringNotContainsString('*(', $exports,
            'Old wildcard client must not appear after update');
    }

    // -------------------------------------------------------------------------
    // AC-NFS-01.3  Delete
    // -------------------------------------------------------------------------

    public function testDeleteShareAbsentFromSharesJsonAndExports(): void
    {
        $pathA = $this->makeMntDir('alpha');
        $pathB = $this->makeMntDir('beta');
        $shares = [
            ['name' => 'Alpha', 'path' => $pathA, 'clients' => ['*'], 'enabled' => true],
            ['name' => 'Beta',  'path' => $pathB, 'clients' => ['*'], 'enabled' => true],
        ];
        saveShares($shares);
        applySharesAndReload($shares);

        // Delete Alpha.
        $shares = [array_values($shares)[1]];  // keep only Beta
        saveShares($shares);
        applySharesAndReload($shares);

        $onDisk = $this->readSharesFromDisk();
        $this->assertCount(1, $onDisk, 'Only one share must remain');
        $this->assertSame('Beta', $onDisk[0]['name'],
            'Beta must remain after Alpha deleted');

        $exports = $this->readExportsFromDisk();
        $this->assertStringNotContainsString($pathA, $exports,
            'Deleted share path must not appear in .exports');
        $this->assertStringContainsString($pathB, $exports,
            'Remaining share path must still appear in .exports');
    }

    // -------------------------------------------------------------------------
    // AC-NFS-01.4  Toggle disabled: present in shares.json, absent from .exports
    // -------------------------------------------------------------------------

    public function testToggleDisabledSharePresentInJsonAbsentFromExports(): void
    {
        $path = $this->makeMntDir('toggled');
        $shares = [['name' => 'Toggled', 'path' => $path, 'clients' => ['*'], 'enabled' => true]];
        saveShares($shares);
        applySharesAndReload($shares);

        // Now disable the share.
        $shares[0]['enabled'] = false;
        saveShares($shares);
        applySharesAndReload($shares);

        // shares.json: share still present with enabled=false.
        $onDisk = $this->readSharesFromDisk();
        $this->assertCount(1, $onDisk,
            'Disabled share must still exist in shares.json');
        $this->assertFalse($onDisk[0]['enabled'],
            'enabled flag must be false in shares.json');

        // .exports: share path must NOT appear.
        $exports = $this->readExportsFromDisk();
        $this->assertStringNotContainsString($path, $exports,
            'Disabled share must NOT appear in .exports file');
    }
}
