# Forger (site-forger)

## Checks
- Tests: `bin/phpunit -c build/phpunit.xml`
- Code style: `composer t3g:cgl`
- Templates: `bin/console lint:twig templates`
- Assets: `ddev exec npm run dev`

## Conventions
- Enums: the value is the source system's id (Redmine id, Gerrit value), `label()` returns the name, `severity()` the style key. Each severity needs a rule in `assets/css/module/_status.scss`, and a test checks that.
- Elasticsearch date histograms need `extended_bounds` in the format of their keys. Align series through `App\Charts\TimeSeries`.
