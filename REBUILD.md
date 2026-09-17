# Rebuilding the Laravel app from a fresh checkout

`app/vendor/` is **not** persisted in workspace snapshots (excluded directory name).
Everything needed to regenerate it *is* committed: `composer.json` + `composer.lock`.

```bash
# 1. PHP 8.3+ and Composer
sudo apt-get install -y php-cli php-mbstring php-xml php-curl php-zip php-sqlite3 php-intl php-bcmath
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# 2. Dependencies (exact versions from the lock file)
cd app && composer install

# 3. Run
php artisan serve --host=0.0.0.0 --port=8000

# 4. PDF toolchain - WITHOUT THESE, 13 tests SILENTLY SKIP rather than fail.
#    The suite still reports green, so the loss is easy to miss.
sudo apt-get install -y chromium poppler-utils fonts-vazirmatn
```

Verified working versions (2026-09-09): PHP 8.4.24, Composer 2.10.3,
Laravel 13.31.0, Filament 4.x, morilog/jalali 3.5.0. **142 Composer packages total.**

The rule engine needs no build step at all:

```bash
php packages/standards-engine/conformance/run.php
```

Full suite: `bash prototype/test/run-all.sh`


---

## 0. vendor/ is a symlink — do not "fix" it

`app/vendor` is a **symlink to `/home/user/.cache/epcc-vendor`**:

```
app/vendor -> /home/user/.cache/epcc-vendor
```

This is deliberate. Composer installs ~16,500 files; the workspace snapshot is
capped at ~10,000 **and truncates silently**. With a real `vendor/` directory
the cap was exceeded (10,008 files) and Composer's own
`vendor/composer/autoload_real.php` was among the files dropped, leaving an app
that could not boot. `.cache` is on the snapshot exclusion list, so moving
vendor there takes the source tree from 10,008 files to **123**.

Recreate it after a reset:

```bash
cd /home/user/epcc-platform/app
mkdir -p /home/user/.cache/epcc-vendor
ln -s /home/user/.cache/epcc-vendor vendor
composer install
```

**Consequence to know about:** Laravel infers the application base path from
the directory containing the registered Composer autoloader, which through the
symlink resolves into `.cache`. `tests/bootstrap.php` pins `APP_BASE_PATH`
before requiring the autoloader, and `phpunit.xml` points at it. If PHPUnit
ever reports `Failed opening required '/home/user/.cache/bootstrap/app.php'`,
that pin has been lost.

---

## 0-RESET. After a sandbox reset: run `restore.sh`

Symptom: the preview is a **white page**, and `php`, `psql` or `composer` are
"command not found". The *source* is intact (it is snapshotted); the *runtime*
is not. PHP, PostgreSQL, Composer and `app/vendor` are all gone.

```bash
cd /home/user/epcc-platform && bash restore.sh
cd app && php artisan serve --host=0.0.0.0 --port=8000
```

This has happened four times. Do not debug the application first — check
`command -v php` before anything else.

### Running behind the preview proxy (white page)

The sandbox preview serves the app at `https://{port}-{sandboxId}.e2b.app`
inside an **iframe on a different site**. Three browser-side defaults break it,
and each one shows a blank page with *nothing in the Laravel log*:

| Console error | Cause | Fix |
|---|---|---|
| `Blocked loading mixed active content "http://…/login"` | The proxy terminates TLS but does **not** send `X-Forwarded-Proto`, so Laravel builds `http://` URLs. `/` redirects to `/login` and the browser refuses to follow it from an https page. | force the scheme |
| `Cookie "laravel_session" has been rejected because it is in a cross-site context and its "SameSite" is "Lax"` | A third-party iframe drops `SameSite=Lax` cookies. No session cookie ⇒ no CSRF token ⇒ login can never work. | `SameSite=None; Secure` |
| redirect goes to a *previous* sandbox's host | `APP_URL` was pinned in `.env`; the sandbox id changes on every reset. | derive from the request |

All three are handled by `app/Http/Middleware/ConfigureForPreviewProxy.php`,
which is prepended to the global stack and applies **only** to hosts ending in
`.e2b.app`. Pinned by `tests/Feature/PreviewProxyTest.php` (6 tests), including
two that assert a normal host still gets `SameSite=Lax`.

### Login does nothing in the preview (cookie-less fallback)

The preview runs the app in an iframe whose top-level document is a **different
site**, so its cookies are third-party. A third-party cookie may be refused for
reasons the server cannot see or satisfy: `SameSite`, `Secure` vs the real
transport, CHIPS/`Partitioned`, or the user's own "block third-party cookies"
setting. When it is refused, every request starts a fresh empty session —
login succeeds, the redirect arrives anonymous, and the form re-renders blank.

**Diagnose it from the database, not the log** (a 419 or a lost session logs
nothing):

```sql
select to_timestamp(last_activity)::time, count(*), count(user_id) as authed
from sessions group by 1 order by 1 desc limit 5;
```

Several rows per page load with `authed = 0` ⇒ the cookie is not coming back.

**Fix: stop depending on the cookie.** A signed `sid` token travels in the URL
instead. Two middleware, and both their classes *and positions* are
load-bearing:

| Middleware | Position | Job |
|---|---|---|
| `PreviewSessionFallback` | **first** in `web`, before `EncryptCookies` | Turns the `sid` token from the query string or POST body back into a real session cookie on the request. |
| `PreviewUrlRewriter` | **last** in `web` (so first on the way out) | Attaches the token to `href`/`src`/`action`/`data-update-uri`, a hidden field in every form, redirect `Location` headers, and the `redirect` member of JSON responses. Injects a small script for links added to the DOM later. |

Because the fallback forges a *genuine* cookie before `EncryptCookies` runs,
everything downstream — `StartSession`, CSRF, the auth guard, and any future
Livewire/Filament panel — behaves normally and needs no special-casing.

> **The forged cookie must carry the `CookieValuePrefix` HMAC**, not just the
> session id, and must be encrypted. `EncryptCookies::validateValue()` silently
> discards any cookie whose plaintext does not start with
> `hash_hmac('sha1', $name.'v2', $key).'|'` — so a cookie holding a bare id is
> thrown away and the mechanism fails in exactly the same way as the bug.

> **The injected script must observe `childList` only.** Observing attributes
> means every `href` it rewrites retriggers the observer: infinite loop, pegged
> CPU, white page.

> **Exceptions bypass the whole middleware pipeline.** A response rendered from
> one — the auth redirect to `/login`, a `ValidationException` going back to
> the form, the 419 handler — never passes back through `PreviewUrlRewriter`
> and would silently lose the token. `bootstrap/app.php` calls
> `PreviewUrlRewriter::apply()` from `$exceptions->respond()` to cover them.

> **`URL::forceScheme()` does not touch `$request->fullUrl()`.** fullUrl() is
> derived from the connection, which the proxy leaves as plain `http`.
> `redirect()->back()` resolves from the session's `_previous.url` (written
> from fullUrl()), so a **failed login redirected to `http://`** and the
> browser blocked it as mixed active content — a blank page with an apparently
> self-wiped form. `ConfigureForPreviewProxy` marks the connection secure
> (`HTTPS=on` + `X-Forwarded-Proto`) to fix this at the source.

> **`$middleware->replace()` does NOT touch group middleware.** Anything aimed
> at the `web` group needs the `*InGroup` variants. `replace()` fails silently
> — the class is simply never swapped, which looks exactly like the fix not
> working.

> **Test over `http://` with a preview `Host`.** Driving these tests at an
> `https://` URL makes the request secure by construction, disabling the very
> condition they exist to cover — a mixed-content regression then passes. One
> guard here was inert for exactly this reason until a mutation test caught it.

> **A session id in a URL is weaker than an HttpOnly cookie** (Referer,
> history, shared links). It is gated on a preview host *and* the
> `epcc.session_url_fallback` flag, which is off by default, and the id is
> never appended to a foreign origin.

> **Verify the host the BROWSER uses, not the one you assume.** Two rounds of
> this bug were spent testing against `*.e2b.app` with curl while the browser
> was on `*.arena.site`. Every curl check passed and the preview stayed broken.
> The console's cookie warnings name the real host — read them first.

> **`TrustProxies` cannot fix the scheme here** — it works by trusting
> `X-Forwarded-Proto`, and this proxy never sends it.

> **Why middleware and not `AppServiceProvider::boot()`** — `boot()` runs once
> per process, so the first request's host would decide the cookie policy for
> every later request. Under Octane that permanently relaxes CSRF protection
> for production hosts. Cookie policy must be decided per request.

`APP_URL` in `.env` is deliberately left as `http://localhost:8000`. Do not pin
it to a sandbox host; it will be wrong after the next reset.

## 0a. Octane / FrankenPHP

The FrankenPHP binary is **166 MB** — far too big for the workspace snapshot.
It lives in the excluded cache dir and is symlinked in:

```bash
cd /home/user/epcc-platform/app
php artisan octane:install --server=frankenphp --no-interaction
mkdir -p /home/user/.cache/epcc-bin
mv frankenphp /home/user/.cache/epcc-bin/
ln -sfn /home/user/.cache/epcc-bin/frankenphp frankenphp
```

Run it (always with `--max-requests`, see Spike §9 rule 10):

```bash
php artisan octane:start --server=frankenphp --host=0.0.0.0 --port=8001 \
  --workers=4 --max-requests=500
```

Plain `php artisan serve` still works and is ~2.8× slower.

---

## 0a-2. PgBouncer (optional, deployment-shaped)

Not needed for development — the app talks to PostgreSQL directly on 5432.
Verified compatible in **transaction pooling** mode (Spike §10).

```bash
sudo apt-get install -y pgbouncer     # binary lands in /usr/sbin
```

Config lives in the excluded cache dir (`~/.cache/pgbouncer/pgbouncer.ini`),
`pool_mode = transaction`, listening on 6432. Note PostgreSQL 17 uses
**scram-sha-256**, so `userlist.txt` must contain the stored verifier, not an
md5 hash:

```bash
sudo -u postgres psql -tAc \
  "select '\"'||rolname||'\" \"'||rolpassword||'\"' from pg_authid where rolname='epcc'" \
  > ~/.cache/pgbouncer/userlist.txt
/usr/sbin/pgbouncer ~/.cache/pgbouncer/pgbouncer.ini
```

Point the app at it with `DB_PORT=6432`. Costs ~1.5 ms per request.

---

## 0a-3. Demo accounts

`migrate:fresh --seed` creates plans, roles and users. Password for every
account is `password`. The login screen lists them and fills the form on click.

| Email | Company | Role | Notes |
|---|---|---|---|
| `karimi@nioc.example` | NIOC-AFF | employer_rep | 100B authority, sees PH-14 + KISH-1 |
| `rezaei@pars.example` | EPC-PARS | project_manager | **40B limit** — blocks PH14-SV-0007 |
| `sadeghi@pars.example` | EPC-PARS | qc_inspector | may view certs, may NOT approve |
| `mousavi@sahand.example` | EPC-SAHAND | site_engineer | scope limited to CIVIL |
| `hosseini@arvand.example` | EPC-ARVAND | project_manager | **Starter plan** — no cost module |
| `tehrani@pmc.example` | PMC-TEHRAN | consultant | sees no certificate rows |
| `former@pars.example` | EPC-PARS | — | **disabled**, cannot log in |

The awkward ones are deliberate: a fixture where everyone can do everything
proves nothing about authorization.

---

### Creating a project

Sign in as `karimi@nioc.example` (employer) and use **+ New project** on the
portfolio. Contractors do not see the button and are refused at both verbs.

Note that creating a project does **not** staff anyone on it except the
creator. A colleague added to the project via `/projects/{code}/team` gains
access; an employee of a participating company who has *not* been added sees
nothing. That is intentional — see ARC §5.4.


## 0a-4. Before shipping a build to a customer: `verify:airgap`

```bash
cd app
php artisan verify:airgap            # acceptance pre-flight (AG-06)
php artisan verify:airgap --strict   # what CI runs; latent findings fail too
php artisan verify:airgap --json     # machine-readable, for the acceptance report
```

Exit 1 means a shipped asset would be fetched from the internet. In the
customer's datacentre that does not render slowly, it **hangs** until the
browser's connect timeout. Never hand over a build that fails this.

It cannot see URLs assembled at runtime - that class of bug (Filament's
`ui-avatars.com` avatar provider) is covered by `AirgapVerificationTest`,
which asserts on the rendered HTML of every panel screen. Run the suite too.

## 0b. If files are missing after a reset — read this first

The workspace snapshot is capped (~128 MB / 10,000 files) and **truncates
silently**. `app/vendor/` alone is ~16,500 files and 118 MB, so it can eat the
entire budget and drop real source directories without any warning. This has
already happened once: `app/resources/views/` and `app/config/` vanished while
everything else looked fine.

**`source-backup.tar.gz` (~240 KB, 163 files) at the repo root is the insurance.**
It contains every source file and excludes `vendor/`, `node_modules/` and
`.git/`. To restore:

```bash
cd /home/user/epcc-platform && tar xzf source-backup.tar.gz
```

Refresh it after meaningful work:

```bash
cd /home/user/epcc-platform && tar --exclude='./app/vendor' --exclude='./node_modules' \
  --exclude='./app/node_modules' --exclude='./.git' --exclude='./app/storage/logs' \
  --exclude='*.tar.gz' -czf source-backup.tar.gz .
```

Before trusting a restored tree, audit it — `vendor/` being present proves nothing:

```bash
cd /home/user/epcc-platform/app
ls resources/views/ config/epcc.php routes/web.php   # the ones that went missing
```

Then confirm the whole thing actually works end to end:

```bash
cd /home/user/epcc-platform/app && php artisan migrate:fresh --seed --force
bash ../prototype/test/run-all.sh          # expect ALL SUITES PASSED
```

`migrate:fresh --seed` must report `EpccDemoSeeder ... DONE` and leave
6 companies / 3 projects / 4 certificates. If it reports only `Seeding
database.` and nothing else, `DatabaseSeeder` has reverted to the Laravel stock
file and is not calling `EpccDemoSeeder` (covered by `SeedPathTest`).

## Database

The app now needs PostgreSQL (SQLite cannot express Row-Level Security).

```bash
sudo apt-get install -y postgresql postgresql-contrib php-pgsql
sudo pg_ctlcluster 17 main start
sudo -u postgres psql -c "CREATE ROLE epcc LOGIN PASSWORD 'epcc_dev' NOSUPERUSER NOBYPASSRLS;"
sudo -u postgres psql -c "CREATE DATABASE epcc OWNER epcc;"
sudo -u postgres psql -c "CREATE DATABASE epcc_test OWNER epcc;"   # test suite

cd app
php artisan migrate:fresh --seed --seeder=EpccDemoSeeder --force
php artisan serve --host=0.0.0.0 --port=8000
```

`NOBYPASSRLS` on the role is deliberate: it makes it impossible to accidentally
run the app with a role that ignores the tenant policies.
