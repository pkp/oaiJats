```
===============================================
=== OAI JATS Plugin
=== Author: Alec Smecher <alec@smecher.bc.ca>
===============================================
```

| main                                                                                                                                         | 3.4.0                                                                                                                                                        | 3.3.0                                                                                                                                                        |
|----------------------------------------------------------------------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------|
| [![oaiJats](https://github.com/pkp/oaiJats/actions/workflows/main.yml/badge.svg)](https://github.com/pkp/oaiJats/actions/workflows/main.yml) | [![oaiJats](https://github.com/pkp/oaiJats/actions/workflows/stable-3_4_0.yml/badge.svg)](https://github.com/pkp/oaiJats/actions/workflows/stable-3_4_0.yml) | [![oaiJats](https://github.com/pkp/oaiJats/actions/workflows/stable-3_3_0.yml/badge.svg)](https://github.com/pkp/oaiJats/actions/workflows/stable-3_3_0.yml) |


## About

This plugin exposes JATS XML via the OAI-PMH interface.
Note that it DOES NOT generate JATS XML itself -- it assumes that this will
already be available. The JATS Template plugin (https://github.com/asmecher/jatsTemplate) can
be used to generate simple JATS documents as a fallback.

Once this plugin is enabled, it will look for JATS XML both in the Galleys
(for published content) and Production Ready (for unpublished content) areas.

It performs limited transformations of these static XML documents to augment
certain areas, such as DOIs, permissions, titles, and abstracts, to help keep
served data consistent with metadata in OJS. However, it is recommended that
editorial practices ensure that the two data sets remain consistent rather than
relying on the transformation built into this plugin.

## License

This software is published under the GNU GPLv3 license. See LICENSE for details.

## System Requirements

This plugin is intended to work with...
 - OJS 3.4.x

## Installation

This plugin should be available from the Plugin Gallery within OJS.

## Configuration

We recommend that the following configuration elements be set in OJS...
 - ISSN
