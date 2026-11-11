# Security policy

## Reporting a vulnerability

Report privately through the repository's GitHub private vulnerability reporting: the **Security** tab, then **Report a vulnerability**. Do not open a public issue, pull request or discussion for a suspected vulnerability.

Include the PHP version and build (NTS or ZTS, `php -v`, `php --ri jsonfuse`), the plan, and the smallest input that shows the problem. A report is easier to act on with a PHPT file or a script that reproduces it.

## Response and disclosure

The first reply to a report comes within 7 days. The vulnerability is disclosed publicly once a release that fixes it is out, or 90 days after the report, whichever comes first.

## Supported versions

Security fixes are made for the latest 1.x minor release only. Pre-release versions (`alpha`, `beta`, `rc`) and 0.x releases receive no fixes once a newer release exists.

## In scope

- Memory safety in the extension's own code: out-of-bounds reads or writes, use-after-free, double free, uninitialised memory reaching output, leaks across requests.
- Bound violations: native memory, buffering or output size beyond the bounds documented in `SPEC.md` and the README, a record accepted past `maxRecordBytes`, or any limit of `SPEC.md` not enforced.
- Record bytes appearing in an exception message, or plan values echoed in a plan exception message.
- Input that JsonFuse accepts although `SPEC.md` says it must be rejected (invalid JSON or UTF-8, a duplicate referenced key, a wrong type).

## Out of scope

- The PHP core issues listed under "What is not defended against" in `docs/THREAT_MODEL.md`, for example `pclose()` of a stream from its own callback, which is a use-after-free in PHP core that the extension cannot prevent.
- Behaviour of PHP code the application runs inside the pipeline: stream wrappers, `php_user_filter` filters and error handlers on either stream.
- A `Plan` built from attacker input; the plan is assumed to come from the application.
- Speed and memory use within the documented bounds.
- Vulnerabilities in yyjson itself, which should be reported upstream; JsonFuse pins it (`third_party/yyjson/PIN.txt`) and rechecks advisories at each release.
