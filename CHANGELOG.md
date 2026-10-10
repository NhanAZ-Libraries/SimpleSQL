# Changelog

## 1.1.0 - 2026-10-11

- Add `saveSessionPair()` to persist two active sessions in one SQLite or MySQL statement before applying either in-memory snapshot.
- Verify the actual MySQL table uses InnoDB and reject paired saves on unsupported engines.
- Reserve both sessions during a paired write so concurrent mutations cannot silently replace a transfer.
- Test successful writes and injected second-row failures on SQLite and MySQL InnoDB in CI.

The SQL table schema is unchanged. Consumers must include the updated SQL resource files with the matching library source. Paired saves return failure without changing either session if SQL rejects the statement. Build and runtime compatibility of a consumer using this release must be verified before publishing it; retain the previous release for rollback.

## 1.0.1 - 2026-10-10

- Keep SQL read errors, malformed SQL rows and unrecoverable YAML corruption from opening an empty session.
- Wait for SQL synchronization before exposing an offline edited YAML session.
- Serialize saves for each session and retain edits made while a save is in progress.
- Keep sessions open after failed saves and write a separate recovery snapshot if SQL fails during shutdown.
- Encode session IDs for safe YAML paths while preserving existing filenames for ordinary player names.
- Add locked PHPStan analysis, regression scripts, hosted CI and Dependabot updates.

The optional third callback to `openSession()` receives a load or synchronization error. Existing two argument calls remain valid. A recovery snapshot must be inspected and restored manually while the server is stopped. Back up the database and YAML directory before upgrading. To roll back, rebuild the consumer with the previous SimpleSQL source and restore the matching data backup if its schema or contents changed.
