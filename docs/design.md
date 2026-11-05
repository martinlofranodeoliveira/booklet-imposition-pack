# Design rationale

## Pipeline

1. Validate bounded selection, signature and sheet geometry.
2. Acquire the output-root cooperative lock, establish root identity, refuse an existing bundle name.
3. Create a private marked stage; copy at most 16 MiB from a regular nonsymlink source outside the root.
4. Inspect the original owned copy through qpdf JSON v2, traversing all objects. Reject the documented dynamic/interactive subset before normalization.
5. Normalize object streams through fixed qpdf arguments; inspect that copy again.
6. Run the package's fixed PHP FPDI/FPDF worker. The parent never parses a PDF through FPDI. Import selected page occurrences and calculate proportional placements.
7. Independently check the resulting PDF with qpdf, text-extract each side with Poppler, render each side at 72 dpi, bound the PNG dimensions and decode it with GD.
8. Write the validation report, check the immutable source hash, remove work files, mark completion and atomically rename under the same lock.

Exceptions leave marked staging for explicit recovery. A failed renderer cannot leak a completed PDF without its map/report. There is no public process-command, tool-path, environment or failure-injection option. Tests change a private boolean with reflection to select the fixed failing renderer. This is not a runtime extension interface.

## Why FPDI plus normalization?

Importing a source XObject preserves its source glyphs and resources without re-encoding text. Rebuilding arbitrary content would be a large and unsafe scope increase. The free FPDI parser has important compressed-xref/object-stream limitations; qpdf normalization is narrowly used to bridge those limitations. Original objects are inspected first so unsupported content cannot be hidden merely because it is dropped during import. The inspection is deliberately conservative, including unreferenced objects and empty annotation entries.

The project does not implement a PDF parser. Fixture modifications use qpdf's JSON writer with stdClass retained for empty JSON objects. Converting all fixture JSON to associative arrays would change empty dictionaries to arrays and can invalidate stream dictionaries.

## Retained process anchor

Each tool invocation gets a dedicated Linux Python stdlib helper. The helper sets `PR_SET_CHILD_SUBREAPER`, launches one fixed-profile child with `start_new_session=True`, and inherits only stdout/stderr for bounded parent capture. FD 3 is an owned parent-to-helper STOP/EOF channel; FD 4 is helper-to-parent cleanup reporting. The work child does not inherit these control descriptors.

The helper observes termination using `waitid(WEXITED|WNOHANG|WNOWAIT)`. It never calls `poll`, `wait` or `communicate` before cleanup authority is sealed. Whether the child exits normally, runs out of budget, floods output, or the PHP parent disappears, the helper sends group TERM, grants a short grace, sends group KILL, then seals further signals. Only then does it reap the direct leader and adopted descendants. The unreaped leader anchors the numeric process-group identity during all authorized signals. A `kill(pid,0)` existence check is never treated as authorization.

PHP drains stdout, stderr and the cleanup report concurrently, bounds all capture, and sends STOP on overflow or deadline. It never uses `proc_get_status` or `proc_terminate`, never reaps a working leader itself, and never sends late numeric PGID kills from a finally block. `proc_close` applies only to the supervisor after its pipes close. A missing/unsealed cleanup report is an error and prevents publication, not a reason to pretend cleanup succeeded.

This scope covers the fixed native tools and worker, which do not intentionally escape their process group. It is not a general foreign-child supervisor, seccomp/container sandbox or defense against malicious process-group escape. Kernel failures and uninterruptible I/O cannot be turned into real-time guarantees. Cleanup failure is not masked by an earlier parser error.

## Map stability and geometry

Planner identity is occurrence ordinal, not page number. The fold tests independently unwrap front-right/back-left positions and then reverse the nested sheets to reconstruct the reading order. Manual maps are also used so testing is not merely the production indexing formula restated.

Map coordinates are logical top-origin point rectangles. FPDF performs its PDF bottom-origin transformation. Optional whole-back `/Rotate 180` has separate effective rectangles in the manifest; it does not rewrite logical pairs. Orientation is a software coordinate contract, not a printer configuration claim.

The fixture's 200 pt squares have uniform gray `20*n` and markers near (12,20). Markers are white below gray 100 and black above it, keeping the dark P1 legible without touching sample points. All fixtures are original synthetic vectors generated through FPDF. The cropped/rotated fixture is written through qpdf's maintained JSON interface.

## Evidence and limits

Known synthetic text and exact gray samples provide regression evidence independent of FPDI. Text hashes in general reports avoid exposing arbitrary source text. A retained first-side PNG supports subsequent human review; software sampling alone cannot prove that everything on a page looks right. Structural acceptance is specifically qpdf exit 0, not certification against every PDF standard.

The workflow admits bounded pages and files, not arbitrary jobs. Sequential native tools reduce simultaneous memory and descriptor use. Native limits are conservative failure controls, not pre-parser safety guarantees. PDF parsing/rendering remains unsafe for hostile documents without additional operational isolation.

The output namespace is cooperative: the same flock protects writers and recovery, and readers only consume completed directories. Path-component checks and marked recovery avoid accidental traversal/deletion but do not provide hostile concurrent-filesystem isolation. Random stage names are operational identities; canonical map fields do not contain those names, absolute input paths or wall-clock timestamps. PDF/library version changes can still change output bytes.
