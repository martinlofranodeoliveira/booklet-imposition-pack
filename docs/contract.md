# Development 0.1.0 contract

This document defines the supported subset, not general PDF compliance. Public entry point: `Lofrano\Booklet\Imposer::impose(inputPdf, orderedOneBasedPages, signatureSize, sheetGeometry, safeOwnedOutputOptions)`. Return value has `directory`, `map`, `validation`. Failures throw `Failure` with a stable `reason`; they do not publish a completed directory. PHP type errors for incorrect parameter types are normal PHP API errors.

## Selection and signatures

Selection is a nonempty PHP list, at most 64 entries. Each entry is an integer page or a record with `page` and optional nullable `label`. Pages are one-based integers, at most 4096, and must exist according to mature inspection. Each occurrence receives a unique one-based ordinal even when page numbers repeat. Label strings must be valid UTF-8, at most 128 bytes, without NUL. Unknown record keys are rejected. Labels never affect paths or PDF font encoding.

Signatures are exactly 4, 8 or 16 pages. Consecutive selection chunks are padded independently with null occurrences to the full signature size. For local zero-indexed padded chunk of size N, sheet i has front indices `[N-1-2i,2i]`, back indices `[2i+1,N-2-2i]`. Emit outer sheet front, outer sheet back, then successive inner sheets; repeat for each signature. At most 32 sides. A side contains exactly two slots. Blank slots have `blank: true`, null occurrence, source size and placements; they add no source XObject or marker to the PDF.

Examples:

| Selection / signature | Pairs |
| --- | --- |
| 1–8 / 8 | [8,1], [2,7], [6,3], [4,5] |
| 1–5 / 8 | [null,1], [2,null], [null,3], [4,5] |
| 2–5 / 4 | [5,2], [3,4] |

CLI selections allow ascending inclusive `2-5` ranges and comma-separated ordered occurrences such as `2,2,5,1`. No implicit sorting or deduplication.

## Geometry and side view

`sheetGeometry` has `width`, `height`, optional integer `back_rotation` (0 default or 180). No other keys. Dimensions are finite numbers in [72,1440] points, 72 points per inch. Left slot is `[0,0,W/2,H]`; right slot is `[W/2,0,W/2,H]`. Map and FPDF placements use a top-left origin, x right, top downward. FPDF converts into PDF bottom-origin coordinates internally: an unrotated rectangle's bottom is `H-top-height`.

Each side is described while looking directly at that side with its top edge up. A back is not a transparency view from the front. No automatic mirroring, tumble, binding or printer duplex inference occurs. Logical slot pairs remain unchanged when back rotation is requested. FPDF's whole-page `/Rotate 180` rotates the back in the PDF viewer/renderer. Its effective rectangle is `(W-x-width,H-top-height,width,height)`; page content orientation rotates too. Front rotation is always zero.

FPDI imports `CROP_BOX` with group XObjects enabled and external link import disabled, then obtains `getTemplateSize`. CropBox, its fallback, and source `/Rotate` handling are delegated to FPDI. Selected effective source dimensions must be finite, positive and no greater than 14400 pt each. Unsupported nonunit or indirect `/UserUnit` values are rejected during inspection; explicit numeric 1 is accepted. Source `/Rotate` must be a direct integer multiple of 90 within [-360,360].

Scale is `min((W/2)/sourceWidth,H/sourceHeight)`. Each proportional result is centered on both axes. A 200 × 200 pt source on 600 × 400 pt yields x 0/300, top 50, width/height 300. JSON coordinates round-trip binary64 through PHP JSON. PDF numeric serialization is not canonical; allow 0.02 pt coordinate tolerance. No distortion, bleeds, gutters, crop marks, creep compensation or arbitrary editing.

## Qualified inspection

The original bounded private copy is inspected using qpdf 11.3 `--json=2 --json-stream-data=none`. Exit 0 is required for every non-version tool; exit 2 errors, exit 3 warnings, other nonzero exits, and nonempty stderr are failures. No password/decrypt option is used for customer inputs. Password-protected inspection failures are rejected as tool failures; inspectable encrypted inputs are rejected by `encrypt.encrypted`.

Required schema: JSON version 2; `qpdf` header `jsonversion:2`; exactly one full object dictionary following the header; `encrypt.encrypted:false`; `acroform.hasacroform:false`; empty attachments and outlines. Pages must number 1–4096. Maximum object ID 32768, at most 32769 object/trailer entries, JSON decoding depth 128, traversal depth 100 and 250000 traversed nodes. Every ordinary object's `value` and stream dictionary is traversed, including unreferenced objects. Capture is bounded to 32 MiB before decoding. These are **post-parser** checks.

Presence of any of these dictionary keys anywhere is rejected, even if empty: `/Annots /AcroForm /A /AA /OpenAction /JS /JavaScript /URI /Launch /SubmitForm /ResetForm /ImportData /RichMedia /EmbeddedFiles /EF /XFA /OCProperties /OC /Collection /AF /Names /Outlines /Dests /PresSteps /Ref /OPI /F`. In particular, `/F` excludes external stream files. Type/subtype names rejected: `/Action /Filespec /EmbeddedFile /OCG /OCMD /Widget /Link /Screen /Movie /Sound /FileAttachment /RichMedia /3D`. Action `/S` names rejected: `/GoTo /GoToR /GoToE /Launch /Thread /URI /Sound /Movie /Hide /Named /SubmitForm /ResetForm /ImportData /JavaScript /SetOCGState /Rendition /Trans /GoTo3DView`. JSON PDF-name hex escapes are normalized for these comparisons. `/S /Transparency` is not an action and is not rejected by that rule.

This intentionally excludes useful static features too. It is a qualified conservative rejection policy, not a sanitizer or proof that every possible active PDF feature has been understood. qpdf JSON is mature object inspection, not a substitute for text extraction or rasterization. Native parsers are not sandboxes.

After original inspection, fixed `qpdf --object-streams=disable` creates a bounded normalized copy for free FPDI. The normalized copy is inspected again. Source bytes are never rewritten. Normalization addresses compressed cross-reference/object-stream limitations; it does not remove encryption or certify content safety.

## Validation and bounds

Fixed limits: input 16 MiB; normalized and result PDF 64 MiB each; PNG 8 MiB; image 4 million pixels checked using `getimagesize` before GD decode; 72 dpi rendering; 64 selected occurrences; 32 sides. Native stderr 64 KiB, ordinary stdout 64 KiB, extracted side text 1 MiB, inspection stdout 32 MiB, supervisor report 16 KiB. Output overflows trigger owned termination, draining and failure. Selected labels aggregate to at most 8192 bytes. Job/map admission is 128 KiB. Intermediate artifacts are sequential, not parallel render batches.

The worker uses PHP `memory_limit=256M`. Child profiles receive Linux RLIMIT_AS 768 MiB, RLIMIT_FSIZE 64 MiB (8 MiB for rendering), RLIMIT_NOFILE 64, no core dumps and an execution-budget-derived CPU limit. RLIMIT_AS is virtual address space, not RSS; RLIMIT_FSIZE applies to individual regular files, not pipes or directory totals. These are useful failure bounds, not hard pre-parser guarantees, process sandboxes or physical directory quotas.

A monotonic 20-second budget starts on API entry and includes cooperative lock acquisition, copying, inspection, worker, sequential validation and publication. Each helper reserves cleanup time. Work is preempted under that budget; authorized termination and reaping are completed even if OS scheduling or uninterruptible I/O delays cleanup. Parent-side large parsing/copying remains bounded but is not a real-time scheduling guarantee.

Validation requires qpdf `--check` exit 0, inspected output page count, unique occurrence ordinals and blank accounting. Poppler extracts UTF-8 text from every side; the normalized whitespace text is hashed, not put into public diagnostics. Every side is rendered to PNG and GD-decoded; dimensions and three deterministic samples are recorded. For arbitrary inputs, these are independent evidence, **not** proof of source/output visual equivalence or correct logical reading order. The synthetic regression suite supplies stronger known-marker and pixel oracles.

## Ownership, publication and recovery

Options are exactly `root` and `name`. Root must already exist and be a local directory. Name matches `[A-Za-z0-9][A-Za-z0-9_-]{0,63}`. All existing input/root path components must be nonsymlinks; `.`/`..`, wrappers, NUL and paths exceeding 4096 bytes are rejected. Sources inside the output root are forbidden. No live source/root edits are supported.

A root marker binds application ID, random token, canonical path hash, device and inode. One cooperative `flock` covers the whole operation. A private `.booklet-stage-<32 hex>` receives a matching marker and private working copy. On failure no completed directory is published; marked staging is retained for explicit recovery. Diagnostics use codes, never native parser output, source paths or raw PDF/text.

Before rename, the work subdirectory is removed. The completed bundle contains `booklet.pdf`, `page-map.json`, `validation.json`, `first-side.png`, `.booklet-complete.json`. The stage marker becomes the completion marker; a same-root rename publishes the new name. Existing files, directories and symlinks at that name are refused. This is no-overwrite only under the documented cooperative-writer assumption; it is not protection from hostile concurrent filesystem replacement. No fsync/power-loss durability promise.

`recover(root)` uses the same lock. It considers only exact stage-name syntax, verifies the root-bound marker, refuses symlinks anywhere in the tree and deletes only verified marked stages. It ignores wrong markers, unrelated prefix directories, completed outputs and all sources. Moving a root invalidates its identity instead of silently adopting it.

CLI errors: one JSON `error` code on stderr, exit 1; success exit 0. Typical codes: `signature_size`, `selection_page_missing`, `sheet_geometry`, `unsupported_interactive`, `unsupported_encryption`, `unsupported_user_unit`, `input_size`, `capture_limit`, `tool_failed`, `tool_warning`, `workflow_deadline`, `supervisor_cleanup`, `bundle_exists`, `namespace_identity`. Native failure details are deliberately not exposed. Recovery is not automatic retries.
