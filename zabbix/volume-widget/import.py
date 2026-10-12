#!/usr/bin/env python3
"""Enable the Volume report widget, and create its dashboard if there is none, in Zabbix 7.0.

  ZABBIX_URL=https://zabbix.example.com ZABBIX_TOKEN=… python3 import.py
  ZABBIX_URL=… ZABBIX_USER=Admin ZABBIX_PASSWORD=… python3 import.py [--insecure]

The module must already be in Zabbix's modules directory as modules/ep_volume. Registering
and enabling it is what "Scan directory" and the Enable link would do. The widget reads the
"ElasticPro client plan" template, which ElasticPro's deploy/zabbix/setup imports.
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
    req = urllib.request.Request(url + "/api_jsonrpc.php", json.dumps({"jsonrpc": "2.0", "method": method, "params": params, "id": 1}).encode(), h)
    # Without this the ordinary failures — a wrong URL, an expired certificate, a 401 — arrive
    # as a Python traceback, which tells an operator nothing about what to fix.
    try:
        return json.load(urllib.request.urlopen(req, context=ctx))
    except urllib.error.HTTPError as e:
        sys.exit(f"{method}: Zabbix answered HTTP {e.code} {e.reason} — is {url}/api_jsonrpc.php the API?")
    except urllib.error.URLError as e:
        sys.exit(f"{method}: cannot reach {url} — {e.reason}" + ("  (--insecure skips the certificate check)" if "CERTIFICATE" in str(e.reason).upper() else ""))
    except ValueError:
        sys.exit(f"{method}: {url} answered something that is not JSON — is that the Zabbix frontend?")

def call(method, params):
    r = raw(method, params)
    if "error" in r: sys.exit(f"{method}: {r['error'].get('data') or r['error'].get('message')}")
    return r["result"]

# Written for Zabbix 7.0, checked before anything is created. apiinfo.version takes no
# authorisation and refuses the request when it is given any.
ver = raw("apiinfo.version", {}, authed=False)
if "error" not in ver and not str(ver.get("result", "")).startswith("7.0."):
    print(f"note: this Zabbix is {ver.get('result')}; this widget is written for 7.0. Carrying on.")

if not auth:
    auth = call("user.login", {"username": os.environ["ZABBIX_USER"], "password": os.environ["ZABBIX_PASSWORD"]})

# The plan template under whatever this Zabbix calls it: the Roles page keeps the mapping in
# templates.json, in the same folders Store::dir() looks in, in the same order.
PLAN_SHIPPED = "ElasticPro client plan"
DATA_DIRS = ([os.environ["EP_DATA_DIR"]] if os.environ.get("EP_DATA_DIR")
             else ["/var/lib/elasticpro-zabbix", "/var/lib/elasticvue-zabbix"])
plan = ""
for d in DATA_DIRS:
    try:
        with open(os.path.join(d, "templates.json")) as fh:
            loaded = json.load(fh)
    except (OSError, ValueError):
        continue
    if isinstance(loaded, dict) and loaded:
        plan = str(loaded.get("plan") or "").strip()
        break
plan = plan or os.environ.get("EP_PLAN_TEMPLATE", "").strip() or PLAN_SHIPPED

if not call("template.get", {"filter": {"host": plan}, "output": ["templateid"]}):
    sys.exit(f'import "{plan}" first (ElasticPro deploy/zabbix/setup/zbx_phase34.py)')

modules = call("module.get", {"filter": {"id": "ep_volume"}, "output": ["moduleid", "status"]})
if not modules:
    r = raw("module.create", {"id": "ep_volume", "relative_path": "modules/ep_volume", "status": 1})
    if "error" in r: sys.exit("the module is not in Zabbix's modules directory: " + str(r["error"].get("data")))
    print("volume report widget registered and enabled")
elif modules[0]["status"] != "1":
    call("module.update", {"moduleid": modules[0]["moduleid"], "status": 1})
    print("volume report widget enabled")

# The group the dashboard filters on. Made when it is missing, as master/import.py does: this
# script used to take whatever hostgroup.get returned, so running it on a Zabbix without the
# group produced a dashboard with NO group filter — showing every cluster host on the system —
# and the guard below then kept that dashboard for good.
GROUP = "Elasticsearch clusters"
groups = call("hostgroup.get", {"filter": {"name": GROUP}, "output": ["groupid"]})
if not groups:
    groups = [{"groupid": call("hostgroup.create", {"name": GROUP})["groupids"][0]}]
    print("host group created:", GROUP)

name = "ElasticPro — Volume report"
# Created once. After that it is the users' dashboard: widgets added, moved or resized there are
# kept, and an import never deletes or rebuilds it. A re-run that finds it is a success, not a
# failure — sys.exit with a message here would have left every repeat run exiting 1.
if call("dashboard.get", {"filter": {"name": name}, "output": ["dashboardid"]}):
    print("dashboard kept as it is:", name)
else:
    fields = [{"type": 2, "name": "groupids.0", "value": g["groupid"]} for g in groups]
    did = call("dashboard.create", {"name": name, "display_period": 60, "auto_start": 0, "pages": [{"widgets": [{
        "type": "ep_volume", "name": "Volume report — client plan", "x": 0, "y": 0, "width": 72, "height": 10, "view_mode": 0,
        "fields": fields}]}]})["dashboardids"][0]
    print("dashboard", did, "created:", name)
