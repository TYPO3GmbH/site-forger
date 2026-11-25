# Forger (site-forger)

Rules for everyone who works on this repository, people and coding agents.

## Writing
- Write in simple technical English: code comments, docblocks, documentation and commit messages.
- Use short sentences and common words. Prefer the active voice. Put one idea in each sentence.
- Name the class, method, file or setting you mean, for example `ApiClient::get()`.

## Code
- Write code that is easy to read: clear names, small methods, simple control flow.
- Add a comment only when the code cannot say it: a reason, a constraint of an external system, a non-obvious decision. Do not repeat in a comment what the code already says.
- Keep lines of code at 120 characters or less. Break a long call, array or condition over several lines instead.
- When a call with several arguments gets long, put each argument on its own line. Do this in all code you change.

## Commits
- Keep commit messages short and factual. Describe only what the commit changes.
- Write the body of a commit message in sentences. Avoid lists.
- Keep every line of a commit message at 72 characters or less, the subject included.
- Use short class names in commit messages, without the namespace.
- Commit fixes as fixups into the commit that introduced the code. Ask before you rewrite commits that are already pushed.
- Do not add `Co-Authored-By` or other attribution trailers to commit messages.

## UI
- Use icons from the template bundle (`{{ icon('actions-…') }}`), not text symbols.
- The UI must fit phone viewports (575px and less).
- The My Work tabs must fit 375px: on phones they hide icons and counts.
- Every breadcrumb starts at "Dashboard". `_macros/breadcrumb.html.twig` adds it.
- A person card reuses `_partials/person_card.html.twig`. A person picker reuses `.person-search*`.
- `public/build/app.css` has no hash in its name: check a UI change after a hard reload.

## Checks
- Tests: `bin/phpunit -c build/phpunit.xml`
- Code style: `composer t3g:cgl`
- Static analysis: `bin/phpstan analyse -c build/phpstan.neon`
- Templates: `bin/console lint:twig templates`
- Assets: `ddev exec npm run dev`
- TypeScript in `assets/ts/`: `ddev exec npx tsc --noEmit`

## Deployment
- The CI deploys with `deploy.php` once its checks are green: a push to `develop` goes to stage.forger.typo3.com, a push to `master` to forger.typo3.com.
- Before the new release goes live, the deployment migrates the database, builds the cache of the release, pauses the jobs and runs `app:elasticsearch:setup`. A new field in `config/Elasticorn/*/Mapping.yaml` needs no step by hand.
- Check the deployment with every change, not only the code. Ask how the change goes live: the migrations, the cache, the worker of the schedule, the paused jobs and the paths the releases share. A change that works locally can fail in `deploy.php`.
- Each release has its own `var/cache`. The releases share only `var/log`, `var/lock`, `var/scheduler` and `var/backports.json`. Keep state that must outlive a release in one of them or in MySQL, not in the cache.
- After a push, follow the "Deployment" job of the CI until it is green. A failed deployment can leave a release live without its last steps, like the restart of the worker.
- A new version of a service (Elasticsearch, MySQL, PHP) updates every check of that version in the same change: the system checks like `ElasticsearchVersion`, their tests, the DDEV setup and the documentation. During an upgrade the checks accept the old and the new version, as long as one environment still runs the old one.
- Raise `ImportSchema::ISSUE` or `ImportSchema::REVIEW` when an import reads more data. The imports then load the older documents again, a share with each run.

## Releases
- Work goes to `develop` first. Check it on stage before it goes to `master`. Stage has the basic auth `foo:bar` and older data than live.
- A release merges `develop` into `master` with a merge commit, never a fast-forward: `git merge --no-ff develop` on `master` gives "Merge branch 'develop'".
- Tag the merge commit with the version, annotated and without a message: `git tag -a 7.4.0 -m ''`. Raise the minor version when the release has a `[FEATURE]`, the patch version when it has only fixes and tasks.
- Push `master` and the tag. Then merge `master` back into `develop` ("Merge branch 'master' into develop") and push `develop`.
- Ask before a release: a push to `master` goes live. The branch rules of GitHub ask for a pull request, but releases go as a direct push.

## Conventions
- Enums: back an enum only when its value goes outside: a source system's id (Redmine id, Gerrit value), a URL parameter or a database column. Otherwise use a pure enum. `label()` returns the name, `severity()` the style key. Not every enum needs `severity()`. Each severity needs a rule in `assets/css/module/_status.scss`, and a test checks that.
- Enums hold no Elasticsearch queries. Put those in `App\Search`.
- Repositories go in `App\Repository`, whatever the storage. Read Elasticsearch documents through the value objects in `App\Document`.
- Elasticsearch date histograms need `extended_bounds` in the format of their keys. Align series through `App\Charts\TimeSeries`.
- Every field of the API needs an OpenAPI description. `tests/Api/ApiDocumentationTest.php` checks that.
- The Forge token of Forger sees more than the public: private issues and notes, the relations to private issues, the files of private notes. None of this may reach a page or the API. Keep email addresses in storage for matching, but never show them on a page or in the API.
- Show detail data on detail pages only, like comments, files and the review log. Lists stay lean, in the UI and in the API.
- When the index already has the data of a new `ImportSchema` version, add a `SchemaStep` in `App\Import\Backfill`.
- A step that writes outside its document implements `BackfillStep`. Imports write a document before its events.
- Raise `StreamSchema::VERSION` when `StreamEvents` derives other events.
- Keep code that reads Elasticsearch answers apart from code that asks, like `StreamAggregations`, so tests need no Elasticsearch.
- Statistics widgets take a size class (`stream-widget is-s|m|l|xl|full`). Don't size them by hand.
- A dashboard list shows a part. "Show all" opens the search with the same filters. Build the filters beside the query, like `OverviewQuery::*Filters()`.
- Count figures about a person as `StreamFigureEnum` does, so they equal the statistics.
