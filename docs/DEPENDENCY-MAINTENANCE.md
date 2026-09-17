# Dependency Maintenance

How Exospace keeps its Composer and npm dependency trees reproducible and
free of known security advisories. The rules that make this work:

- Both lockfiles (`composer.lock`, `package-lock.json`) are committed and are
  the only source of what gets installed. Never delete a lockfile to "fix" an
  install problem — regenerate it with a scoped update instead.
- Coolify/Nixpacks builds run `npm ci --no-audit --no-fund`, which installs
  exactly the locked versions and fails if `package.json` and the lockfile
  disagree. Composer builds run `composer install --no-dev`, which behaves
  the same way for PHP packages.
- The `Dependency Audit` CI job fails on any known advisory
  (`composer audit --locked`, `npm audit`) and on manifest/lockfile drift
  (`composer validate --strict`). A red audit job must be resolved by a
  targeted update — not by ignoring the job or weakening the constraints.

## Applying a security update

Update only the affected packages and let their own constraints move their
transitive requirements. Do not run bare `composer update` / `npm update`,
which re-resolves the entire tree:

```bash
# PHP: example — update one vulnerable package plus its requirements
composer update vendor/package --with-all-dependencies
composer validate --strict && composer audit --locked

# Node: example — bump one package to the version that fixes the advisory
npm install package-name@<fixed-version>
npm audit
```

Commit the manifest and lockfile together in one change. Run the test suite
(`composer test`) and `npm run build` before pushing; CI re-runs both plus the
audit job.

## Rolling out dependency changes

No Coolify configuration change is required for routine dependency updates:
the build phase reads the lockfiles from the repository. If a build fails on
`npm ci` ("lock file out of sync"), the lockfile was not regenerated after a
`package.json` edit — run `npm install --package-lock-only` locally, verify
`npm ci && npm run build`, and commit the refreshed lockfile.

Rollback for a dependency change is `git revert` of the commit that touched
the manifests/lockfiles, then redeploy. There is no server-side state to
clean up: vendor/ and node_modules/ are rebuilt from the lockfiles on every
deploy.

## Before overlaying project files onto production

If project files are copied onto the repository from a local working copy or
backup archive rather than merged via git, verify that the incoming tree does
not silently replace a newer `composer.lock` or `package-lock.json` with an
older one (or remove the npm lockfile). A missing or downgraded lockfile
reopens every dependency to uncontrolled resolution on the next deploy. The
fastest check is `git status` / `git diff --stat` before committing an
overlay: lockfile changes must always be deliberate.
