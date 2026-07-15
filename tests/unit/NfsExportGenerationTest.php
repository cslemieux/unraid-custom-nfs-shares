<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests: NFS export-line generation, exports(5) escaping, option assembly.
 *
 * Task 7.2 — AC-NFS-03.2, AC-NFS-03.3, AC-NFS-04.1, AC-NFS-04.2, AC-NFS-08.1, AC-NFS-16.1
 *
 * ISOLATION: NFS lib global functions collide with SMB lib. Each test runs in
 * its own process so both suites can coexist (PHPUnit 9 annotation form).
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class NfsExportGenerationTest extends TestCase
{
    private string $tmpBase;

    protected function setUp(): void
    {
        $this->tmpBase = sys_get_temp_dir() . '/nfs-unit-gen-' . getmypid() . '-' . uniqid();
        mkdir($this->tmpBase . '/mnt/user', 0755, true);

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

    // -------------------------------------------------------------------------
    // buildShareExportLine — space-path double-quoting (AC-NFS-04.1/04.2)
    // -------------------------------------------------------------------------

    public function testSpacePathIsDoubleQuoted(): void
    {
        $share = ['path' => '/mnt/user/my share', 'clients' => ['192.168.1.0/24']];
        $line  = buildShareExportLine($share);
        $this->assertStringContainsString('"/mnt/user/my share"', $line,
            'Space in path must be wrapped in double-quotes per exports(5)');
    }

    public function testSpacePathIsNotSingleQuoted(): void
    {
        $share = ['path' => '/mnt/user/my share', 'clients' => ['192.168.1.0/24']];
        $line  = buildShareExportLine($share);
        $this->assertStringNotContainsString("'", $line,
            'Single-quoting (shell style) must NOT be used for exports(5) paths');
    }

    public function testSpacePathDoesNotUseEscapeShellArg(): void
    {
        $share        = ['path' => '/mnt/user/my share', 'clients' => ['192.168.1.0/24']];
        $line         = buildShareExportLine($share);
        $shellArgForm = escapeshellarg('/mnt/user/my share');
        $this->assertStringNotContainsString($shellArgForm, $line,
            'Output must not contain the escapeshellarg() representation');
    }

    // -------------------------------------------------------------------------
    // encodeExportsPath — non-ASCII byte → backslash-octal
    // -------------------------------------------------------------------------

    public function testNonAsciiBytesAreBackslashOctalEncoded(): void
    {
        // 0xC3 0xA9 = UTF-8 bytes for é
        $path    = '/mnt/user/caf' . "\xC3\xA9";
        $encoded = encodeExportsPath($path);
        $this->assertStringContainsString('\\303', $encoded,
            'Byte 0xC3 must encode as \\303 (octal)');
        $this->assertStringContainsString('\\251', $encoded,
            'Byte 0xA9 must encode as \\251 (octal)');
    }

    public function testNoSpacePathNotDoubleQuoted(): void
    {
        $encoded = encodeExportsPath('/mnt/user/media');
        $this->assertStringNotContainsString('"', $encoded,
            'Path without spaces must not be wrapped in double-quotes');
        $this->assertSame('/mnt/user/media', $encoded);
    }

    // -------------------------------------------------------------------------
    // buildExportOptions — ordering and no trailing comma when optionals absent
    // -------------------------------------------------------------------------

    public function testOptionOrderingWithAllDefaults(): void
    {
        $parts = explode(',', buildExportOptions([]));
        $this->assertGreaterThanOrEqual(4, count($parts));
        $this->assertSame('rw',               $parts[0], 'access first');
        $this->assertSame('sync',             $parts[1], 'sync second');
        $this->assertSame('no_subtree_check', $parts[2], 'subtree third');
        $this->assertSame('root_squash',      $parts[3], 'squash fourth');
    }

    public function testNoTrailingCommaWhenOptionalsAbsent(): void
    {
        $opts = buildExportOptions([
            'access' => 'ro', 'sync' => 'async',
            'subtree' => 'subtree_check', 'squash' => 'no_root_squash',
        ]);
        $this->assertStringNotContainsString(',,', $opts);
        $this->assertStringEndsNotWith(',', $opts);
    }

    public function testOptionalFieldsAbsentExactlyFourParts(): void
    {
        $this->assertCount(4, explode(',', buildExportOptions([])),
            'Without optional fields, exactly 4 option tokens expected');
    }

    public function testAnonuidAnongidAppearAfterSquash(): void
    {
        $parts  = explode(',', buildExportOptions(['anonuid' => '65534', 'anongid' => '65534']));
        $sqIdx  = array_search('root_squash',    $parts, true);
        $uidIdx = array_search('anonuid=65534',  $parts, true);
        $gidIdx = array_search('anongid=65534',  $parts, true);
        $this->assertNotFalse($uidIdx, 'anonuid must be present');
        $this->assertNotFalse($gidIdx, 'anongid must be present');
        $this->assertGreaterThan($sqIdx, $uidIdx, 'anonuid must come after squash');
        $this->assertGreaterThan($sqIdx, $gidIdx, 'anongid must come after squash');
    }

    public function testExtraOptionsAppendedLast(): void
    {
        $parts = explode(',', buildExportOptions(['extra_options' => ['fsid=0', 'crossmnt']]));
        $this->assertCount(6, $parts);
        $this->assertSame('fsid=0',   $parts[4]);
        $this->assertSame('crossmnt', $parts[5]);
    }

    // -------------------------------------------------------------------------
    // generateNfsExports — disabled omitted, line count, deterministic
    // -------------------------------------------------------------------------

    public function testDisabledShareIsOmittedFromOutput(): void
    {
        $shares = [
            ['name' => 'en',  'path' => '/mnt/user/a', 'clients' => ['*'], 'enabled' => true],
            ['name' => 'dis', 'path' => '/mnt/user/b', 'clients' => ['*'], 'enabled' => false],
        ];
        $out = generateNfsExports($shares);
        $this->assertStringContainsString('/mnt/user/a', $out);
        $this->assertStringNotContainsString('/mnt/user/b', $out);
    }

    public function testLineCountEqualsEnabledShareCount(): void
    {
        $shares = [
            ['name' => 's1', 'path' => '/mnt/user/s1', 'clients' => ['*'], 'enabled' => true],
            ['name' => 's2', 'path' => '/mnt/user/s2', 'clients' => ['*'], 'enabled' => false],
            ['name' => 's3', 'path' => '/mnt/user/s3', 'clients' => ['*']], // defaults to enabled
        ];
        $bodyLines = array_values(array_filter(
            explode("\n", generateNfsExports($shares)),
            static fn(string $l): bool => trim($l) !== '' && $l[0] !== '#'
        ));
        $this->assertCount(2, $bodyLines,
            '2 enabled shares → 2 body lines');
    }

    public function testTwoCallsProduceIdenticalOutput(): void
    {
        $shares = [
            ['name' => 'alpha', 'path' => '/mnt/user/alpha', 'clients' => ['192.168.0.0/16'], 'access' => 'ro'],
            ['name' => 'beta',  'path' => '/mnt/user/beta',  'clients' => ['@trusted']],
        ];
        $this->assertSame(generateNfsExports($shares), generateNfsExports($shares),
            'generateNfsExports must be deterministic');
    }

    // -------------------------------------------------------------------------
    // Injection guard: newline in field cannot produce extra exports line
    // -------------------------------------------------------------------------

    public function testNewlineInCommentNoExtraLine(): void
    {
        $shares = [[
            'name'    => 'inject',
            'path'    => '/mnt/user/safe',
            'comment' => "legit\n/etc/exports\n* (rw)",
            'clients' => ['*'],
        ]];
        $bodyLines = array_values(array_filter(
            explode("\n", generateNfsExports($shares)),
            static fn(string $l): bool => trim($l) !== '' && $l[0] !== '#'
        ));
        $this->assertCount(1, $bodyLines,
            'Newline in comment must not inject extra export lines');
    }

    public function testNewlineInOptionNoExtraLine(): void
    {
        $shares = [[
            'name'          => 'opt-inject',
            'path'          => '/mnt/user/safe',
            'extra_options' => ["fsid=0\n/etc/exports\n* (rw,no_root_squash)"],
            'clients'       => ['*'],
        ]];
        $bodyLines = array_values(array_filter(
            explode("\n", generateNfsExports($shares)),
            static fn(string $l): bool => trim($l) !== '' && $l[0] !== '#'
        ));
        $this->assertCount(1, $bodyLines,
            'Newline in extra_options must not inject extra export lines');
    }

    // -------------------------------------------------------------------------
    // Auto-fsid assignment — exportfs requires fsid= for FUSE paths
    // -------------------------------------------------------------------------

    public function testBuildExportOptionsEmitsAssignedFsid(): void
    {
        $parts = explode(',', buildExportOptions(['fsid' => 200]));
        $this->assertContains('fsid=200', $parts);
    }

    public function testBuildExportOptionsOmitsAutoFsidWhenUserSuppliedOne(): void
    {
        $opts = buildExportOptions(['fsid' => 200, 'extra_options' => ['fsid=0']]);
        $this->assertStringNotContainsString('fsid=200', $opts,
            'User-supplied fsid must win; no duplicate fsid tokens');
        $this->assertSame(1, substr_count($opts, 'fsid='),
            'Exactly one fsid token expected');
    }

    public function testAssignFsidsStartsAtAutoBase(): void
    {
        $out = assignFsids([['name' => 'a', 'path' => '/mnt/user/a']]);
        $this->assertSame(ConfigRegistry::FSID_AUTO_BASE, $out[0]['fsid']);
    }

    public function testAssignFsidsUniqueSequential(): void
    {
        $out = assignFsids([
            ['name' => 'a'], ['name' => 'b'], ['name' => 'c'],
        ]);
        $fsids = array_column($out, 'fsid');
        $this->assertSame([200, 201, 202], $fsids);
        $this->assertSame($fsids, array_unique($fsids));
    }

    public function testAssignFsidsPreservesExistingValues(): void
    {
        $out = assignFsids([
            ['name' => 'a', 'fsid' => 205],
            ['name' => 'b'],
        ]);
        $this->assertSame(205, $out[0]['fsid'], 'Existing fsid never reassigned');
        $this->assertSame(200, $out[1]['fsid'], 'New share gets smallest free value');
    }

    public function testAssignFsidsSkipsSharesWithUserFsid(): void
    {
        $out = assignFsids([['name' => 'a', 'extra_options' => ['fsid=0']]]);
        $this->assertArrayNotHasKey('fsid', $out[0],
            'No auto value alongside a user-supplied fsid token');
    }

    public function testAssignFsidsAvoidsNumericUserFsidCollision(): void
    {
        $out = assignFsids([
            ['name' => 'a', 'extra_options' => ['fsid=200']],
            ['name' => 'b'],
        ]);
        $this->assertSame(201, $out[1]['fsid'],
            'Auto assignment must not collide with a numeric user fsid');
    }

    public function testAssignFsidsIdempotent(): void
    {
        $once  = assignFsids([['name' => 'a'], ['name' => 'b', 'fsid' => 300]]);
        $twice = assignFsids($once);
        $this->assertSame($once, $twice);
    }

    public function testGenerateNfsExportsEveryLineHasFsid(): void
    {
        $shares = [
            ['name' => 'a', 'path' => '/mnt/user/a', 'clients' => ['*']],
            ['name' => 'b', 'path' => '/mnt/user/b', 'clients' => ['10.0.0.0/8']],
        ];
        $bodyLines = array_values(array_filter(
            explode("\n", generateNfsExports($shares)),
            static fn(string $l): bool => trim($l) !== '' && $l[0] !== '#'
        ));
        $this->assertCount(2, $bodyLines);
        foreach ($bodyLines as $line) {
            $this->assertMatchesRegularExpression('/[(,]fsid=\d+[,)]/', $line,
                'Every generated export line must carry an fsid');
        }
    }

    public function testNormalizeShareCastsDigitStringFsidToInt(): void
    {
        $share = normalizeShare(['name' => 'a', 'fsid' => '204']);
        $this->assertSame(204, $share['fsid']);
    }

    public function testNormalizeShareDropsInvalidFsid(): void
    {
        foreach ([-5, 'abc', null, 3.14, ['x']] as $bad) {
            $share = normalizeShare(['name' => 'a', 'fsid' => $bad]);
            $this->assertArrayNotHasKey('fsid', $share,
                'Invalid fsid value must be dropped: ' . var_export($bad, true));
        }
    }

    public function testSaveSharesPersistsAssignedFsidRoundTrip(): void
    {
        mkdir($this->tmpBase . '/config/plugins/custom.nfs.shares', 0755, true);
        $this->assertTrue(saveShares(
            [['name' => 'rt', 'path' => '/mnt/user/rt', 'clients' => ['*']]],
            $this->tmpBase . '/config'
        ));
        $loaded = loadShares($this->tmpBase . '/config');
        $this->assertSame(ConfigRegistry::FSID_AUTO_BASE, $loaded[0]['fsid'],
            'Assigned fsid must survive the save/load round trip');
    }
}
