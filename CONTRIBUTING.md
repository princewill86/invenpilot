# Contributing

Use the setup instructions in [README.md](README.md). Keep frontend API calls in the API client and enforce validation, permissions, and business isolation on the server.

- Run the relevant backend feature tests and frontend checks before opening a pull request.
- Include the expected result and an automated regression test for changes to stock, money, or authorization.
- Use a dedicated MySQL test database for data isolation, locking, and transaction tests.
- Use forward migrations for schema changes. Keep stock changes in the ledger service and preserve historical order snapshots.
- Use descriptive commits and describe deployment or migration requirements in the pull request.

For security-sensitive issues, see [SECURITY.md](SECURITY.md).
