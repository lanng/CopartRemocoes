# Task 6: Removal Request Importer

## Boundary

`RemovalRequestImporter::handle()` receives an existing
`IntegrationInboxItem` and a prepared PDF. It owns consolidation, source
agreement, form constraints, identity resolution, no-op detection, new
register creation, and inbox state updates. Task 7 update matrices remain out
of scope.

## Creation Flow

The importer first stores the complete extracted payload on the inbox item,
then validates all required fields and shared values. A new register is
created only after validation succeeds and its PDF has been uploaded. The
database transaction creates the register and marks the inbox item processed.
If the transaction fails after upload, the newly uploaded path is deleted.

The register uses `copart`, `pending`, normalized shared values, ISO dates,
decimal money values, and no tow yard or ignored request metadata. Notes keep
manual content and add at most one `Telefones Origem: ...` line. A zero FIPE
value creates the register with inbox status `alert` and the `zero_fipe`
alert instead of resolving it.

## Existing Register Flow

Identity is resolved by the normalized vehicle ID and plate under transaction
locking. A joint unique match is the existing register; missing both, partial
matches, plate ownership conflicts, and multiple matches are handled without
mutation. Matching hash plus empty typed changes becomes `no_changes`.

Any other existing-record difference becomes `pending` with
`update_required`, `proposed_changes`, and `register_id`; no candidate upload
or register update is performed in Task 6.

## Job And Dispatch

The processing job is unique by inbox item, retries three times with a 120
second timeout and `[30, 120, 300]` backoff, and prevents item overlap. It
processes `queued` and retryable `processing` items only. Terminal states are
idempotent. Domain failures become safe `pending` reasons; HTTP, storage, and
other transient failures are rethrown. `failed()` records `processing_failed`
without exposing message body or secrets.

Existing queued items are dispatched during sync recovery. A unique job
suppresses duplicate dispatches, while duplicate inbox inserts return the
already persisted item.

## Verification

Tests will cover complete creation, zero FIPE, every source/identity/constraint
failure, no-op and update-required behavior, upload compensation, job lifecycle
and retry classification, dispatch recovery/races, and a single concurrent
token refresh. Focused Microsoft Graph tests, the full suite, and Pint are run
before completion.
