# ADR-0002: No runtime Composer dependencies

## Status

Accepted.

## Context

The brief requires the app to run on plain XAMPP/WampServer/LAMP, and reviewers explicitly assess "code structure without a framework". A typical PHP app pulls in a router, an ORM, a validation library and an env-file parser as Composer dependencies; on a shared-hosting-style XAMPP install, that means the reviewer (or us, during submission) must run `composer install` before anything works, and any dependency's own transitive requirements become our problem too.

## Decision

`composer.json` has **no `require`**, only `require-dev` (PHPUnit, PHPStan, PHP-CS-Fixer — none of which ship in the release ZIP or run in production). Everything the app needs at runtime — routing, request/response, a `.env` parser, PDO access, validation — is written by us in `src/`. Composer is used only for PSR-4 autoloading and dev tooling. The release ZIP ships a committed `vendor/autoload.php` (generated with `--no-dev`), so a XAMPP user never has to run Composer at all.

## Consequences

- No dependency-version drift, no transitive vulnerability surface, no "works on my machine because of a global Composer cache" risk.
- We own bugs a library would otherwise have fixed for us (e.g. the router's regex-based path matching, the `.env` parser). Scope is small enough that this is a good trade for this project.
- Anything genuinely hard to get right ourselves (image processing, HTTP client) is either a PHP extension (`gd`, `curl`) rather than a Composer package, or deliberately out of scope.
