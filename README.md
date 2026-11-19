# Dolibarr MCP

A Dolibarr 24 module that serves a [Model Context Protocol](https://modelcontextprotocol.io) (MCP) endpoint at
`custom/dolimcp/mcp.php`. An MCP client such as Claude Code can then read and change your Dolibarr data
(third parties, proposals, invoices, projects, time entries, setup) with the rights of a Dolibarr user.

The module sits on top of the Dolibarr REST API: it needs the API module enabled, and it calls the API on
the same server with the key of the caller. It has no rights of its own.

Licence: GPL-3.0-or-later.

## Requirements

- Tested with Dolibarr 24.0.2; requires Dolibarr 24.0 or later, with the REST API module enabled.
- PHP 8.1 or later.

## Installation

From a release:

1. Download `module_dolimcp-<version>.zip` from the
   [releases page](https://github.com/Quorae/dolibarr-mcp/releases).
2. In Dolibarr, go to Home > Setup > Modules/Applications > Deploy/install external app/module and upload the archive
   (or unzip it into `htdocs/custom/`).

From the sources: copy the `dolimcp/` directory into `htdocs/custom/` of your Dolibarr.

## Activation

Enable "Dolibarr MCP" in Home > Setup > Modules/Applications (the API module is enabled with it).
The module's setup page (the gear icon on its line) shows the endpoint address, the command to give to Claude Code,
the optional internal API address and the journal.

## Configuring Claude Code

Create an API key on a Dolibarr user (user card > API key), then run:

```bash
claude mcp add --transport http dolibarr https://dolibarr.example.com/custom/dolimcp/mcp.php \
  --header "DOLAPIKEY: <your API key>"
```

The endpoint reads the key from the `DOLAPIKEY` header or from `Authorization: Bearer <your API key>`, like the
Dolibarr REST API. The protocol versions `2026-07-28`, `2025-03-26`, `2025-06-18` and `2025-11-25` are supported.

If Dolibarr cannot reach itself on `http://127.0.0.1`, set the internal API URL (constant `DOLIMCP_API_URL`) on the setup page,
for example `https://dolibarr.example.com/api/index.php`.

## Tools

| Family | Tools |
| --- | --- |
| Session | `whoami` |
| Objects (third parties, contacts, products, proposals, invoices, projects, tasks, events, bank accounts, users) | `object_search`, `object_get`, `object_create`, `object_update`, `object_delete` |
| Documents | `document_action` (validate, close, pay, convert a proposal to an invoice...), `payment_create`, `document_pdf`, `document_send` (email) |
| Files | `file_list`, `file_get`, `file_attach` |
| Time tracking and invoicing | `time_invoice`, `project_list`, `time_entry_list`, `time_entry_get`, `time_entry_create`, `time_entry_update`, `time_entry_delete` |
| Raw REST API | `api_endpoints`, `api_request` |
| Setup (administrators) | `company_get`, `company_set`, `company_logo_set`, `const_get`, `const_set`, `module_list`, `module_set`, `dictionary_list`, `dictionary_set`, `extrafield_list`, `extrafield_set`, `extrafield_delete`, `document_setup_get`, `document_setup_set` |
| Access (administrators) | `user_rights_get`, `user_rights_set`, `cron_list`, `cron_set` |

Each tool describes its parameters to the client.

## Rights

Every call runs as the owner of the API key and applies that user's Dolibarr rights: the objects and
documents tools use the rights of the object (for example `facture` for invoices, `propal` for proposals), the time
tools use the `projet` rights (`lire`, `time`, and `all` to act on other users), and the setup and access tools
are reserved to administrators. Calls the user may not make fail with an error; the module never raises rights.

## No confirmation

Except for the two refusals below, every action runs on the first call: the module does not ask for a
confirmation. Whether to ask is up to the MCP client and the person using it. Give an API key to a user whose
rights match what you accept to be done in your name.

The module refuses to disable itself or the API module through the MCP. Do that in Dolibarr's interface.

## Journal

Each call that changes something is recorded in the `llx_dolimcp_journal` table: date, user, tool, parameters and outcome.
Secrets in the parameters (keys, passwords, tokens) are masked before they are stored. The setup page lists the last 100 calls.
Read-only calls, including `GET` through `api_request`, are not recorded.

## Development

Tests run in Docker; nothing else is needed.

```bash
bin/test unit                                   # unit tests
bin/test integration                            # integration tests, against a Dolibarr 24 in Docker Compose
bin/test integration tests/integration/ModuleTest.php   # one file
bin/test down                                   # remove the test stack and its volumes
bin/package 0.1.0                               # build dist/module_dolimcp-0.1.0.zip
```

`bin/package` refuses a version that differs from the one in `dolimcp/core/modules/modDoliMcp.class.php`.
Pushing a tag `vX.Y.Z` builds the archive and publishes a GitHub release.
