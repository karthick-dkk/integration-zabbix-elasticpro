#!/usr/bin/env python3
"""Set Zabbix 7.0 up for the ElasticPro Clients page and its report widgets.

  ZABBIX_URL=https://zabbix.example.com ZABBIX_TOKEN=… python3 import.py
  ZABBIX_URL=… ZABBIX_USER=Admin ZABBIX_PASSWORD=… python3 import.py [--insecure]

The Elasticsearch cluster template and the Linux agent template are expected under the names
"Elasticsearch Cluster by HTTP EP" and "Linux by Zabbix agent -EP". A Zabbix that calls them
something else is pointed at them in templates.json in the Clients page's data folder, or with
EP_CLUSTER_TEMPLATE / EP_AGENT_TEMPLATE for a run from another machine.

It checks the templates the clients' hosts are linked to (a missing one is named, not worked
around: import it first), creates the host groups that are missing, enables the Clients page
and the widget modules, and creates the widgets' dashboards ("ElasticPro — Client
capacity", "— Client resources") when they do not exist yet. An existing dashboard is never
changed: widgets people added there stay.

The master template itself is not imported here. It follows the roles set on the Clients page,
so the page writes it: ElasticPro → Clients → "Write master template" (and again, by
itself, whenever a role changes).
"""
import json, os, ssl, sys, urllib.error, urllib.request

url = os.environ.get("ZABBIX_URL", "").rstrip("/")
if not url: sys.exit("set ZABBIX_URL (and ZABBIX_TOKEN, or ZABBIX_USER + ZABBIX_PASSWORD)")
ctx = ssl._create_unverified_context() if "--insecure" in sys.argv else None
auth = os.environ.get("ZABBIX_TOKEN")
# Said before anything is asked of the network: it costs no round trip to notice.
if not auth and not (os.environ.get("ZABBIX_USER") and os.environ.get("ZABBIX_PASSWORD")):
    sys.exit("set ZABBIX_TOKEN, or both ZABBIX_USER and ZABBIX_PASSWORD")

def raw(method, params, authed=True):
    h = {"Content-Type": "application/json-rpc"}
    if authed and auth: h["Authorization"] = "Bearer " + auth
    # Inside the try as well: a URL with no scheme ("zabbix.example.com") makes Request itself
    # raise, and that was still a traceback.
    try:
        req = urllib.request.Request(url + "/api_jsonrpc.php", json.dumps({"jsonrpc": "2.0", "method": method, "params": params, "id": 1}).encode(), h)
        # A timeout, or a host that accepts the connection and never answers hangs the script for
        # as long as the kernel allows — which for the version check is before anything is created.
        return json.load(urllib.request.urlopen(req, context=ctx, timeout=30))
    except urllib.error.HTTPError as e:
        sys.exit(f"{method}: Zabbix answered HTTP {e.code} {e.reason} — is {url}/api_jsonrpc.php the API?")
    except urllib.error.URLError as e:
        sys.exit(f"{method}: cannot reach {url} — {e.reason}" + ("  (--insecure skips the certificate check)" if "CERTIFICATE" in str(e.reason).upper() else ""))
    except ValueError as e:
        sys.exit(f"{method}: {url} — {e}. Is ZABBIX_URL the Zabbix frontend, with its scheme?")
    except OSError as e:
        sys.exit(f"{method}: reading the answer from {url} failed — {e}")

def call(method, params):
    r = raw(method, params)
    if "error" in r: sys.exit(f"{method}: {r['error'].get('data') or r['error'].get('message')}")
    return r["result"]

# These modules are written for Zabbix 7.0: the templates they import declare version 7.0 and
# they use constants 7.0 has. Checked here, before anything is created, because the alternative
# is a half-set-up Zabbix and an error from the middle of an import. apiinfo.version takes no
# authorisation, and refuses the request when it is given any.
ver = raw("apiinfo.version", {}, authed=False)
if "error" not in ver:
    got = str(ver.get("result", ""))
    if not got.startswith("7.0."):
        print(f"note: this Zabbix is {got}; these modules are written for 7.0. Carrying on — check the templates import cleanly.")

if not auth:
    auth = call("user.login", {"username": os.environ["ZABBIX_USER"], "password": os.environ["ZABBIX_PASSWORD"]})

# The two templates a site may have under its own names. One definition, shared with the Clients
# page: it keeps them in templates.json in its data folder, so read that where it is reachable,
# and fall back to the environment for a run from another machine.
# Every slot the Roles page can map, with the name ElasticPro ships. Keep in step with
# Roles::TEMPLATE_SLOTS in shared/php/Roles.php — shared/test/export.test.mjs holds the two
# together. All ten and not just two: a site that renamed "ElasticPro alerts" on the Roles page
# used to be told by this script to import a template it had deliberately renamed.
TEMPLATE_DEFAULTS = {
    "cluster": "Elasticsearch Cluster by HTTP EP",
    "health": "ElasticPro cluster health",
    "agent": "Linux by Zabbix agent -EP",
    "master": "ElasticPro client master",
    "devices": "ElasticPro cluster devices",
    "jump": "ElasticPro Elasticsearch via SSH jump host",
    "jump_ulm": "ElasticPro log archive ES via SSH jump host",
    "ulm": "ElasticPro log archive S3",
    "plan": "ElasticPro client plan",
    "alerts": "ElasticPro alerts",
    "delay": "ElasticPro log delay",
}
# The folders Store::dir() looks in, in its order, so this script and the PHP agree about which
# templates.json is the live one. Reading the wrong one — or none — made the script demand
# templates by the shipped names on a site that had mapped them.
DATA_DIRS = ([os.environ["EP_DATA_DIR"]] if os.environ.get("EP_DATA_DIR")
             else ["/var/lib/elasticpro-zabbix", "/var/lib/elasticvue-zabbix"])
saved = {}
for d in DATA_DIRS:
    try:
        with open(os.path.join(d, "templates.json")) as fh:
            loaded = json.load(fh)
    except (OSError, ValueError):
        continue
    if isinstance(loaded, dict) and loaded:
        saved = loaded
        break
TEMPLATES = {k: (str(saved.get(k) or "").strip() or os.environ.get("EP_" + k.upper() + "_TEMPLATE", "").strip() or d)
             for k, d in TEMPLATE_DEFAULTS.items()}

# Required: the clients page and its report widgets cannot work without these.
NEEDS_TEMPLATES = [TEMPLATES["cluster"], TEMPLATES["agent"], TEMPLATES["plan"],
                   TEMPLATES["alerts"], TEMPLATES["delay"]]
# Optional: the S3 log-archive check. It was removed from ElasticPro and lives in its own
# Zabbix template now, imported separately (see deploy/PRODUCTION.md) — a Zabbix with no
# client using the archive check may never have it, and that is not a reason to refuse
# everything else here.
OPTIONAL_TEMPLATES = [TEMPLATES["ulm"]]
# Also optional, with its own note: the cluster health template (templates/
# template-elasticpro-cluster-health.yaml in this repository). Cluster Management links it to
# every cluster host it saves, so a Zabbix without it refuses the save until it is imported —
# but an install that still runs an older Elasticsearch template on its clusters is not broken
# by its absence, and the move onto it is a deliberate step (docs/MIGRATION.md).
HEALTH_TEMPLATE = TEMPLATES["health"]
# The groups discovered hosts are linked to, and the one master hosts go in. "Log archive" is
# only used by clients with the S3 template above; creating it early costs nothing and a host
# using it later finds it already there.
NEEDS_GROUPS = ["Elasticsearch clusters", "Log archive", "ESNodes", "Parsers", "Forwarders", "Engines", "ElasticPro clients"]

have = {t["host"] for t in call("template.get", {"filter": {"host": NEEDS_TEMPLATES + OPTIONAL_TEMPLATES + [HEALTH_TEMPLATE]}, "output": ["host"]})}
missing = [t for t in NEEDS_TEMPLATES if t not in have]
if missing:
    sys.exit("import these templates first: " + ", ".join(missing))
missing_optional = [t for t in OPTIONAL_TEMPLATES if t not in have]
if missing_optional:
    print("note: " + ", ".join(missing_optional) + " not found — skipping the S3/log-archive "
          "parts. Import it separately (see deploy/PRODUCTION.md, \"Log archive template\") if "
          "this Zabbix will monitor a client with an archive bucket; everything else here "
          "(host groups, the client-capacity and client-resources dashboards) is unaffected.")
if HEALTH_TEMPLATE not in have:
    print("note: " + HEALTH_TEMPLATE + " not found — import zabbix/templates/template-elasticpro-cluster-health.yaml "
          "before saving a client in Cluster Management (it links the template to every cluster host). Clusters "
          "still on an older Elasticsearch template are moved onto it with tools/migrate-templates.py "
          "(docs/MIGRATION.md), history kept.")

groups = {g["name"] for g in call("hostgroup.get", {"filter": {"name": NEEDS_GROUPS}, "output": ["name"]})}
for g in NEEDS_GROUPS:
    if g not in groups:
        call("hostgroup.create", {"name": g})
        print("created host group", g)

t = call("template.get", {"filter": {"host": TEMPLATES["master"]}, "output": ["templateid"], "selectHosts": ["host"]})
print("master template:", ("written; master hosts: " + (", ".join(h["host"] for h in t[0]["hosts"]) or "none yet")) if t
      else "not written yet — ElasticPro → Clients → Write master template")

# The cross-client tables. Their modules have to be in Zabbix's modules directory already
# (see ../capacity-widget/README.md); registering and enabling them is what "Scan directory"
# and the Enable link would do.
# A call whose failure is a warning, not a stop: a module folder that is not there yet is
# reported and the rest of the set-up carries on. raw() already turns a network failure into a
# sentence, so this is only about handing the envelope back with its error intact.
def soft(method, params):
    return raw(method, params)

# The Cluster Management page: a module with no dashboard of its own.
for page in ["ep_clients"]:
    found = call("module.get", {"filter": {"id": page}, "output": ["moduleid", "status"]})
    if not found:
        r = soft("module.create", {"id": page, "relative_path": f"modules/{page}", "status": 1})
        print(f"{page}:", "not in Zabbix's modules directory — " + str(r["error"].get("data")) if "error" in r else "registered and enabled")
    elif found[0]["status"] != "1":
        call("module.update", {"moduleid": found[0]["moduleid"], "status": 1})
        print(f"{page}: enabled")

WIDGETS = [("ep_capacity", "ElasticPro — Client capacity", "Client capacity"),
           ("ep_resources", "ElasticPro — Client resources", "Client resources"),
           ("ep_tophosts", "ElasticPro — Top hosts", "Top hosts"),
           ("ep_overview", "ElasticPro — Client overview", "Client overview")]
# The groups the widgets look in: the one master hosts are made in, plus every group that
# already holds a host carrying the master template. A site whose clients predate the
# current group name (or that keeps them under its own) otherwise got a widget pinned to
# an empty group, reporting "no client master hosts" with the hosts in plain sight.
gids = {call("hostgroup.get", {"filter": {"name": "ElasticPro clients"}, "output": ["groupid"]})[0]["groupid"]}
master_ids = [t["templateid"] for t in call("template.get", {"filter": {"host": TEMPLATES["master"]}, "output": ["templateid"]})]
if master_ids:
    for h in call("host.get", {"templateids": master_ids, "output": ["hostid"], "selectHostGroups": ["groupid"]}):
        gids.update(g["groupid"] for g in h["hostgroups"])
group_fields = [{"type": 2, "name": f"groupids.{i}", "value": g} for i, g in enumerate(sorted(gids, key=int))]
for module_id, dashboard, widget in WIDGETS:
    modules = call("module.get", {"filter": {"id": module_id}, "output": ["moduleid", "status"]})
    if not modules:
        r = soft("module.create", {"id": module_id, "relative_path": f"modules/{module_id}", "status": 1})
        if "error" in r:
            print(f"{module_id}: not in Zabbix's modules directory — {dashboard} not created:", r["error"].get("data"))
            continue
        print(f"{module_id}: registered and enabled")
    elif modules[0]["status"] != "1":
        call("module.update", {"moduleid": modules[0]["moduleid"], "status": 1})
        print(f"{module_id}: enabled")
    # Created once. After that it is the users' dashboard: widgets added, moved or resized
    # there are kept — an import never deletes or rebuilds it.
    if call("dashboard.get", {"filter": {"name": dashboard}, "output": ["dashboardid"]}):
        print("dashboard kept as it is:", dashboard)
        continue
    did = call("dashboard.create", {"name": dashboard, "display_period": 60, "auto_start": 0, "pages": [{"widgets": [{
        "type": module_id, "name": widget, "x": 0, "y": 0, "width": 72, "height": 10, "view_mode": 0,
        "fields": group_fields}]}]})["dashboardids"][0]
    print("dashboard", did, "created:", dashboard)
