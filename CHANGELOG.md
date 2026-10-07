# Changelog

All notable changes to the Brewless command line client. Versions follow
[semantic versioning](https://semver.org); before 1.0 a minor version may
change how a command behaves.

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
