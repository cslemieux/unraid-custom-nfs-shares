<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests: NFS share validation — label (F-0001), clients, anon, path TOCTOU.
 *
 * Task 7.3 — AC-NFS-07.1, AC-NFS-07.2, AC-NFS-07.3, AC-NFS-07.4,
 *             AC-NFS-09.1, AC-NFS-09.2, AC-NFS-16.1
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class NfsValidationTest extends TestCase
{
    /** Temporary harness root — contains /mnt so TestModeDetector resolves. */
    private string $tmpBase;
    /** Convenience: a real /mnt/user dir inside the harness. */
    private string $mntUser;

    protected function setUp(): void
    {
        $this->tmpBase = sys_get_temp_dir() . '/nfs-unit-val-' . getmypid() . '-' . uniqid();
        $this->mntUser = $this->tmpBase . '/mnt/user';
        mkdir($this->mntUser, 0755, true);

        if (!defined('PHPUNIT_TEST')) {
            define('PHPUNIT_TEST', true);
        }

        require_once __DIR__ . '/../../source/usr/local/emhttp/plugins/custom.nfs.shares/include/ConfigRegistry.php';
        require_once __DIR__ . '/../../source/usr/local/emhttp/plugins/custom.nfs.shares/include/TestModeDetector.php';

        // config lives at <tmpBase>/config; harness root is <tmpBase> (has /mnt).
        ConfigRegistry::setConfigBase($this->tmpBase . '/config');
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

    // =========================================================================
    // validateShareLabel — F-0001
    // =========================================================================

    public function testLabelEmptyIsError(): void
    {
        $errors = validateShareLabel('', []);
        $this->assertNotEmpty($errors, 'Empty label must produce an error');
    }

    public function testLabel41CharsIsError(): void
    {
        $errors = validateShareLabel(str_repeat('a', 41), []);
        $this->assertNotEmpty($errors, '41-char label must produce an error');
    }

    public function testLabel40CharsIsOk(): void
    {
        $errors = validateShareLabel(str_repeat('a', 40), []);
        $this->assertEmpty($errors, '40-char label must be valid');
    }

    public function testLabelWithSlashIsError(): void
    {
        $errors = validateShareLabel('bad/name', []);
        $this->assertNotEmpty($errors, 'Label containing "/" must be invalid');
    }

    public function testLabelWithDoubleQuoteIsError(): void
    {
        $errors = validateShareLabel('bad"name', []);
        $this->assertNotEmpty($errors, 'Label containing \'"\' must be invalid');
    }

    public function testLabelWithAngleBracketIsError(): void
    {
        $errors = validateShareLabel('bad<name', []);
        $this->assertNotEmpty($errors, 'Label containing "<" must be invalid');
    }

    public function testCaseInsensitiveDuplicateIsError(): void
    {
        $existing = [['name' => 'Foo']];
        $errors   = validateShareLabel('foo', $existing);
        $this->assertNotEmpty($errors, 'Case-insensitive duplicate must be an error');
    }

    public function testEditKeepingOwnNameIsOk(): void
    {
        // "foo" editing its own name "foo" → no duplicate error.
        $existing = [['name' => 'foo']];
        $errors   = validateShareLabel('foo', $existing, 'foo');
        $this->assertEmpty($errors, 'Editing and keeping own name must not produce a duplicate error');
    }

    public function testEditKeepingOwnNameCaseInsensitiveIsOk(): void
    {
        // "foo" editing its own name stored as "Foo" → should still pass.
        $existing = [['name' => 'Foo']];
        $errors   = validateShareLabel('Foo', $existing, 'Foo');
        $this->assertEmpty($errors);
    }

    public function testValidLabelIsOk(): void
    {
        $errors = validateShareLabel('My-NFS-Share_1', []);
        $this->assertEmpty($errors, 'Valid label must produce no errors');
    }

    // =========================================================================
    // validateClients — AC-NFS-07.1, AC-NFS-07.3
    // =========================================================================

    public function testEmptyClientsArrayIsError(): void
    {
        $errors = validateClients([]);
        $this->assertNotEmpty($errors, 'Empty clients array must be rejected');
    }

    public function testBadClientSpecIsError(): void
    {
        $errors = validateClients(['bad host!']);
        $this->assertNotEmpty($errors, 'Client spec with spaces/bang must be rejected');
    }

    public function testCidrClientIsOk(): void
    {
        $errors = validateClients(['192.168.1.0/24']);
        $this->assertEmpty($errors, 'CIDR client spec must be valid');
    }

    public function testNetgroupClientIsOk(): void
    {
        $errors = validateClients(['@trusted']);
        $this->assertEmpty($errors, '@netgroup client spec must be valid');
    }

    public function testWildcardClientIsOk(): void
    {
        $errors = validateClients(['*']);
        $this->assertEmpty($errors, 'Wildcard "*" client spec must be valid');
    }

    public function testHostnameWildcardClientIsOk(): void
    {
        $errors = validateClients(['*.example.com']);
        $this->assertEmpty($errors, 'Hostname wildcard must be valid');
    }

    public function testMultipleValidClientsIsOk(): void
    {
        $errors = validateClients(['192.168.1.0/24', '@trusted', '*']);
        $this->assertEmpty($errors, 'Multiple valid client specs must produce no errors');
    }

    // =========================================================================
    // validateAnon — AC-NFS-07.2
    // =========================================================================

    public function testAnonuidNegativeOneIsError(): void
    {
        $errors = validateAnon(-1, null);
        $this->assertNotEmpty($errors, 'anonuid=-1 must be rejected');
    }

    public function testAnonuidZeroIsOk(): void
    {
        $errors = validateAnon(0, null);
        $this->assertEmpty($errors, 'anonuid=0 must be valid');
    }

    public function testAnonuid1000IsOk(): void
    {
        $errors = validateAnon(1000, null);
        $this->assertEmpty($errors, 'anonuid=1000 must be valid');
    }

    public function testAnongidNegativeIsError(): void
    {
        $errors = validateAnon(null, -5);
        $this->assertNotEmpty($errors, 'anongid<0 must be rejected');
    }

    public function testBothNullIsOk(): void
    {
        $errors = validateAnon(null, null);
        $this->assertEmpty($errors, 'Both null must produce no errors');
    }

    // =========================================================================
    // validatePath — TOCTOU, AC-NFS-09.1, AC-NFS-09.2
    // =========================================================================

    public function testNonExistentPathIsError(): void
    {
        $errors = validatePath('/mnt/user/does-not-exist-' . uniqid());
        $this->assertNotEmpty($errors, 'Non-existent path must be rejected');
    }

    public function testValidMntPathIsOk(): void
    {
        $dir = $this->mntUser . '/testdir-' . uniqid();
        mkdir($dir, 0755, true);
        $errors = validatePath('/mnt/user/' . basename($dir));
        $this->assertEmpty($errors, 'Existing /mnt/ dir must be valid');
    }

    public function testSymlinkEscapingMntIsRejected(): void
    {
        // Create a dir OUTSIDE /mnt, then symlink into /mnt pointing at it.
        $outside = $this->tmpBase . '/outside-' . uniqid();
        mkdir($outside, 0755);
        $linkInMnt = $this->mntUser . '/escape-link-' . uniqid();
        symlink($outside, $linkInMnt);

        // The symlink's lexical path is under /mnt/ but realpath resolves
        // to outside → must be rejected (AC-NFS-09.1).
        $errors = validatePath('/mnt/user/' . basename($linkInMnt));
        $this->assertNotEmpty($errors,
            'Symlink whose realpath escapes /mnt/ must be rejected');
    }

    public function testEmptyPathIsError(): void
    {
        $errors = validatePath('');
        $this->assertNotEmpty($errors, 'Empty path must be rejected');
    }

    // =========================================================================
    // validateShare — aggregation returns ALL errors
    // =========================================================================

    public function testValidateShareAggregatesAllErrors(): void
    {
        // Invalid name + non-existent path + empty clients.
        $share = [
            'name'    => '',                        // error: empty name
            'path'    => '/mnt/nonexistent-' . uniqid(), // error: not found
            'clients' => [],                         // error: no clients
        ];
        $errors = validateShare($share, []);

        $this->assertGreaterThanOrEqual(3, count($errors),
            'validateShare must aggregate errors from name, path, and clients');
    }

    public function testValidateShareReturnsEmptyForValidShare(): void
    {
        $dir = $this->mntUser . '/valid-' . uniqid();
        mkdir($dir, 0755, true);

        $share = [
            'name'    => 'ValidShare',
            'path'    => '/mnt/user/' . basename($dir),
            'clients' => ['192.168.1.0/24'],
        ];
        $errors = validateShare($share, []);
        $this->assertEmpty($errors, 'Fully valid share must produce no errors');
    }

    // -------------------------------------------------------------------------
    // Duplicate fsid guard
    // -------------------------------------------------------------------------

    public function testUserFsidCollidingWithOtherUserFsidRejected(): void
    {
        $dir = $this->mntUser . '/fsid-' . uniqid();
        mkdir($dir, 0755, true);

        $existing = [['name' => 'other', 'extra_options' => ['fsid=250']]];
        $errors = validateShare([
            'name'          => 'newshare',
            'path'          => '/mnt/user/' . basename($dir),
            'clients'       => ['*'],
            'extra_options' => ['fsid=250'],
        ], $existing);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('fsid=250', implode(' ', $errors));
    }

    public function testUserFsidCollidingWithAutoAssignedFsidRejected(): void
    {
        $dir = $this->mntUser . '/fsid-' . uniqid();
        mkdir($dir, 0755, true);

        $existing = [['name' => 'other', 'fsid' => 200]];
        $errors = validateShare([
            'name'          => 'newshare',
            'path'          => '/mnt/user/' . basename($dir),
            'clients'       => ['*'],
            'extra_options' => ['fsid=200'],
        ], $existing);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString("used by export 'other'", implode(' ', $errors));
    }

    public function testEditedShareMayKeepItsOwnFsid(): void
    {
        $dir = $this->mntUser . '/fsid-' . uniqid();
        mkdir($dir, 0755, true);

        $existing = [['name' => 'me', 'extra_options' => ['fsid=260']]];
        $errors = validateShare([
            'name'          => 'me',
            'path'          => '/mnt/user/' . basename($dir),
            'clients'       => ['*'],
            'extra_options' => ['fsid=260'],
        ], $existing, 'me');
        $this->assertEmpty($errors, 'A share must be able to keep its own fsid on edit');
    }
}
