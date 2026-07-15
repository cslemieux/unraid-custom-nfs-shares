<?php

declare(strict_types=1);

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace

/**
 * Configuration Registry for the Custom NFS Shares plugin.
 *
 * Provides a testable way to manage config paths and validation patterns.
 * In production falls back to CONFIG_BASE or '/boot/config'.
 * In tests the config base can be overridden per-test via setConfigBase().
 *
 * Why this exists: PHP constants cannot be redefined; PHPUnit runs all
 * tests in one process; each test needs an isolated config directory.
 */
class ConfigRegistry
{
    // -----------------------------------------------------------------------
    // Validation patterns
    // -----------------------------------------------------------------------

    /**
     * Forbidden-character DENYLIST for share label (name) validation.
     *
     * preg_match() returning truthy => name IS INVALID.
     * Mirrors SMB plugin post-v2026.04.01 semantics. Forbidden: leading/
     * trailing whitespace, control chars (0x00-0x1F, 0x7F), square brackets,
     * double-quote, path separators (/ \), colon, semicolon, pipe, angle
     * brackets, comma, question mark, asterisk, equals sign.
     *
     * Design gap #8 confirmation: IS a denylist; truthy match = INVALID.
     *
     * @see AC-NFS-07.4
     */
    public const SHARE_NAME_PATTERN = '/^\s+|\s+$|[\x00-\x1F\x7F\[\]"\/\\\\:;|<>,\?\*=]/u';

    /**
     * Client specification pattern (per exports(5)):
     *  - Wildcard:            *
     *  - Netgroup:            @name  (e.g. @trusted)
     *  - Hostname/wildcard:   host.example.com, *.example.com
     *  - IPv4 address:        192.168.1.100
     *  - IPv4 CIDR:           192.168.1.0/24
     *  - IPv4 + subnet mask:  192.168.0.0/255.255.255.0
     *
     * @see AC-NFS-07.1, AC-NFS-07.3
     */
    public const CLIENT_PATTERN =
        '/^(@[a-zA-Z0-9_.-]+|\*|[a-zA-Z0-9*?._-]+(\/(\d{1,3}|\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}))?)$/';

    /**
     * Export option token pattern. Matches safe NFS option tokens such as:
     * ro, rw, sync, no_subtree_check, root_squash, fsid=0, sec=krb5,
     * anonuid=65534, crossmnt. Token must start with letter/underscore.
     *
     * @see AC-NFS-08.1
     */
    public const OPTION_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*(?:=[a-zA-Z0-9_.:-]+)?$/';

    /**
     * All export paths must reside under this prefix (AC-NFS-09.1).
     */
    public const PATH_PREFIX = '/mnt/';

    /**
     * Base of the auto-assigned fsid range. exportfs REQUIRES an explicit
     * fsid= for FUSE-backed paths (/mnt/user is shfs — no stable device ID),
     * and every /mnt/user export is rejected without one. Unraid's native
     * user-share exports use fsid=100+N; starting at 200 keeps the plugin's
     * managed range clear of natives (Unraid installs do not approach 100
     * user shares in practice). fsid=0 (NFSv4 pseudo-root) is never
     * auto-assigned. Found during on-device verification 2026-07-15.
     */
    public const FSID_AUTO_BASE = 200;

    /**
     * Backup filename pattern — prevents path-traversal via filename.
     * Format: shares-YYYYMMDD-HHmmss.json
     */
    public const BACKUP_FILENAME_PATTERN = '/^shares-\d{8}-\d{6}\.json$/';

    /**
     * Runtime NFS exports drop-in location.
     * File ends in .exports so exportfs reads it from /etc/exports.d/.
     *
     * @see AC-NFS-03.1
     */
    public const NFS_EXPORTS_DROP_IN = '/etc/exports.d/custom-nfs-shares.exports';

    // -----------------------------------------------------------------------
    // Internal state
    // -----------------------------------------------------------------------

    private static ?string $configBase = null;

    // -----------------------------------------------------------------------
    // Config-base accessors
    // -----------------------------------------------------------------------

    public static function getConfigBase(): string
    {
        if (self::$configBase !== null) {
            return self::$configBase;
        }
        if (defined('CONFIG_BASE')) {
            return CONFIG_BASE;
        }
        return '/boot/config';
    }

    public static function setConfigBase(string $path): void
    {
        self::$configBase = $path;
    }

    public static function reset(): void
    {
        self::$configBase = null;
    }

    public static function isOverridden(): bool
    {
        return self::$configBase !== null;
    }

    // -----------------------------------------------------------------------
    // Persistent config paths (flash / /boot/config)
    // -----------------------------------------------------------------------

    public static function getPluginConfigDir(): string
    {
        return self::getConfigBase() . '/plugins/custom.nfs.shares';
    }

    public static function getSharesFilePath(): string
    {
        return self::getPluginConfigDir() . '/shares.json';
    }

    public static function getSettingsFilePath(): string
    {
        return self::getPluginConfigDir() . '/settings.cfg';
    }

    /**
     * Persistent copy of the exports drop-in (on flash).
     * Both persistent and runtime copies contain identical content (AC-NFS-03.2).
     */
    public static function getPersistentExportsPath(): string
    {
        return self::getPluginConfigDir() . '/custom-nfs-shares.exports';
    }

    public static function getBackupsDir(): string
    {
        return self::getPluginConfigDir() . '/backups';
    }

    // -----------------------------------------------------------------------
    // Runtime path (/etc/exports.d/ -- chroot-prefixed in tests)
    // -----------------------------------------------------------------------

    /**
     * Runtime NFS exports drop-in path.
     *
     * Production: /etc/exports.d/custom-nfs-shares.exports
     * Test mode:  <harnessRoot>/etc/exports.d/custom-nfs-shares.exports
     *
     * NOTE: TestModeDetector must already be loaded when this is called.
     * lib.php ensures this by requiring TestModeDetector.php first.
     */
    public static function getExportsDropInPath(): string
    {
        return self::withHarnessPrefix(self::NFS_EXPORTS_DROP_IN);
    }

    /**
     * Prepend the test harness root to an absolute path in test mode.
     * In production returns the path unchanged.
     *
     * @param string $absolute Absolute path starting with /
     * @return string Path, optionally prefixed with harnessRoot
     */
    private static function withHarnessPrefix(string $absolute): string
    {
        if (!TestModeDetector::isTestMode()) {
            return $absolute;
        }
        $harnessRoot = TestModeDetector::getHarnessRoot();
        return $harnessRoot !== '' ? $harnessRoot . $absolute : $absolute;
    }
}
