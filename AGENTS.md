# AGENTS.md

This is a Symfony project. Check `composer.json` for the exact Symfony/PHP version
in use, and read `symfony.lock` to see which recipes ran. Don't assume Doctrine,
Twig, API Platform, Messenger, or Lock are installed unless one of those says so.

## Ask before generating

If the task doesn't specify, ask rather than guess:

- Persistence: Doctrine ORM, Doctrine ODM, or none?
- Interface: server-rendered (Twig), API (Serializer, maybe API Platform), or both?
- Auth: SecurityBundle, and which authenticator?

If you can't ask (no interactive channel), state the assumption you're making and
pick the smallest option (e.g. no persistence layer) rather than scaffolding a
full stack nobody asked for.

## Adding features: Flex, not hand-wiring

Install new capabilities with `composer require <package>` (e.g. `symfony/lock`,
`symfony/messenger`, `orm-pack`) and let the Flex recipe register the bundle and
generate its config. Don't hand-edit `config/bundles.php` or hand-write a bundle's
base config; that's what the recipe is for. Don't skip a good-fit component just
because it isn't installed yet; installing it is one command.

## Frontend: Asset Mapper + Tailwind, no Node

The frontend runs on the Asset Mapper component. There is no Node.js, no
Vite, no Webpack Encore, and no bundler step: assets are served as native
ES modules and versioned files. Don't add a JS build pipeline, and don't
introduce npm dependencies as a default reflex.

- CSS is Tailwind v4, integrated through `symfonycasts/tailwind-bundle`
  (`composer require symfonycasts/tailwind-bundle`). The stylesheet lives in
  `assets/styles/app.css` with `@import "tailwindcss";` and is built with
  `bin/console tailwind:build` (or `--watch` during development). Import that
  stylesheet from Twig, not the compiled output.
- Style with Tailwind utilities in markup. Don't hand-write bespoke CSS when
  a utility class would do, and don't reach for Bootstrap: this project
  deliberately moved off it.
- New frontend packages go through `bin/console importmap:require <package>`,
  which wires the importmap versioning. Import them as ES modules in
  `assets/app.js` or in the Stimulus controller that needs them. Prefer
  packages that ship native ESM and need no build step.
- Templates are Twig with Tailwind classes. Keep the markup semantic and
  accessible (label/aria attributes, focus states).

### Interactive widgets

Tooltips, date pickers, floating labels, dropdowns, dialogs come from, in
order of preference:

1. Native platform features: `<dialog>`, `<details>`, the Popover API,
   `light-dark()`. Use these before any library.
2. A `symfony/ux-*` package when it covers the need well, especially anything
   wired to Symfony's form component or rendering (`composer require` it
   rather than hand-rolling or installing a lookalike library).
3. A lightweight Tailwind-compatible library (Preline, Flowbite) or a minimal
   one like flatpickr, wired through importmap.
4. A small project-specific Stimulus controller.

Avoid monolithic component frameworks (React, Vue) unless the task
explicitly asks for them.

## JavaScript: Stimulus + Turbo

JavaScript behavior is written as Stimulus controllers, not as ad-hoc
`<script>` blocks, global event listeners, or another framework (Alpine,
jQuery). If `symfony/stimulus-bundle` isn't installed, `composer require` it
rather than substituting a different approach.

- One behavior = one controller in `assets/controllers/`, named
  `name_controller.js`, attached with `data-controller="name"`.
- Read state from `data-*-value` and `data-*-target` attributes instead of
  DOM scraping or hidden globals; declare them with `static values = {}` /
  `static targets = []`.
- Third-party libraries are imported inside the controller that needs them
  (via importmap), initialized in `connect()`, and cleaned up in
  `disconnect()`. A library may complement a controller; it never replaces
  one.
- Turbo is enabled (`symfony/ux-turbo`). Write controllers to be idempotent
  across Turbo navigations: assume `connect()` may run more than once per
  page load, and release everything (listeners, timers, library instances)
  in `disconnect()`.
- Don't hand-roll behavior a `symfony/ux-*` package already provides;
  `composer require` the package instead.

## Frontend workflow

- Rebuild CSS after touching Tailwind classes or templates if the watcher
  isn't running: `bin/console tailwind:build`.
- Check `bin/console debug:asset-map` when an asset 404s; an unmapped import
  is the usual cause, not a missing file.
- Frontend changes are done when they render correctly in the browser and
  pass the existing test suite; add functional coverage for interactive
  behavior where it matters.

## Conventions

Follow https://symfony.com/doc/current/best_practices.html to write idiomatic
Symfony:

- Use PHP attributes for framework metadata, and not only on controllers:
  `#[Route]`, `#[MapRequestPayload]`, `#[IsGranted]` on actions, `#[Assert\...]`
  on properties, `#[AsCommand]`, `#[AsEventListener]`, `#[AsMessageHandler]`, and
  `#[AsAlias]` / `#[AsTaggedItem]` / `#[Autoconfigure]` on services. No YAML or
  XML routing.
- Rely on autowiring and autoconfiguration. Type-hint constructor arguments and
  let the container resolve them. Where a type-hint can't express it, stay in the
  class with `#[Autowire]` (parameters, env vars, expressions) or `#[Target]` (one
  of several implementations of an interface). A YAML service definition is the
  last resort, not the first.
- Controllers extend `AbstractController`, stay thin, and delegate to services.
- Use the framework for what it already does: Form for server-rendered forms,
  Validator for validation, Serializer for JSON, Messenger for async work,
  Security (voters, authenticators) for access control, Twig `path()`/`url()`
  instead of hardcoded URLs.
- Before hand-writing infrastructure (locks, queues, caches, HTTP clients,
  mailers, schedulers) or reaching for a third-party library, check whether a
  Symfony component covers it. It usually does.

Three specifics worth spelling out, because they are easy to get wrong:

- Bind request data with `#[MapRequestPayload]` / `#[MapQueryString]` on action
  arguments, which wires up Serializer and Validator for you, instead of calling
  `json_decode()` or `SerializerInterface` by hand. If neither package is
  installed yet, `composer require` them rather than falling back to manual
  parsing.
- Use constructor property promotion, and `readonly` for DTOs and value objects.
  Don't mark a service `readonly` if it might become `lazy: true`: a lazy proxy
  can't extend a `readonly` class.
- Use `symfony/lock` (`LockFactory`) for mutual exclusion. A hand-built flag or
  lock file looks fine in review and is usually wrong under concurrency.

## Everyday workflow

- Run the app with `symfony serve -d`, and commands with `symfony console ...`
  (or `bin/console` when the Symfony CLI isn't available).
- When something fails, read `var/log/dev.log` and the web profiler
  (`/_profiler`) before changing code.
- If `maker-bundle` is installed, prefer `bin/console make:*` with every argument
  passed up front and `--no-interaction` where supported: makers prompt on a
  terminal by default, which hangs a non-interactive shell. If a maker still
  needs interactive input, hand-write the code instead.
- If Doctrine ORM is installed, schema changes go through migrations
  (`bin/console make:migration`, then `doctrine:migrations:migrate`), never
  `doctrine:schema:update` or hand-written SQL.
- `.env` is committed and holds defaults only. Real secrets belong in `.env.local`
  (git-ignored) or the secrets vault (`bin/console secrets:set`), read via
  `%env(...)%`.

## Testing

Install `symfony/test-pack` if it isn't already. Functional/HTTP tests extend
`WebTestCase`; service-level tests extend `KernelTestCase`. Run
`php bin/phpunit` (falls back to `vendor/bin/phpunit`). A feature isn't done
until it has a test that exercises it the way a caller would, an HTTP request for
a controller or a service call for a service, not just "it didn't throw."

## Code style

Symfony's coding standard, the `@Symfony` php-cs-fixer ruleset (a PSR-12-derived
superset). Run `vendor/bin/php-cs-fixer fix` if `friendsofphp/php-cs-fixer` is
installed; it isn't part of the skeleton by default.

## Discover, don't guess

Framework APIs change between versions and your training data may be stale. Look
things up in the project instead of relying on memory:

- `bin/console about`: versions, environment, paths.
- `bin/console debug:router`, `debug:container`, `debug:autowiring <name>`,
  `debug:config <bundle>`, `config:dump-reference <bundle>`: what exists and how
  it is configured.
- `bin/console lint:container`, plus `lint:twig templates/` and
  `lint:yaml config/` where those packages are installed: validate before running.
- Read the installed source and docblocks under `vendor/`.
- Docs: https://symfony.com/doc/current/ (switch to the version matching
  `composer.json` if it differs).
