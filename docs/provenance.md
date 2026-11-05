# Dependency qualification and reproducibility

Application code and synthetic fixture generators in this repository are original MIT code, copyright 2026 Lofrano Tecnologia. FPDI, FPDF and native/runtime dependencies retain their separate upstream licenses. No third-party source is vendored here.

## Qualified preparation baseline

The following dependency preparation was supplied as an exercised baseline, not as a test run of this application:

| Component | Qualified version |
| --- | --- |
| PHP | 8.4.27 NTS |
| Composer | 2.11.1 |
| FPDI | 2.6.9 |
| FPDF | 1.9.0 |
| qpdf | 11.3.0, JSON v2 |
| Poppler | 22.12.0 |
| Python | 3.11.2, Linux stdlib |
| GD | PHP GD extension; record installed build using `php --ri gd` |

Official Composer PHAR SHA-256: `6f1b891e1d4927d62740276c540fb490e47689e19e102c49e8da849794882f8c`.

The committed `composer.lock` was resolved by Composer against the exact package requirements, with plugins and scripts disabled. The archive configuration excludes dependency/build trees and local credentials; runtime helpers and metadata remain package inputs.

Dependency probes established the square-page placement, qpdf JSON fields, object-stream normalization and CropBox/Rotate dimensions. Application regression tests subsequently exercised those behaviors on the generated library. The application's synthetic eight-page output was rendered with Poppler at 72 dpi and all four side PNGs were inspected directly: the left/right pairs are P8/P1, P2/P7, P6/P3 and P4/P5, with legible markers and white top/bottom margins. This is inspection of that sample, not certification of arbitrary documents, printers or accessibility. The CLI itself never claims human visual inspection.

## Record the actual installation

```sh
composer install --no-plugins --no-scripts
composer --version
composer show setasign/fpdi
composer show setasign/fpdf
sha256sum composer.lock
php --version
php --ri gd
qpdf --version
pdftoppm -v
pdftotext -v
python3 --version
php tests/run.php
php bin/booklet.php demo --assert --json --output /tmp/booklet-qualification
```

Keep the actual lock and version record with the installation's qualification evidence. Completed validation reports record PHP, GD, both installed PHP library versions, qpdf and Poppler version output, plus the package-local lock hash when present. A Composer dependency installation may have only its consuming project's lock; the package-local field is then null, not a fabricated value. Composer itself is only needed at installation, not at runtime.

The Dockerfile pins the PHP base by digest and verifies the official Composer PHAR. Exact library versions are in composer.json. Debian apt package repositories are mutable: this is a repeatable preparation recipe for the qualified family, not a claim of bit-for-bit reproducible OS packages. Inspect build version output; newly resolved native versions require requalification. Runtime execution is offline; CI explicitly disables container networking for tests and demo.

Native executables are fixed at `/usr/bin/qpdf`, `/usr/bin/pdftotext`, `/usr/bin/pdftoppm`, `/usr/bin/python3`. The PHP worker accepts only the parent runtime path `/usr/local/bin/php` or `/usr/bin/php8.4`. The Docker target uses the former. Other locations/platforms require a reviewed code change and lifecycle qualification, not a caller-supplied executable override.

Upstream references:

- https://getcomposer.org/download/
- https://manuals.setasign.com/fpdi-manual/v2/
- https://manuals.setasign.com/fpdi-manual/v2/limitations/
- https://www.fpdf.org/
- https://qpdf.readthedocs.io/en/stable/json.html
- https://poppler.freedesktop.org/
