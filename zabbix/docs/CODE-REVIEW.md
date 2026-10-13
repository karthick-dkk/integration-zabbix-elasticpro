# Code review — ElasticPro Zabbix modules

Reviewed: the settings/menu/mapping/hardening change set (commit `86400ec` plus the fixes below).
Method: adversarial read of every changed file, then verification on a live Zabbix 7.0.31 host
(Ubuntu 24.04, PHP 8.3.6, PostgreSQL 16) at `192.168.64.13`.

Written for: the engineers who own these modules.

Every finding below was **confirmed by reading the code at the cited line**, not inferred. Each one
marked *Fixed* has a regression test named beside it; run `npm test` and the per-module
`test/*.test.php`.

---

## Summary

| # | Finding | Severity | State |
|---|---|---|---|
| 1 | Cluster Management ignored the forecast windows the Settings page saves | Medium | Fixed |
| 2 | Cluster Management hard-coded the default client type while the widgets read it from Settings | Medium | Fixed |
| 3 | The Volume report picked `$templates[0]`, so the mapped plan template could lose to the shipped one | Medium | Fixed |
| 4 | `Settings::reset()` reported success even when the file could not be deleted | Medium | Fixed |
| 5 | A usage high-mark of `0` turned every cell red and alarmed every cluster | Medium | Fixed |
| 6 | The Settings page offered *Write master template* as a GET link | Medium | Fixed |
| 7 | `master/import.py` still hard-coded the master template name | Low | Fixed |
| 8 | Neither `import.py` had a socket timeout; `Request()` sat outside the `try` | Low | Fixed |
| 9 | `Store::writable()` was called once per client, inside a loop | Low | Fixed |
| 10 | Every PHP suite died on a Zabbix host, because gettext already defines `_()` | High (test) | Fixed |
| 11 | The PHP test bridges failed instead of skipping when Docker was unavailable | Low (test) | Fixed |
| 12 | Deploying from macOS injected AppleDouble `._*` files into the Zabbix web root | Medium (deploy) | Fixed in procedure |
| 13 | `test/` directories would be served under the frontend if deployed | Medium (deploy) | Fixed in procedure |
| 14 | The 500-server cap truncates silently in two paths | Low | Accepted, documented |
| 15 | `ClientSpec::TYPES` const → method is a breaking change for any external caller | Low | Accepted, flagged |

---

## 1. Cluster Management ignored the saved forecast windows

`clients-module/views/ep.clients.list.php:164` called `Forecast::fullIn($c['full_in'])` with no
second argument, so it always used the shipped `Forecast::WINDOWS`. Only the capacity widget passed
`Settings::forecast()`. The Settings page states these apply to Cluster Management.

**Failure:** set *Red under* to 14 days with a master host whose `ep.es.storage.full_in` is 10 days.
The capacity widget shows red; the Clients list shows yellow. One figure, two colours, two pages.

**Fix:** `ClientList` passes `'forecast' => Settings::forecast($cfg)`; the view reads it.
**Test:** `spec.test.php` — "the list is given the site's forecast windows".

## 2. The default client type was hard-coded in the list

`ClientList.php:132` and `:159` used `?? 'On-Prem'` while the three widgets used
`Settings::defaultType()`. The `??` also let an *empty* `{$EP.CLIENT.TYPE}` through, where the
widgets test `!== ''`.

**Failure:** set the default type to `CI`; a master host with no type reads `CI` in every widget and
`On-Prem` on the Clients page.

**Fix:** both sites use `Settings::defaultType($cfg)` with the widgets' non-empty test.
**Test:** `spec.test.php` — "the list takes the default client type from the settings".

## 3. The Volume report could read the wrong plan template

`volume-widget/actions/WidgetView.php:66` took `$templates[0]`. Since `templateNamesFor('plan')`
returns the mapped name, then aliases, then the shipped name — and the Zabbix API does **not** answer
in that order — the columns (`:70`), the threshold macros (`:120`) and the host query (`:111`) all
depended on database row order.

**Failure:** a site maps `plan` to its own template and still has `ElasticPro client plan` installed.
Which template drives the report is arbitrary, and clusters linked only to the other one **disappear
from the report**. The equivalent fix had been applied to the resources widget but not this one.

**Fix:** the result is sorted into the order the names were asked for, every `templateid` is used, and
macros merge first-definition-wins so the mapped template's own thresholds beat a leftover shipped one.
**Test:** `volume-widget/test/view.test.php` — "two plan templates at once" (the fake deliberately
returns the shipped one first).

## 4. `Settings::reset()` claimed success on a failed delete

`shared/php/Settings.php` called `Store::delete()` and discarded the result; `SettingsSave` then
showed "Settings are back to the ones ElasticPro ships".

**Failure:** `settings.json` exists but cannot be unlinked (folder no longer writable). The file
stays, the memo cache is nulled, and the next page re-reads the **old** settings under a success
banner.

**Fix:** `reset(): bool`, and the page reports the failure naming the file.
**Test:** `spec.test.php` — "reset answers whether the settings file is gone".

> Still open by design: `ColumnSettings::reset()`, `Backups` rotation and `ImportApply` also ignore
> the new `bool`. They are lower-consequence (a lost column preference, an un-rotated backup), but if
> you want them surfaced too, that is a one-line change each.

## 5. A high mark of `0` made everything red — and alarmed every cluster

`Settings::clean()` clamped usage to `[0,100]`, so `high = 0` was accepted.

**Failure:** every figure satisfies `$usage >= $high`, so every cell renders `ep-high`. Worse, the
templates now take these macros from Settings, so the next *Write master template* puts `0` into
`{$EP.USAGE.HIGH}`, and the jump host template's triggers (`JumpTemplate.php:256-257`,
`last(...)>={$EP.USAGE.HIGH}`) then **fire on every cluster at once**.

**Fix:** `high` has a floor of `1.0`. `warn` may still be `0` — "always at least yellow" is a real
choice — and is ordered under `high` as before.
**Test:** `spec.test.php` — "a high mark of 0 is lifted to the lowest usable one".

## 6. The Settings page offered a state-changing action by GET

`ep.clients.settings.php:66` used `CLink` to `ep.clients.template.install`. The Clients list offers
the same action as a POST with a token (`ep.clients.list.php:55`), and the codebase already records
that Zabbix only validates the token on a POST (`ep.clients.edit.php:320`).

**Failure:** any page that can make an admin's browser fetch a URL could rewrite the templates —
which carry `deleteMissing` rules for items, triggers, graphs and discovery rules.

**Fix:** the drift warning moved out of the settings `CFormGrid` (HTML forbids nested forms) to a
page-level banner with its own POST form and token.
**Test:** `spec.test.php` — "the settings page offers Write master template as a POST".

## 7–8. The two `import.py` scripts

- `master/import.py:132` still filtered `template.get` on the literal `"ElasticPro client master"`
  instead of `TEMPLATES["master"]`, so a site that renamed it was told "not written yet" about a
  template it has. **Fixed.**
- Neither script passed a `timeout` to `urlopen`, so the new `apiinfo.version` call — which runs
  *before* anything is created — could hang indefinitely against a host that accepts the connection
  and never answers. **Fixed (30 s).**
- `urllib.request.Request(...)` was built outside the `try`, so `ZABBIX_URL` without a scheme raised
  a bare traceback. **Fixed**, and `OSError` while reading the body is now caught too.

**Test:** `shared/test/deploy.test.mjs` — "both scripts say what went wrong instead of raising a
traceback", "the set-up script knows every template slot the Roles page can map".

## 9. `Store::writable()` inside the per-client loop

`ClientList.php` called it once per client. Each call may `error_log` the reason the folder is
unusable and may `mkdir` — from a page that only shows things. On an unwritable folder that was N log
lines per page load.

**Fix:** hoisted to one call per request.
**Test:** `spec.test.php` — "the data folder is checked once per request, not once per client".

## 10. Every PHP suite died on a Zabbix host *(the highest-value finding)*

The suites define `function _($s)`. PHP's **gettext** extension already defines `_()`, and a Zabbix
frontend host has gettext loaded — Zabbix uses it for translations. Result:

```
PHP Fatal error: Cannot redeclare _() in .../spec.test.php on line 17
```

All six suites, on exactly the machine the modules run on. They passed only in the
`php:8.4-cli-alpine` container, which has no gettext — so the test suite had never once been executed
against the PHP that actually serves these pages.

**Fix:** every stub guarded with `if (!function_exists(...))`. gettext's `_()` returns its argument
when no domain is bound, which is what the stub did.

**Now verified:** 1,511 assertions pass on the host's own PHP 8.3.6.

## 11. The PHP bridges failed instead of skipping without Docker

The `.test.mjs` bridges skipped only on the exact string `Cannot connect to the Docker daemon`. A
stopped Docker Desktop words it differently (`failed to connect to the docker API at unix://…`), so
`npm test` went red for a reason unrelated to the code. **Fixed** with a named `noDocker()` predicate
that still fails loudly on a genuine test failure.

## 12–13. Two deployment-hygiene findings

- **AppleDouble files.** `tar` on macOS carried `._*` resource-fork files into
  `/usr/share/zabbix/modules/`, exactly doubling the file count — including `._Module.php`, a
  163-byte binary served by the web root. Deploy with `COPYFILE_DISABLE=1 tar --no-xattrs`, and
  `find … -name '._*' -delete` afterwards.
- **`test/` directories must not ship.** They are standalone PHP scripts that create temp
  directories and would be reachable at `…/zabbix/modules/ep_clients/test/spec.test.php`. The deploy
  excludes `test/`, `node_modules` and `import.py`; only 91 PHP files reach the server.

## 14–15. Accepted, with reasons

- **The 500-server cap truncates silently.** `ClientSpec::canonServers()` returns `[]` for a client
  with more than 500 hosts, and `splitIps()` drops the excess without a word. Unreachable for real
  clients (tens of machines), and the cap exists to bound a runaway POST — but it is a silent path.
  If you want it loud, `splitIps()` should return the count it dropped.
- **`ClientSpec::TYPES` became `ClientSpec::types()`.** No caller remains in this checkout, but a
  `public const` → `public static function` is a breaking change for anything in the ElasticPro repo
  that referenced it. Grep that repo before release.

---

## Checked and found clean

So the coverage is legible, not just the failures:

- **`Settings::all()` memoisation.** `save()` assigns the cache only after `Store::write()` succeeds;
  a failed write leaves it untouched. `reset()` nulls it. No request both saves settings and renders
  from them — `SettingsSave` redirects, and the templates are written by separate actions.
- **`clean()` key order** matches `DEFAULTS` exactly, inner orders included, so the `===` comparisons
  elsewhere are safe. The types loop trims, caps at 32 chars, dedupes case-insensitively, caps at 8,
  and falls back correctly. The forecast ordering pass has no off-by-one.
- **`usageFor()` precedence**, walked for every combination of macro / field / file, including
  inverted pairs from mixed sources: always a sane, ordered pair. A macro of `"0"` counts as set; an
  override of `0` means inherit, which is what lets an empty box mean "follow the site".
- **`Bars::INHERIT = 100`** is a valid `CWidgetFieldSelect` key and saves as INT32. Upgrade paths for
  stored `1`/`2`/`3` are unaffected; `styleOf(100)` degrades to the default style rather than drawing
  nothing.
- **`columns.json`** — no reader of the removed `template`, `warn`, `high` keys remains in PHP, JS,
  Python or the tests.
- **`Store::writable()`'s `set_error_handler`** always reaches `restore_error_handler()`; no early
  return or throw between them, so a suite's throwing handler survives.
- **`$this->cfg`** is assigned first in each widget's `doAction()`; no reader is reachable earlier.
- **The menu's find-or-create**, verified live: the third-party `elasticpro` module creates its
  "ElasticPro" section in `init()`, which Zabbix runs before any module's `onBeforeAction()`. So
  `ep_clients` always finds and joins it, and the duplicate-section case cannot occur in that
  direction. With `elasticpro` disabled, `ep_clients` creates the section itself.
- **Both `import.py`** check credentials before touching the network, send `apiinfo.version`
  unauthenticated (ES refuses an authorised one), cannot produce an empty template name, and exit 0
  on an existing dashboard.

---

## Not reachable without a Zabbix session

Recorded so it is not mistaken for passing: nothing below has been executed.

- Module **loading** — unauthenticated requests get "Page not found" for module actions (the
  third-party `elasticpro` module behaves identically), because Zabbix registers module routes only
  after login.
- Every rendered page, the permissions matrix for three user types, real CSRF enforcement, the dark
  theme, and the widget **edit forms** — a `CWidgetFieldIntegerBox` mistake shows up as a blank
  widget and nowhere else.
- The destructive paths (restore, delete-for-good, re-create) against real Zabbix history.

`docs/TEST-CASES.md` covers all of it as 318 executable cases.
