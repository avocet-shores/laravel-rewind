# Contributing

Contributions are welcome and will be fully credited.

## Pull Requests

- **Fork the repo** and create your branch from `main`.
- **Add tests** for any new functionality. We use [Pest](https://pestphp.com/).
- **Ensure the test suite passes** before submitting:
  ```bash
  composer test
  ```
- **Run static analysis** to catch type errors:
  ```bash
  composer analyse
  ```
- **Follow the existing code style.** We use [Laravel Pint](https://laravel.com/docs/pint):
  ```bash
  composer format
  ```
- **One pull request per feature.** If you want to do more than one thing, send multiple PRs.
- **Write a clear PR description** explaining the change and why it's needed.

## Bug Reports

When filing an issue, include:

- PHP and Laravel version
- Steps to reproduce
- Expected vs. actual behavior
- Relevant stack trace or error output

## Development Setup

```bash
git clone https://github.com/avocet-shores/laravel-rewind.git
cd laravel-rewind
composer install
composer test
```

## Code of Conduct

Please be respectful and constructive in all interactions. We're all here to build something useful.
