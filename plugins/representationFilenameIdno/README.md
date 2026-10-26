# representationFilenameIdno

Providence application plugin that derives `ca_object_representations.idno` from the uploaded media filename and fills a blank preferred label from the same filename.

Source is maintained in the `plugins/representationFilenameIdno/` folder of the shared
`providence-plugins` repository. Install this folder independently using the
existing plugin name. See the repository root README for shared checks.

## What it does

- Sets representation `idno` from `original_filename` on new uploads.
- Sets representation preferred label from `original_filename` when the label would otherwise be blank.
- Optionally strips the filename extension before generating values.
- Optionally updates `idno` when media is replaced and the stored filename changes.
- Optionally updates a blank preferred label when media is replaced and the stored filename changes.
- Preserves explicitly entered `idno` values on insert.
- Preserves manual `idno` edits on update when configured to do so.
- Ensures generated `idno` values are unique by appending a numeric suffix when needed.

## Why this exists

This plugin is aimed at ordinary Providence editor uploads, especially `Object > Media`, where teams may want:

- consistent representation identifiers based on filenames
- a human-readable representation title when no preferred label was entered
- no Providence core patches

Batch media import already has built-in filename-to-identifier options in stock Providence. This plugin is primarily for interactive editor workflows.

## Installation

Copy the plugin directory to:

- `app/plugins/representationFilenameIdno`

Create a server-local config file at:

- `app/plugins/representationFilenameIdno/conf/plugin.local.conf`

using this example as a starting point:

- `app/plugins/representationFilenameIdno/conf/plugin.local.conf.example`

Recommended pattern:

1. Leave `conf/plugin.conf` in version control with `enable = 0`
2. Enable and tune behavior only in `conf/plugin.local.conf`
3. Restart Apache or PHP-FPM if opcache is enabled

## Default behavior

With the shipped example config:

- the plugin is enabled only in `plugin.local.conf`
- `idno` is generated from the uploaded filename without the file extension
- blank preferred labels are filled from the same filename
- media replacement updates `idno` and blank labels when the filename changes
- duplicate generated `idno` values receive `-2`, `-3`, and so on

Example:

- upload `Scan 001.tif`
- generated `idno`: `Scan_001`
- generated preferred label when blank: `Scan_001`

## Configuration

`enable`

- Turns the plugin on or off.

`strip_extension`

- Removes the final filename extension before generating `idno` and preferred label values.

`preserve_existing_idno_on_insert`

- Leaves a pre-existing `idno` alone when a new representation is saved.

`sync_on_media_replace`

- Updates `idno` when an existing representation gets a new media file with a different stored filename.

`preserve_manual_idno_on_update`

- Prevents replacement-time `idno` sync if the user also changed `idno` in the same save.

`set_preferred_label_from_filename_when_blank`

- Fills the representation preferred label from filename only when Providence would otherwise leave it blank.

`update_blank_preferred_label_on_media_replace`

- Repeats that blank-label fallback when media is replaced.

`replace_whitespace_with`

- Replaces runs of whitespace with the configured character or string.

`lowercase`

- Lowercases generated `idno` values and generated label text.

`ensure_unique`

- Appends numeric suffixes to generated `idno` values if needed.

`suffix_separator`

- Separator used before uniqueness suffixes.

## Notes

- No Providence core changes are required.
- Interactive uploads in the Object > Media bundle are supported.
- Full representation edits are supported for new records and media replacement updates.
- Preferred labels are only auto-filled when blank; the plugin does not overwrite an existing nonblank label.
