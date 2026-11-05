# Offline schema fixtures

These official schema files were retrieved on November 5, 2026 for synthetic
validation. Their schema declarations, notices and import URLs are retained;
line endings are normalized to LF and trailing whitespace is removed. The
test resolves every import to this directory, and refuses unknown imports.
These are test resources, never application configuration or harvested data.

| File | Official source |
| --- | --- |
| `mods-3-8.xsd` | https://www.loc.gov/standards/mods/v3/mods-3-8.xsd |
| `oai_dc.xsd` | https://www.openarchives.org/OAI/2.0/oai_dc.xsd |
| `simpledc20021212.xsd` | https://www.dublincore.org/schemas/xmls/simpledc20021212.xsd |
| `xml.xsd` | https://www.loc.gov/mods/xml.xsd |
| `xlink.xsd` | https://www.loc.gov/standards/mods/xlink.xsd |

The XLink file is LOC's schema defining the `simpleLink` attribute group imported
by MODS. W3C's general XLink schema uses a different group and is not a compatible
substitute. The MODS schema's older XLink import URL currently works over HTTP;
the HTTPS source above supplies the same LOC schema for offline validation.
