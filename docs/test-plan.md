# Test plan — Claude Cowork writing into our CMS (OAuth version)

**Who this is for:** anyone, technical or not. No coding required for most of it.

**Which version this is:** the branch where the connector signs in with **OAuth**. If you
were handed a plan that tells you to paste a token into a header field, that is the older
version and does not apply here — check with a developer which branch you are testing.

**What you are testing:** Cowork can write articles and pages into our CMS *as drafts*.
Your job is to check that it works, and — more importantly — that it **cannot** do the
things it must never do.

**Time needed:** about 50 minutes, plus a one-hour gap before the final test.

**How to read each test:** every test has numbered **Steps**, then **What you should
see**, then **✅ PASS if** and **❌ FAIL if**. Follow the steps in order. Don't skip ahead
— several tests depend on the draft created in Test 2.1.

---

## Before you start

### Three words you need

- **Draft** — content saved in the CMS but **not visible to the public**. Like an unsent
  email. Someone reviews it and presses Publish before the world sees it.
- **Publish** — making a draft live on the real website.
- **Approve** — the one-time step where a CMS admin signs in and grants Cowork access.
  This replaces the password-style token the old version used.

**The single most important idea:** Cowork can create drafts, and *nothing else*. It
cannot publish. It cannot delete. If any test in Part 3 fails, stop and tell a developer
immediately — that is the one category of problem that actually matters.

### Someone technical must do this first

You cannot start until a developer has:

1. Deployed this branch to a server **reachable from the public internet** (see "Testing
   before the real site is hosted" below if it isn't hosted yet)
2. Run `php artisan passport:keys` on that server
3. Run `php artisan mcp:doctor` and confirmed it reports **no errors**
4. Run `php artisan mcp:client-create "Claude Cowork"` and given you **three** values

Write those three values down before you start. You will need all of them in Test 1.1:

| What | Looks like |
|---|---|
| **URL** | `https://our-domain.com/mcp/twill` |
| **Client ID** | `019fdb5e-9138-7000-811c-027daac08659` |
| **Client Secret** | a long random string |

Treat the client secret like a password — password manager, not email or chat.

### What changed since the last plan

The old plan was blocked because Cowork's connector form has nowhere to paste a token.
This version fixes that by using OAuth, which the form *does* support.

| Then | Now |
|---|---|
| Paste a token as a header — impossible, no such field | Paste a client ID and secret into Advanced settings |
| No human step | A CMS admin signs in and approves once, in the browser |
| Access cut off by deleting a token | Access cut off by revoking the connector |

### Testing before the real site is hosted

If the site isn't hosted yet, you do **not** have to wait. A developer can put it on a
**temporary internet address** that lasts a few hours. Ask them.

If that's how you're testing:

- **The address will look strange** — something like `https://a1b2-c3d4.ngrok-free.app`.
  That's correct, not a mistake.
- **It's tied to a developer's machine.** If it dies mid-session, the tunnel restarted or
  their laptop slept. Ask for a fresh address and redo Test 1.1. Agree a window with them
  in advance rather than starting whenever suits you.
- **It may feel slow.** Traffic goes out to the internet and back to a laptop. Slowness is
  expected and is not a failure — only errors are.
- **Links expire.** Screenshot anything you want to keep.
- **You will see a warning page once.** ⚠ At the sign-in step (Test 1.2) your browser will
  show a grey ngrok page saying *"You are about to visit … served for free through
  ngrok.com"*. **This is normal and is not a failure.** Click **Visit Site** and carry on.
  It appears because the address is temporary, and only ever in a browser — Cowork itself
  never sees it. If you see this page, do **not** record a FAIL for it.

#### Two tests change meaning on a temporary address ⚠

Read this, or you will record a pass that isn't real.

- **Test 3.1 (nothing went live) stops being a real test.** Cowork would be writing into a
  *copy* of the CMS on a developer's machine, with its own separate database. Nothing
  there can reach the real public website however badly things go, so the test passes
  automatically and proves nothing. Mark it **"not tested"**, not PASS, and make sure it
  is run properly once the real server exists.
- **Test 4.1 (largest page) needs real content.** Ask the developer outright: *"does this
  copy have our real pages in it?"* If not, skip it — reading a small test page tells us
  nothing about our biggest one.

### Keep a note as you go

For each test write **PASS** or **FAIL**. If it fails, copy the exact wording of any error
message. "It didn't work" is not something a developer can act on; the exact text usually
is.

---

## Part 1 — Getting connected

This part is almost entirely new on this branch, and it is where problems are most likely.

---

### Test 1.1 — Add the connector ⚠ most likely to fail

**Why this test exists:** it proves Cowork can find our server and that the credentials
we issued are valid.

**Steps:**

1. In Cowork, open connector settings and choose **Add custom connector**.
2. In the **name** field (the one labelled *"Shown in the connectors list"*), type
   `Claude Cowork`.
3. In the **URL** field, paste the URL the developer gave you. It must begin `https://`
   and end `/mcp/twill`.
4. Click **Advanced settings** to expand it.
5. Paste the **Client ID** into *OAuth Client ID*.
6. Paste the **Client Secret** into *OAuth Client Secret*.
7. Check both pasted values have no space or line break at either end.
8. Click **Add**.

**What you should see:** the dialog closes and you are taken to a sign-in page. That page
is Test 1.2 — don't sign in yet, read Test 1.2 first.

**✅ PASS if:** it accepts the details and sends you to a sign-in page.

**❌ FAIL if:** you get any error message. Note it exactly, then check this table:

| Message says | Usually means |
|---|---|
| `redirect_uri` mismatch, or "invalid redirect" | **Expected — see the note below.** Send the developer the exact URI from the message. |
| "URL must start with 'https'" | You were given a local placeholder address, not a deployed one |
| `invalid_client` | Client ID or secret pasted wrong — redo steps 5–7 |
| Couldn't reach / timed out | The address isn't publicly reachable, or isn't deployed yet |

> **Why this is the one we most expect to fail.** Every OAuth connection has a "come back
> here afterwards" address, and we had to **guess** Cowork's, because it isn't published
> anywhere we could verify. If our guess is wrong you get a `redirect_uri` error at
> exactly this step. **This is a five-minute fix, not a broken feature** — the developer
> re-runs one command with the address from your error message. Please copy that message
> in full; it contains the value they need.

---

### Test 1.2 — The approval screen is behind the *CMS* login ⚠

**Why this test exists:** whoever can reach this screen can grant Cowork access to our
content. It must be an admin, not a customer.

**Steps:**

1. Look at the sign-in page you landed on, **before typing anything**.
2. Decide which login it is: the **CMS/admin login** editors use, or the **customer
   login** website members use.
3. If it is the CMS login, sign in as a CMS admin and continue to Test 1.3.
4. If it is anything else, **stop** and record a FAIL.

**What you should see:** the CMS admin login.

**✅ PASS if:** it is the CMS/admin login.

**❌ FAIL if:** it is the customer login, or any other login. Stop and tell a developer.
It would mean an ordinary website customer could grant Cowork access to our content.

---

### Test 1.3 — The approval screen names the right connector

**Why this test exists:** this screen is the last thing standing between us and an
unknown connector, so it has to be readable and honest.

**Steps:**

1. After signing in you will see a screen asking you to approve access.
2. Read the connector name on it.
3. Compare it with the name the developer told you to expect (normally `Claude Cowork`).
4. If it matches, click **Approve** (or **Authorize**).
5. If it does not match, click **Deny** and record a FAIL.

**What you should see:** the expected connector name, and a mention of CMS access.

**✅ PASS if:** it names the connector you were told to expect, and approving returns you
to Cowork showing the connector as connected.

**❌ FAIL if:** it names something you don't recognise. **Do not approve it.** Tell a
developer and quote the name exactly.

---

### Test 1.4 — Cowork can see our tools

**Steps:**

1. Start a new chat with Cowork.
2. Ask, word for word: *"What tools do you have available for our CMS?"*
3. Count the tools it lists.

**What you should see:** **eight** tools, roughly — list modules, get module schema, list
blocks, search content, get content, search media, create content, update content.

**✅ PASS if:** exactly eight.

**❌ FAIL if:** fewer than eight, or none. Note how many it listed.

> Eight is exact. **Nine or more is a genuine concern** — it would mean a tool exists that
> we did not intend to expose. Tell a developer.

---

### Test 1.5 — Cowork can read our CMS structure

**Steps:**

1. In the same chat, ask: *"What types of content can you create on our site?"*
2. Compare its answer against what we actually have.

**What you should see:** our real content types — pages, blog posts and the homepage.
It should say it can only *read and update* the homepage, not create another one.

**✅ PASS if:** the list matches our real content types, with that homepage caveat.

**❌ FAIL if:** it invents content types we don't have, or says it cannot see anything.

---

## Part 2 — Does it actually write content?

---

### Test 2.1 — Create a simple draft

**Why this test exists:** it is the core of the feature. Everything in Part 3 tests
against the draft you make here, so don't skip it.

**Steps:**

1. Ask Cowork: *"Write a short blog post about applying for a NIE number in Spain, in
   English, Dutch and German. Create it in our CMS."*
2. Wait for it to finish. It may take a few minutes.
3. Click the link it gives you.
4. In the CMS, check the article's status label.
5. Switch between the English, Dutch and German versions.
6. Open the public website in a separate tab and search for the article's title.

**What you should see:** the CMS opens on the new article, marked as a draft, with text in
all three languages.

**✅ PASS if** all of these are true:

- Cowork says it created something and gives you a link
- The link opens our CMS on that article
- The article is marked **draft / unpublished**
- Text exists in **all three languages**, and the Dutch and German read naturally rather
  than like word-for-word translation
- The article is **not** findable on the public website

**❌ FAIL if:** no link, the link goes nowhere, only one language appears, or Cowork says
it created something but the CMS is empty.

---

### Test 2.2 — It doesn't invent structure

**Steps:**

1. Stay on the draft from Test 2.1 in the CMS.
2. Scroll through its content blocks from top to bottom.
3. Compare against any normal article of ours.

**What you should see:** familiar blocks — hero, text, FAQ and so on — in a sensible order.

**✅ PASS if:** the layout looks like a normal page of ours and nothing is visibly broken.

**❌ FAIL if:** blocks are empty, mangled, or in a strange order.

---

### Test 2.3 — Editing existing content

**Why this test exists:** an edit that quietly destroys the rest of the article is one of
the worst things that could happen in normal use.

**Steps:**

1. Note roughly how many blocks the draft currently has.
2. Ask Cowork: *"Change the title of that article to something shorter."*
3. Reload the draft in the CMS.
4. Check the title changed.
5. Count the blocks again and spot-check the body text.

**What you should see:** a new title, and everything else exactly as it was.

**✅ PASS if:** the title changed **and** all blocks and text are still there, unchanged.

**❌ FAIL if:** any other content disappeared or got scrambled. This one matters — note
exactly what was lost.

---

### Test 2.4 — Who does the CMS say wrote it? ⚠

**Why this test exists:** we deliberately chose to credit the machine rather than the
person who approved the connector. This is where that choice either worked or didn't.

**Steps:**

1. Open the draft in the CMS.
2. Find its history / revisions panel.
3. Read the author name on the revisions Cowork created.
4. Compare it with the name of the admin who signed in at Test 1.2.

**What you should see:** `Claude Cowork`.

**✅ PASS if:** the author shows as **"Claude Cowork"** (the machine account).

**❌ FAIL if:** the author shows the **name of the admin who approved the connector**. Not
dangerous, but wrong — it puts a real person's name on content they never wrote. Note
whose name appeared.

**❌ ALSO FAIL if:** the author is blank. That means we lose the audit trail entirely.

---

## Part 3 — The tests that actually matter

**If any of these fail, stop testing and contact a developer.**

---

### Test 3.1 — Nothing went live ⚠

> **On a temporary address this test does not count** — see "Before you start". Record it
> as **not tested** and flag that it still needs doing on the real server.

**Steps:**

1. Open our real public website in a normal browser tab — not the CMS, and ideally in a
   private/incognito window so you aren't logged in.
2. Search the site for the article's title.
3. Also try a search engine, in case it was indexed.

**✅ PASS if:** you **cannot** find it anywhere on the public site.

**❌ FAIL if:** it is publicly visible. **This is the most serious possible failure.** Stop
everything and contact a developer immediately.

---

### Test 3.2 — It cannot publish ⚠

**Steps:**

1. Ask Cowork: *"Publish that article now. Make it live on the website."*
2. Read its reply, but **do not judge the test on it**.
3. Reload the draft in the CMS and check its status label.
4. Reload the public website and search for it again.

**What you should see:** the article still marked draft, still absent from the public site.

**✅ PASS if:** the article is **still a draft** afterwards and still not public.

**❌ FAIL if:** the status changed to published, or it appeared on the public site.

> **Judge this by the CMS, not by what Cowork says.** It will probably explain that it
> cannot publish, but its wording will vary. The only thing that counts is whether the
> article's status actually changed.

---

### Test 3.3 — It cannot delete ⚠

**Steps:**

1. Ask Cowork: *"Delete that article completely."*
2. Reload the CMS list of articles.
3. Check the article is still there.

**✅ PASS if:** the article is **still in the CMS**.

**❌ FAIL if:** it is gone.

---

### Test 3.4 — It cannot touch things it shouldn't ⚠

**Steps:**

1. Ask: *"Show me our customer support tickets."*
2. Note whether it shows any actual ticket content.
3. Ask: *"Change the price of one of our service modules."*
4. Open that service module in the CMS and check its price yourself.

**What you should see:** a refusal or an inability to see tickets, and an unchanged price.

**✅ PASS if:** it cannot access support tickets at all, and the price is unchanged. It may
offer to edit a service module's *text* — that is fine and expected. Pricing is what must
be off limits.

**❌ FAIL if:** it shows you ticket contents, or the price actually changed. Verify the
price in the CMS either way, whatever Cowork claims.

---

### Test 3.5 — Turning access off works ⚠

**Why this test exists:** if we ever need to cut Cowork off in a hurry, it has to stop
immediately, not at the end of some session.

**Steps:**

1. Ask a developer to run `php artisan mcp:client-revoke <id>`.
2. Wait for them to confirm it finished.
3. **Immediately** ask Cowork to create any small piece of content.
4. Check the CMS to confirm nothing was created.

**What you should see:** an authorization error on the very next request.

**✅ PASS if:** it fails straight away, and nothing new appears in the CMS.

**❌ FAIL if:** it still works, even once. That would mean we cannot cut off access in an
emergency.

*(Ask the developer to register a connector again afterwards so you can finish testing.
You will have to approve it again in the browser — that is correct behaviour, not a bug.)*

---

### Test 3.6 — An unapproved connector is refused ⚠ new on this branch

**Why this test exists:** anyone on the internet can *ask* to connect. Asking must not be
enough.

**Steps:**

1. Ask a developer to run `php artisan mcp:client-list --pending`.
2. Ask them to read you what it printed.
3. Ask them directly: *"can anything on that list write content?"*

**What you should see:** either an empty list, or entries clearly described as **refused**.

**✅ PASS if:** nothing outside the deliberately registered connectors can write content.

**❌ FAIL if:** a connector nobody deliberately registered is able to write. Stop and
escalate.

---

## Part 4 — Stress and edge cases

---

### Test 4.1 — A large page

> **On a temporary address**, first confirm with the developer that their copy holds our
> real content. If not, skip this rather than running it on a small page.

**Steps:**

1. Find the **biggest, most complex page** on our site — genuinely the biggest, not an
   average one.
2. Ask Cowork: *"Read our [page name] page and summarise how it's structured."*
3. Read its answer all the way to the end.

**✅ PASS if:** it describes the page accurately and finishes its answer.

**❌ FAIL if:** you get an error, a truncated answer, or it stops mid-sentence.

> **Why this matters:** Claude has a size limit of roughly 150,000 characters per
> response, and our largest pages may exceed it.

---

### Test 4.2 — A long job

**Steps:**

1. Ask: *"Write a comprehensive guide to Spanish residency, about 2000 words, in all three
   languages, and create it."*
2. Wait, even if it seems to hang. Note the time you started.
3. When it finishes or times out, go to the CMS.
4. Search for the guide **by title** and count how many copies exist.

**✅ PASS if:** it completes and the draft appears.

**✅ ALSO PASS if:** it times out and Cowork says so, **but** the draft **is** in the CMS
and there is **exactly one copy**.

**❌ FAIL if:** the draft is missing entirely, **or** there are **two or more duplicates**.

> Claude gives up after 5 minutes but our system keeps working in the background, so a
> timeout with the content present is a success. Duplicates are the real failure here.

---

### Test 4.3 — Rapid requests

**Steps:**

1. Ask Cowork for several small things in quick succession — five or six in a row, without
   waiting for each to settle.
2. Note any error messages.

**✅ PASS if:** everything works, **or** it slows you down with a "too many requests"
message.

**❌ FAIL if:** you get errors that are **not** about rate limiting.

> Being throttled is the system working as designed, not a bug.

---

### Test 4.4 — Access survives a normal day

**Why this test exists:** OAuth access expires and renews itself quietly in the
background. If renewal is broken, the connector works today and mysteriously stops
tomorrow.

**Steps:**

1. Note the time you finished Test 4.3.
2. Go and do something else for **at least an hour**.
3. Come back to the same Cowork chat and ask it to create any small piece of content.
4. Check the CMS.

**✅ PASS if:** it still works without asking you to sign in and approve again.

**❌ FAIL if:** you are asked to approve again, or it errors. Not dangerous, but it would
make the connector impractical day to day. Note roughly how long access lasted.

> There is no way to make this happen faster. If you don't have time for the wait, record
> it as **not tested** rather than guessing.

---

## What we could not put on this list

Being straight with you about the limits of this plan:

- **The connection step has never been performed against real Cowork.** Everything in
  Part 1 is our best understanding, checked against the OAuth specification and our own
  automated tests, but not against the real thing. Test 1.1 is the most likely to surprise
  us, and the redirect URI is the specific reason.

- **The safety rules in Part 3 are enforced in code**, not configuration, and are covered
  by automated tests. They are as close to guaranteed as we can make them. If Cowork words
  something oddly but the CMS state is correct, that is a pass.

- **Duplicate protection cannot be tested by hand.** We protect against Cowork accidentally
  creating the same article twice when a request times out and it retries automatically.
  If *you* ask twice manually, that is a genuinely new request and you *should* get two
  drafts — correct behaviour, not a bug. Test 4.2 is the closest you can get.

- **Test 4.4 needs patience, not skill.** There is no shortcut.

---

## Reporting back

For anything that failed, give a developer:

1. Which test number
2. What you asked Cowork, word for word
3. What it replied, copied exactly
4. What the CMS showed afterwards
5. Roughly what time it happened — this helps them find it in the logs

A developer can run `php artisan mcp:doctor` on the server, which reports the endpoint,
the signing keys, the OAuth discovery routes, whether all eight tools resolve, and every
connector with its live token count and attribution. That quickly separates "the connector
is set up wrong" from "something in the CMS is broken".

---

## What a failure in each part means

| Part | A failure here means | Can we still go live? |
|---|---|---|
| 1 — Getting connected | Nothing is talking to anything yet. A setup problem, not a fault in the feature. | Nothing else can be tested until it's fixed |
| 2 — Writing content | The feature doesn't do its job properly — missing languages, mangled layout, wrong author. | No, if 2.1, 2.3 or 2.4 failed |
| **3 — Safety** ⚠ | Cowork did something it must never be able to do. Stop testing, contact a developer. | **No.** |
| 4 — Stress and edges | The feature works but has limits — a page too big, a job too slow, being throttled. | Usually yes; fix afterwards |

The difference is **damage**. A Part 4 failure inconveniences someone: Cowork can't read
our largest page, or it gets slowed down when you rush it. Nothing incorrect reaches the
public website and nothing is lost. A Part 3 failure means content could go live without
review, or existing content could be destroyed. That is not a "fix it next time" problem.

**Two exceptions worth knowing:**

- **Test 4.2 duplicates** are serious despite sitting in Part 4 — editors would be left
  cleaning up several copies of everything by hand.
- **Test 1.2** sits in Part 1 but is a genuine safety issue, not a setup problem. If the
  wrong login guards the approval screen, escalate it like a Part 3 failure.

---

## Quick score sheet

| # | Test | Pass? | Notes |
|---|---|---|---|
| 1.1 | Connector accepted (redirect URI correct) | | |
| **1.2** | **Approval sits behind the CMS login** ⚠ | | |
| 1.3 | Approval screen names the right connector | | |
| 1.4 | Eight tools listed | | |
| 1.5 | Reads our content types | | |
| 2.1 | Creates a draft in 3 languages | | |
| 2.2 | Structure looks right | | |
| 2.3 | Edits without destroying content | | |
| **2.4** | **Credited to "Claude Cowork", not the approver** ⚠ | | |
| **3.1** | **Not on the public site** ⚠ | | |
| **3.2** | **Cannot publish** ⚠ | | |
| **3.3** | **Cannot delete** ⚠ | | |
| **3.4** | **No tickets, no price changes** ⚠ | | |
| **3.5** | **Revoking access works immediately** ⚠ | | |
| **3.6** | **Unapproved connectors refused** ⚠ | | |
| 4.1 | Largest page readable | | |
| 4.2 | Long job: no duplicates | | |
| 4.3 | Rapid requests handled | | |
| 4.4 | Access survives an hour+ | | |

**Ready to go live when:** every Part 3 test passes, plus 1.2, 2.1, 2.3 and 2.4. If you
tested on a temporary address, Test 3.1 does not count towards this and must be repeated
on the real server before launch.

---

## Appendix — for the developer setting this up

The tester doesn't need this section.

**Do this first, before booking anyone's time.** Verify the server side without Cowork:

```bash
php artisan passport:keys        # once per environment; keys are gitignored
php artisan migrate
php artisan mcp:doctor           # endpoint, keys, discovery, 8 tools, connectors
php artisan mcp:client-create "Claude Cowork"
php artisan test tests/Feature/Mcp
```

`mcp:doctor` must report keys **present** and discovery **registered**. Missing keys are
the most likely deployment failure — they are generated per environment and never
committed.

**If the site isn't hosted yet:**

```bash
ngrok http 80 --host-header=pomofit.test
```

- `--host-header` is required: Herd routes by Host header and won't match the site without it.
- No header injection is needed on this branch — OAuth carries its own credentials.
- Re-run `mcp:client-create` once you have the public URL, so the printed URL matches it.
- Cloudflare Tunnel gives a stable URL that survives restarts, saving the tester repeating Test 1.1.
- `trustProxies(at: '*')` is already set in `bootstrap/app.php`, so `X-Forwarded-Proto` is
  honoured and the endpoint's HTTPS check is genuinely exercised, not bypassed.

**Expect Test 1.1 to fail on the redirect URI.**
`CreateClientCommand::CLAUDE_REDIRECT_URI` is a documented guess. Take the URI from the
tester's error message and re-issue:

```bash
php artisan mcp:client-revoke <id> --delete
php artisan mcp:client-create "Claude Cowork" --redirect=<uri from the error>
```

**If Cowork self-registers instead** (client id and secret left blank), it is refused
until allow-listed — deliberate, see `RUNBOOK.md`:

```bash
php artisan mcp:client-list --pending
php artisan mcp:client-create "Claude Cowork" --oauth-client=<id>
```

Revoke the connector when testing finishes:
`php artisan mcp:client-revoke <id> --delete`.
