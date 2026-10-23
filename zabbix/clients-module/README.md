# Cluster Management (Zabbix 7.0 module)

**Cluster Management** (Super admins and ElasticPro admins; other Zabbix Admins read-only — see [Who may edit](#who-may-edit)): every client, its status, whether
its monitoring is set up and working, open problems, when its storage fills, and the last change —
add, edit, clone, disable, maintenance, decommission, bulk import, backups, roles.

## Clients

The form is five cards: **Client** (name, type, purchased by storage or devices; SOC/Manager lead,
cluster DL and contract end date), **Elasticsearch** (URL, user, password, monitored by), **Log archive (S3)**, **Servers** and **Requested capacity**.

| Action | What happens |
|---|---|
| **Add client** | Creates (or reuses) the host group named after the client, then `<client>-Master` (figures, alerts, dashboard), `<client>-ES-Cluster` when an ES URL is given, `<client>-ULM` when a bucket is given, and one Linux host per server. |
| **Type** | **DI**: log archive (S3 bucket, region) and ES URL required. **On-Prem**: optional. tag1 values are not typed in: the archive check finds them in the cluster once a day. |
| **Servers** | Added one at a time: an IP (or several pasted), one or more roles, optional extra services and notes. The host name follows the roles: `karthi-AIML-1`, `karthi-ES-Data-Parser-Engine-1`, `karthi-ES-Data-Hot-Coord-1`; a single node is one server holding ES Data, Parser, Forwarder and Engine (`karthi-ES-Data-Parser-Forwarder-Engine-1`), marked *single node* in Client resources. The page previews each name before it is added. |
| **Password** | **Vault** (a `path:key`) or **Zabbix secret** (typed once, kept as a secret macro on the cluster and archive hosts, never shown again). |
| **Monitored by** | The Zabbix server, a proxy or a proxy group — every host of the client except the master is monitored through it. Or **Windows jump host (SSH), no proxy**: see below. |
| **Requested capacity** | Per role; rows appear for the roles the servers have ("+ Request another role" for capacity not built yet). A family's total adds up the roles that count in it. |
| **Set up as client** | For a cluster made by hand before this page. Its hosts are **kept** — history, passwords — renamed to the pattern, and only what is missing is added. |
| **Edit** | Change anything. Changing a server's roles renames it and moves it between role and family groups; removing a server deletes its host **only if this page made it**. |
| **Clone** | A new client started from an existing one: roles, requested capacity, purchase and settings (monitored by, jump host, region) copied; name, ES URL, password, API key, contacts, archive bucket and servers left empty. |
| **Alerts** | "Email this client's problems to the cluster DL": see below. |
| **Weekly report** | "Mail a PDF … to the cluster DL every Monday": see below. |
| **Test connection** | On a saved client: asks Zabbix to run the cluster check (HTTP, or SSH through the jump host) and each server's agent check now, and shows what they found. |
| **Delete for good** | Only for a decommissioned client, and only after its name is typed. Deletes the hosts this page made, with their history. Hosts made by hand and host groups stay. |
| **Remove only in ElasticPro** | On the client's page, any status, for whoever may edit, name typed to confirm. The client leaves Cluster Management and the reports: its master host is unlinked from the master template (plain unlink; items and history stay) and loses its `{$EP.*}`, `{$EVP.*}`, `{$GRP.CLIENT}`, `{$ES.*}` and `{$ULM.*}` macros, and the page's records of it (registry, last change) go; the history entry and a backup stay. No host is disabled, deleted or moved, and monitoring continues. Its dashboard and weekly report, alert action and DL user group, and maintenance periods stay in Zabbix. |

A server joins the group of each of its roles and of each family they count in (a single node
joins ESNodes, Parsers, Forwarders and Engines), so a shared server counts once per family and in
full for each role. Hosts the page makes carry `managed-by: elasticpro-clients`; nothing without
it is ever deleted. Several hosts on one IP (made before servers could hold several roles) are
shown on the edit page with a **merge** box: ticked, the first host is kept with its history and
the others deleted, after a backup. Nothing is merged without it.

**Host inventory.** `inv:` tags also belong to the separate Host Inventory module
(github.com/karthick-dkk/zabbix-host-inventory): this page writes the server attributes it is given
and never removes an `inv:` tag it does not set, so the two can run on one Zabbix. Every host the page keeps carries `ep-client` and `ep-kind`
(master, cluster, archive, server) tags; a server also one `ep-role` per role, one `ep-service`
per role or extra service, and `inv:<name>` tags for extra CSV columns. Servers the page creates
use automatic inventory mode; notes go to the inventory's Notes.

## Bulk import (Excel workbook)

One workbook, **clients.xlsx** (**Import / export** → Download template, or Export current clients):

- **Clients** — one row per client: settings, then requested capacity per role (five columns a
  role, in one collapsed column group; Other disks as `/data=4000; /logs=500`).
- **Servers** — one row per server: Client, IP, Host name, Role 1–4, Services, Notes. Any other
  column becomes an attribute of the server. Host name: empty — ElasticPro names the host from
  the client and roles, as before; given — kept as the host's own (tag `ep-name=own`), which no
  later save renames; on an existing server a different name renames the host. Names are checked
  against Zabbix's rule and for clashes at Check time. A client on the Servers sheet gets exactly
  the servers listed for it.
- **Sample** — filled-in examples; never imported, nor is any sheet but Clients and Servers.

Every header has a note (what it takes, its choices, an example); every column with a fixed set of
values is a dropdown filled from this Zabbix (client types, roles, proxies and proxy groups), and the
import checks those values again. The workbook is written and read in plain PHP (`lib/Zip.php`,
`lib/Xlsx.php`, `lib/Workbook.php`) — no zip extension needed — into exactly what the CSV files give,
so everything after the upload is one path. The old **clients.csv** / **servers.csv** are still
accepted by the same upload (servers.csv's `name` column is the Host name), and their templates and export (`ep.clients.csv.template`,
`ep.clients.csv.export`) still answer.

Every row is checked first; one error — a bad field, a client twice, an IP under two clients — and
nothing is applied. New clients are added straight away, after a backup. Changes to existing
clients are listed field by field and server by server (roles before → after) and wait for a
tick; a change that deletes a host is never ticked for you. Overlaps (an IP another host already
has, an ES URL two clients share) are warnings; a new client with one waits for a tick too. After
applying, each client is read back from Zabbix and compared with the file.

## Backups

Taken before every change — save, removal, import, role change, restore — and the newest **three**
kept. **Restore** brings back roles, settings and servers; a client that is as it was is left
alone, clients added since are removed (their page-made hosts), and the present is backed up
first, so a restore can be undone. Backups from before servers could hold several roles still
restore. Each backup downloads as clients.csv and servers.csv.

## Status

| Status | Hosts | Set by |
|---|---|---|
| **Active** | monitored | a new client; **Enable** |
| **Maintenance** | monitored, inside a Zabbix maintenance named `ElasticPro: <client>` (keep collecting or not; from now or later, for 1–24 h). Extending replaces it; **End maintenance** deletes it. The status is read from Zabbix, so a maintenance deleted there ends here too. | **Maintenance…** |
| **Disabled** | not monitored. A host that was already off is tagged `ep-was-off` and **Enable** leaves it off. | **Disable** |
| **Decommissioned** | not monitored, kept with their history; hidden from the report widgets. **Restore** takes it back to Disabled. | **Decommission**, only from Disabled |

The status lives in Zabbix: `{$EP.CLIENT.STATUS}` on the master host and an `ep-status` tag on
every host. The report widgets badge disabled clients and clients in maintenance. Tick several
rows for **Maintenance… / Disable / Enable** on all of them at once.

## The list

Tabs by status, and a search that also matches host names and IPs. **Setup** is the checks that
say the monitoring works: Elasticsearch answers (or SSH to the jump host does), devices are
counted, agents answer, the log archive check ran and found tag1, the proxy answers. The first
failing check is named. **Problems** links to Zabbix Problems filtered by the client's
`ep-client` tag. **Disk full in** is `timeleft()` over 7 days of storage usage ("not growing"
when it never reaches 100 %). Every change is recorded with who and when; the edit page shows the
client's activity.

**Other flags on a row.** *storage / devices over purchase* — the master host's share of what was
bought (`ep.es.storage.purchased.usage`, `ep.devices.usage`) above 100 %; the master template
raises a WARNING for storage too. *proxy down* — the client's proxy has not been seen for 5
minutes, or its proxy group is offline or degrading, which explains every host looking broken at
once. *N hosts changed outside* — a host renamed, or moved out of the client's group, in Zabbix
(see below).

**Removed from Zabbix.** The page records each client's form and hosts (`registry.json` in the
data folder). A host deleted in Zabbix behind its back shows as removed, with **Re-create** (as
it was, new history, after a backup) or **Accept removal**. A client whose master host was
deleted stays listed, with **Re-create** or **Forget**. Nothing is re-created by itself. A host
that still exists but was renamed or moved out of the client's group is **changed outside**, never
"removed" (re-creating it would put a second host on its IP): **Put back** renames it and adds the
group again.

**Read-only for Zabbix Admins who may not edit.** They see the list and client pages for the
clients whose hosts they may read — no buttons, the form disabled, nothing from the page's own
records (removed or changed hosts), which know about hosts beyond their permissions — and a line
saying why: *"Editing needs an ElasticPro admin role (or Zabbix Super admin). Your ElasticPro role:
operator."*

## Who may edit

ElasticPro's role decides (decision of 2026-10-22, reversing "Zabbix wins" for roles only — Zabbix
still owns the hosts and host groups):

| Zabbix user type | ElasticPro says | Cluster Management |
|---|---|---|
| Super admin | (not asked) | edits |
| Admin or User | `admin`, not blocked or locked, signed answer over https with verification (or loopback) | edits — within their Zabbix rights (below); never Backups or Re-create |
| Admin | `operator`, `user`, `guest`, blocked or locked | reads |
| Admin | never seen them sign in (`role: null`) | reads — the Zabbix mapping makes a Zabbix Admin an operator |
| Admin | could not be asked | reads, and the page says *"ElasticPro could not be asked who may edit; only Super admins can edit until it answers."* with the reason |
| User | anything but `admin` | nothing (as before) |

The question goes to the ElasticPro core with the **ElasticPro** module's pairing (`modules/elasticpro`,
id `elasticpro`), signed with the scheme and the code (`SsoClient::sign()`) of the sign-in:

- **Request.** `GET {core_url}/sso/zabbix/role?claim=<rawurlencode(JSON)>`, the JSON being exactly
  `{"zabbix_user":…,"zabbix_user_type":…,"ts":…,"nonce":"<32 hex>"}` (a fresh nonce per question),
  with `X-Zabbix-Module-Signature: hex HMAC-SHA256(JSON, secret)`. The core checks the signature, the
  clock (±60 s) and the nonce (16–128 characters, never used before — a reused one is 401 `replayed`)
  and answers for `<user>@zabbix`; nothing is created by asking.
- **Answer.** The core signs what it sends: `X-ElasticPro-Signature: hex HMAC-SHA256(ts + "\n" + nonce
  + "\n" + body, secret)`, with this question's ts and nonce and the exact body bytes, and the 200 body
  echoes `"nonce"` and `"ts"`.
- **What is believed.** An answer counts only with status 200, the signature present and matching,
  the echoed nonce and ts equal to the ones sent, and a nonce this Zabbix session has not consumed
  before (the session keeps the last 64). Otherwise the page says *"the answer was not signed by
  ElasticPro"* or *"replayed answer"*.
- **Over what.** The signature is what authenticates the answer: somebody on the path can block it
  (then only Super admins edit) but cannot forge or replay it. On top of that, an `admin` is acted on
  only when `core_url` is https with TLS verification on, a loopback core (127.0.0.0/8, ::1,
  localhost), or plain http to a host listed in the ElasticPro module's `trusted_plain_hosts`
  (config.php, or Administration → ElasticPro; exact host names only, never IP addresses or
  wildcards; default `ep-core`, the core's name on the documented compose network). For any other
  core the lookup still runs — an operator is still told their role — but nobody is elevated, and
  the page says *"ElasticPro's answer cannot be trusted over this connection"* with *"core_url is
  plain HTTP / TLS verification is off; elevation needs https with verification, a loopback core, or
  a host listed in trusted_plain_hosts"*. https with verification remains the recommendation.

With that module not enabled, Zabbix not paired, the core not answering, or any check above failing,
only Super admins edit, and the page says so with the reason. An ElasticPro admin sets someone's role on ElasticPro's Accounts page; for a
Zabbix sign-in that pins it, so Zabbix sign-ins no longer change it.

The answer is kept in the Zabbix session for 60 s. This module's pages ask again once it is older;
the sidebar on other Zabbix pages uses the last answer and asks only when there is none, so
ordinary browsing costs one question per session. Roles appears in the sidebar for exactly those
who may edit.

**Backups and the register stay Super admin, whatever ElasticPro's role.** A backup holds every
client and host group, and the page's register of hosts (what was removed or changed outside, and
Re-create / Put back / Accept removal) knows hosts beyond what anyone else may read in Zabbix. So
Backups (list, download, restore) and Re-create refuse anyone but a Super admin, and only a Super
admin's list and client pages compare with the register or record a client in it.

**Zabbix rights still apply.** Someone who is not a Super admin writes to Zabbix as themself, and
Zabbix keeps some steps for Super admins whatever their groups say. So before any change — save,
import, status, delete, remove from ElasticPro, roles, writing the master template (and, for the
record, re-create and restore, which only Super admins reach) — the page works out what the change needs and asks Zabbix about this user's rights
(`hostgroup.get`/`host.get`/`templategroup.get` with `editable`). If any step is out of reach it
refuses **before anything is written**, naming the step:

- creating a host group — the client's own for a new client, or a role's — is a Super admin's;
- the cluster DL alert routing or weekly report (its Zabbix user group and DL account) is a Super
  admin's, so a client with either on — or being turned off — needs one;
- creating a template group is a Super admin's; writing the master template needs write on
  `Templates/Applications`;
- every host group the save puts hosts in, and every host it changes, needs write permission;
- templates the user cannot see are named (read permission on their template group).

This is a deliberate ceiling: those Super-admin-only steps are refused, not performed, until the
ElasticPro core has its own Zabbix write identity for them. The backup before every change, and
what it lets you undo, is unchanged. ElasticPro → Settings writes only `settings.json` in the data
folder, so it asks nothing of Zabbix.

## Alerts and the weekly report to the cluster DL

Two ticks on the client card, each needing the cluster DL:

- **Alerts** — the client's problems (warning and above), and their recovery, by email to the DL;
  only this client's, and none while it is in maintenance (the action pauses suppressed problems;
  problems still open when it ends are sent then).
- **Weekly report** — a PDF of the dashboard **ElasticPro: <client>** (Client resources, Client
  capacity and Volume report for the client's group) mailed to the DL each Monday at 08:00, for
  the week before.

The page keeps, per client: a user group **ElasticPro: <client>** (read on the client's group, no
frontend access), a user **ep-dl-<client>** whose one Email media is the DL (a password nobody
knows), a trigger action **ElasticPro: <client>**, and for the report the dashboard and a Zabbix
scheduled report **ElasticPro: <client> weekly**. They follow every change — made, updated, and
removed when unticked, decommissioned or deleted (the dashboard only while it holds nothing but
the page's widgets).

Zabbix needs an active **Email** media type (Alerts → Media types); without one the save says so.
The weekly report also needs Zabbix's web service and report writers
(ElasticPro's `deploy/zabbix/stack` runs both) and **Administration → General → Other → Frontend URL** set to an
address the web service can open (`http://web:8080/` in the stack).

## Windows jump host (SSH), no proxy

For clusters reachable only from a Windows jump host. The Zabbix server opens an SSH session to
the jump host (`ssh.run` items, signed in with its own key) and runs `curl.exe` there against
the cluster; the answers come back as text and the same figures as the HTTP templates are read
from them (`es.cluster.status`, `es.nodes.fs.*`, `ep.es.devices.seen`, …), so the master
template, the widgets and the alerts work unchanged.

- **Jump host**: OpenSSH Server, and `curl.exe` (in Windows 10 1803+ and Server 2019+). A user
  whose `authorized_keys` holds the Zabbix server's public key.
- **Zabbix server**: the key pair in its `SSHKeyLocation` (`/var/lib/zabbix/ssh_keys` in the
  Docker image), readable by the zabbix user. ElasticPro's `deploy/zabbix/jump-sim/setup.sh` makes it in
  the stack's `ssh-keys` volume.
- **Elasticsearch**: a read-only API key in Vault (the form takes its `path:key`): cluster
  privileges `monitor`, `read_ilm`, `read_slm`; `monitor` and `view_index_metadata` on `*`,
  and `read` on the log indices (`{$EP.DEVICE.INDEX}`, `{$ULM.ES.INDEX}`) for the device count
  and the log archive check.
- **Form**: jump host, port (22), user, key file name (`id_ed25519`), the API key's Vault path and
  the **ES certificate** check: Windows trust store (default), a CA file on the jump host
  (`--cacert`), or not checked (`-k`, for a self-signed cluster; the API key then goes to whatever
  answers at the ES URL).

Three sessions: every minute (health, stats, nodes), every 10 minutes (shards, indices, ILM, SLM,
tasks, aliases, dangling) and every 6 hours (device count). A rejected key or an unreachable
cluster is a HIGH problem naming each endpoint and its HTTP code; no answer at all for 5 minutes
is another.

**Log archive check through a jump host.** The check itself runs on the Zabbix server as a script,
which cannot use SSH. So the ULM host also gets **ElasticPro log archive ES via SSH jump host**:
two SSH items asking Elasticsearch the check's two searches (which tag x branch x day has logs,
hourly; which tag1 values, daily), and `{$ULM.ES.VIA}` = zabbix tells the check to read their
answers from the Zabbix API, then ask S3 as always. That needs two **global** macros —
`{$ULM.ZABBIX.API.URL}` (this Zabbix's API as the server reaches it, e.g.
`http://web:8080/api_jsonrpc.php`) and `{$ULM.ZABBIX.API.TOKEN}` (a secret: an API token that may
read the ULM hosts' items) — and the branch field (`{$ULM.ES.BRANCH.FIELD}`) set. A stale answer,
a refused key or a failed SSH session is a failed check, never a missing day.
ElasticPro's `deploy/zabbix/jump-sim/` is a stand-in jump host (OpenSSH + curl answering as `curl.exe`) with
the fixture cluster behind it, for testing without Windows.

## Roles

Families and their roles: ES (ES Data, ES Data Hot, ES Data Warm, ES Coordination, ES Master),
Parser (Parser, S3 Parser), Forwarder, Engine (Engine, UEBA, AIML). There is no Single node role:
pick ES Data, Parser, Forwarder and Engine for one server. A site whose roles still carry the
"Single node" family an earlier release shipped keeps it working, and can remove it here once no
server uses it. Add a family
or role, rename, reorder, remove (refused while machines use it). Each change rewrites the master
template. Roles saved by an older version gain the new ones on the next **Write master template**.

## Templates it writes

**Write master template** writes four: **ElasticPro client master** (from the roles),
**ElasticPro Elasticsearch via SSH jump host** (linked instead of the HTTP templates when a
client is monitored through a jump host), **ElasticPro log archive ES via SSH jump host** (on
such a client's ULM host) and **ElasticPro cluster devices**, linked to each cluster host: distinct `src_hostname` values with
logs in the last 24 hours, every 6 hours, with alerts when more devices send logs than were
purchased and when the count halves in a day. Field, window and index are its macros
(`{$EP.DEVICE.FIELD}` must name a keyword field).

## Install

1. Copy this folder to Zabbix's modules directory as `modules/ep_clients` (Docker: mount it at
   `/usr/share/zabbix/modules/ep_clients`).
2. Give zabbix-web a writable data folder for roles, settings, column settings and backups, and
   point `EP_DATA_DIR` at it. With Docker, a named volume over `/var/lib/zabbix` (empty and owned
   by zabbix in the image, so the volume starts owned by zabbix):
   ```yaml
   environment:
     EP_DATA_DIR: /var/lib/zabbix/elasticpro
   volumes:
     - ep-data:/var/lib/zabbix
   ```
   Installed from packages rather than Docker, make it yourself and give it to the user PHP runs
   as — `www-data` on Debian and Ubuntu, `apache` or `nginx` on RHEL (`ps -o user= -C php-fpm`
   says which):
   ```sh
   sudo install -d -o www-data -g www-data -m 0770 /var/lib/elasticpro-zabbix
   ```
   With SELinux enforcing, also `sudo semanage fcontext -a -t httpd_var_lib_t
   '/var/lib/elasticpro-zabbix(/.*)?' && sudo restorecon -R /var/lib/elasticpro-zabbix`.

   Without a writable folder the page shows a red banner and refuses every change (no backup, no
   change), and the reason — the permissions, a read-only mount, a missing parent — is written to
   the webserver's error log.
3. `../master/import.py` checks the templates, creates groups and enables the modules; then
   **Write master template** on the page (it writes the jump host and cluster devices templates too).

The page appears in the sidebar under **ElasticPro** — the module makes that section itself when
no other ElasticPro module has, and joins the existing one when something has, so it needs nothing
but itself. Beside it sit **Roles**, **Settings** and **Backups**. Zabbix Admins see Cluster
Management and Settings, read-only, unless ElasticPro calls them an admin; Roles is for those who
may edit, Backups for Super admins alone ([Who may edit](#who-may-edit)). For an ElasticPro admin to edit, the
**ElasticPro** module (`embed-module`, installed as `modules/elasticpro`) must be enabled and paired.

## Settings

**ElasticPro → Settings** (whoever may edit) keeps the numbers the page and all three report widgets
read, in `settings.json` in the data folder: where usage turns yellow and red, how figures are
drawn and rounded, when a filling disk is called urgent, and which client types exist. A dashboard
widget may override its thresholds and bar style for itself; a client's master host overrides the
thresholds for itself with `{$EP.USAGE.WARN}` / `{$EP.USAGE.HIGH}`. The order is always: the host,
then the widget, then this page, then what ElasticPro ships.

Changing a threshold does **not** move the alerts on its own: the templates' triggers compare
against the templates' own copy of those two macros. The page says so and links to *Write master
template*, which brings them into step.

Nothing here is settable from the Settings page that was not already a number in the code, so a
site that never opens it sees exactly what it saw before.

**Colours** is the one palette every ElasticPro widget draws with — OK, Warn, High, Unknown, Short,
Free, Down, Stale and Link, each for the Blue (light) and Dark theme, and eight chart series
colours. Six hex digits each; anything else keeps the shipped colour and the page says which. Beside
each colour, what text reads on it: white, or — when white falls below 4.5:1 — flagged, with dark
text instead. A widget carries the colours of the theme its viewer chose (`Settings::paletteStyle()`),
and a column or threshold may still choose its own. The shipped colours are the panel's own, so
nothing changes until somebody edits them.

The foot of the page says which build is installed: "Zabbix modules 2.1.0 · build 723f101". The
version is this folder's `manifest.json`. The rest comes from a `BUILD` file beside it —
`key=value` lines (`modules_version`, `modules_commit`, `modules_commit_short`, `modules_commit_date`,
`modules_tree`; `#` lines ignored, other keys ignored) — which ElasticPro's release archive writes
into every module folder. A folder copied from a plain checkout has none, and the page says "build not
recorded" rather than guess; a value that is missing, `unknown` or not shaped like a commit or a
version is left out the same way (`lib/Build.php`, `test/build.test.php`).

The widget modules keep copies of `lib/Store.php`, `Settings.php`, `Roles.php`,
`ColumnSettings.php`, the column engine (`Columns.php`, `ColumnMath.php`, `ColumnReader.php`) and the
rest of `../shared/php/` (`node ../sync-assets.mjs`). Tests: `npm test` at the
top of this folder runs everything; on their own, `php test/spec.test.php`, `php test/e2e.test.php`
and `php test/menu.test.php` (or their `.test.mjs` runners, which use a PHP container), and
`test/form.test.mjs` — the form's name preview against the same table (`test/names.json`) the PHP
naming is held to.
