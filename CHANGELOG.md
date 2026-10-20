# Changelog

All notable changes to the Brewless command line client. Versions follow
[semantic versioning](https://semver.org); before 1.0 a minor version may
change how a command behaves.

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
