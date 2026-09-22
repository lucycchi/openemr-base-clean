[![Syntax Status](https://github.com/openemr/openemr/actions/workflows/syntax.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/syntax.yml)
[![Styling Status](https://github.com/openemr/openemr/actions/workflows/styling.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/styling.yml)
[![Testing Status](https://github.com/openemr/openemr/actions/workflows/test.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/test.yml)
[![JS Unit Testing Status](https://github.com/openemr/openemr/actions/workflows/js-test.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/js-test.yml)
[![PHPStan](https://github.com/openemr/openemr/actions/workflows/phpstan.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/phpstan.yml)
[![Rector](https://github.com/openemr/openemr/actions/workflows/rector.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/rector.yml)
[![ShellCheck](https://github.com/openemr/openemr/actions/workflows/shellcheck.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/shellcheck.yml)
[![Docker Compose Linting](https://github.com/openemr/openemr/actions/workflows/docker-compose-lint.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/docker-compose-lint.yml)
[![Dockerfile Linting](https://github.com/openemr/openemr/actions/workflows/docker-lint-hadolint.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/docker-lint-hadolint.yml)
[![Isolated Tests](https://github.com/openemr/openemr/actions/workflows/isolated-tests.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/isolated-tests.yml)
[![Inferno Certification Test](https://github.com/openemr/openemr/actions/workflows/inferno-test.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/inferno-test.yml)
[![Composer Checks](https://github.com/openemr/openemr/actions/workflows/composer.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/composer.yml)
[![Composer Require Checker](https://github.com/openemr/openemr/actions/workflows/composer-require-checker.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/composer-require-checker.yml)
[![API Docs Freshness Checks](https://github.com/openemr/openemr/actions/workflows/api-docs.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/api-docs.yml)
[![codecov](https://codecov.io/gh/openemr/openemr/graph/badge.svg?token=7Eu3U1Ozdq)](https://codecov.io/gh/openemr/openemr)

[![Backers on Open Collective](https://opencollective.com/openemr/backers/badge.svg)](#backers) [![Sponsors on Open Collective](https://opencollective.com/openemr/sponsors/badge.svg)](#sponsors)

# OpenEMR

[OpenEMR](https://open-emr.org) is a Free and Open Source electronic health records and medical practice management application. It features fully integrated electronic health records, practice management, scheduling, electronic billing, internationalization, free support, a vibrant community, and a whole lot more. It runs on Windows, Linux, Mac OS X, and many other platforms.

## Clinical Co-Pilot (AgentForge Week 1)

This fork adds a Clinical Co-Pilot: a verified pre-room briefing and chart
Q&A panel on the patient dashboard for primary care physicians. The language
model never generates clinical facts; deterministic PHP assembles cited
facts, the model narrates by fact id, and every sentence is verified before
it renders.

All Clinical Co-Pilot documentation lives in [clinical_copilot/](clinical_copilot/)
— start with [clinical_copilot/README.md](clinical_copilot/README.md).

| Document | Purpose |
|---|---|
| [clinical_copilot/USING_CLINICAL_COPILOT.md](clinical_copilot/USING_CLINICAL_COPILOT.md) | How to use the feature: setup, reading the panel, follow-ups, every status message, demo patients |
| [USERS.md](USERS.md) | The physician, their workflow, three use cases and why an agent |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Summary, data flow, verification, trust boundaries, failure modes |
| [KEY_METRICS.md](KEY_METRICS.md) | Five metrics with baselines and alert thresholds |
| [AUDIT.md](AUDIT.md) | Security, performance, architecture, data-quality and HIPAA audit |
| [clinical_copilot/DESIGN.md](clinical_copilot/DESIGN.md) | Design record with the reviewed decisions |
| [clinical_copilot/api-collection/](clinical_copilot/api-collection/README.md) | Runnable Bruno API collection: every endpoint and workflow, with assertions |
| [clinical_copilot/EVALS.md](clinical_copilot/EVALS.md) | Test suite with results: structure, scope, design decisions |
| [tests/evals/README.md](tests/evals/README.md) | Eval suite: cases, failure modes, how to run |
| [docker/vps/README.md](docker/vps/README.md) | Deployment |
| [TODOS.md](TODOS.md) | Known debt and deferred work, including the two open items from the Week 1 grader feedback |
| [docs/designs/week2-multimodal-evidence-agent.md](docs/designs/week2-multimodal-evidence-agent.md) | Week 2 plan (approved design doc with the phased TODO list) |

**Deployed:** https://146-190-139-37.sslip.io (login `admin`; demo data). Health: [/health](https://146-190-139-37.sslip.io/interface/modules/custom_modules/oe-module-clinical-copilot/public/health.php) · [/ready](https://146-190-139-37.sslip.io/interface/modules/custom_modules/oe-module-clinical-copilot/public/ready.php)

**Try it locally:** bring up the dev stack (below), then register the module:

```bash
M=interface/modules/custom_modules/oe-module-clinical-copilot/sql
openemr-cmd e "cd /var/www/localhost/htdocs/openemr/$M && grep -v '^#' install.sql | mariadb -h mysql -uopenemr -popenemr openemr && mariadb -h mysql -uopenemr -popenemr openemr < register.sql"
```

Put `OPENAI_API_KEY=...` in a root `.env` (git-ignored). Log in as `admin`/`pass`,
open a patient, open their latest encounter, then Dashboard: the panel is at
the top. Health: `/interface/modules/custom_modules/oe-module-clinical-copilot/public/health.php`
and `.../ready.php`.

### Running the App Locally

OpenEMR is a server-rendered PHP app (not a separate frontend/backend split), so
"running the app" means two independent, long-running processes side by side:
the Docker stack (PHP/Apache backend + MySQL + supporting services), and a
webpack watcher on the host that compiles theme assets the backend serves.

**Prerequisites (one-time):**
- [Docker Desktop](https://docs.docker.com/desktop/) installed, with WSL2
  integration enabled for your distro if on Windows/WSL2
- [`openemr-cmd`](https://github.com/openemr/openemr-devops/tree/master/utilities/openemr-cmd)
  installed and on your `PATH` — the canonical CLI for the dev Docker stack:
  ```bash
  curl -L https://raw.githubusercontent.com/openemr/openemr-devops/master/utilities/openemr-cmd/openemr-cmd -o ~/.local/bin/openemr-cmd
  curl -L https://raw.githubusercontent.com/openemr/openemr-devops/master/utilities/openemr-cmd/openemr-cmd-h -o ~/.local/bin/openemr-cmd-h
  chmod +x ~/.local/bin/openemr-cmd ~/.local/bin/openemr-cmd-h
  ```

**Run first — the Docker stack** (backend, database, phpMyAdmin, etc.). This
builds/pulls images on first run, which takes a few minutes; on later runs
it's fast:

```bash
cd docker/development-easy
openemr-cmd up
```

Wait for it to finish starting (watch progress with
`docker compose logs -f openemr` in a separate terminal), then the app is
reachable at:
- App: http://localhost:8300/ or https://localhost:9300/ — login `admin` / `pass`
- phpMyAdmin: http://localhost:8310/

**Run second (optional, in a separate terminal) — the asset watcher.** Only
needed if you're editing SASS/JS themes and want them rebuilt automatically:

```bash
npm install
npm run dev
```

This does not require or block on the Docker stack, but the app won't show
your latest frontend changes until this has rebuilt them, so start the
Docker stack first, then this, if you're actively editing styles/JS.

**Stopping and resuming** (preserves data, faster than `up`/`down`):

```bash
openemr-cmd stop     # pause all containers
openemr-cmd start    # resume all containers
```

`openemr-cmd stop`/`start` only affects the Docker-managed services above —
`npm run dev` is a separate host process you start/stop independently.

**Loading sample patient data.** The database (MariaDB) lives on a named
Docker volume (`databasevolume` in
[docker-compose.yml](docker/development-easy/docker-compose.yml)), so any
data you load persists across `stop`/`start` and `down`/`up`. Only
`docker compose down -v` or an explicit `dev-reset*` wipes it. To seed a
realistic dataset once, then snapshot it so you can reset to it any time:

```bash
openemr-cmd dev-reset-install-demodata   # alias: drid — curated demo patients,
                                         # provider users with ACLs, portal logins
openemr-cmd import-random-patients 30    # alias: irp — Synthea-generated patients
                                         # with full clinical histories (~secs each)
openemr-cmd backup-snapshot baseline     # alias: bs — snapshot DB + sites/ files
```

Later, after a test run dirties the data:

```bash
openemr-cmd restore-snapshot baseline    # alias: rs — back to the seeded state and removes new data
openemr-cmd list-snapshots
```

To share the seeded state with a teammate or another machine:

```bash
openemr-cmd get-capsule baseline.tgz     # copy the snapshot out of the container
openemr-cmd put-capsule baseline.tgz     # ...and on the other machine, load it
openemr-cmd restore-snapshot baseline
```

Notes: `drid` is destructive (it resets the DB first), so run it on a fresh
stack. `irp` disables the audit log during import — dev stack only, never on
real data. Demo credentials are listed on the
[Development Demo wiki page](https://www.open-emr.org/wiki/index.php/Development_Demo#Demo_Credentials).
Hand-authored SQL seeds also work (see
[sql/example_patient_data.sql](sql/example_patient_data.sql) for the pattern;
load with
`mysql -h127.0.0.1 -P8320 -uopenemr -popenemr openemr < <file>` or via
phpMyAdmin at http://localhost:8310), but realistic charts span many tables,
so prefer `drid`/`irp`. See
[CONTRIBUTING.md](CONTRIBUTING.md) items 9–12 for details.

See [CONTRIBUTING.md](CONTRIBUTING.md) for the full setup guide, test
commands, and code-quality tooling.

### Contributing

OpenEMR is a leader in healthcare open source software and comprises a large and diverse community of software developers, medical providers and educators with a very healthy mix of both volunteers and professionals. [Join us and learn how to start contributing today!](https://open-emr.org/wiki/index.php/FAQ#How_do_I_begin_to_volunteer_for_the_OpenEMR_project.3F)

> Already comfortable with git? Check out [CONTRIBUTING.md](CONTRIBUTING.md) for quick setup instructions and requirements for contributing to OpenEMR by resolving a bug or adding an awesome feature 😊.

### Support

Community and Professional support can be found [here](https://open-emr.org/wiki/index.php/OpenEMR_Support_Guide).

Extensive documentation and forums can be found on the [OpenEMR website](https://open-emr.org) that can help you to become more familiar about the project 📖.

### Reporting Issues and Bugs

Report these on the [Issue Tracker](https://github.com/openemr/openemr/issues). If you are unsure if it is an issue/bug, then always feel free to use the [Forum](https://community.open-emr.org/) and [Chat](https://www.open-emr.org/chat/) to discuss about the issue 🪲.

### Reporting Security Vulnerabilities

Check out [SECURITY.md](.github/SECURITY.md)

### API

Check out [API_README.md](API_README.md)

### Docker

Check out [DOCKER_README.md](DOCKER_README.md)

### FHIR

Check out [FHIR_README.md](FHIR_README.md)

### For Developers

If using OpenEMR directly from the code repository, then the following commands will build OpenEMR (Node.js version 24.* is required) :

```shell
composer install --no-dev
npm install
npm run build
composer dump-autoload -o
```

### Contributors

This project exists thanks to all the people who have contributed. [[Contribute]](CONTRIBUTING.md).
<a href="https://github.com/openemr/openemr/graphs/contributors"><img src="https://opencollective.com/openemr/contributors.svg?width=890" /></a>


### Sponsors

Thanks to our [ONC Certification Major Sponsors](https://www.open-emr.org/wiki/index.php/OpenEMR_Certification_Stage_III_Meaningful_Use#Major_sponsors)!


### License

[GNU GPL](LICENSE)
