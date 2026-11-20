# Forger (site-forger)

Rules for everyone who works on this repository, people and coding agents.

## Writing
- Write in simple technical English: code comments, docblocks, documentation and commit messages.
- Use short sentences and common words. Prefer the active voice. Put one idea in each sentence.
- Name the class, method, file or setting you mean, for example `ApiClient::get()`.

## Code
- Write code that is easy to read: clear names, small methods, simple control flow.
- Add a comment only when the code cannot say it: a reason, a constraint of an external system, a non-obvious decision. Do not repeat in a comment what the code already says.

## Commits
- Keep commit messages short and factual. Describe only what the commit changes.
- Write the body of a commit message in sentences. Avoid lists.
- Keep every line of a commit message at 72 characters or less, the subject included.
- Use short class names in commit messages, without the namespace.
- Do not add `Co-Authored-By` or other attribution trailers to commit messages.

## Checks
- Tests: `bin/phpunit -c build/phpunit.xml`
- Code style: `composer t3g:cgl`
- Static analysis: `bin/phpstan analyse -c build/phpstan.neon`
- Templates: `bin/console lint:twig templates`
- Assets: `ddev exec npm run dev`

## Conventions
- Enums: the value is the source system's id (Redmine id, Gerrit value), `label()` returns the name, `severity()` the style key. Each severity needs a rule in `assets/css/module/_status.scss`, and a test checks that.
- Elasticsearch date histograms need `extended_bounds` in the format of their keys. Align series through `App\Charts\TimeSeries`.
