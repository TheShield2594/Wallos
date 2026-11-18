# Security Policy

## Reporting a Vulnerability

If you discover a security vulnerability in Tally, please report it privately through GitHub: open the repository's **Security** tab and choose **Report a vulnerability** ([direct link](https://github.com/TheShield2594/Wallos/security/advisories/new)). Please don't open a public issue for security problems.

If the vulnerability also affects upstream [Wallos](https://github.com/ellite/Wallos), please report it there as well.

## Supported Versions

Security fixes go into `main` and ship in the next Tally release. Only the latest release is supported; please upgrade before reporting a problem in an older one.

| Version                                   | Supported          |
| ----------------------------------------- | ------------------ |
| Latest `tally-v1.x` release               | :white_check_mark: |
| `main` (the `ghcr.io/theshield2594/tally:main` image) | :white_check_mark: |
| Older `tally-v1.x` releases               | :x:                |
| Wallos releases (`v5.8.3` and earlier)    | :x: (report to [Wallos](https://github.com/ellite/Wallos)) |

## Security Measures

- The container image `ghcr.io/theshield2594/tally` is scanned with [Trivy](https://trivy.dev) every week and on every push to `main` ([workflow](.github/workflows/trivy.yaml)). Findings appear under the repository's **Security → Code scanning** tab.
- The image is rebuilt every week so it picks up Alpine security fixes, and can run rootless with a read-only root filesystem (see "Hardened containers" in the [README](README.md)).
- The test suite runs on every push, and no image is published from code whose tests fail.

## Responsible Disclosure

Please follow responsible disclosure practices and allow reasonable time to address a reported vulnerability before making it public.
