# Conversation regression testing

Run the complete automated suite with `php vendor/phpunit/phpunit/phpunit`.
It uses the configured dedicated testing database, never the application's live conversation
database. `ConversationIntentMatrixTest` exercises wording variants and factual capability guards;
`ConversationRegressionMatrixTest` tests HTTP orchestration, database reload, cursor bounds,
topic restoration, JSON field extraction and support declines across error paths. Existing real
draft fixtures also check that the new guards keep valid answers instead of over-filtering them.

## Live replay

The repository dataset `resources/evaluation/conversations.php` contains fifteen independent chats,
399 messages in total. `integration` reproduces the seventh audit; `presentation` exercises other
wordings and subject returns; `boundaries` tests missing plans, numeric bounds, completion and
declined support. `regression8` contains the 30-message eighth audit, including named procedure
changes, complete refund fields, declined PHP with requested JSON and days-to-hours conversion.
`regression9` adds 28 messages covering production/Sandbox isolation, bidirectional JSON roles,
named duration reminders, complete two-step procedures and restoring the Sandbox plan after
several subject changes.
`regression11` adds 33 messages covering natural follow-ups, negated JSON roles, switching between
payment and reporting contracts, complete reporting fields, acknowledgements, multiple duration
units and short required/optional-parameter requests.
`regression13` adds 32 messages covering required → optional → required summaries, ordinal step
requests, direct and sequential unit wording, documentary JSON reuse and requested status values.
Requests use only `/api/mistral-raw/ask`; the runner never calls `/api/ticket`.

`regression15` adds the 31-message fifteenth audit. `phrasing15` adds 16 messages with polite
navigation, unsupported compound ordinals, fresh combined parameter requests, sentence-level
status negation and restoration of both parameter groups after several subject changes.
The assertions check every required payment field and the explicitly optional fields agent,
product and free_info, rather than accepting a response merely because it has a table or list.

`regression17` adds the complete 35-message seventeenth audit. `phrasing17` adds 20 messages
combining Unicode minus signs, composed polite navigation, body/header/query scope changes,
exhaustive limited tables, one-sentence combined groups, bare unit requests and negative JSON
status constraints. Assertions check field completeness and excluded locations, not only shape.

`regression19` adds the complete 39-message nineteenth audit. `combinations19` adds 22 messages
combining labeled negative steps, last-step verbs, declined examples before parameter locations,
complete paragraphs and sentence limits, multiple bare conversion units, unsupported/mixed units,
status exclusions and recovery of the original documentary example.

`regression21` adds the 39-message twenty-first audit. `classification21` adds 22 messages
checking agreement between requested parameter kind, selected contract and stored group,
query articles, spoken negative steps, courteous multi-unit requests, unsupported explicit
conversions, status exceptions and complete combined tables.

Start a local server, then run the campaign:

```powershell
php artisan serve --host=127.0.0.1 --port=8765 --no-reload
php artisan assistant:conversation-replay --output=storage/app/evaluations/conversation-replay.json
```

The runner accepts only loopback server URLs. It uses the configured real retrieval and model
providers and creates test conversations in the local application database. Its 2200 ms default
delay helps respect the API's 30 requests/minute limit. Avoid simultaneous campaigns against
the same server. Each scenario has its own conversation reference; static refusals preserve it.

To verify process restart, stop at the first 12 messages:

```powershell
php artisan assistant:conversation-replay --output=storage/app/evaluations/restart-check.json --limit=12
```

Stop and restart the local server, then continue without repeating those messages:

```powershell
php artisan assistant:conversation-replay --output=storage/app/evaluations/restart-check.json --resume
```

Resume requires the same dataset hash, scenario selection and base URL. The transcript records
every response, sources, HTTP status, latency, scenario, turn and assertion failures; it is saved
after every request. A nonzero command exit code means at least one recorded assertion failed.
`--limit` intentionally produces a partial campaign: check the expected message count before
calling it complete. A resumed campaign retains earlier failures; rerun the affected scenario in
a separate output file after fixing it.

```powershell
php artisan assistant:conversation-replay --scenario=presentation --output=storage/app/evaluations/presentation-final.json
php artisan assistant:conversation-replay --scenario=regression8 --output=storage/app/evaluations/eighth-audit-final.json
php artisan assistant:conversation-replay --scenario=regression9 --output=storage/app/evaluations/ninth-audit-final.json
php artisan assistant:conversation-replay --scenario=regression11 --output=storage/app/evaluations/eleventh-audit-final.json
php artisan assistant:conversation-replay --scenario=regression13 --output=storage/app/evaluations/thirteenth-audit-final.json
```

## What these checks establish

Assertions check required/forbidden content, selected answer types, support flags, expected HTTP
statuses, raw source markers, JSON syntax and unexpected changes of conversation reference.
Explicit `numbered_points` assertions reject extra/missing numbered items. A reported temporary
or unavailable memory state fails the normal replay, even when HTTP 200 is returned; targeted
outage cases can declare the expected `memory_status` instead.
They deliberately catch refusals returned as HTTP 200 when the scenario has enough documented
facts to answer. They do not prove every factual statement: manually review long generated
answers against their supporting sources, especially new procedures and claimed API actions.
The live replay does not test browser interactions, uploads, deployment or production providers'
performance guarantees. Unit/feature coverage separately exercises sensitive input, source
injection and provider outage handling.

`EighthAuditRegressionTest` adds adversarial predicate-negation variants, mixed positive/negative
code requests, multi-object JSON roles, conflicting HTTPS rules and required/optional fields.
`EighthAuditPersistenceTest` exercises database reload, plan ownership, unit conversion, long JSON
metadata and evidence invalidation after source content changes or document deactivation.

`NinthAuditRegressionTest` covers locale number grouping, ambiguous JSON values, single-object
role mismatches, procedure ownership and complete action grouping. `NinthAuditFactsTest` tests
the actual HTTP orchestration for fresh source retrieval, role changes, named conversions and
retaining the original procedure after formatting. `NinthAuditMemoryFailureTest` simulates
database/cache write failures, stale database context, recovery, temporary anonymous references
and the isolation of closed/developer-owned conversations. These fault injections avoid filling
a disk or disabling the local database. Run them against the separate testing database; do not
run concurrent database-resetting test suites against the same database.
Failure coverage also includes a cache returning false, a cancelled database save, a corrupt cache
envelope and an unavailable logger. JSON fixtures include mixed sections and a separate reporting
contract to catch keyword-only role classification and cross-contract source selection.

`EleventhAuditRegressionTest` covers natural references, negated roles, multiple conversion units,
unsupported number forms, legacy snapshot isolation, recently used topic retention, JSON field
count claims and interrupted inline examples. `EleventhAuditConversationTest` replays payment →
reporting → payment with database reloads, short fee-parameter follow-ups and a truncated provider
response that must leave the prior durable context untouched. Client tests separately reject
`finish_reason=length`; provider interruption is a visible error, never a successful partial answer.

`ThirteenthAuditRegressionTest` covers ordinal/numeric steps, cross-procedure next-step wording,
direct duration questions, "puis" conversions, JSON status constraints, negated roles and parameter
kind selection. `ThirteenthAuditConversationTest` exercises database reload, legacy subject return,
required/optional source separation, active parameter presentation and source invalidation across
both stored sets. JSON reuse and status inspection must work without a fresh model generation.

`FifteenthAuditRegressionTest` covers description-only optional markers, compatible split
contracts with field-level citations, conflicting statuses, query/body scopes, full tables and
bold field summaries, polite and unsupported ordinal navigation, positive and negative JSON
status constraints, separate unit instructions and mixed routing/header claims.
`FifteenthAuditConversationTest` verifies durable combined groups after cache loss, repeated
formatting without retrieval/model calls, fresh combined contract retrieval, cursor preservation,
JSON reuse after reload and export retention questions without an invented reset or escalation.

`SeventeenthAuditRegressionTest` covers Unicode signs, composed politeness, all four duration
units, parameter location inclusion/exclusion, negative status wording, exhaustive table grouping,
single-sentence combined groups and procedure/example removal from parameter summaries.
`SeventeenthAuditConversationTest` verifies scope-specific durable answers, cache loss and subject
return, unknown-scope legacy memory, retrieval only for missing locations, bare conversions without
model calls, safe clarification without a duration, and scoped refund fields without a procedure.

`NineteenthAuditRegressionTest` uses matrices of signs × number labels × verbs, polite prefixes ×
last-step verbs, format declines × parameter locations, unit prefixes × separators × sequences,
and exclusion verbs. It also checks paragraph completeness and sentence grouping without losing
fields. These combinations complement the fixed-wording conversation datasets.
Navigation matrices use the imperative alone and the infinitive after Peux-tu, so grammatical
composition is tested rather than only the accepted action tokens in isolation.
`NineteenthAuditConversationTest` checks repeated multi-unit conversions after cache loss, safe
clarification for unsupported units with memory unchanged, the first request without a prior fact,
correct scopes after declined examples/code, labeled cursor bounds and JSON example preservation.

`TwentyFirstAuditRegressionTest` checks parameter polarity against the actual contract renderer,
query articles, spoken negatives, courtesy sequences, full conversion validation, status exceptions,
idempotent semantic memory repair and malformed legacy records. `TwentyFirstAuditConversationTest`
checks payment and refund requests through storage/reload/summary, existing poisoned groups,
inactive subject repair without losing duration facts, explicit and implicit follow-ups, scope
changes, safe conversion clarification, cursor preservation and documentary JSON retention.

Before a live campaign, verify that the application/database disks have free space. Put temporary
files and server logs on a volume with sufficient space. Infrastructure failure runs remain useful
evidence but must not be described as successful conversational validation. Keep failed transcripts
and write a separate output for a clean rerun after corrections.
