# Security policy

## Reporting a vulnerability

Do not open a public issue. Use GitHub's private vulnerability reporting: open the repository's **Security** tab and choose **Report a vulnerability**.

Please include the affected provider or component, the version or commit, and a minimal reproduction. Never include real credentials or customer data. You should receive an acknowledgement within a few days.

## Supported versions

Security fixes are released for the latest major version.

## Scope

In scope: anything in this package that could cause a payment to be accepted without the provider confirming it, a callback to change the verified amount or order, credentials to leak, or requests to reach an unintended host.

Out of scope: vulnerabilities in the providers' own services, and integration mistakes that the README's security section warns about. For example, fulfilling an order without calling `verify()`, or reusing a SEP `RefNum`.
