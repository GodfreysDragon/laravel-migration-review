# Security policy

## Reporting a vulnerability

Please report vulnerabilities privately through [GitHub security advisories](https://github.com/GodfreysDragon/laravel-migration-review/security/advisories/new), not in a public issue. Expect a first response within a week.

## Supported versions

Fixes go into the latest release only.

## What the command runs

The static rules only parse migration files. The SQL preview calls each migration's `up()` in Laravel's pretend mode, which runs every part of `up()` except the queries on the migration's own connection. Review only code you would be willing to run, or pass `--no-sql`. See [Security](README.md#security) in the README.
