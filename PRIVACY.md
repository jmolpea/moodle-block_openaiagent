# Privacy and data protection — Smart Tutor & Support AI (`block_openaiagent`)

Everything below is verified against the source in this repository, not written
from memory: `classes/privacy/provider.php`, `classes/ai/client_base.php`,
`classes/local/support_*.php`, `classes/license/validator.php` and
`db/install.xml`.

**In one sentence:** your data stays in your Moodle; exactly two defined flows
leave the site — to the AI provider *you* choose and to *your own* support
mailbox — and there is no third flow, in particular none towards the plugin
vendor.

---

## 1. Roles under the GDPR

| Role | Who |
|---|---|
| **Controller** | Your institution |
| **Processor** | The AI provider you contract with (OpenAI, Anthropic, Google or DeepSeek), under the agreement you sign with them |
| **RSMAX Consulting SL** (trading as Pluginia) | **Neither.** We do not receive, access or process your data at any point |

That third row is not a promise; it is a property of the code. See §4.

---

## 2. What is stored inside your Moodle

Five tables hold personal data, each declared field by field in the Moodle
Privacy API provider:

| Table | What it holds |
|---|---|
| `block_openaiagent_conversations` | Conversation per user and course, with its summary |
| `block_openaiagent_messages` | Role and content of each message — the most sensitive data the plugin holds |
| `block_openaiagent_usage` | Conversation counter per user and block instance |
| `block_openaiagent_userstats` | Questions per user, course and day |
| `block_openaiagent_supportreq` | Escalated incidents: category, summary, status, reference, recipients, dates |

Seven further tables (`_agents`, `_courseconfig`, `_coursetools`, `_chunks`,
`_filetext`, `_msgstats`, `_toolstats`) hold configuration, indexed course
documents and non-identifying aggregates. They contain no personal data.

All of it lives in **your** Moodle database, on **your** infrastructure, under
**your** backup and retention policy.

---

## 3. What leaves the site

### 3.1 To the AI provider you configure

Happens each time a participant sends a message.

| Sent | Detail |
|---|---|
| The conversation messages | What the participant wrote, plus the assistant's previous replies, within the history window you configure |
| The participant's **first name only** | No surname, no email address, no username |
| The course name | — |
| The output of any Moodle tool used to answer | This **does** include grades and submission states, because that is what the model needs in order to reason about them |

**Never sent:** the user id, the email address, the username, the ID number.

The destination is the provider *you* configure, under *your* contract with them.
Custom base URLs are supported, so a compatible gateway or an EU-resident
endpoint can be used instead.

### 3.2 To your own support mailbox

Happens only when a participant escalates an incident **and confirms it**.

Sent: the participant's full name, their email address and the incident summary,
delivered through Moodle's own `email_to_user()` from the site noreply account,
with the participant in `Reply-To`.

The model never sees an email address. It drafts a summary; Moodle fills in the
recipient and the participant's identity from its own database at send time.
Nothing is sent until the participant reads the summary and presses confirm.

### 3.3 There is no third flow

See below.

---

## 4. The plugin does not phone home

- Licence enforcement is an **RSA-SHA256 signature check performed entirely
  offline**, against a public key shipped inside the plugin
  (`classes/license/validator.php`). There is no vendor endpoint to contact.
- There is **no telemetry, no usage reporting and no analytics call** of any
  kind, to us or to anyone else.
- The 15-day evaluation period is a **timestamp in your own database**. Opening
  it requires no registration, no form and no key, and sends us nothing.
- If our infrastructure disappeared tomorrow, your installation would carry on
  working exactly as it does today.

You can verify all of this: the plugin is GPL v3 and the whole source is in this
repository.

---

## 5. Data subject rights

| Right | How |
|---|---|
| **Access / portability** | `export_user_data()` exports the user's conversations, messages, statistics and incidents through Moodle's standard tools |
| **Erasure** | `delete_data_for_user()` and `delete_data_for_users()` remove everything for the user in the requested context |
| **Bulk erasure by context** | `delete_data_for_all_users_in_context()` — deleting a course removes everything associated with it |
| **Listing affected users** | `get_users_in_context()` (`core_userlist_provider`) |

**Automatic retention.** The `conversation_retention_days` setting plus the
`purge_conversations_task` scheduled task enforce a window over three things:
conversations nobody has used since the cutoff (with their messages), stored
messages older than the cutoff wherever they live — a conversation is reused for
as long as a participant keeps chatting, so the sweep has to reach inside the
live ones too — and support escalations older than the cutoff, including any
left orphaned by a deleted conversation. Escalations still awaiting the
participant's confirmation are retired by their own expiry, not by this purge.
**The default is 0 (no purge)** — how long to keep data is your decision, not
ours, but the tool is there. A value between 90 and 365 days is reasonable for
most institutions.

The purge runs on Moodle's cron. If your site's cron does not run, nothing is
deleted: check the task's last run under *Site administration → Server →
Scheduled tasks*.

---

## 6. Security

- **API keys never reach the browser.** The block's JavaScript talks only to the
  plugin's own Moodle web services; every provider call happens server-side.
- Keys are stored with `admin_setting_configpasswordunmask`, not as visible text
  on the settings page.
- **Identity is authoritative from the server.** The user id and course id are
  resolved from the Moodle session and the block instance, never from the chat
  message, so a "pretend I am the teacher" injection has no surface to act on.
- **Access restrictions are honoured.** Tools execute with the authenticated
  user's own capabilities: if a student cannot see an activity, the assistant
  cannot see it either.
- **Nine capabilities** with declared risk levels, so access control fits your
  role scheme.
- All web service entry points use typed `required_param`/`optional_param`,
  `require_login`, a capability check, and `sesskey` on state-changing actions.
- All SQL goes through Moodle's DML API with placeholders.
- All outbound HTTP goes through Moodle's `\curl` class, honouring the site's
  proxy configuration and host blocking rules, with bounded timeouts.
- Every tool call and every support request fires a Moodle event, so both are
  visible in the standard site logs.

---

## 7. Questions your DPO will ask

**Are models trained on our data?**
That depends **entirely on the contract you sign with your AI provider**, not on
the plugin. OpenAI, Anthropic and Google all offer API terms that exclude
training on customer data; verify it in your own agreement. The plugin cannot
influence this either way and we will not pretend otherwise.

**Where is the data processed geographically?**
Wherever your provider processes it. If you need EU residency, choose a provider
and configuration that guarantee it — the plugin supports all four providers and
custom base URLs for compatible gateways.

**Can we stop grades being sent to the model?**
Yes. In the per-course configuration you can disable the Moodle tools that read
grades, or disable the platform assistant entirely and keep only the document
tutor. With only the tutor active, what leaves the site is the participant's
message, their first name and the course name.

**Can we run it with no personal data leaving at all?**
Almost. With only the tutor active it is the message, the first name and the
course name. Remove the first name from the prompt — it is a setting — and what
leaves is the question text and the course context.

**What happens if the AI provider goes down?**
The assistant returns a controlled error. Moodle continues to work normally; the
block never blocks a page.

**Can we evaluate it without signing anything or giving you data?**
Yes. Installation opens a 15-day period with the complete product. No
registration, no form, no key to request from us, and no data reaches us — the
countdown lives in your database.

**What if you disappear?**
Nothing changes until your key expires, because we are not in the runtime path.
The Terms of Sale commit to a perpetual key for all active subscribers in the
event of end of life, and you hold the complete source under GPL v3.

---

## Contact

Data protection questions: **julio@rsmax.es**
RSMAX Consulting SL (trading as Pluginia), Madrid, Spain — NIF B01746064
