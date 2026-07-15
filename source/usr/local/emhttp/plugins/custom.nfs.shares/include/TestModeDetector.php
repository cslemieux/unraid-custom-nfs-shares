<?php

declare(strict_types=1);

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace

require_once __DIR__ . '/ConfigRegistry.php';

/**
 * Centralized test-mode detection for the Custom NFS Shares plugin.
 *
 * Test mode when: PHPUNIT_TEST constant defined, OR CONFIG_BASE contains /tmp/.
 * Ported near-verbatim from SMB plugin's TestModeDetector.php;
 * only NFS-specific change: getMockScriptPaths() returns exportfs/showmount/rpcinfo.
 */
class TestModeDetector
{
    private static ?bool $isTestMode = null;
    private static ?string $harnessRoot = null;

    public static function isTestMode(): bool
    {
        if (self::$isTestMode !== null) {
            return self::$isTestMode;
        }
        if (defined('PHPUNIT_TEST')) {
            self::$isTestMode = true;
            return true;
        }
        if (defined('CONFIG_BASE')) {
            $configBase = CONFIG_BASE;
            if (
                strpos($configBase, '/tmp/') !== false
                || strpos($configBase, '/private/tmp/') !== false
            ) {
                self::$isTestMode = true;
                return true;
            }
        }
        self::$isTestMode = false;
        return false;
    }

    public static function getHarnessRoot(): string
    {
        if (self::$harnessRoot !== null) {
            return self::$harnessRoot;
        }
        if (!self::isTestMode()) {
            self::$harnessRoot = '';
            return '';
        }
        $configBase = ConfigRegistry::getConfigBase();
        if ($configBase === '') {
            self::$harnessRoot = '';
            return '';
        }
        $root2 = dirname(dirname($configBase));
        $root4 = dirname(dirname(dirname(dirname($configBase))));
        if (is_dir($root2 . '/mnt')) {
            self::$harnessRoot = $root2;
        } elseif (is_dir($root4 . '/mnt')) {
            self::$harnessRoot = $root4;
        } else {
            $candidate = dirname($configBase);
            while ($candidate !== '/' && $candidate !== '.') {
                if (is_dir($candidate . '/mnt')) {
                    self::$harnessRoot = $candidate;
                    return self::$harnessRoot;
                }
                $candidate = dirname($candidate);
            }
            self::$harnessRoot = $root2;
        }
        return self::$harnessRoot;
    }

    public static function getPathPattern(): string
    {
        return self::isTestMode() ? '#/mnt/#' : '#^/mnt/#';
    }

    public static function resolvePath(string $path): string
    {
        if (!self::isTestMode()) {
            return $path;
        }
        if (strpos($path, '/tmp/') === 0 || strpos($path, '/private/tmp/') === 0) {
            return $path;
        }
        $harnessRoot = self::getHarnessRoot();
        return $harnessRoot !== '' ? $harnessRoot . $path : $path;
    }

    public static function isValidMntPath(string $realPath): bool
    {
        if (self::isTestMode()) {
            return strpos($realPath, '/mnt/') !== false;
        }
        return strpos($realPath, '/mnt/') === 0;
    }

    public static function stripHarnessRoot(string $path): string
    {
        if (!self::isTestMode()) {
            return $path;
        }
        $harnessRoot = self::getHarnessRoot();
        if ($harnessRoot !== '' && strpos($path, $harnessRoot) === 0) {
            return substr($path, strlen($harnessRoot));
        }
        return $path;
    }

    /**
     * Mock NFS command script paths for integration tests.
 * Returns null in production (use real system commands).
 *
     * @return array{exportfs: string, showmount: string, rpcinfo: string, rcNfsd: string}|null
     */
    public static function getMockScriptPaths(): ?array
    {
        if (!self::isTestMode()) {
            return null;
        }
        $harnessRoot = self::getHarnessRoot();
        return [
            'exportfs'  => $harnessRoot . '/usr/sbin/exportfs',
            'showmount' => $harnessRoot . '/usr/sbin/showmount',
            'rpcinfo'   => $harnessRoot . '/usr/sbin/rpcinfo',
            'rcNfsd'    => $harnessRoot . '/etc/rc.d/rc.nfsd',
        ];
    }

    public static function reset(): void
    {
        self::$isTestMode = null;
        self::$harnessRoot = null;
    }
}
