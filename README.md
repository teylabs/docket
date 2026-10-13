# Docket: Long-Running Work Tracking for Laravel

[![Tests](https://img.shields.io/github/actions/workflow/status/teylabs/docket/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/teylabs/docket/actions/workflows/run-tests.yml)
[![Code Style](https://img.shields.io/github/actions/workflow/status/teylabs/docket/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/teylabs/docket/actions/workflows/fix-php-code-style-issues.yml)

Docket is being developed to track long-running Laravel work and show its progress, from dispatch to result.

> [!NOTE]
> Docket is in early development, with no release and nothing usable yet. This repository contains only the package skeleton, tooling and a smoke test.

## Installation

The package targets PHP 8.3+ and Laravel 12 or 13. There is no release to install yet.

## Testing

From a checkout of this repository:

```bash
composer install
composer validate --strict
composer test
composer analyse
composer lint
composer check-docs
# -> validates metadata, runs the smoke test, checks PHPStan and Pint, and verifies the document index
```

## Changelog

See [CHANGELOG](CHANGELOG.md).

## Contributing

See the shared [contributing guide](https://github.com/teylabs/.github/blob/main/CONTRIBUTING.md) and [agent guidance](AGENTS.md). Bugs and ideas go to [Issues](https://github.com/teylabs/docket/issues).

## Security Vulnerabilities

Please follow the shared [security policy](https://github.com/teylabs/.github/blob/main/SECURITY.md). Do not report vulnerabilities in public issues.

## Credits

- [Jasper Tey](https://github.com/jaspertey)
- [All Contributors](https://github.com/teylabs/docket/graphs/contributors)

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
