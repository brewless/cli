# Changelog

All notable changes to the Brewless command line client. Versions follow
[semantic versioning](https://semver.org); before 1.0 a minor version may
change how a command behaves.

## 0.1.0 (not released yet)

First version.

- `login` and `logout`: sign a machine in with a code you approve in the console.
- `init`: connect a project to an application; writes `brewless.yml` without secrets.
- `deploy`, `releases`, `rollback`: build a commit in your own project, put it live, and go back.
- `env:pull`, `env:push`: read and write an environment's variables straight in your own Secret Manager.
- `command`, `logs`: run one console command in an environment, and read or follow its logs.
- `domain:add`: make an environment answer on a domain of your own, with a certificate.
- `scale`: set between how many replicas your provider scales an environment.
- `provision`: make what a new environment needs in your own cloud accounts.
