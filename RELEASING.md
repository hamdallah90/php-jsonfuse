# Releasing JsonFuse

## Versions and tags

Versions follow semantic versioning. `1.0.0` was tagged directly.

| Tag | Use |
| --- | --- |
| `1.x.y` patch | fixes only, no API change |
| `1.x.0` minor | backward-compatible additions |
| `2.0.0` | a breaking change |
| `1.x.0-alpha.N`, `-beta.N`, `-rc.N` | optional pre-releases of a minor or major, for changes that want feedback first; any code change after the last pre-release makes the next number |

A tag goes on a commit whose CI run is green, and the release commit changes nothing but the version string (`PHP_JSONFUSE_VERSION` in `src/jsonfuse.c`). A new PHP minor is added to the supported range in a release of its own once it is released and CI is green on it.

## Approval

Each of these needs the maintainer's explicit approval at the time, never in advance:

- creating each tag;
- submitting to Packagist.

## Steps for every tag

1. **Green commit.** Use a commit on the main branch whose full CI matrix is green: every blocking job passed on that exact commit.
2. **Version string.** `PHP_JSONFUSE_VERSION` in `src/jsonfuse.c` matches the tag (a leading `v` in the tag aside), and a build reports it:

   ```sh
   php -d extension=modules/jsonfuse.so -r 'echo phpversion("jsonfuse"), "\n";'
   ```

3. **Changelog.** `CHANGELOG.md` has an entry for the version.
4. **Package check.** The archive holds no excluded path, and the extension builds and loads from it:

   ```sh
   git archive --format=tar HEAD | tar -t | grep -E '^(tests|fuzz|benchmarks|\.github)/' && echo "FAIL: excluded path in archive"
   dir=$(mktemp -d) && git archive --format=tar HEAD | tar -x -C "$dir" && cd "$dir" \
     && phpize && ./configure && make \
     && php -d extension=modules/jsonfuse.so -m | grep -x jsonfuse
   ```

   The first command must print nothing; the second must end by printing `jsonfuse`.
5. **Benchmarks.** Every published number comes from that commit with provenance: `benchmarks/run.sh` on a clean checkout of the commit, whose `raw.log` starts with `PROVENANCE` lines showing `publishable=yes`, the commit, an empty `git status --porcelain`, the `.so` SHA-256 and `php --ri jsonfuse` (see `benchmarks/README.md`). Numbers from any other build are not published.

Then, with the maintainer's approval for this tag, create an annotated tag on that commit. Pushing the tag and submitting to Packagist are separate approvals.
