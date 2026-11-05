# Public metadata crosswalk

The same authorized object supplies MODS 3.8 (`mods`) and unqualified Dublin
Core (`oai_dc`). MODS is the fuller description. DC retains readable values and
uses repeated elements where it cannot retain MODS structure. One object remains
one OAI record, including objects with several public representations.

The catalogue adapter supplies an explicit allowlist of descriptive fields.
The renderer does not query records, resolve permissions or enumerate metadata
elements. A value's presence in the DTO means the catalogue adapter has already
authorized it for anonymous publication. No internal notes, preservation state,
staff records or storage paths belong in that DTO.

| Authorized description | MODS 3.8 | Dublin Core |
| --- | --- | --- |
| Primary and alternate titles | `titleInfo/title`; alternate `titleInfo` has `type="alternative"` | Repeated `dc:title` |
| Catalogue and legacy identifiers | Repeated typed `identifier` | Repeated `dc:identifier` |
| People, organizations and families | Typed `name/namePart` with repeated text `role/roleTerm`; publisher also appears in `originInfo/publisher` | Creator and publisher names in their elements; other roles in `dc:contributor`, with the role retained in text |
| Dates and languages | Typed dates in `originInfo`, with original display text; optional normalized range endpoints and uncertainty; text `languageTerm` | Repeated display dates and language labels |
| Description, captions and approved notes | `abstract`; typed `note` | Description and labeled notes; actual source/coverage notes use their corresponding DC elements |
| Object type, form, dimensions, duration and file format | `typeOfResource` when a defensible mapping exists; local/refined type in `genre`; `physicalDescription/form`, `extent` and `internetMediaType` | Repeated `dc:type` and `dc:format` |
| Subjects and places | Typed `subject` children, authority code and validated authority URI where known | Subject names/text; geographic and temporal terms in `dc:coverage` |
| Collections and related records | Typed `relatedItem` with title, identifier and public URL | Repeated `dc:relation` |
| Rights and copyright | Typed `accessCondition`, including a validated rights URI where supplied | Repeated `dc:rights` |
| Repository and digital access | `location/physicalLocation`; separately typed object/representation viewer, preview and raw URLs | Object landing, representation viewer and original-file URLs in `dc:identifier` |

Do not claim that an untyped legacy date is a creation date. It remains
`dateOther`, preserving its original type when available. Normalized date
endpoints are optional additions, never substitutes for display text. Oral
history remains a genre unless the data establishes a resource type; its local
label alone does not establish whether the object is an audio recording or a
transcript.

MODS 3.8 uses `url access="object in context" usage="primary"` for the public
landing page and `access="preview"` for thumbnails. A single-file object has a
top-level `access="raw object"` URL. Several files become `relatedItem
type="constituent"` elements, each with its own title/caption, MIME type and raw
URL; none is mislabeled as the complete multi-file object. The adapter supplies
only usable public representations. Hidden paired JPEG/PDF copies remain hidden.
The adapter can translate native original/preview URL prefixes to the public
Pawtucket media alias, preserving the exact media hash and filename suffix.
The viewer, raw-file and preview URLs then share the public frontend host while
retaining their distinct metadata roles. This does not move or regenerate files.

When the adapter supplies a representation's public `context_url`, it appears
as `access="object in context"` with a viewer display label, alongside its
separately labeled raw-file URL. Human-facing object and representation viewer
links use the public Pawtucket frontend. Direct original/preview URLs remain
technical media links and may use a separately approved static-media host.
The renderer does not invent viewer routes or substitute staff editor URLs.

The renderer accepts only absolute HTTP(S) URLs without embedded credentials,
control characters or invalid UTF-8. It removes characters forbidden by XML 1.0
from text and supplies unescaped text to Providence's native XML formatter.
The formatter owns XML escaping; values must not be pre-escaped.

## Native exporter definitions

`mappings/tadl_public_oai_mods.tsv` and `mappings/tadl_public_oai_dc.tsv` define
native XML exporters. They intentionally read only the object primary key.
The plugin's narrowly scoped `hookExportRecord` replaces their placeholder tree
with the authorized DTO rendered by `Metadata::render()`. They do not contain
unrestricted related-record traversals or media templates. If the plugin is
disabled, they cannot fall back to an expansive metadata dump.

Providence's native exporter loader supports delimited text. TSV is intentional:
the parser detects CSV by the source filename's `.csv` suffix, whereas a web
upload can receive a temporary filename without that suffix. The native importer
replaces an exporter with the same code; importing these mappings is a separate
installation step and must be done only after reviewing the existing mappings.

## Validation and limits

Run `php plugins/publicOai/tests/metadata_test.php` from the source repository.
The test uses the trusted sibling Providence reference, or the explicit
`PROVIDENCE_REFERENCE_ROOT`, without loading its application settings. It
executes the actual native XML formatter and its XML helpers, validates MODS and
DC against the official vendored schemas, and checks Unicode/control characters,
XML punctuation, repeated values, roles, dates, URL safety and multi-file records.
Schema validation resolves imports locally and performs no network requests.

This proves metadata serialization with synthetic data. Live catalogue field
availability, anonymous ACLs, real media delivery and complete harvest behavior
require separate integration checks.

A richer schema cannot reconstruct data omitted from migration. Compare a
representative sample with Omeka: the historical exporter selected one catalogue
identifier, reported unmatched creator/publisher/contributor text separately,
combined Type/Format information into format notes, and did not explicitly map
DC Source/Relation into objects. Any gaps should be reviewed as metadata repair,
not silently filled with guessed values.

References: [MODS 3.8](https://www.loc.gov/standards/mods/),
[names and roles](https://www.loc.gov/standards/mods/userguide/name.html),
[dates](https://www.loc.gov/standards/mods/userguide/origininfo.html),
[digital locations](https://www.loc.gov/standards/mods/userguide/location.html),
[relationships](https://www.loc.gov/standards/mods/userguide/relateditem.html),
and [rights](https://www.loc.gov/standards/mods/userguide/accesscondition.html).
