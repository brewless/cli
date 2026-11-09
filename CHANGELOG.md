# Changelog

All notable changes to the Brewless command line client. Versions follow
[semantic versioning](https://semver.org): from 1.0 on, the way a command
behaves only changes in a major version.

## Unreleased

- `wakes <environment>` says when your pull zone is not taking the files your pages ask for with a version (`?v=`) from your storage zone. Your container then serves all of them, which is what the figure above the table counts, and nothing said so before.
- `wakes <environment>` names the pages the edge took from your storage zone with `edge.keep_pages` apart from the requests that reached the container. bunny.net logs such a page as a `MISS`, so before this they were counted as the container's, and on a site of a few hundred pages that was most of the figure. It also says when your page store holds more folders than Brewless reads at once, because the count then reads high.

## 1.2.0 (2026-11-05)

- `cancel <environment>` stops the release that is under way, after asking (`--yes` to skip). Before, a release that hung could only be stopped in the console, and every deploy was refused until then. A release that waits is cancelled at once; one at work on a step finishes it first.
- `deploy` cancels the release it started when its run is stopped (SIGTERM, which a pipeline sends when you stop the run). Before, the pipeline said it stopped while the release went on. Ctrl-C still only stops following, and now says how to cancel.
- `releases` shows the step the release under way is at and what it waits for ("The release container is still rolling out."), which before only the console showed.
- `releases`, `storage:status` and `db:import-status` show times in this machine's zone (TZ, else the system's), and `releases` names the zone. Before they showed Brewless's UTC, which reads an hour off next to a clock in Amsterdam.
- `deploy` and the other commands that follow a release have lines for the steps of the realtime server (BL-61).
- `wakes <environment>` lists the addresses that reached the web container over the last 24 hours, read from bunny.net's request log: what the edge did not answer itself, most first, with the cache status. It is what keeps a serverless container awake. Of a query string only the names are shown.

## 1.1.0 (2026-11-05)

- `edge:rotate-secret <environment>` replaces the secret with which the edge proves to your containers that a request came through it. The containers hold the new secret and the one before it until the edge has moved over, so the site keeps answering throughout; it takes two releases of the image that is live. Needs `brewless/laravel` 1.2 or later in the application, and says so when it is older instead of replacing the secret.

## 1.0.0 (2026-11-04)

- First stable release. The commands, their options and their output are what
  they will stay, and a change to any of them now needs a major version. No
  code changed since 0.25.0.

## 0.25.0 (2026-11-04)

- `deploy` packs the commit without gzip. On a repository that holds media, gzip costs seconds of processor time for about a per cent of size: measured on a 700 MB working tree, 19.9 s to take off 24 MB, and `gzip -1` is no faster on data that does not compress. The upload goes straight into a bucket of your own project over a fast line, so those seconds bought nothing. Brewless reads a compressed archive too, so an older client keeps working.

## 0.24.2 (2026-11-03)

- `deploy`, `rollback` and the other commands that follow a release show the new **Files address** step with its hostname (`✓ Files address  cdn.example.com`), and no line when the environment has no bucket for files behind bunny.net. Brewless gives the public files of such a bucket an address of its own; see `storage.cdn` in `brewless.yml`.
- Setting an environment up from `brewless.yml` shows the bucket for files also when `storage` is a map (`name`, `keep_versions_days`, `cdn`); before, only the name form had its line.

## 0.24.1 (2026-11-03)

- `deploy`, `rollback` and the other commands that follow a release show how long each step took, not the time since the deploy began. The live line counts from the same moment, so the two always agree.

## 0.24.0 (2026-11-02)

- `deploy` says when bunny.net caches the site: after the release is live, a line such as `✓ bunny.net caches the homepage of example.com` when Brewless asked the homepage again and it came from the edge. What stands in the way (a cookie, a Cache-Control, files over `http://` or from the container's own address) stays a warning, with what helps.

## 0.23.1 (2026-11-02)

- `env:pull` for an environment nothing was pushed for yet writes an empty `.env.<environment>` (mode 0600, with the `.gitignore` check) to fill in, and leaves a file that is there already alone. Before, it wrote nothing, and `env:push` then sent you back to `env:pull`.
- `env:push` refuses an empty file as the first push of an environment: that is the file to fill in, not "no variables".

## 0.23.0 (2026-10-31)

- `db:capacity <environment>` shows the vCPU bounds of an environment's Serverless SQL Database: the normal ones, the ones wanted now, what Scaleway gives right now, and the running and planned boosts, with a notice when Scaleway drifted or a change keeps failing. A shared database says its boosts hold for every environment linked to it (BL-224).
- `db:boost <environment> --min=4 --max=15 --for=2h` raises them for a while, from now or planned with `--at` and `--until` (`--prewarm`, `--cooldown`, `--name`, `--key`, `--database`). It shows Scaleway's likely cost first, warns that a large raise can restart the database, and asks (`--yes` does not); a boost that starts now is followed until Scaleway gives the new minimum (`--no-wait` does not). `--at` is read in this machine's time zone unless it has an offset (BL-224).
- A pipeline token may use these commands for the environments of its own application, within the limits of the environment's capacity key (BL-224).
- `db:boost:extend <environment> <key> --until=…` lets a boost end later, and `db:boost:cancel <environment> <key>` cancels a planned one or ends a running one, which then cools down (BL-224).

## 0.22.0 (2026-10-29)

- `db:import <environment> --file=<dump>` copies a dump `pg_dump` wrote on this machine into the environment's database: plain SQL, the same gzipped, or `pg_dump`'s own format (`-Fc`, `-Ft`), read with `pg_restore`. It always copies from here, in Docker, as `--local` does, because a job in your project cannot reach a file on your machine; the destination has to be PostgreSQL. It is one transaction, so a dump that stops halfway leaves the database as it was, and the audit trail notes the name of the dump, never its path. Lines of a dump made with `pg_dump --create` that name a database of its own are left out (BL-207).

## 0.21.1 (2026-10-29)

- The choices of `init` and `provision` fit the terminal again: they are short, and what they mean stands above the question, wrapped, instead of being cut off ("a page is at most…").

## 0.21.0 (2026-10-29)

- `db:import <environment> [postgres|mysql://<user>@<host>[:<port>]/<database>]` copies a PostgreSQL or MySQL database into the environment's database; MySQL into PostgreSQL is converted with pgloader. The password comes from `SOURCE_DB_PASSWORD`, `--from-env` (the `DB_*` lines of an old `.env`) or a prompt, never from the command line. It changes nothing at the source and refuses a destination with tables unless `--replace` is given (on production you type its name). By default the copy runs in your Scaleway project; `--local` runs it in Docker on this machine (BL-205).
- `db:import-status <environment>` shows the last copy into an environment's database (BL-205).

## 0.20.0 (2026-10-29)

- A domain whose DNS you keep at a provider of your own no longer makes `domain:add` wait for its certificate: it ends with the record to make there, and Brewless looks it up and issues the certificate by itself (BL-201). On the domain itself, such as `example.eu`, it names the ways around a `CNAME` (BL-204).
- `domain:list <environment>` lists the domains of an environment with their state, and for one kept elsewhere the record to make and what Brewless last found, as `vapor record:list` did (BL-202).
- `domain:check <environment> <hostname>` looks the record up now, also for a domain Brewless stopped waiting for (BL-202).

## 0.18.1 (2026-10-28)

- A release shows the warm-up of the edge as `Edge warmed  216 pages` (with the pages that failed or were left out), and no line when there was nothing to warm (BL-195). The step that subscribes mail events reads `Mail events subscribed` instead of its name.

## 0.18.0 (2026-10-28)

- `brewless plan` shows an advice line (`*`) with what stands and Brewless's advice under it, such as a homepage the edge cannot keep (BL-192).
- `init` asks a Laravel application how long the edge may keep its public pages (an hour, a day, or not at all) and writes it into `brewless.yml` as `edge.cache` for every new environment (BL-193). `--edge-cache=1h|1d|30m|<seconds>|0` says it without a question; a script that says nothing gets `1h`. More than seven days, or a unit other than `m`, `h` or `d`, is refused. Another framework is only given `edge.cache` when `--edge-cache` names it.
- When the project's `composer.lock` holds `brewless/laravel` older than 0.18, `init` says that it sends a session cookie on every page, so the edge keeps none, and how to update.

## 0.17.0 (2026-10-27)

- `init` asks for the mailer of production, and `deploy` writes the mail DNS records after a yes (`--mail`, `--mail-records`) (BL-185).

## 0.16.2 (2026-10-25)

- The release that `db:failover` starts says "Image still in your registry" for its image, as a rollback does, instead of "Image built and pushed": nothing is built.

## 0.16.1 (2026-10-25)

- `deploy` reads `database` also in its map form (`database: {name: …, standby: true}`). Linking such a database to a new environment stopped with "Array to string conversion".

## 0.16.0 (2026-10-25)

- `db:failover <environment>` switches an environment to the standby copy of its Managed Database (`database: {name, standby: true}` in `brewless.yml`). It says that the last seconds can be missing and that the old database keeps running and costing, asks, follows the switch and then the release that puts the new `DB_HOST` live. `--force` skips the question.
- `deploy` shows the step for the standby copy only when `brewless.yml` asks for one.

## 0.15.0 (2026-10-24)

- `init` and `provision` choose a queue at Scaleway Queues for a new environment unless you choose otherwise, also without `--queue`. A job wakes the worker, which sleeps while there is nothing to do. Jobs in the database are still offered, with what they cost: the worker is called every minute to look for them and never sleeps. Before, the database was the default.

## 0.14.0 (2026-10-24)

- `plan` shows a note under a line when Brewless gives one, such as that bot control under `firewall` takes a paid Shield plan at bunny.net.
- `init --from-vapor` carries `firewall.rate-limit` over as `firewall.rate_limit`: Vapor counts per address in 5 minutes, Brewless in 10 seconds, so the number is divided by 30. `bot-control` is named as not carried over.

## 0.13.1 (2026-10-24)

- `deploy` and `rollback` keep following the release when Brewless briefly answers with a server error or cannot be reached, as can happen while a new version goes live. After two minutes without an answer they stop and say that the release goes on at Brewless. A refusal, such as a revoked token, still stops them at once.

## 0.13.0 (2026-10-24)

- `init --name=<name>` creates a new application without a question. Without a terminal, `init` now says that `--application=` or `--name=` is missing, instead of only "Required.".
- `env:pull` says so when git would not ignore the file it wrote: Laravel's own `.gitignore` names `.env.production` but not `.env.staging`. In a terminal it offers to add the file to `.gitignore`.
- `deploy` names every step in words, also the check of the edge settings and the test address, which showed their internal names.
- `detach` says that the test address on Brewless's own domain stops working, next to what stays.

## 0.12.0 (2026-10-23)

- `deploy` and `init` name the steps for the Private Network and the cache of an environment (`network: private` and `cache` in `brewless.yml`), and the bucket for backups and the key for databases per tenant, instead of their internal names; a step the file does not ask for stays out of sight.
- `init --from-vapor` says how to add a cache and a Private Network to `brewless.yml` instead of that Brewless has none.

## 0.11.0 (2026-10-20)

- `service:push <environment> <service>` builds a service of `brewless.yml` (`environments.<name>.services`) with Docker on this machine, uploads the image straight into a private bucket of your Scaleway project and puts it live; it prints each step and fails with the reason when Brewless could not. This needs Docker, and Brewless with services (BL-139).

## 0.10.0 (2026-10-20)

- `init` asks which Scaleway project a new application runs in when the organisation connected several, and `--project=` names it by name or ID (this needs Brewless with several Scaleway projects, BL-137). With one project nothing changes.

## 0.9.0 (2026-10-20)

- A pipeline deploys without `brewless login`: with `BREWLESS_API_TOKEN` set to a pipeline token from the Account page of the console, every command uses that token instead of the sign-in of the machine. When Brewless refuses it, the client says the token was refused instead of asking to sign in.

## 0.8.0 (2026-10-16)

- `init --from-vapor` writes a full `brewless.yml` from `vapor.yml` and says once what it cannot carry over. Variables from Vapor are added to what Brewless wrote, and new resources carry names of their own.
- `deploy` sends the whole `brewless.yml` along. Brewless applies sizes, scale, timeouts, `worker.queues`, `scheduler`, `build` and `release` steps and plain `variables` with the release (this needs Brewless with brewless.yml phase 2, and `brewless/laravel` 0.3 for `release`).
- Before the release, `deploy` does what the file asks of the environment's resources: it makes an environment the file names that does not exist yet and sets it up, adds a database, queue or bucket it is missing, links a database Brewless manages already with a login of the environment's own, and adds its domains. It prints each step and what it let go after `false`. Domains the environment does not answer on yet are named and only added after a yes, or with `deploy --domains`: adding one can point its DNS at Brewless. A Brewless that does not do this yet is told apart, and the release is deployed as before.
- What Brewless refuses in the file at a deploy is printed with its line, and nothing is deployed.
- `plan` marks lines a deploy would refuse with `!` and the reason, and says which lines a deploy does not apply.

## 0.7.0 (2026-10-15)

- `plan` shows, per environment, what `brewless.yml` asks for next to what stands now: the same (`=`), a change (`~`), something to add (`+`), something the file leaves out that stays (`·`), and what cannot be read (`?`). It changes nothing. When Brewless refuses the file, every reason comes with the line it is on, and a file that is not valid YAML is named with its line before anything is sent.
- `deploy` without an environment deploys to `default-environment` from `brewless.yml`.
- `dns:list` shows the DNS provider of each zone (Bunny DNS or Scaleway DNS), and the commands no longer name bunny.net where the zone may live at Scaleway DNS.

## 0.6.0 (2026-10-15)

- `dns:remove` with `--no-interaction` and without `--force` fails and says to pass `--force`. It used to print "Nothing was removed." and end successfully, because it could not ask.
- `domain:add` and `deploy` print what Brewless wants you to know after the operation, such as a DNS zone it took over for the domain, or `APP_URL` still naming the provider's address. After a new domain, `domain:add` offers to set `APP_URL` to it; it is only changed after a yes, and takes effect with the next deploy.

## 0.5.0 (2026-10-15)

- `logs --source=` reads the logs of another container or database of the environment, such as `release` or `worker`, by its name. Without it, `logs` reads the web container as before. A name the environment does not have is refused with the names it does have.
- `deploy` and `rollback` show the step that is still running on a live line, with its seconds, what it waits for and when it is tried again, so a long image build no longer looks frozen. Without a terminal, or with `--no-interaction`, the output stays as it was.
- `dns:list`, `dns:add`, `dns:update` and `dns:remove` read and change the records of the DNS zones Brewless manages for your environments, the same as the DNS page of the console. `dns:remove` asks first unless you pass `--force`.

## 0.4.0 (2026-10-15)

- `env:pull` and `env:push` go through Brewless, with the connection your organisation already has: no Scaleway key is needed on your machine any more. The values pass through and are not kept. `--direct` keeps the old way, with a key of your own, so the values never pass through Brewless.

## 0.3.1 (2026-10-15)

- `deploy` streams the packed commit to your bucket instead of reading it into memory, so a repository with large committed files (videos, for one) no longer stops with "Allowed memory size exhausted".

## 0.3.0 (2026-10-14)

- The questions of `init` and `provision` follow what Scaleway offers in the environment's region: no new Serverless SQL Database outside Paris, no Scaleway Queues outside Paris and Amsterdam. "Set it up the same way" is only offered when the region can make that.
- A plan the server refuses, such as `--database=new` in a region without Serverless SQL Database, ends in the server's own words; `init` adds how to try again.

## 0.2.1 (2026-10-07)

- The question to upgrade comes before the command instead of after it; after a yes the command runs with the new version.

## 0.2.0 (2026-10-07)

- Once a day the client says when a newer version exists and offers to install it. Silent in a pipeline or a pipe; `BREWLESS_NO_UPDATE_CHECK=1` turns it off.
- `init` adds the environments, asks what they need and sets them up.

## 0.1.1 (2026-10-07)

- Installable with Composer, globally or in a project: `composer global require brewless/cli`. The package holds the built client and requires only PHP.

## 0.1.0 (2026-10-07)

First version.

- `login` and `logout`: sign a machine in with a code you approve in the console.
- `init`: connect a project to an application; writes `brewless.yml` without secrets.
- `deploy`, `releases`, `rollback`: build a commit in your own project, put it live, and go back.
- `env:pull`, `env:push`: read and write an environment's variables straight in your own Secret Manager.
- `command`, `logs`: run one console command in an environment, and read or follow its logs.
- `domain:add`: make an environment answer on a domain of your own, with a certificate.
- `scale`: set between how many replicas your provider scales an environment.
- `provision`: make what a new environment needs in your own cloud accounts.
- `export`: write everything Brewless knows about an environment to a folder, with Terraform and without secrets.
- `php` in `brewless.yml` chooses the PHP version a release is built for.
- `detach`: stop Brewless managing an environment; everything keeps running in your own accounts.
