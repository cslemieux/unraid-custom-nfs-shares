<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration tests: apply/rollback (task 7.5).
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class NfsApplyRollbackTest extends TestCase
{
    private string $tmpBase;
    private string $configBase;
    private string $exportsFile;
    private string $persistentExports;
    private string $seqFile;

    protected function setUp(): void
    {
        $this->tmpBase    = sys_get_temp_dir() . '/nfs-int-rb-' . getmypid() . '-' . uniqid();
        $this->configBase = $this->tmpBase . '/config';
        mkdir($this->tmpBase . '/mnt/user', 0755, true);
        mkdir($this->configBase . '/plugins/custom.nfs.shares', 0755, true);
        mkdir($this->tmpBase . '/etc/exports.d', 0755, true);
        mkdir($this->tmpBase . '/usr/sbin', 0755, true);
        mkdir($this->tmpBase . '/etc/rc.d', 0755, true);
        $this->seqFile           = $this->tmpBase . '/exportfs-seq.txt';
        $this->exportsFile       = $this->tmpBase . '/etc/exports.d/custom-nfs-shares.exports';
        $this->persistentExports = $this->configBase . '/plugins/custom.nfs.shares/custom-nfs-shares.exports';
        $sp = $this->seqFile;
        $stub  = '#!/bin/sh' . '
';
        $stub .= 'SEQ=' . chr(34) . $sp . chr(34) . '
';
        $stub .= 'CODE=0' . '
';
        $stub .= 'if [ -f ' . chr(34) . $sp . chr(34) . ' ]; then' . '
';
        $stub .= '    CODE=$(head -1 ' . chr(34) . $sp . chr(34) . ' 2>/dev/null || echo 0)' . '
';
        $stub .= '    tail -n +2 ' . chr(34) . $sp . chr(34) . ' > ' . chr(34) . $sp . '.tmp' . chr(34) . ' 2>/dev/null && mv ' . chr(34) . $sp . '.tmp' . chr(34) . ' ' . chr(34) . $sp . chr(34) . ' || true' . '
';
        $stub .= 'fi' . '
';
        $stub .= 'echo ' . chr(34) . 'exportfs-stub exit ' . '${CODE}' . chr(34) . '
';
        $stub .= 'exit ${CODE:-0}' . '
';
        $es = $this->tmpBase . '/usr/sbin/exportfs';
        file_put_contents($es, $stub); chmod($es, 0755);
        foreach (['showmount', 'rpcinfo'] as $cmd) {
            $p = $this->tmpBase . '/usr/sbin/' . $cmd;
            file_put_contents($p, '#!/bin/sh
exit 0
'); chmod($p, 0755);
        }
        $rc = $this->tmpBase . '/etc/rc.d/rc.nfsd';
        file_put_contents($rc, '#!/bin/sh
exit 0
'); chmod($rc, 0755);
        if (!defined('PHPUNIT_TEST')) { define('PHPUNIT_TEST', true); }
        ini_set('error_log', '/dev/null');
        require_once __DIR__ . '/../../source/usr/local/emhttp/plugins/custom.nfs.shares/include/ConfigRegistry.php';
        require_once __DIR__ . '/../../source/usr/local/emhttp/plugins/custom.nfs.shares/include/TestModeDetector.php';
        ConfigRegistry::setConfigBase($this->configBase); TestModeDetector::reset();
        require_once __DIR__ . '/../../source/usr/local/emhttp/plugins/custom.nfs.shares/include/lib.php';
    }

    protected function tearDown(): void
    {
        ConfigRegistry::reset(); TestModeDetector::reset();
        if (is_dir($this->tmpBase)) { exec('rm -rf ' . escapeshellarg($this->tmpBase)); }
    }

    private function setExportfsSequence(int ...$codes): void
    { file_put_contents($this->seqFile, implode('
', $codes) . '
'); }

    private function makeMntDir(string $name): string
    {
        $r = $this->tmpBase . '/mnt/user/' . $name;
        if (!is_dir($r)) { mkdir($r, 0755, true); }
        return '/mnt/user/' . $name;
    }

    private function buildShares(string $path): array
    { return [['name' => 'TestShare', 'path' => $path, 'clients' => ['192.168.1.0/24'], 'enabled' => true]]; }

    private function readExports(): string
    { return file_exists($this->exportsFile) ? (string)file_get_contents($this->exportsFile) : ''; }

    private function readPersistent(): string
    { return file_exists($this->persistentExports) ? (string)file_get_contents($this->persistentExports) : ''; }

    private function assertNoTmpFiles(): void
    {
        $f = array_merge(glob($this->tmpBase . '/etc/exports.d/*.tmp.*') ?: [], glob($this->tmpBase . '/config/plugins/custom.nfs.shares/*.tmp.*') ?: []);
        $this->assertEmpty($f, 'No *.tmp.* files must remain');
    }

    public function testHappyPathBothCopiesUpdated(): void
    {
        $this->setExportfsSequence(0); $p=$this->makeMntDir('happy'); $sh=$this->buildShares($p); saveShares($sh); $r=applySharesAndReload($sh);
        $this->assertTrue($r['success']); $this->assertStringContainsString($p, $this->readExports()); $this->assertStringContainsString($p, $this->readPersistent());
    }

    public function testSingleFaultRollbackRestoresLkg(): void
    {
        $p1=$this->makeMntDir('lkg'); $s1=$this->buildShares($p1); saveShares($s1); $this->setExportfsSequence(0); applySharesAndReload($s1); $lkg=$this->readExports(); $this->assertNotEmpty($lkg);
        $p2=$this->makeMntDir('new'); $s2=$this->buildShares($p2); saveShares($s2); $this->setExportfsSequence(1,0); $r=applySharesAndReload($s2);
        $this->assertFalse($r['success']); $this->assertFalse($r['escalated']); $this->assertSame($lkg, $this->readExports());
    }

    public function testNoTmpFilesAfterHappyPath(): void
    { $this->setExportfsSequence(0); $s=$this->buildShares($this->makeMntDir('at-ok')); saveShares($s); applySharesAndReload($s); $this->assertNoTmpFiles(); }
    public function testNoTmpFilesAfterSingleFault(): void
    { $s=$this->buildShares($this->makeMntDir('at-sf')); saveShares($s); $this->setExportfsSequence(0); applySharesAndReload($s); $this->setExportfsSequence(1,0); applySharesAndReload($s); $this->assertNoTmpFiles(); }
    public function testNoTmpFilesAfterDoubleFault(): void
    { $s=$this->buildShares($this->makeMntDir('at-df')); saveShares($s); $this->setExportfsSequence(0); applySharesAndReload($s); $this->setExportfsSequence(1,1); applySharesAndReload($s); $this->assertNoTmpFiles(); }

    public function testDoubleFaultEscalatesWithManualAdvice(): void
    {
        $p1=$this->makeMntDir('df'); $s1=$this->buildShares($p1); saveShares($s1); $this->setExportfsSequence(0); applySharesAndReload($s1); $lkg=$this->readExports();
        $p2=$this->makeMntDir('df-new'); $s2=$this->buildShares($p2); saveShares($s2); $this->setExportfsSequence(1,1); $r=applySharesAndReload($s2);
        $this->assertFalse($r['success']); $this->assertTrue($r['escalated']);
        $this->assertStringContainsString('exportfs-stub exit 1', $r['error']);
        $this->assertMatchesRegularExpression('/manual|exportfs.*-ra|restart/i', $r['error']);
        $this->assertSame($lkg, $this->readExports());
    }

    public function testValidateCandidateCountMismatch(): void
    {
        $c=chr(35)." h".chr(10).chr(10)."/mnt/user/a *(rw,sync,no_subtree_check,root_squash)".chr(10); $e=validateCandidateExports($c,2); $this->assertNotNull($e); $this->assertStringContainsString('count',strtolower($e));
    }
    public function testValidateCandidateNonPathLine(): void
    {
        $c=chr(35)." h".chr(10).chr(10)."badline client(rw,sync,no_subtree_check,root_squash)".chr(10); $e=validateCandidateExports($c,1); $this->assertNotNull($e,'Non-path body line must be rejected');
    }
    public function testValidateCandidateWhitespaceBeforeParen(): void
    {
        $c=chr(35)." h".chr(10).chr(10)."/mnt/user/a 192.168.1.0/24 (rw,sync,no_subtree_check,root_squash)".chr(10); $e=validateCandidateExports($c,1); $this->assertNotNull($e,'Whitespace before ( must be rejected');
    }
    public function testValidateCandidateUnbalancedParen(): void
    {
        $c=chr(35)." h".chr(10).chr(10)."/mnt/user/a 192.168.1.0/24(rw".chr(10); $e=validateCandidateExports($c,1); $this->assertNotNull($e,'Unbalanced paren must be rejected');
    }
    public function testValidateCandidateRawTab(): void
    {
        $tab=chr(9); $c=chr(35)." h".chr(10).chr(10)."/mnt/user/a".$tab."/24(rw,sync,no_subtree_check,root_squash)".chr(10); $e=validateCandidateExports($c,1); $this->assertNotNull($e,'Raw tab must be rejected');
    }
    public function testValidationFailureDoesNotWriteAnyFile(): void
    {
        $this->assertFileDoesNotExist($this->exportsFile);
        $bad=['name'=>'Bad','path'=>'','clients'=>['*'],'enabled'=>true]; $r=applySharesAndReload([$bad]); $this->assertFalse($r['success']);
        $this->assertFileDoesNotExist($this->exportsFile); $this->assertFileDoesNotExist($this->persistentExports);
    }
}