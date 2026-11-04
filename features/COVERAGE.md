# shell-command Behat coverage

Suite for `ee shell` (package `easyengine/shell-command`, `src/Shell_Command.php`). Written against upstream/develop `9270f7d` and core `v4.12.0` (docker-compose v2.27.0 standalone, which is what `EE_DOCKER::docker_compose_with_custom()` calls).

## Inventory

`ee shell [<site-name>] [--user=<user>] [--service=<service>] [--command=<command>]`, plus the global parameters `--skip-tty[=<true/false>]` and `--custom-compose=<file>` that change how it execs.

| # | Item | Behaviour in the code | Covered by |
|---|---|---|---|
| 1 | help / synopsis | docblock synopsis, `--service` default `php` | validation: "The command is registered…" |
| 2 | help EXAMPLES heading | docblock has ` ## EXAMPLES` (extra space) so help prints the raw heading | not covered yet (its scenario comes back with the fix) |
| 3 | `<site-name>` omitted, outside a site dir | `auto_site_name()` errors "Could not find the site…" | validation: "No site name outside a site directory", "…in interactive mode…" |
| 4 | `<site-name>` unknown | same error (not "does not exist"), no Docker call | validation: "Unknown site name" |
| 5 | `<site-name>` omitted, inside the site dir | detected from `pwd` (site root and symlinked `app/htdocs`) | html: "detected from the site root directory", "…(symlinked) web root…", "…in interactive mode" |
| 6 | wrong `<site-name>` inside a site dir | "X is not a valid site-name. Did you mean `ee shell  <site>`?" | html: "A wrong site name inside a site directory…" |
| 7 | trailing slash | stripped | html: "A trailing slash…" |
| 8 | `http(s)://` prefix | accepted by `auto_site_name()` but raw arg reaches `get_site_info()` | not covered yet (its scenario comes back with the fix) |
| 9 | disabled site | `get_site_info()` refuses, both modes | html: "A disabled site is refused", "…in interactive mode too" |
| 10 | too many positionals / unknown flags / typos | core synopsis validation, suggestions | validation: "Unknown parameters…" (4 examples), "Too many positional arguments" |
| 11 | `--service` default `php` | php container, `www-data`, `/var/www/htdocs` | php-command: "The default service is php…", interactive: "Commands piped to STDIN…" |
| 12 | html site: `php` → `nginx` | default and explicit `--service=php` mapped to nginx | html: "The default service of an html site is nginx", "An explicit --service=php…" |
| 13 | `--service=<site service>` | `docker-compose config --services` check | html (nginx), php-command (nginx, postfix, db with `--local-db`), interactive (postfix), wp (nginx, postfix, mailhog) |
| 14 | unsupported service | "X site does not have support to launch Y shell." | html outline (db, redis, mailhog, postfix, global-cron), php-command (redis), interactive (db), wp (db on global db) |
| 15 | service in compose but not started | exec fails "is not running" | php-command: "A service that exists but is not started…", wp: mailhog scenario (container started directly, not via `ee mailhog enable`, which creates the global admin-tools auth), html: stopped nginx (both modes) |
| 16 | services from `docker-compose-custom.yml` | included by `docker_compose_with_custom()` in the check and the exec | php-command: "A service from docker-compose-custom.yml…" |
| 17 | `--custom-compose=<missing>` | warning, falls back to `docker-compose.yml` only | php-command: same scenario |
| 18 | `--service` without value | warning, service unset, empty-service error | html: "--service without a value…" |
| 19 | `global-nginx-proxy` / `global-redis` / `global-db` | global branch, `chdir(EE_SERVICE_DIR)` | global: redis, nginx-proxy, global-db scenarios (the Background creates `bh-shell-command-5.test` with `--with-db --cache` so global-db and global-redis exist on a fresh stack) |
| 20 | global service ignores `<site-name>` | no site lookup at all | global: "The site argument is ignored…" |
| 21 | `global-cron` is not a global target | falls into the site branch | validation: "global-cron is not a global shell target…", html outline |
| 22 | stopped global container | "global-redis service container is not running." (both modes) | global: "A stopped global service is reported…" `@destructive` |
| 23 | global-db `my.cnf` | written once from `Option::get(GLOBAL_DB)`, enables password-less `mariadb` (both modes; the global-db root has no unix_socket auth, checked in a throwaway container, so the query really proves the file), not overwritten | global: "…creates my.cnf…", "An existing my.cnf is not overwritten", "Interactive global-db shell…" (all `@destructive`) |
| 24 | global-db `my.cnf` mode | root password written 0644 | not covered yet (its scenario comes back with the fix) |
| 25 | shell binary | `sh` for mailhog (no bash in image), `bash` otherwise | wp: mailhog scenario (command and interactive) |
| 26 | `--command` output | STDOUT replayed, STDERR via `error_log()` | php-command: "STDOUT of the command is printed", "STDERR is kept separate…", "Multi-line output…" |
| 27 | `--command` exit status | any failure → exit 1 (EasyEngine/shell-command#25) | php-command: outline (true/false/exit 1), "An unknown binary…", "STDERR is kept separate…" and global "A failing command…" (non-zero only, so a fix for the collapsed status doesn't break them), wp: failing WP-CLI |
| 28 | `--command` exact status | collapsed to 1 | not covered yet (its scenario comes back with the fix) |
| 29 | `--command` quoting | wrapped as `bash -c "<cmd>"` by the host shell | php-command: shell syntax outline (works); double quotes, variables and command substitution not covered yet (their scenarios come back with the fix) |
| 30 | `--command` STDIN | forwarded (`exec -i`) | php-command: "STDIN is forwarded to the command" (with/without `--skip-tty`) |
| 31 | `--command` TTY | output captured, never a TTY, even from a terminal | php-command: "Command mode never allocates a TTY…", "…even when ee runs in a terminal" |
| 32 | `--command` without value / empty / `0` | warning then interactive / silently interactive | php-command: "--command without a value warns" (only the warning is pinned; the interactive fallback is a known bug); empty or falsy `--command` (`""`, `0`) not covered yet (its scenario comes back with the fix) |
| 33 | `--skip-tty` | adds `-T` in `docker_compose_exec()` only | accepted and harmless only: validation, php-command outline, "--skip-tty does not hide a failure", interactive "--skip-tty keeps working with piped input". Whether `-T` is really added isn't observable, because docker-compose v2 disables the TTY whenever its stdout is a pipe (always the case under `EE::exec`) |
| 34 | `--skip-tty` in interactive mode | ignored (no `-T`) | interactive: "From a terminal with STDIN redirected…" (control); `--skip-tty` there not covered yet (its scenario comes back with the fix) |
| 35 | interactive mode | `proc_open` with ee's own fds, status via `proc_close()` | interactive: piped commands, EOF, exit status outline (0/3/42/255), last command status, 127, STDOUT/STDERR separation, session state |
| 36 | interactive TTY | allocated when ee runs in a terminal | interactive: "From a terminal the shell gets a TTY…" |
| 37 | `--user` valid, command mode | `--user='--user=<u>'` double wrap | not covered yet (its scenarios, php-command outline for root and www-data and the global service one, come back with the fix) |
| 38 | `--user` valid, interactive | works, but the user check swallows piped STDIN | not covered yet (its scenarios, interactive outline for root and www-data and the global service one, come back with the fix) |
| 39 | `--user` unknown | warning, falls back to the default user | php-command, interactive, global ("…falls back…"); the interactive fallback's piped input not covered yet: Docker-version dependent (its scenario comes back with the fix) |
| 40 | `--user` without value | warning, default user | php-command: "--user without a value…" |
| 41 | WordPress sites | WP-CLI as www-data (no `--allow-root`), DB access, global db wiring | wp: core version, siteurl, DB_HOST, failing WP-CLI, interactive WP-CLI |
| 42 | idempotency | repeated runs give the same result; `my.cnf` kept | html: "Running the same command twice…" (weak: `ee shell` itself has no side effects), global: "An existing my.cnf is not overwritten" |
| 43 | `check_proc_available('ee_shell')` | errors when `proc_open` is disabled | not covered: needs a php.ini with `disable_functions`, which the suite can't set for `bin/ee` without changing the host PHP config |
| 44 | `proc_open()` failure → exit 1 | defensive branch | not covered: can't be triggered from outside |
| 45 | "Docker is not installed" in `container_status()` | core helper | not covered: would need Docker removed from PATH on the live stack |
| 46 | `delem_log()` start/end | debug log lines | not covered: logging only |
| 47 | `--skip-tty=false` (core) | the string `'false'` still adds `-T` | not covered: not observable through `ee shell`, docker-compose v2 already disables the TTY when its stdout is a pipe (always the case under `EE::exec`) |

Coverage estimate: 36 of 47 inventory items (about 77%). Items 2, 8, 24, 28, 37 and 38 wait for the held-back known-bug scenarios; items 43–47 are defensive or environment-level branches. `check_shell_available()` and `Shell_Command::__invoke()` are exercised except the valid-`--user` path, which comes back with the scenarios of the two `--user` bugs; `check_user_available()` is reached only with an unknown user, and the two failure branches of `run()` aren't reached.

## Features

| Feature | Site(s) | Scenarios (outline rows counted) |
|---|---|---|
| `shell-validation.feature` | none | 11 |
| `shell-html.feature` | `bh-shell-command-1.test` (html) | 20 |
| `shell-php-command.feature` | `bh-shell-command-2.test` (php 8.4, `--with-db --local-db`) | 30 |
| `shell-php-interactive.feature` | `bh-shell-command-3.test` (php) | 16 |
| `shell-wp.feature` | `bh-shell-command-4.test` (wp, global db) | 9 |
| `shell-global-services.feature` | `bh-shell-command-5.test` (php, `--with-db --cache`) | 10 |
| **Total** | 5 sites | **96** |

Tags: `@destructive` 4, `@le` 0, `@remote` 0 (nothing in `ee shell` needs public DNS or backups). CI runs the suite twice, with a terminal (main) and without one (notty). No `@known-bug` scenarios ship here: the 18 scenario rows written for the open bugs below are held back until their fixes land and then return as regular scenarios.

## Known bugs (scenarios held back until fixed)

Their scenarios assert the fixed behaviour and return with the fixes.

- **`--user` wrapped twice** (Medium): in `--command` mode a valid `--user` is turned into `--user=<u>` and then quoted again by `docker_compose_exec()`, producing `--user='--user=<u>'`, so docker fails with "unable to find user --user=root". Every `ee shell --user=<existing user> --command=…` fails. Reproduced with the same compose invocation in a throwaway project.
- **Exit status collapsed** (Low): `--command` collapses every failure to exit 1, while the interactive mode propagates the real status.
- **`http://` site names** (Low): `ee shell http://<site>` fails with "Site http://<site> does not exist.": `auto_site_name()` accepts the scheme but returns the raw argument to `get_site_info()`. The same pattern exists in other commands that call these two helpers.
- **Host-side expansion** (Medium): the command is embedded as `bash -c "<command>"` in a host shell string, so double quotes end the wrapper early and `$VAR`, `$(…)` and backticks are expanded on the host (as root) instead of in the container. Callers in site-command (backup/restore/clone) and dash-command pass commands through this path.
- **Empty `--command`** (Low): `--command=` (empty) silently opens the interactive shell and reads STDIN; `--command` without a value does the same with only a warning.
- **`--skip-tty` ignored interactively** (Low): the interactive path builds its own `exec` without `-T`, so `--skip-tty` is ignored; from a terminal with redirected STDIN docker fails with "the input device is not a TTY".
- **Help and README text** (Cosmetic): help prints ` ## EXAMPLES`; the README lists `[--skip-tty]` as a command option (it's a global parameter); the "Could not find the site…" message has double spaces (`ee shell  <site-name>`).
- **`my.cnf` mode** (Low): the global-db `my.cnf` with the DB root password is written with mode 0644. Defence in depth: on current Docker `/var/lib/docker` is 0710 root:root, so other local users can't reach it by path.
- **User check swallows STDIN** (Medium): `check_user_available()` runs `docker-compose exec` with STDIN attached, which swallows piped input: `ee shell <site> --user=root < script.sh` runs nothing. Reproduced in a throwaway compose project. On Docker 28.0.4 (GitHub runner) the check for an unknown user swallows the input too; on 29.8.1 it doesn't.

## Running

- The site steps are idempotent (`Given the <type> site '<name>' exists` creates the site once per feature and re-enables it if a scenario disabled it), so scenarios in a feature are independent. `@AfterFeature` deletes only `bh-shell-command-*` sites; `@AfterScenario` restarts any container a step stopped.
- Interactive scenarios pipe input or use `< /dev/null`, and TTY cases run `script -qec … /dev/null` with piped input or `< /dev/null`, so none depends on Behat's own terminal. Plain `--command` runs still inherit Behat's STDIN (harmless: the commands don't read it). Help is run with `COLUMNS=200` so the synopsis doesn't wrap on a narrow terminal.
- `@destructive` scenarios stop `services_global-redis_1` and delete/regenerate `/opt/easyengine/services/mariadb/conf/conf.d/my.cnf`. `@AfterScenario` undoes both even when a step fails: `Given the file … is restored after the scenario` snapshots my.cnf (content and mode, or its absence) and puts it back, and stopped containers are restarted; a failed restore fails the hook with what to fix by hand. Only a killed Behat process skips the hooks.
- The custom compose scenario uses the local `easyengine/redis:v4.11.0` image; update the tag when `img-versions.json` moves. Its `docker-compose-custom.yml` is restored and the `bh-extra` container removed after the scenario (`Given the service … of site … is up` registers what it creates).
- Estimated runtime: about 3.5 minutes of site creation (html ~15s, php ~25s twice, php with local db ~40s, wp ~60s), about 5 to 6 minutes for the scenarios (each `ee shell` call is 1 to 3s plus the site-list check in the Background), about 1 minute of site deletion: roughly 10 minutes in total. Excluding `@destructive`: `--tags='~@destructive'`.
- Validation done: `vendor/bin/behat --dry-run --strict` has no undefined or ambiguous steps (it exits 1 in dry-run because all steps are skipped, same as core's own suite), and `php -l` of `features/bootstrap/*.php` passes on php:7.4-cli and php:8.5-cli.
