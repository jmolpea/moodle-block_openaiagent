# Smart Tutor & Support AI — a course assistant for Moodle™

*Frankenstyle component: `block_openaiagent`. The technical identifier is kept
unchanged so existing installations upgrade normally; the product name is
Smart Tutor & Support AI.*

A Moodle block that adds two specialised AI agents to a course, coordinated by an
intent router:

- **Tutor** — answers subject questions using *only* the documents the teacher
  uploads as the course knowledge base. Local RAG with unit, section and page
  citations. No open-web knowledge, no filling in the gaps.
- **Platform assistant** — answers questions about live course and learner data
  (grades, progress, activities, deadlines, submissions, groups, access
  restrictions) by running Moodle tools locally, on behalf of the authenticated
  user and with that user's own permissions.
- **Clarification** — asks for precision when a message is ambiguous.

Works with **OpenAI, Anthropic (Claude), Google Gemini and DeepSeek**. Knowledge
base embeddings come from OpenAI or Gemini, or fall back to keyword search when
no embeddings key is configured.

---

## Try it: 15-day evaluation, no key required

A fresh installation opens a **15-day evaluation period automatically**. During
it the assistant is fully functional with no licence key at all — every agent,
the knowledge base, the Moodle tools and support escalation. The settings page
shows the days remaining.

When the period ends, request a site licence key at **julio@rsmax.es**.

## Requirements

- Moodle 4.5 LTS (the only version the plugin declares support for)
- PHP 8.1+ with the `openssl` and `curl` extensions
- An API key from at least one supported AI provider — **you bring your own and
  pay your provider directly**
- Working outbound email, if you use support escalation
- Cron running normally (document indexing and digest sending are scheduled tasks)

No Composer step. No build step. Install the ZIP and go.

## Installation

1. **Site administration → Plugins → Install plugins**, upload the ZIP, choose
   plugin type **Block**.
2. Complete the database upgrade when Moodle prompts you.
3. **Site administration → Plugins → Blocks → Smart Tutor & Support AI**: choose
   your AI provider and paste its API key. Save, so the model lists rebuild.
4. Add the block to a course and open **Assistant configuration**.

*Alternatively*, unzip into `blocks/` so the plugin sits at `blocks/openaiagent/`,
then visit **Site administration → Notifications**.

## Site settings

- **Licence** — site key and its status (see *Licensing* below).
- **AI provider** — OpenAI / Anthropic / Gemini / DeepSeek. Credential fields
  show and hide live; the model lists refresh on save.
- **Embeddings** — provider and model for semantic retrieval over the knowledge
  base (auto, OpenAI, Gemini, or off).
- **Models, temperature and token limits** per role (router, tutor, assistant,
  clarification).
- **Features** — guardrails, document retrieval and the number of chunks
  retrieved, context history, language policy.
- **Usage limits** per user and minute/day, **logging** and **debugging**.
- **Support escalation** — recipients, allowed domains, templates, anti-spam
  limits and digest mode.

## Per-course settings (*Assistant configuration*)

- **Enable or disable each agent.** Leave only the tutor for a subject-matter
  bot, or only the platform assistant for a first-line helpdesk. With one agent
  enabled the router is skipped. At least one must stay on.
- **Tutor knowledge base** — upload **citable** documents (the tutor may name
  them in answers) or **internal** ones (used but never cited). PDF, TXT and MD.
  Indexed on save; PDFs are chunked per page so citations can name the page.
- **Per-course prompts** — course prompt, assistant prompt, router prompt, FAQs.
- **Moodle tools** the assistant may use, grading policies, protected documents
  and default answer texts.
- **Support escalation** — inherit from the site, force on, or force off per
  course, with its own recipients and templates.

## Support escalation

When the platform assistant cannot resolve a query it offers to raise a support
request by email. **It applies to the assistant only**: the tutor route is
unaffected.

The model never sees an email address. It drafts an incident summary; the
recipient, the participant's identity and the course data are filled in by Moodle
from its own database at send time. **Nothing is sent until the participant
confirms the summary in front of them.**

When the offer appears is decided by the server, not the model: one of five
triggers must fire — the participant asks for a person, the assistant fell back
to its default answer, a tool failed, the same question repeats without progress,
or the answer itself recommends contacting support.

Escalation mail is sent from the site's noreply account with the participant in
`Reply-To`, so support replies reach them directly without breaking SPF or DMARC.
It requires working site email. To check it, **Site administration → Blocks →
Smart Tutor & Support AI → Test tools** sends a real message to each configured
address and reports the result.

### Controls

Per-participant daily caps, cooldowns between requests, a duplicate-summary
window, a per-course circuit breaker for large enrolments, allowed-domain
restriction for outbound mail, optional transcript attachment, optional copy to
the participant, a reference prefix, and a digest mode that batches a course's
requests into one email every N minutes. Every one can be set site-wide and
overridden per course with an explicit inherit / yes / no switch.

### Tracking

Headline figures live in the usage dashboard. The full list, filterable by
period, status, participant name or reference, is at **Site administration →
Blocks → Smart Tutor & Support AI → Support escalation report**.

## Knowledge base and tutor accuracy

The tutor answers only from the documents uploaded in the assistant
configuration — it does not read ordinary course resources. Each indexed chunk
carries a hierarchical breadcrumb such as
`[Section: Unit 3 › Step III › III.1 The S-curve | p. 42]`, and retrieval
prioritises the chunk whose section matches the question, so location questions
("which unit covers X?") cite the real section and page.

Under **Test tools** you can see per-course indexing status, use the **Retrieval
Inspector** to check exactly which chunks the tutor receives for a given
question, and send a test escalation email.

## Licensing

The plugin requires a **site licence key**, verified **offline** (RSA-SHA256) and
bound to the installation's `wwwroot`: a key issued for one site never validates
on another. **The plugin never contacts a vendor server, sends no telemetry and
reports no usage data.**

After the 15-day evaluation period, an invalid or missing key disables the
assistant (the block shows a notice and the orchestrator makes no AI calls). An
administrator can always reach the settings to paste a key.

Paste it at *Plugin settings → Licence → Licence key*; the status (valid,
expired, invalid, evaluation, or not configured) is shown directly below.

### Issuing keys (vendor only)

`generate_license.php` signs keys with the private key (`license_private.key`).
**Neither file is distributed**: both are excluded from the ZIP and from version
control.

```
php generate_license.php --genkeys   # Generate/rotate the RSA pair (once).
php generate_license.php             # Issue a key for a customer's URL.
```

## Building the distribution ZIP

Always use the bundled script, which excludes the private key and the generator
with a hard guard:

```
.\build_zip.ps1
```

Requires 7-Zip. Never zip the folder by hand — the private key would travel to
the customer.

> Regenerate the AMD build (`npx grunt amd --root=blocks/openaiagent` from a
> Moodle checkout, on Node 22) whenever `amd/src/chat.js` changes, or the CI
> `grunt` check fails.

## Privacy

Messages are sent to the configured AI provider to produce the replies. The
user's identity and the course are authoritative from the Moodle server, never
from the message. **Only the participant's first name is sent** — never the user
id, email address, username or ID number. The output of any Moodle tools used to
answer is also sent, which includes grades and submission states.

A complete Moodle Privacy API provider is implemented: five tables documented
field by field, both external destinations declared, and full export and erasure
support including `core_userlist_provider`. Conversations can be purged by age
with a scheduled task.

## Support

Issues: this repository's issue tracker.
Commercial and configuration questions: **julio@rsmax.es** · https://pluginia.es
Languages: English, Spanish, Portuguese.

## Licence

GNU General Public License v3 or later — see `LICENSE`.

Copyright © 2025-2026 RSMAX Consulting SL. Pluginia is a brand of RSMAX
Consulting SL.

Moodle™ is a registered trademark of Moodle Pty Ltd. This plugin is an
independent product and is not affiliated with or endorsed by Moodle Pty Ltd,
OpenAI, Anthropic, Google or DeepSeek.
