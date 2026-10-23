#!/usr/bin/env python3
"""A stand-in for the web server that serves ElasticPro's automation document, for
template_live_test.py. Serves FIXTURE at /automation.json behind HTTP basic auth, with
generatedEpoch rewritten to the current time on every request (or to ?age=SECONDS ago), so
the "scrape has stopped" trigger can be tested both ways. Anything else is 404.

  python3 mock_scrape_server.py --port 8000 --user zabbix --password-file /run/pw [--bind 0.0.0.0] [--fixture f.json]

It listens on 127.0.0.1 unless --bind says otherwise: a Zabbix server on the same machine reaches
it there, and nothing else needs to.

The password comes from a file or $MOCK_PASSWORD, never the command line. It is a test
credential for a throwaway endpoint, not a real one.
"""
import argparse, base64, json, os, time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse, parse_qs

ap = argparse.ArgumentParser()
ap.add_argument("--port", type=int, default=8000)
ap.add_argument("--bind", default="127.0.0.1")
ap.add_argument("--user", default="zabbix")
ap.add_argument("--password-file")
ap.add_argument("--fixture", default=os.path.join(os.path.dirname(os.path.abspath(__file__)), "fixtures", "automation.json"))
a = ap.parse_args()
password = (open(a.password_file).read().strip() if a.password_file else os.environ.get("MOCK_PASSWORD", ""))
expected = "Basic " + base64.b64encode(f"{a.user}:{password}".encode()).decode()


class H(BaseHTTPRequestHandler):
    def do_GET(self):
        u = urlparse(self.path)
        if u.path != "/automation.json":
            return self._send(404, b"not found\n", "text/plain")
        if self.headers.get("Authorization") != expected:
            return self._send(401, b"unauthorized\n", "text/plain", {"WWW-Authenticate": 'Basic realm="elasticpro"'})
        doc = json.load(open(a.fixture))
        age = int((parse_qs(u.query).get("age") or ["0"])[0])
        doc["generatedEpoch"] = int(time.time()) - age
        self._send(200, json.dumps(doc).encode(), "application/json")

    def _send(self, code, body, ctype, extra=None):
        self.send_response(code)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(body)))
        for k, v in (extra or {}).items():
            self.send_header(k, v)
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, fmt, *args):
        pass


ThreadingHTTPServer((a.bind, a.port), H).serve_forever()
