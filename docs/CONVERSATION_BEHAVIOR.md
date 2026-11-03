# Conversation behaviour

The active chat keeps the current topic separately from the raw latest question and the recent
follow-ups. An explicit topic change resets that topic. Old conversations without these fields
continue to use their existing resolved question.

Verified numbered procedures are stored as a plan with their original sources. First/next/previous/last-step
requests use this plan and a cursor rather than a new search on an ambiguous short question.
Ordinal and explanatory requests ("la quatrième étape", "explique l’étape 4") select the saved
step too. "L’étape suivante" respects the named procedure and cannot consume another plan.
Clarifications on the same topic keep the procedure available for later summaries.
Explicit step numbers (including French words) select the same plan. Out-of-range, negative or
fractional numbers leave the cursor unchanged. A step request without a plan asks for a procedure
instead of searching for an ambiguous number.
The plan has its own procedure identity (for example Sandbox versus production or refund). Naming a different
procedure in a navigation command cannot consume the previous plan. Negative/fractional numbers
inside a descriptive question do not create a navigation command.
Explicit returns to a Sandbox plan also restore compatible older snapshots stored under the payment topic.

Presentation requests reuse verified content. Tables always have a separator and data rows;
an empty generated table fails verification. Summaries never pad missing content with invented
steps. A procedural summary uses the plan rather than an unrelated one-sentence clarification.
Direct documentary answers obey the requested presentation too. When a three-step procedure is
condensed into two steps, the final actions are grouped rather than discarded; the complete
original plan remains available for subsequent numbered navigation.

The durable database state also retains the last useful answer, its sources and separately the
last described parameters. A rejected draft does not overwrite these facts. Presentation changes
are stored without replacing the technical question or the original facts. Up to ten topic
snapshots allow a return to fees, exports or credentials without combining their facts. Older
conversation records remain compatible. This memory is scoped to a conversation reference;
starting a new chat does not share its content.
Required and optional parameter answers are retained in separate bounded sets, with their own
sources. Explicit summaries select the requested set; an unqualified subsequent presentation
uses the most recently presented kind. Legacy memory that clearly contains the other kind is
retrieved again rather than relabelled. Evidence freshness includes both saved sets.
Restoring a legacy snapshot clears omitted fact fields before merging it, so another topic's
duration or JSON cannot leak into it. Updating a topic moves its snapshot to the most recently
used end before enforcing the ten-topic limit.
Database writes are attempted before updating the cache. Each write has a revision, so a newer
temporary answer takes precedence over older database context after a write failure. API replies
report `memory_status` (`durable`, `temporary`, `unavailable`) and `memory_persisted` when memory
is written or a temporary snapshot is reused. Temporary/unavailable states include a visible French
warning in the answer. Temporary cache entries expire after 120 minutes and do not guarantee
survival after a cache/process restart. The next successful write restores durable storage.
Only server-issued opaque temporary references can continue without a database row. Existing
developer-owned or closed conversations cannot be accessed through this fallback. If both writes
fail, the user is asked to repeat the full context rather than being promised durable memory.
The last verified JSON payload and its sources are retained separately too: a request for field
names can extract top-level names after an intermediate presentation without regenerating values.
Structured key/role metadata is extracted before the text length limit, so long JSON examples do
not lose their field names. Incoming notifications and outgoing acknowledgements remain separate.
If several objects are still plausible, the assistant asks which object instead of merging keys.
Role filtering also applies to a single object: an acknowledgement cannot answer an incoming
notification question. A missing role is looked up in the retrieved safe callback documentation.
Different JSON values remain distinct even when their keys match; only a request for common
field names can safely collapse identical schemas.
Documentary reuse recognizes "cette notification" and an acknowledgement to return. A requested
literal status must match the selected JSON example; the assistant never fabricates a matching
value by editing another example. Status inspection reads the verified payload and clarifies when
several values are present, without switching to the Check Status API merely because of "statut".
The closest section heading describes each JSON block; a preceding callback heading cannot
reclassify a later authentication response. Known transaction schemas also constrain source
selection, so a reporting/export notification cannot replace a payment notification. The shared
word "webhook" alone does not establish that two messages have the same contract.
Reporting has its own topic identity. An explicit request to switch between reporting and payment
overrides the previous JSON family. Reporting acknowledgements are extracted from the matching
reporting source (2xx and the documented `received` example), with no inferred payment fields.
Negated role clauses are removed before selecting a JSON role; declining application code does
not remove a positive request for notification fields.
Presentation requests for an unknown named topic must not reuse another topic's parameters.

Tables are parsed as rows before another presentation is generated: headers and separators
never become data. Negative format instructions override the presence of the format's name.
Parameter summaries group remaining fields rather than silently dropping them to meet a count.
Negated example requests never trigger a new example merely because they contain “exemple” or
“JSON”. A technical presentation request with “merci” remains a request rather than small talk.
A refusal of an application language can coexist with a positive request to reuse a documentary
JSON example. It does not exempt a separate positive request for a class or application script.

For a complete Sandbox procedure, the retrieved guide may be expanded with its other active,
verified sections from the same active knowledge revision (bounded to 25 sections). The direct
plan requires every expected section and cites it; it never fills a missing step. Mandatory KYC
and Check Status requirements remain explicit. Source injection checks apply to the expanded
sections before they enter a fact answer.

Support intent is interpreted before action. A declined support clause is removed while any
information request is preserved. Offering support returns an authorized button for the original
question; the chat does not open a ticket automatically.
Both ask routes also enforce declined support at the final JSON response boundary, including
outage messages, missing context and undocumented HTTP errors. They remove ticket invitations
and ticket tokens when support is declined, without changing the HTTP status or source list.

Simple facts (an API method/path, export retention or a key lifetime) may be extracted from
unambiguous retrieved sources. Source conflicts cause the direct answer to be withheld. The
normal source/injection boundary still applies, and the answer cites the actual supporting chunk.
Documented payment callback acknowledgements and fees GET examples are also extracted directly;
they keep dynamic echo requirements and distinguish query parameters from a JSON body. A duration
conversion is calculated locally from the previous verified value and retains its source. An
undocumented extension of an old export link is not inferred from a new export request.
Duration facts retain their value and unit; seconds, minutes, hours and days can be converted
without a new model call. Several incompatible durations cause a clarification instead of a guess.
Named reminders in a requested unit are converted before presentation. If the named topic changes,
its own stored or freshly retrieved duration is used. French thousands separators (ordinary,
nonbreaking and narrow nonbreaking spaces) are parsed as a complete number; malformed grouping
does not silently turn into a shorter duration.
Signed and scientific forms that the parser does not support are rejected as a whole. A request
for multiple units returns each conversion from the same verified fact and start event.
Direct "combien de minutes" wording and sequential connectors such as "puis en minutes" select
the requested units too. Explicitly declined trailing units are excluded.

Natural references such as "ce parcours", "cette durée" and bare required/optional-parameter
follow-ups preserve the previous subject. Explicitly named subjects still take priority. Required
and optional field answers use one matching API contract, including compatible fragments from
several excerpts. Conflicting field statuses prevent a direct merged answer. Optional markers
are recognized in the type annotation or the description; an unmarked field is not assumed
optional. An absence of listed optional fields is reported only for the requested scope.
Body, query, path and header restrictions apply before rendering the documented field set.

Parameter memory also indexes each group by its requested locations. A header-only answer cannot
overwrite a previously saved body answer. Explicit scope changes reuse the matching saved variant
or retrieve the missing contract. Legacy entries without scope metadata are rechecked against
sources for an explicit location. The same scope parser drives documentary extraction and memory
selection, including excluded locations. These variants participate in the existing evidence
freshness checks and are cleared with the rest of parameter memory when sources change.
The active location is preserved across presentations and subject snapshots, so an implicit
“both groups” continues the selected location. Missing groups are retrieved for that location;
they are not filled from a different location. “All parameters” explicitly requests all locations.

Provider responses marked `length` or `content_filter` raise a visible incomplete-response error
and do not overwrite memory. An interrupted inline code fragment also invalidates a generated
draft so a partial list cannot be treated as a complete verified answer. Announced counts of
mandatory JSON fields are checked against the corresponding role and reporting contract,
including claims in list introductions.
Required fields can be rendered as a complete set from a single matching API contract, with
optional fields excluded. The refund procedure copies the documented field set and example and
requires explicit synchronous/no-webhook evidence; “asynchrone” does not satisfy “synchrone”.

Required and optional parameter groups remain separately available in durable memory. Combined
presentations include both groups and remap citations to their combined source list. Tables have
no implicit three-row limit; concise parameter lists group all fields instead of dropping the
remaining fields. Bold-wrapped field identifiers are preserved along with plain code spans.
Explicit row limits still apply to ordinary tables. A combined two-group summary keeps both
groups intact, with one point per group.

Single-sentence combined groups honor the requested sentence format and omit procedure examples.
An exhaustive table with an explicit row limit groups the remaining fields in its final row;
ordinary limited tables may still show only the requested number of items. A scoped request for
refund fields takes priority over the full refund procedure.

Polite step navigation uses the saved cursor. Unknown or out-of-range ordinals produce a bounds
response and leave it unchanged. JSON status exclusions apply even across sentence boundaries;
no excluded or requested status is fabricated. A separate instruction requesting the answer in
minutes/hours is recognized. Export download questions retain the documented start event and
do not claim a retention reset when the excerpts do not describe one.

Composed politeness (such as “S’il te plaît, peux-tu me montrer…”) is stripped before navigation.
Unicode negative signs are rejected before normalization can erase them. Bare duration requests
such as “En heures uniquement” convert the current verified fact, preserving its subject and
canonical duration; without a duration they ask for clarification without changing saved memory.
JSON status exclusions also recognize “pas avec le statut”.

Signs are validated with the same optional number labels used for step extraction, so “étape
numéro −1” remains invalid. All recognized navigation verbs can select the last step. Parameter
scope negation removes only clauses that actually exclude a location; “sans exemple” or “sans
code” before the location does not erase the positive scope. Status exclusion verbs include
évite, exclus and ignore, and cannot be reinterpreted as a positive request for the excluded value.

Bare conversion sequences share the parser used by “Et en”: “En heures et en minutes” converts
both units from the same canonical fact. Unsupported or partly unsupported bare sequences ask
for clarification before retrieval or generation and leave saved context unchanged. Without a
prior duration, that clarification does not claim a previous fact exists.

Parameter paragraphs have no implicit three-field cap. Explicit sentence limits group remaining
fields into the last sentence rather than dropping them. Sentence rendering is shared with
combined required/optional groups; non-parameter summary behavior remains separately controlled.

Contract selection uses the same positive parameter-kind interpretation as memory selection.
Documentary contract answers carry their actual kind internally; the controller checks it before
rendering and stores that kind with the canonical group. A negated mandatory term cannot select
the mandatory renderer for an optional request. Optional refund-field requests bypass the full
refund procedure.

Before reuse, semantic parameter-memory repair removes proven contradictory group headings or
conflicting kind metadata, including aliases and inactive subject snapshots. It clears unsafe
parameter fallbacks and presentation answers and marks missing groups for documentary retrieval.
Unrelated facts, valid groups and plans are preserved. This repair is independent of documentary
freshness: unchanged sources cannot legitimize a wrong group label. Ambiguous descriptive prose
without a contradictory heading is not treated as proof of misclassification.

Query wording accepts “de requête” and “de la requête”. Spoken negative step numbers such as
“numéro moins un” are rejected without rebuilding the plan. JSON status exclusions include sauf.
Conversion validation applies both to bare sequences and explicit “Convertis cette/la durée”
requests. Complete courtesy phrases are accepted separately from unit selection, including a
comma after seulement; a partially supported sequence still asks for clarification before lookup.

Protocol verification distinguishes routing codes from credentials, tracks references across
sentences and contrast clauses, and checks the start event for retention periods. A negative
clause must not exempt a positive claim after “mais”. Inline documented API paths are not
application code. Code examples still pass their normal validation.
Capability checks distinguish consulting status from cancelling or modifying a transaction, and
outgoing API authentication from authentication of incoming callbacks. Evidence must establish
the claimed capability, rather than just contain its vocabulary elsewhere in a guide.
The direct HTTPS policy answer distinguishes a recommendation from a proven technical rejection.
An explicit contradictory HTTPS rule is reported as a source conflict. A negated obligation is
not treated as a positive binding rule. Capability polarity is attached to the action predicate:
an unrelated “ne pas interrompre” cannot authorize cancellation through a status endpoint.

Newly persisted evidence has a fingerprint of its cited chunks, content, active flags and active
knowledge revision. Before memory reuse, the fingerprint is checked. A mismatch clears the old
plan, examples and facts and asks for a complete question against current documentation. Legacy
records without a fingerprint remain compatible and acquire one on their next verified turn;
their earlier evidence does not have the same retrospective freshness guarantee.

Focused regression coverage is in `ConversationReliabilityTest`, `FollowUpDetectorTest`,
`ConversationActionsTest`, `DocumentedFactAnswerTest`, `CredentialDurationTest`, and
`MistralRawAskTest`. These checks supplement live multi-turn replays; neither alone guarantees
that every possible model generation is correct.

The expanded replay dataset, command, assertion limits and restart workflow are described in
`docs/CONVERSATION_TESTING.md`.
# Références courtes aux étapes et exemples Markdown

Les relances comme « expliques moi le 1 en détail », « détaille le 2 » ou
« explique le premier point » sélectionnent les étapes du plan vérifié en mémoire.
Elles ne déclenchent pas une recherche isolée sur le numéro. Sans plan vérifié,
le chat demande d'abord la procédure ; les numéros négatifs, décimaux et les
sélections ambiguës ne déplacent pas le curseur. Les callbacks/webhooks au pluriel
conservent leur sujet et leurs instantanés, distincts des exports de reporting.
Une demande comme « Résume cette étape » reformule uniquement l'étape sélectionnée,
sans nouvelle recherche ni génération et sans remplacer le plan mémorisé.

Le traitement des citations préserve l'indentation des blocs JSON et cURL et
place les citations après la clôture du bloc. Le rendu du navigateur corrige
aussi ces délimiteurs dans les anciennes réponses de l'historique, sans changer
les champs ni les valeurs des exemples enregistrés.
