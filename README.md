<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Zammad bot

Posts a Zammad ticket into a Nextcloud Talk conversation when the ticket is
tagged for your team.

A Zammad trigger calls this app's webhook, the app looks the tag up in its
tag → conversation map and posts the ticket as a Talk bot. Every ticket is
announced once per tag, no matter how often the trigger fires afterwards.

```
Zammad trigger ──webhook──▶ /apps/zammad_bot/webhook ──▶ Talk conversation
                            verify signature
                            tag → conversation
                            announce once
```

## Setup

### 1. Create the Talk bot

```bash
occ talk:bot:create --no-setup -- "Zammad" "Posts Zammad tickets tagged for this team"
# Bot installed
# ID: 7
# Secret: <64 characters>

occ talk:bot:setup 7 <conversation-token> [<conversation-token> …]
```

`talk:bot:create` registers a `responseonly://` bot: it may post messages but is
never invoked itself, which is all this app needs. The secret is only printed
when you do not pass `--secret`.

The bot has to be set up in every conversation it posts to, otherwise Talk
answers `401` and the app logs that hint.

### 2. Configure the app

```bash
occ zammad_bot:configure \
    --zammad-url=https://zammad.example.com \
    --generate-webhook-secret

occ zammad_bot:configure --talk-bot-secret   # prompts, input stays hidden
```

The command prints the endpoint to paste into Zammad. Run it without options at
any time to review the configuration; secrets are never printed back.

Map each team tag to a conversation:

```bash
occ zammad_bot:tag:set team-infra   <conversation-token>
occ zammad_bot:tag:set team-support <conversation-token>
occ zammad_bot:tag:list
```

### 3. Zammad webhook

Manage → Webhook → New Webhook

| Field | Value |
|---|---|
| Endpoint | the URL printed by `occ zammad_bot:configure` |
| HMAC SHA1 Signature Token | the token from `--generate-webhook-secret` |
| SSL verification | yes |
| HTTP Basic Authentication | leave empty |
| Custom Payload | see below |

Leave HTTP Basic Authentication empty. The endpoint is public and the HMAC
signature is what authenticates it; credentials there would only be read as a
Nextcloud login attempt.

Zammad's default payload does **not** contain the ticket's tags, so the bot has
nothing to match on. Enable Custom Payload and send the tag as a **literal
string** matching this webhook's trigger condition:

```json
{
  "tag": "team-infra",
  "ticket": {
    "id": "#{ticket.id}",
    "number": "#{ticket.number}",
    "title": "#{ticket.title}",
    "state": "#{ticket.state.name}",
    "priority": "#{ticket.priority.name}",
    "group": "#{ticket.group.name}",
    "customer": "#{ticket.customer.fullname}"
  }
}
```

Create one webhook and one trigger per team, each with its own `tag` literal.
The tag in the payload and the tag in the trigger condition must match, and both
must match a mapping made with `occ zammad_bot:tag:set`.

!!! Do not use `#{ticket.tag_list}` or `#{ticket.tags}`

    The webhook payload renderer does not resolve them. Zammad sends the
    placeholder verbatim instead, which the app reports back as
    `"tags": ["#{ticket.tag_list / no such method}"]` and ignores. The tag has
    to be a literal.

Leave HTTP Basic Authentication empty. The endpoint is public and the HMAC
signature is what authenticates it; credentials there are read as a Nextcloud
login attempt, and repeated failures count against Zammad's IP in the
brute-force protection until it starts delaying and then rejecting deliveries.

The custom payload also sends far less than the default one, which includes full
group membership lists and organisation subscription and licence keys on every
ticket update.

### 4. Zammad trigger

Manage → Trigger → New Trigger

| Field | Value |
|---|---|
| Activated by | Action → **Always** (see below) |
| Conditions | Ticket → Action `is` `updated` |
| Conditions | Ticket → Tags `contains one` `team-infra` (this webhook's tag) |
| Action | Notification → Webhook → the webhook above |

### Set the activator to "Always"

Zammad's default **Selective** mode only runs a trigger when *an attribute from
the condition was updated or an article was added*. Because a tag change is not
recorded as a ticket update, Selective means the webhook effectively only fires
when somebody also writes an article — which looks exactly like "it fired once
and then stopped".

**Always** runs the trigger whenever the ticket is updated and the condition
matches. Zammad warns this fires more often, including on internal changes; that
is harmless here, because the first delivery for a ticket and tag posts and every
later one is discarded as a duplicate.

## Known limitation: tag-only changes

Zammad does not fire triggers when a tag is the only thing that changed. From
the Zammad admin documentation:

> Changing an element which doesn't require an explicit ticket update
> (e.g. setting a tag) does not necessarily lead to a trigger execution.

This is [zammad#2438](https://github.com/zammad/zammad/issues/2438), open since
2018. Adding a tag through the tag widget saves over AJAX and will not notify;
setting the tag as part of a ticket update will.

If that matters, add a Zammad **Scheduler** with the same tag condition pointing
at the same webhook. It runs periodically and catches the tag-only case. No
configuration in this app has to change: repeated deliveries are already
discarded, so the trigger and the scheduler cannot notify twice.

## Setting up several teams

The Zammad side is one webhook plus one trigger per team; the Nextcloud side is
one command per team. To generate the eight payloads, fill in the pairs and run:

```bash
while read -r tag token; do
  occ zammad_bot:tag:set "$tag" "$token"
  printf '\n=== webhook payload for %s ===\n' "$tag"
  sed "s/__TAG__/$tag/" <<'JSON'
{
  "tag": "__TAG__",
  "ticket": {
    "id": "#{ticket.id}",
    "number": "#{ticket.number}",
    "title": "#{ticket.title}",
    "state": "#{ticket.state.name}",
    "priority": "#{ticket.priority.name}",
    "group": "#{ticket.group.name}",
    "customer": "#{ticket.customer.fullname}"
  }
}
JSON
done <<'TEAMS'
team-infra      abc12345
team-support    def67890
TEAMS
```

Then paste each payload into its webhook and point a trigger at it whose `Tags
contains one` condition is that same tag. `occ zammad_bot:tag:list` shows what
Nextcloud knows about.

## Configuration reference

| Command | Purpose |
|---|---|
| `occ zammad_bot:configure` | show or change the configuration |
| `occ zammad_bot:tag:set <tag> <token>` | map a tag to a conversation |
| `occ zammad_bot:tag:remove <tag>` | remove a mapping |
| `occ zammad_bot:tag:list` | list the mappings |

`occ zammad_bot:configure` also accepts `--talk-base-url` when the server cannot
reach its own public URL, `--verify-tls=false` for a self-signed loopback, and
`--retention-days` for how long a ticket stays deduplicated (90 by default).

## Tests

```bash
composer test:unit
```

The suite runs against the Nextcloud instance the app is installed in, because
the dedupe guarantee is a database unique index.

## Troubleshooting

| Symptom | Cause |
|---|---|
| `401` from the webhook | the signature token differs between Zammad and `occ zammad_bot:configure` |
| App logs `Talk bot API returned 401` | the bot is not set up in that conversation, run `occ talk:bot:setup` |
| Nothing happens when a tag is added | read the response body Zammad recorded, see below |
| Repeated messages for one ticket | should not happen; check that the dedupe table `oc_zammad_bot_notified` exists |

## Reading the response

Every accepted delivery answers with what the app saw, and Zammad records it
against the webhook. That is the quickest way to tell what went wrong, and it
works regardless of the Nextcloud log level:

```json
{"status":"ok","sent":0,"skipped":0,"failed":0,"tags":[],"configured":["team-infra"]}
```

| Field | Meaning |
|---|---|
| `tags` | the tags the app found in the payload |
| `configured` | the tags currently mapped to a conversation |
| `sent` | messages posted |
| `skipped` | already announced for that ticket and tag |
| `failed` | Talk refused the message, see the Nextcloud log |

`"tags": []` means the payload carried no tags at all — Zammad's **default
payload does not include them**, so configure the custom payload above. If
`tags` is non-empty but nothing matched, compare it against `configured`: the
tag is simply not mapped, or is spelled differently.

The same explanation is logged at `info`, which the default Nextcloud log level
(`2`, warnings and above) suppresses. Lower it with
`occ config:system:set loglevel --value=1 --type=integer` if you want it in the
log as well.
