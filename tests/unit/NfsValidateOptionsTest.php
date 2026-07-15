<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for validateOptions() and validateShare() enum allow-list
 * rejection paths in the NFS plugin.
 *
 * Addresses CF-001 (validateOptions reject-path coverage) and CF-005
 * (enum allow-list rejection paths for access/sync/subtree/squash).
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class NfsValidateOptionsTest extends TestCase
{
    /** Temporary harness root — contains /mnt so TestModeDetector resolves. */
    private string $tmpBase;
    private string $mntUser;

    protected function setUp(): void
    {
        $this->tmpBase  = sys_get_temp_dir() . '/nfs-unit-opts-' . getmypid() . '-' . uniqid();
        $this->mntUser  = $this->tmpBase . '/mnt/user';
        mkdir($this->mntUser, 0755, true);

        if (!defined('PHPUNIT_TEST')) {
            define('PHPUNIT_TEST', true);
        }

        require_once __DIR__ . '/../../source/usr/local/emhttp/plugins/custom.nfs.shares/include/ConfigRegistry.php';
        require_once __DIR__ . '/../../source/usr/local/emhttp/plugins/custom.nfs.shares/include/TestModeDetector.php';

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
    // validateOptions() — CF-001: reject-path coverage
    // =========================================================================

    public function testValidateOptionsEmptyArrayIsOk(): void
    {
        $errors = validateOptions([]);
        $this->assertEmpty($errors, 'Empty options array must produce no errors');
    }

    public function testValidateOptionsValidSingleTokenIsOk(): void
    {
        $errors = validateOptions(['fsid=0']);
        $this->assertEmpty($errors, 'Valid token "fsid=0" must produce no errors');
    }

    public function testValidateOptionsMultipleValidTokensAreOk(): void
    {
        $errors = validateOptions(['fsid=0', 'crossmnt', 'nohide']);
        $this->assertEmpty($errors, 'Multiple valid tokens must produce no errors');
    }

    public function testValidateOptionsTokenWithSemicolonIsRejected(): void
    {
        $errors = validateOptions(['rw;evil']);
        $this->assertNotEmpty($errors, 'Token containing ";" must be rejected');
        $this->assertStringContainsString('rw;evil', $errors[0]);
    }

    public function testValidateOptionsTokenWithSpaceIsRejected(): void
    {
        $errors = validateOptions(['rw evil']);
        $this->assertNotEmpty($errors, 'Token containing a space must be rejected');
    }

    public function testValidateOptionsTokenWithNewlineIsRejected(): void
    {
        $errors = validateOptions(["rw\nevil"]);
        $this->assertNotEmpty($errors, 'Token containing a newline must be rejected');
    }

    public function testValidateOptionsTokenWithParenIsRejected(): void
    {
        $errors = validateOptions(['fsid=0(extra)']);
        $this->assertNotEmpty($errors, 'Token containing "(" must be rejected');
    }

    public function testValidateOptionsTokenWithBackslashIsRejected(): void
    {
        $errors = validateOptions(['rw\\bad']);
        $this->assertNotEmpty($errors, 'Token containing "\\" must be rejected');
    }

    public function testValidateOptionsTokenWithDollarIsRejected(): void
    {
        $errors = validateOptions(['$var']);
        $this->assertNotEmpty($errors, 'Token containing "$" must be rejected');
    }

    public function testValidateOptionsEmptyStringTokenIsRejected(): void
    {
        $errors = validateOptions(['']);
        $this->assertNotEmpty($errors, 'Empty string token must be rejected');
    }

    public function testValidateOptionsNonStringTokenIsRejected(): void
    {
        // Non-strings arrive as mixed; validateOptions guards against them.
        $errors = validateOptions([42]);
        $this->assertNotEmpty($errors, 'Non-string token must be rejected');
    }

    public function testValidateOptionsMultipleTokensOneInvalidProducesError(): void
    {
        $errors = validateOptions(['fsid=0', 'bad token!', 'crossmnt']);
        $this->assertNotEmpty($errors, 'A single invalid token among valid ones must produce errors');
        $this->assertCount(1, $errors, 'Exactly one error for one invalid token');
    }

    public function testValidateOptionsMultipleInvalidTokensProduceMultipleErrors(): void
    {
        $errors = validateOptions(['bad;one', 'bad two']);
        $this->assertCount(2, $errors, 'Two invalid tokens must produce two errors');
    }

    // =========================================================================
    // validateShare() enum allow-list rejection paths — CF-005
    // =========================================================================

    /** Build a minimal valid share array with a real /mnt path. */
    private function makeValidShare(): array
    {
        $dir = $this->mntUser . '/testshare-' . uniqid();
        mkdir($dir, 0755, true);
        return [
            'name'    => 'ValidShare',
            'path'    => '/mnt/user/' . basename($dir),
            'clients' => ['192.168.1.0/24'],
        ];
    }

    // ---- access ----

    public function testValidAccessRwIsOk(): void
    {
        $share = $this->makeValidShare();
        $share['access'] = 'rw';
        $errors = validateShare($share, []);
        $this->assertEmpty($errors, '"rw" is a valid access value');
    }

    public function testValidAccessRoIsOk(): void
    {
        $share = $this->makeValidShare();
        $share['access'] = 'ro';
        $errors = validateShare($share, []);
        $this->assertEmpty($errors, '"ro" is a valid access value');
    }

    public function testInvalidAccessValueIsRejected(): void
    {
        $share = $this->makeValidShare();
        $share['access'] = 'write';
        $errors = validateShare($share, []);
        $this->assertNotEmpty($errors, 'Out-of-set access value must be rejected');
        $found = false;
        foreach ($errors as $e) {
            if (stripos($e, 'access') !== false) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Error message must mention "access"');
    }

    public function testAccessWithInjectionAttemptIsRejected(): void
    {
        $share = $this->makeValidShare();
        $share['access'] = 'rw,sync';  // looks valid but not in allow-list
        $errors = validateShare($share, []);
        $this->assertNotEmpty($errors, 'Injection-style access value must be rejected');
    }

    // ---- sync ----

    public function testValidSyncValueIsOk(): void
    {
        $share = $this->makeValidShare();
        $share['sync'] = 'sync';
        $errors = validateShare($share, []);
        $this->assertEmpty($errors, '"sync" is a valid sync value');
    }

    public function testValidAsyncValueIsOk(): void
    {
        $share = $this->makeValidShare();
        $share['sync'] = 'async';
        $errors = validateShare($share, []);
        $this->assertEmpty($errors, '"async" is a valid sync value');
    }

    public function testInvalidSyncValueIsRejected(): void
    {
        $share = $this->makeValidShare();
        $share['sync'] = 'nosync';
        $errors = validateShare($share, []);
        $this->assertNotEmpty($errors, 'Out-of-set sync value must be rejected');
        $found = false;
        foreach ($errors as $e) {
            if (stripos($e, 'sync') !== false) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Error message must mention "sync"');
    }

    // ---- subtree ----

    public function testValidSubtreeCheckIsOk(): void
    {
        $share = $this->makeValidShare();
        $share['subtree'] = 'subtree_check';
        $errors = validateShare($share, []);
        $this->assertEmpty($errors, '"subtree_check" is a valid subtree value');
    }

    public function testValidNoSubtreeCheckIsOk(): void
    {
        $share = $this->makeValidShare();
        $share['subtree'] = 'no_subtree_check';
        $errors = validateShare($share, []);
        $this->assertEmpty($errors, '"no_subtree_check" is a valid subtree value');
    }

    public function testInvalidSubtreeValueIsRejected(): void
    {
        $share = $this->makeValidShare();
        $share['subtree'] = 'subtree';
        $errors = validateShare($share, []);
        $this->assertNotEmpty($errors, 'Out-of-set subtree value must be rejected');
        $found = false;
        foreach ($errors as $e) {
            if (stripos($e, 'subtree') !== false) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Error message must mention "subtree"');
    }

    // ---- squash ----

    public function testValidRootSquashIsOk(): void
    {
        $share = $this->makeValidShare();
        $share['squash'] = 'root_squash';
        $errors = validateShare($share, []);
        $this->assertEmpty($errors, '"root_squash" is a valid squash value');
    }

    public function testValidNoRootSquashIsOk(): void
    {
        $share = $this->makeValidShare();
        $share['squash'] = 'no_root_squash';
        $errors = validateShare($share, []);
        $this->assertEmpty($errors, '"no_root_squash" is a valid squash value');
    }

    public function testValidAllSquashIsOk(): void
    {
        $share = $this->makeValidShare();
        $share['squash'] = 'all_squash';
        $errors = validateShare($share, []);
        $this->assertEmpty($errors, '"all_squash" is a valid squash value');
    }

    public function testInvalidSquashValueIsRejected(): void
    {
        $share = $this->makeValidShare();
        $share['squash'] = 'none';
        $errors = validateShare($share, []);
        $this->assertNotEmpty($errors, 'Out-of-set squash value must be rejected');
        $found = false;
        foreach ($errors as $e) {
            if (stripos($e, 'squash') !== false) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Error message must mention "squash"');
    }

    public function testAllFourEnumFieldsInvalidSimultaneouslyProducesFourErrors(): void
    {
        $share = $this->makeValidShare();
        $share['access']  = 'INVALID_ACCESS';
        $share['sync']    = 'INVALID_SYNC';
        $share['subtree'] = 'INVALID_SUBTREE';
        $share['squash']  = 'INVALID_SQUASH';
        $errors = validateShare($share, []);
        // At minimum, there must be 4 errors (one per bad enum field).
        $this->assertGreaterThanOrEqual(
            4,
            count($errors),
            'Four invalid enum fields must produce at least 4 errors'
        );
    }

    public function testEmptyStringEnumFieldIsIgnored(): void
    {
        // An empty-string value for an enum field is treated as absent
        // (generation applies defaults) — must not produce a validation error.
        $share = $this->makeValidShare();
        $share['access'] = '';
        $errors = validateShare($share, []);
        $this->assertEmpty($errors, 'Empty-string enum field must not trigger validation error');
    }

    public function testAbsentEnumFieldsAreIgnored(): void
    {
        // A share with no access/sync/subtree/squash keys must be valid.
        $share = $this->makeValidShare();
        $errors = validateShare($share, []);
        $this->assertEmpty($errors, 'Share without enum fields must be fully valid');
    }
}
