# Reviewable AI menu import

A focused PHP/React code sample from a restaurant billing application. A restaurant owner uploads a printed menu, receives an editable draft, corrects uncertain fields, and explicitly saves dishes and portion prices. The engineering focus is the boundary between unreliable model output and persistent business data.

## Review in five minutes

1. Read `app/Services/MenuScanner.php`: configured provider fallback, bounded retry, strict JSON/schema validation, and uncertainty-preserving normalization.
2. Read `tests/MenuScannerValidationTest.php` and `tests/MenuScannerResilienceTest.php`: malformed output, false-string handling, negative prices, rate limits, complete failure, and cross-image merge behavior.
3. Read `integration/app/Http/Controllers/MenuImportController.php` and `integration/resources/js/menu-import/main.jsx`: separate extraction/review/commit stages and server-side validation.
4. Read [verification and limitations](docs/VERIFICATION.md), then inspect the synthetic fixtures and recorded provider output under `evaluation/`.
5. Optional companion: `app/Support/ColorExtractor.php` and `LogoProcessor.php`, with logo persistence paths under `integration/`.

## Run the offline component tests

Requires PHP 8.4+, Composer, GD, mbstring, DOM/XML and the usual Composer platform requirements. The committed lockfile was installed and tested with PHP 8.4.24. No API key, database, web server, or full application is needed.

```sh
composer install
composer test
```

Nine tests / 35 assertions passed. HTTP calls are faked and stray requests are prohibited. `tests/bootstrap.php` and `tests/TestCase.php` are small sample-only container adapters; application component files are copied from the updated original source.

Replay the recorded evaluation with Python 3 (standard library only):

```sh
python evaluation/score.py
python -m unittest discover -s evaluation -p "test_*.py"
```

Optional live extraction: provide `MISTRAL_API_KEY` or `OPENROUTER_API_KEY` through your environment, then run:

```sh
php bin/scan.php evaluation/fixtures/clear.png
```

This explicitly sends that image to the configured provider and can incur API charges. If both keys are set, OpenRouter is attempted first, then Mistral on failure. No credentials are included. TLS verification remains enabled. Models can be overridden with `MISTRAL_VISION_MODEL` and `OPENROUTER_VISION_MODEL`.

## Scope and provenance

The `app/` components are runnable through the supplied test harness. `integration/` contains source excerpts from the full Laravel/Filament/React application for review; it is **not** a separately runnable website. Its framework feature tests require the original app's tenancy setup, migrations, models and test infrastructure. The package does not claim to ship those dependencies or reproduce the full browser demo.

`docs/source-manifest.json` records relative paths and SHA-256 hashes against the updated original files. No tenant data, original environment file, API keys, or database is included. Screenshots show an isolated synthetic tenant.

## Design and results

- Treat AI extraction as a draft. Unknown dietary flags remain unknown until reviewed; a model's confident flag is still not proof.
- Reject malformed structure so alternate-provider fallback can run. Do not silently turn a broken response into an empty menu.
- Preserve price meaning: `-50` never becomes `50`, and ambiguous strings are left unresolved.
- Validate again on the server; a browser control is not a trust boundary.
- Check logo writes before changing settings, retain the previous logo on failure, and keep saturated bright colors in the palette.

Four live Mistral scans matched 24 expected dish instances across four synthetic images, including eight portion variants. The clear and blurred images reuse six dishes, so there are only 18 distinct dishes. This is a small controlled evaluation, **not a production accuracy estimate**. See [measured results](docs/VERIFICATION.md).

## AI assistance

AI coding tools were used extensively to generate the implementation, prepare fixes, tests, evaluation tooling and this documentation. This sample is presented transparently as AI-assisted work. It should not be interpreted as evidence that every line was independently authored by hand. The accompanying walkthrough explains the behavior and its remaining limits.

See [design decisions](docs/DESIGN.md) and [two-minute demo script](docs/DEMO.md).
