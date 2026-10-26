# Reading the collection scope permissions

The collection scope plugin maintains these groups automatically. They let
Providence keep each person's collections and objects inside their assigned
trees. **Routine cataloguing does not require editing these permissions.**

## What you see when you open Access

Opening an object's or collection's **Access** screen shows a summary first:

- **Global access** says all groups and users have “no access” unless an exception
  grants it. This is the starting rule, before the exceptions below are applied.
- **Exceptions** lists the collection scope groups that can access this record.
  These group lines are the expected way that staff receive access.
- **Edit Global Access** and **Edit Exceptions** expand the editing controls.
  You can read the current permissions without opening either editor.
- The inheritance dropdowns remain visible below the summaries. Both should
  remain at their “Do not inherit…” settings, as explained below.

For a record with `gcrm_all` and one root group listed under Exceptions, read the
screen as: **“Unrestricted staff and staff assigned to this tree can access the
record.”** The “no access” sentence on its own is not the final access decision.

## What the group names mean

| Group code | Who it represents |
| --- | --- |
| `gcrm_all` | Backend users who remain unrestricted by the collection scope rules |
| `gcrm_r_<collection ID>` | Restricted users assigned to that collection root |

For example, `gcrm_r_101` means people assigned to collection **101**, including
its descendants. The number is a collection ID, not a user ID or a group ID.
The `Collection scope:` prefix is just the group's display name.

`gcrm_all` does **not** mean everyone. Restricted users do not join this group.
It preserves access for staff to whom the scope rules do not apply. Administrators
also retain Providence's administrator bypass, regardless of their root mapping.
These private groups do not let public website visitors enroll themselves.

Only roots currently used by restricted users need a root group. A root assigned
only to administrators or users outside `required_role` might have no generated
group. That does not indicate a missing permission.

## Why the screen says “Everyone: no access”

This is the expected default: a person needs an appropriate group exception to
access the record. It does not mean the record is inaccessible to its cataloguers.

An example record might show:

| Setting | Meaning |
| --- | --- |
| Everyone: no access | No blanket record access |
| `gcrm_all`: can edit + delete | Unrestricted staff retain access |
| `gcrm_r_101`: can edit + delete | Staff assigned to root 101 can access this record |

Membership in **any one** of the listed groups is enough at the record ACL layer.
A person does not need to belong to both groups. A restricted user assigned only
to an unrelated root receives no access through these exceptions.

The “can edit + delete” setting is the record-level allowance. Normal role,
type and bundle permissions still decide which actions a person may perform.
A person with a read-only role does not gain editing powers from this group.

A shared object can have several root group exceptions. Someone assigned to any
of those trees can see it, as intended.

## Why both inheritance controls are off

Leave these settings at their managed defaults:

- **Do not inherit access settings from related collections**
- **Do not inherit item-level access control settings from parents**

The plugin already calculates membership from collection trees and object
ancestors and writes the appropriate group exceptions directly. Native ACL
inheritance stays off to keep a second inheritance mechanism from adding
competing permissions. These dropdowns being off does not stop descendants
from belonging to their assigned tree.

## Where to make changes

Change a person's assigned roots in the installation's
`app/plugins/groupCollectionRootMap/conf/plugin.local.conf`, using
`user_root_collection_map`. The numbers on the left are user IDs; the lists on
the right contain collection root IDs. Descriptive comments help identify them;
the numeric values determine the assignments.

Mapping and role changes need an ACL synchronization. Follow the
[synchronization instructions](../README.md#review-and-activate-native-acls),
starting with a dry run. The command requires a private backup directory when
applying changes. Normal editor changes to collection placement and relationships
are reconciled by the plugin.

Leave the generated memberships, group codes, per-record exceptions and
inheritance controls to the plugin. Keep generated groups private and free of
application roles. Their `gcrm:v1` descriptions and ACL notes identify managed
entries; replacing those markers with explanatory text breaks that ownership
check. Use this guide or configuration comments for explanations instead.

Manual permission changes can either be corrected by synchronization or cause a
conflict that blocks normal access until an administrator resolves it. If an ACL
was changed accidentally, start with the synchronization dry run and inspect its
report before making further changes.

## What stays global

Entities, places, occurrences/events and other authority records stay shared.
Representations remain outside this plugin's record ACL segmentation too.
The record's public `access` field and Pawtucket's publication rules are separate
from this staff-facing permissions panel. Changing these groups is not how to
publish or unpublish material on the public site.
