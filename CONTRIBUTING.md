# Contributing

This is development version 0.1.0. Discuss changes through repository issues or pull requests. General organizational contact is available at https://lofranotecnologia.com.br. Report vulnerabilities privately as described in SECURITY.md.

## Local checks

Use the qualified Linux PHP 8.4 toolchain described in README.md. Install dependencies without plugins or scripts:

```sh
composer install --no-plugins --no-scripts
php tests/run.php
php bin/booklet.php demo --assert --json --output /tmp/booklet-review
```

The output directory must be new. Inspect the final `/tmp/booklet-review/bundles/eight/first-side.png` visually, read the completed map/report and independently compare the extracted-text hashes and samples. Do not claim visual review merely because a renderer exited successfully. Docker commands in the README provide the same offline test/demo execution after dependency installation.

Keep test data synthetic. Do not submit private client PDFs, real personal data, secrets, screenshots, copied proprietary code or unverified printing claims. Fixture generation should use maintained PDF libraries/qpdf JSON, not a new raw-PDF parser. Preserve empty JSON dictionaries as objects when writing qpdf JSON.

Add regression tests for externally observable behavior and failures. Changes to process supervision must retain signal-before-reap ordering, bounded capture, cleanup reporting and a fixed command surface; exercise actual native processes, not only mocks. Changes to inspection must specify the exact accepted/rejected subset and include unreferenced-object tests. Update docs/contract.md before changing public behavior.

Use strict PHP types, four-space indentation, stable public error codes and no raw native diagnostics containing source paths or content. Avoid new dependencies, runtime networking, publish/deployment workflows and arbitrary executable configuration. Helpers must locate package assets independently of caller CWD, including Composer-installed packages.

Record actual installed Composer/native versions and lock hashes when qualifying a new environment. Do not fabricate lockfiles, visual reviews, performance measurements or compatibility claims. The public Dockerfile pins the PHP base and Composer artifact; apt repositories remain a reproducibility limitation documented in docs/provenance.md.

Contributions must be original and distributable under MIT. Third-party dependency licenses remain their own.
