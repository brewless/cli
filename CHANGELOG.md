# Changelog

All notable changes to the Brewless command line client. Versions follow
[semantic versioning](https://semver.org); before 1.0 a minor version may
change how a command behaves.

## Unreleased

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
