# Custom NFS Shares - Unraid Plugin

Create and manage custom NFS exports on your Unraid server with a user-friendly web interface. Sibling plugin to [Custom SMB Shares](https://github.com/cslemieux/unraid-custom-smb-shares).

## Features

### Export Management
- **Create, Edit, Delete**: Full CRUD operations through the Unraid WebGUI
- **Enable/Disable Toggle**: Temporarily disable exports without deleting them
- **Import/Export**: Backup and restore export configurations as JSON
- **Automatic fsid handling**: Unraid user shares (`/mnt/user/...`) are FUSE-backed and require an explicit `fsid=` — the plugin assigns stable, unique fsids automatically

### Access Control
- **Per-export client lists**: hostnames, IPs, CIDR subnets, or `*`
- **Access mode**: read/write or read-only per export
- **Squash options**: `root_squash` (default), `no_root_squash`, `all_squash` with anonuid/anongid
- **Safety warnings**: UI warns on `async` (data-integrity risk) and `no_root_squash` (security risk)

### Reliability
- **Validated apply with rollback**: every change is structurally validated, applied via `exportfs -ra`, and automatically rolled back to the last known good configuration if the kernel rejects it
- **Atomic writes**: exports files are written atomically (temp + rename) — a crash can never leave a corrupt config
- **Array lifecycle integration**: exports are re-applied automatically when the array starts
- **Automatic Backups**: created before destructive operations, with configurable retention

## Requirements

- Unraid OS 6.12 or later
- NFS service enabled (Settings → NFS)

## Installation

1. Navigate to **Plugins** > **Install Plugin**
2. Paste this URL:
   ```
   https://raw.githubusercontent.com/cslemieux/unraid-custom-nfs-shares/main/custom.nfs.shares.plg
   ```
3. Click **Install**

## Usage

Navigate to **Tasks** > **NFS Shares** (or move it to Settings → User Utilities via the plugin's Settings page).

### Creating an Export

1. Click **Add Export**
2. Select a **Path** under `/mnt/` (Browse button available)
3. Enter a unique **Export Name** (UI label only — not sent over the network)
4. Add **Clients** (one per line): `192.168.1.0/24`, `10.0.0.5`, `nas-client.lan`, or `*`
5. Choose access, sync, subtree, and squash options (safe defaults: `rw,sync,no_subtree_check,root_squash`)
6. Click **Add Export**

### fsid handling

NFS requires an explicit `fsid=` for FUSE-backed paths — which includes every Unraid user share under `/mnt/user/`. The plugin automatically assigns each export a stable, unique fsid (starting at 200, clear of Unraid's native range). You can override it by adding your own `fsid=N` to the export's extra options.

## Configuration Files

| File | Location | Purpose |
|------|----------|---------|
| Export definitions | `/boot/config/plugins/custom.nfs.shares/shares.json` | JSON array of export configurations |
| Plugin settings | `/boot/config/plugins/custom.nfs.shares/settings.cfg` | Plugin enable state, backup count, menu placement |
| Persistent exports | `/boot/config/plugins/custom.nfs.shares/custom-nfs-shares.exports` | Generated exports(5) drop-in (flash copy) |
| Runtime exports | `/etc/exports.d/custom-nfs-shares.exports` | Active exports(5) drop-in |
| Backups | `/boot/config/plugins/custom.nfs.shares/backups/` | Timestamped JSON backup files |

## Troubleshooting

### Export not accessible

1. Verify the export is enabled (toggle is on)
2. Verify NFS is running (Settings → NFS)
3. Check your client is covered by the export's client list — mounts from non-listed clients are refused
4. Check `exportfs -v` on the server shows the export

### "requires fsid= for NFS export"

This should not occur — the plugin auto-assigns fsids. If you supplied a custom `fsid=` in extra options, ensure it is unique across all exports.

### Changes not taking effect

The plugin validates and applies changes immediately with automatic rollback on failure. If a save reports a reload warning, the previous working configuration is still active — check the message for the specific exportfs error.

## Development

```bash
composer install
composer check       # lint + static analysis + tests
composer test        # tests only
bash build-nfs.sh    # build the .txz package
```

Development happens in a private monorepo shared with the Custom SMB Shares plugin; this repository receives squashed releases. Issues and pull requests are welcome here.

## Changelog

### v2026.07.15
- Initial release
- Full export CRUD with validated apply + automatic rollback
- Automatic stable fsid assignment (required for /mnt/user FUSE paths)
- Client allow-lists, access/sync/subtree/squash options, anonuid/anongid
- Backups with retention, JSON import/export, settings page
- Array lifecycle integration (exports re-applied on array start)
- 350+ automated tests; verified on-device on Unraid 7.3.1 including real NFS v3/v4 client mounts

## Support

- [GitHub Issues](https://github.com/cslemieux/unraid-custom-nfs-shares/issues)
- [Unraid Forums](https://forums.unraid.net/)

## License

MIT License - See [LICENSE](LICENSE) for details
