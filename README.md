# Brewless CLI

The command line client of [Brewless](https://brewless.eu). It signs this
machine in to your organisation, connects a project to an application and
deploys it to your own cloud account.

## Installation

The client needs PHP 8.3 or newer and `git`. Install it with Composer, for
every project on this machine or in one project:

```bash
composer global require brewless/cli
```

```bash
composer require --dev brewless/cli
```

Installed globally the command is `brewless`, as long as Composer's global
`vendor/bin` directory is on your `PATH`. Installed in a project it is
`vendor/bin/brewless`:

```bash
vendor/bin/brewless list
```

The package holds one built file and requires nothing but PHP, so it does not
touch the versions of your application's own dependencies.

### Staying up to date

Once a day, before a command, the client looks whether a newer version was
released. If so it says which and asks whether to upgrade first. Yes installs
it the way the client was installed (Composer, or the downloaded file after
its checksum matched) and then runs what you asked for with the new version;
no shows the command to do it later and carries on. It only asks a person
at a terminal: in a pipeline or a pipe it stays silent, and
`BREWLESS_NO_UPDATE_CHECK=1` turns it off.

### Without Composer

Every release carries the same file, with a checksum next to it:

```bash
curl -fsSL -o brewless https://github.com/brewless/cli/releases/latest/download/brewless
curl -fsSL https://github.com/brewless/cli/releases/latest/download/brewless.sha256 | shasum -a 256 -c
chmod +x brewless && sudo mv brewless /usr/local/bin/brewless
```

## Usage

```bash
brewless login acme          # sign in; you approve it in the console
brewless init                # connects this project, adds its environments and sets them up
brewless provision production # sets one environment up later, or again after something stopped it
brewless deploy production   # build the current commit and put it live
brewless releases production # what was deployed
brewless rollback production # put the previous release back
brewless export production   # everything Brewless knows, as a folder with Terraform
brewless detach production   # Brewless stops managing it; it keeps running
```

## What init asks

`brewless init` writes `brewless.yml` (no secrets) and then asks what the
application should have:

1. which environments, `production` and `staging` unless you say otherwise,
   and in which region;
2. for each one: a new Serverless SQL Database, a database that stands in
   your Scaleway project already, or none;
3. where its jobs wait: in that database, in a queue at Scaleway Queues, or
   nowhere;
4. whether it gets a private bucket for its files.

It says back what that comes to and makes it, in your own accounts, after one
yes. Say no and it prints the `brewless provision` line that does the same
later.

A script names everything and is asked nothing:

```bash
brewless init --application=shop --environments=production,staging --region=fr-par \
    --database=new --queue=sqs --bucket --yes --no-interaction
```

### Coming from Laravel Vapor

With a `vapor.yml` in the project, `init` offers to start from it: its
environments, its PHP version, and for each environment a database, a queue
and a bucket where Vapor had one. Nothing at Vapor or AWS is read or changed.
Your variables, the data in your databases and the files in your buckets are
yours to move; `init` lists them at the end.

## PHP version

A release is built for the PHP version your `brewless.yml` names:

```yaml
php: "8.3"
```

Without that line Brewless builds for its default version. The versions it
can build are listed when you ask for one it cannot.

## What happens on a deploy

1. The current commit is packed (`git archive`): only what is committed is deployed.
2. The archive is uploaded straight into a private bucket of **your own**
   Scaleway project, through a link that works for fifteen minutes. The source
   does not pass through Brewless.
3. A job in your project builds the image and pushes it to your registry.
4. The release commands (migrations) run on the new image. If they fail,
   nothing changes for visitors.
5. The web container switches to the new image and has to answer healthy,
   then the workers follow.

The client prints each step as it finishes and ends with the address of the
environment.

## Signing in

`brewless login` shows a code and a link. Approving it in the console gives
this machine a token of its own, kept in `~/.config/brewless/credentials.json`
(readable by you only). The token acts as you, works for 90 days and can be
ended from the Account page of the console at any time.

## Settings

| Variable | Meaning | Default |
| --- | --- | --- |
| `BREWLESS_HOST` | The domain organisations live under | `brewless.eu` |
| `BREWLESS_SCHEME` | `http` only for a local installation | `https` |
| `BREWLESS_HOME` | Where sign-ins are kept | `~/.config/brewless` |
| `BREWLESS_NO_UPDATE_CHECK` | `1` never looks for a newer client | not set |

## Development

```bash
composer install
./vendor/bin/pest
./vendor/bin/pint
```

### Releasing

`builds/brewless` is committed: it is the file Composer installs. Build it
with the new version, commit it, then tag.

```bash
php -d phar.readonly=0 brewless app:build brewless --build-version=1.2.3
git commit -am "chore: release 1.2.3" && git tag v1.2.3 && git push origin main v1.2.3
```

## Licence

MIT. See [LICENSE](LICENSE).
