# Booklet Imposition Pack

Development version **0.1.0**, by [Lofrano Tecnologia](https://lofranotecnologia.com.br). MIT. [Português](README.pt-BR.md).

A PHP Composer library and local CLI that import selected static PDF pages into 4-, 8- or 16-page signatures. It produces a PDF, explicit page/blank map, independent validation report and first-side PNG in one completed directory. No server, browser, telemetry or online runtime service.

## Install and exercise

The qualified target is Linux, PHP 8.4, GD, Python 3 stdlib, qpdf 11.3 JSON v2 and Poppler 22.12. Exact PHP dependencies are FPDI 2.6.9 and FPDF 1.9.0. Python retains child-process ownership; PHP remains the application runtime. Native paths and PHP binary locations are deliberately restricted; see [contract](docs/contract.md).

Using Docker with an unprivileged application user:

```sh
docker build --tag booklet-imposition-pack .
docker run --rm --network none booklet-imposition-pack php tests/run.php
docker run --rm --network none booklet-imposition-pack php bin/booklet.php demo --assert --json
```

Or, with that toolchain installed locally:

```sh
composer install --no-plugins --no-scripts
php tests/run.php
php bin/booklet.php demo --assert
php bin/booklet.php demo --assert --json --output /tmp/booklet-qa
```

`--output` must name a new directory whose parent exists. The demo retains its synthetic source and completed bundle, including `first-side.png`, for read-only visual review. It performs structural, extracted-text and decoded-pixel checks; it does **not** perform a human visual inspection. Open the final PNG locally as part of QA, not merely its extracted text.

Representative arithmetic and sample output (the temporary path varies):

```text
Booklet Imposition Pack development 0.1.0
Verified: yes
Sheets: 2; sides: 4
Pairs: [8,1] [2,7] [6,3] [4,5]
First side: 600 x 400 pixels; markers P8 P1
Samples: 160,160,160 / 20,20,20 / 255,255,255
```

The actual command additionally prints its bundle path and the visual-inspection limitation. `--json` emits one parseable JSON result, including the same pairs and actual samples. Errors are stable codes on stderr with exit status 1. Tests exercise the real native tools and a fixed failure helper; they do not require a network.

## Library

```php
require 'vendor/autoload.php';

$pack = new Lofrano\Booklet\Imposer();
$result = $pack->impose(
    '/tmp/source.pdf',
    [2, 3, ['page' => 4, 'label' => 'Oficina — revisão'], 5],
    4,
    ['width' => 600, 'height' => 400, 'back_rotation' => 0],
    ['root' => '/tmp/booklet-output', 'name' => 'manual']
);
echo $result['directory'];
```

Create `/tmp/booklet-output` first. Sources must be regular local files outside the owned output root, without symlink path components. Selection order and repeated occurrences are preserved; labels are UTF-8 metadata, not filenames or replacement page text. Existing bundle names are never overwritten.

CLI equivalent for inclusive pages 2 through 5:

```sh
mkdir /tmp/booklet-output
php bin/booklet.php impose --input /tmp/source.pdf --pages 2-5 --signature 4 --width 600 --height 400 --root /tmp/booklet-output --name manual --json
php bin/booklet.php recover --root /tmp/booklet-output --json
```

Recovery is explicit: it removes only this namespace's valid, marked staging directories, under the writer lock. Do not put inputs inside the output namespace. Readers consume only named directories containing `.booklet-complete.json`, never `.booklet-stage-*` directories.

## Why this design?

FPDI/FPDF handle imported content and page geometry; qpdf inspects all objects before normalization, including unreferenced ones. qpdf disables object streams for the free FPDI parser. Poppler independently extracts text and renders every output side; GD checks image dimensions and samples. A small retained Linux supervisor makes FPDI parsing preemptible and terminates owned process groups before reaping their leaders. Tools run sequentially. [Design](docs/design.md) explains the lifecycle.

For pages 1–5 with signature 8, output pairs are `[blank,1] [2,blank] [blank,3] [4,5]`. A blank is `null` occurrence plus `blank: true`, never page zero. A square 200 pt source on a 600 × 400 pt sheet becomes 300 × 300 pt at top 50, left 0 or 300.

## Limits and honest scope

Defaults are fixed: 16 MiB source, 64 selected occurrences, 32 sides, 20-second monotonic workflow budget, 256 MiB PHP worker memory limit, bounded native capture and files. Sheet dimensions are 72–1440 pt. Mature parsing precedes page-count/object/geometry checks; none of these is a pre-parser memory guarantee or an OS sandbox. Kernel-level uninterruptible I/O can delay cleanup beyond the execution budget.

Only the conservative static subset in [docs/contract.md](docs/contract.md) is accepted. Encryption, annotations (even empty annotation arrays), forms, actions, embedded files, layers, named destinations and outlines are rejected. This is **not** a PDF sanitizer, broad PDF standards implementation, accessibility validator or print-quality certification. Arbitrary source glyphs stay inside imported PDF content; fixture labels alone use built-in Helvetica.

Sides use explicit back-view coordinates. Back rotation defaults to zero; optional 180 rotates the whole back page, not its individual slots. Logical pairs do not infer printer duplex settings, paper handling or universally correct binding orientation. Verify an actual printer setup separately.

The local filesystem and all participating writers must cooperate: no live root/source edits or hostile symlink races. Publication uses a same-filesystem rename under `flock`, not a durable database transaction. Resource admission checks are not physical directory quotas. Completed PDFs need not be byte-identical across library/native versions; maps use binary64 coordinates and a documented 0.02 pt serialization tolerance.

Dependency qualification and version-recording instructions: [provenance](docs/provenance.md). Security reports: [private GitHub reporting](https://github.com/martinlofranodeoliveira/booklet-imposition-pack/security/advisories/new). General contact: [official homepage](https://lofranotecnologia.com.br).
