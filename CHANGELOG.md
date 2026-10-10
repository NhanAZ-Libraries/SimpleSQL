# Changelog

## 1.0.1 - 2026-10-10

- Keep SQL read errors, malformed SQL rows and unrecoverable YAML corruption from opening an empty session.
- Wait for SQL synchronization before exposing an offline edited YAML session.
- Serialize saves for each session and retain edits made while a save is in progress.
- Keep sessions open after failed saves and write a separate recovery snapshot if SQL fails during shutdown.
- Encode session IDs for safe YAML paths while preserving existing filenames for ordinary player names.
- Add locked PHPStan analysis, regression scripts, hosted CI and Dependabot updates.

The optional third callback to `openSession()` receives a load or synchronization error. Existing two argument calls remain valid. A recovery snapshot must be inspected and restored manually while the server is stopped. Back up the database and YAML directory before upgrading. To roll back, rebuild the consumer with the previous SimpleSQL source and restore the matching data backup if its schema or contents changed.
