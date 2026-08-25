# Task 6 Removal Request Importer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Import valid removal-request emails into new Copart registers safely, while making existing records idempotent and leaving Task 7 updates pending.

**Architecture:** Keep parsing and PDF preparation unchanged. Add a typed importer as the only domain boundary for consolidation, validation, identity locking, upload compensation, and inbox state transitions; keep the queue job responsible for orchestration and retry classification. Harden dispatch recovery and Graph token refresh at their existing boundaries.

**Tech Stack:** PHP 8.3, Laravel 12, Eloquent, queue jobs, PHPUnit 11, SQLite test database, Laravel HTTP/Storage/Queue fakes, Pint.

---

### Task 1: Importer contract and test fixtures

**Files:**
- Create: `app/Services/MicrosoftGraph/RemovalRequests/RemovalRequestImporter.php`
- Create: `tests/Feature/MicrosoftGraph/RemovalRequestImporterTest.php`

- [ ] **Step 1: Add failing creation test with complete subject/body/PDF data**

Build an `IntegrationInboxItem` with `message_type=removal_request`, parser-shaped
`extracted_data`, and a `PreparedRemovalPdf` using a temporary `%PDF-` file. Assert
the importer creates one `Register`, stores the normalized fields, appends the
legacy phone line, uploads to `registros/copart/{id}/{uuid}/CartaDeRemoção {plate}.pdf`,
and returns the inbox item as `processed`.

- [ ] **Step 2: Run the focused test and verify it fails**

Run: `php artisan test --compact tests/Feature/MicrosoftGraph/RemovalRequestImporterTest.php --filter=test_it_creates_a_complete_register`

Expected: FAIL because `RemovalRequestImporter` is not implemented.

- [ ] **Step 3: Implement the minimum typed importer skeleton**

Use `public function handle(IntegrationInboxItem $item, PreparedRemovalPdf $pdf): IntegrationInboxItem`.
Whitelist the twelve requested fields, merge `subject`, `body`, and PDF data into
`extracted_data`, normalize through the injected `RemovalRequestNormalizer`, call
`RemovalRequestPdfStorage::store()` only for a new register, create `Register` and
update the inbox inside `DB::transaction()`, and delete the newly uploaded path in
the transaction catch block. Use `App\Enums\CompanyEnum::COPART` and
`RegisterStatusEnum::PENDING` values, and retain only the requested register
attributes.

- [ ] **Step 4: Run the focused test and verify it passes**

Run: `php artisan test --compact tests/Feature/MicrosoftGraph/RemovalRequestImporterTest.php --filter=test_it_creates_a_complete_register`

Expected: PASS.

- [ ] **Step 5: Commit the importer baseline**

Run: `git add app/Services/MicrosoftGraph/RemovalRequests/RemovalRequestImporter.php tests/Feature/MicrosoftGraph/RemovalRequestImporterTest.php && git commit -m "feat(removal): add importer creation flow"`

### Task 2: Source agreement and validation failures

**Files:**
- Modify: `app/Services/MicrosoftGraph/RemovalRequests/RemovalRequestImporter.php`
- Modify: `tests/Feature/MicrosoftGraph/RemovalRequestImporterTest.php`

- [ ] **Step 1: Add data-provider tests for missing, divergent, and invalid values**

Cover missing body required fields, missing PDF required fields, and each shared
field conflict: normalized plate, vehicle ID across subject/body/PDF, insurance
across all sources, destination body/PDF, and both deadlines body/PDF. Add model
length, plate length, city length, reversed dates, invalid identifier, and invalid
money cases. Assert status `pending`, deterministic failure code, complete PDF
data in `extracted_data`, no `Register`, and no S3 object.

- [ ] **Step 2: Run the focused tests and verify they fail**

Run: `php artisan test --compact tests/Feature/MicrosoftGraph/RemovalRequestImporterTest.php`

Expected: FAIL for the new validation cases.

- [ ] **Step 3: Implement deterministic validation before mutation**

Normalize every source with `RemovalRequestNormalizer`; require body fields
`vehicle_id`, `insurance`, `destination_city`, `deadline_withdraw`,
`deadline_delivery`, `value`, `fipe_value`, `payment_code`; require PDF fields
`vehicle_model`, `vehicle_plate`, `origin_city`, `destination_city`,
`vehicle_id`, `insurance`, and both deadlines. Reject disagreements with stable
codes such as `vehicle_plate_mismatch`, `vehicle_id_mismatch`,
`insurance_mismatch`, `destination_city_mismatch`, and
`deadline_mismatch`. Validate field lengths and date order before upload. Set
`failure_reason` and `status=pending`, clear resolution fields, and save the
consolidated extracted payload without changing registers or storage.

- [ ] **Step 4: Run the focused tests and verify they pass**

Run: `php artisan test --compact tests/Feature/MicrosoftGraph/RemovalRequestImporterTest.php`

Expected: PASS.

### Task 3: Identity locking and existing-register decisions

**Files:**
- Modify: `app/Services/MicrosoftGraph/RemovalRequests/RemovalRequestImporter.php`
- Modify: `tests/Feature/MicrosoftGraph/RemovalRequestImporterTest.php`

- [ ] **Step 1: Add identity/no-op/update tests**

Test no ID and no plate creates a new register, joint unique ID/plate resolves
an existing register, partial identity and ambiguous matches become
`identity_conflict` with no mutation, and a plate belonging to another ID is
also a conflict. Test equal hash with empty typed changes yields `no_changes`
and no upload. Test null or different current hash yields `pending` with
`update_required`, `proposed_changes`, and `register_id`, without upload/update.

- [ ] **Step 2: Run tests and verify the new cases fail**

Run: `php artisan test --compact tests/Feature/MicrosoftGraph/RemovalRequestImporterTest.php`

Expected: FAIL in identity and existing-record cases.

- [ ] **Step 3: Implement locked identity resolution and typed proposed changes**

Inside the transaction query ID and plate matches with `lockForUpdate()`,
deduplicate by register ID, and accept only zero matches or exactly one joint
match. Compare the whitelist with type-specific normalization. Store
`current`/`proposed` pairs in `proposed_changes`; do not upload for existing
records. Use `no_changes` only when `pdf_sha256` equals the prepared hash and
the changes map is empty.

- [ ] **Step 4: Run the importer test file**

Run: `php artisan test --compact tests/Feature/MicrosoftGraph/RemovalRequestImporterTest.php`

Expected: PASS.

### Task 4: Notes, FIPE alert, and compensation

**Files:**
- Modify: `app/Services/MicrosoftGraph/RemovalRequests/RemovalRequestImporter.php`
- Modify: `tests/Feature/MicrosoftGraph/RemovalRequestImporterTest.php`

- [ ] **Step 1: Add tests for notes and zero FIPE**

Assert manual notes are preserved, the exact phone line is added once in
extracted order, one phone has no separator, and repeated imports do not
duplicate an equivalent line. Assert FIPE `0.00` creates the register but leaves
the inbox `alert`, `alerts=['zero_fipe']`, and `resolved_at=null`.

- [ ] **Step 2: Add a database-failure compensation test**

Mock `Register` creation or force a transaction failure after `store()`;
assert the storage delete receives the new path and no register/inbox partial
state remains.

- [ ] **Step 3: Implement notes/alert/compensation behavior**

Use line-based note matching that normalizes whitespace only for equivalence,
never overwrite existing notes, and calculate final status from normalized FIPE.
Track the uploaded path locally and delete it only if the transaction fails.

- [ ] **Step 4: Run focused importer tests**

Run: `php artisan test --compact tests/Feature/MicrosoftGraph/RemovalRequestImporterTest.php`

Expected: PASS.

### Task 5: Queue job orchestration

**Files:**
- Modify: `app/Jobs/ProcessRemovalRequestEmail.php`
- Modify: `tests/Feature/MicrosoftGraph/ProcessRemovalRequestEmailTest.php`

- [ ] **Step 1: Add job lifecycle and option tests**

Assert `ShouldBeUnique`, `uniqueId()` equals item ID, `uniqueFor` is suitable for
the retry window, `tries=3`, `timeout=120`, `backoff=[30,120,300]`, and a
`WithoutOverlapping` middleware key includes the item ID. Test active Graph
connection lookup, `processing` transition, preparer arguments, importer call,
temporary cleanup, terminal idempotency, domain failure to safe pending, HTTP/
S3 exception rethrow, missing connection handling, and `failed()`.

- [ ] **Step 2: Run the job tests and verify they fail**

Run: `php artisan test --compact tests/Feature/MicrosoftGraph/ProcessRemovalRequestEmailTest.php`

Expected: FAIL because the job is currently a skeleton.

- [ ] **Step 3: Implement the job**

Inject services through `handle()`, lock/reload the inbox item, process only
`queued` or retryable technical `processing`, prepare with `external_id` and
normalized plate, call the importer, and unlink `temporaryPath` in `finally`.
Catch `DomainException` as a controlled pending state; leave HTTP/storage and
other transient exceptions unhandled for queue retry. In `failed(Throwable)`
write only `processing_failed` and a safe generic message.

- [ ] **Step 4: Run job tests**

Run: `php artisan test --compact tests/Feature/MicrosoftGraph/ProcessRemovalRequestEmailTest.php`

Expected: PASS.

### Task 6: Dispatch recovery and duplicate insert race

**Files:**
- Modify: `app/Services/MicrosoftGraph/RemovalRequests/QueueRemovalRequestEmail.php`
- Modify: `tests/Feature/MicrosoftGraph/RemovalRequestMessageRouterTest.php`

- [ ] **Step 1: Add recovery and duplicate-race tests**

With Queue fake, call the router twice for the same queued item and assert one
job. Simulate an existing queued item whose after-commit dispatch was lost,
then route the message again and assert a recovery dispatch. Simulate a unique
constraint `QueryException` during creation and assert the existing item is
reloaded and returned without aborting sync.

- [ ] **Step 2: Run router tests and verify failure**

Run: `php artisan test --compact tests/Feature/MicrosoftGraph/RemovalRequestMessageRouterTest.php`

Expected: FAIL for recovery/race behavior.

- [ ] **Step 3: Implement safe dispatch recovery**

Use the job's real uniqueness contract for suppression, dispatch existing
queued/processing-retryable items after commit, and catch only duplicate-key
`QueryException` around insertion before reloading by source/external ID.
Never dispatch from a transaction that later rolls back.

- [ ] **Step 4: Run router and sync tests**

Run: `php artisan test --compact tests/Feature/MicrosoftGraph/RemovalRequestMessageRouterTest.php tests/Feature/MicrosoftGraph/SyncChecklistEmailsTest.php`

Expected: PASS.

### Task 7: Concurrent Graph token refresh

**Files:**
- Modify: `app/Services/MicrosoftGraph/MicrosoftGraphClient.php`
- Modify: `tests/Feature/MicrosoftGraph/MicrosoftGraphClientTest.php`

- [ ] **Step 1: Add stale-model refresh test**

Create two stale `MicrosoftGraphConnection` model instances with expired
tokens. Fake the token endpoint and Graph endpoint, invoke the client
sequentially with both stale instances, and assert the token endpoint is called
once and both calls use the persisted refreshed token.

- [ ] **Step 2: Run the focused Graph test and verify failure**

Run: `php artisan test --compact tests/Feature/MicrosoftGraph/MicrosoftGraphClientTest.php --filter=test_it_refreshes_an_expired_token_only_once_for_stale_models`

Expected: FAIL with two refresh requests.

- [ ] **Step 3: Implement transaction/lock/reload/revalidation**

Wrap refresh in `DB::transaction()`, reload the connection with
`lockForUpdate()`, return the reloaded non-expired access token, and only then
call the token endpoint. Persist the endpoint response on the locked model and
return its token.

- [ ] **Step 4: Run Graph tests**

Run: `php artisan test --compact tests/Feature/MicrosoftGraph/MicrosoftGraphClientTest.php`

Expected: PASS.

### Task 8: Formatting, full verification, and delivery commit

**Files:** all Task6 implementation and test files.

- [ ] **Step 1: Run focused Microsoft Graph tests**

Run: `php artisan test --compact tests/Feature/MicrosoftGraph/RemovalRequestImporterTest.php tests/Feature/MicrosoftGraph/ProcessRemovalRequestEmailTest.php tests/Feature/MicrosoftGraph/MicrosoftGraphClientTest.php tests/Feature/MicrosoftGraph/RemovalRequestMessageRouterTest.php`

Expected: PASS.

- [ ] **Step 2: Run Pint on dirty files**

Run: `vendor/bin/pint --dirty --format agent`

Expected: formatter completes without errors.

- [ ] **Step 3: Run the complete suite**

Run: `php artisan test --compact`

Expected: PASS.

- [ ] **Step 4: Inspect the final diff and status**

Run: `git status --short && git diff --check && git diff --stat`

Expected: only Task6 files are changed and there are no whitespace errors.

- [ ] **Step 5: Create the implementation commit**

Run: `git add app/Jobs/ProcessRemovalRequestEmail.php app/Services/MicrosoftGraph/MicrosoftGraphClient.php app/Services/MicrosoftGraph/RemovalRequests/RemovalRequestImporter.php app/Services/MicrosoftGraph/RemovalRequests/QueueRemovalRequestEmail.php tests/Feature/MicrosoftGraph/RemovalRequestImporterTest.php tests/Feature/MicrosoftGraph/ProcessRemovalRequestEmailTest.php tests/Feature/MicrosoftGraph/MicrosoftGraphClientTest.php tests/Feature/MicrosoftGraph/RemovalRequestMessageRouterTest.php && git commit -m "feat(registers): import new removal requests"`
