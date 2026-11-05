# Security

Development version 0.1.0 handles potentially hostile PDF bytes through mature native and PHP parsers. It is not a sanitizer, sandbox or production-readiness certification. Read docs/contract.md before exposing it to untrusted inputs. Use additional operating-system isolation appropriate to your environment.

Report suspected vulnerabilities using GitHub private vulnerability reporting:

https://github.com/martinlofranodeoliveira/booklet-imposition-pack/security/advisories/new

Include a minimal synthetic reproducer, affected version, tool versions and expected versus actual behavior. Do not attach confidential PDFs, personal information, credentials or raw customer documents. Avoid public disclosure of an unresolved exploit in an issue. No response-time SLA is promised.

The output root is a cooperative local namespace, not a hostile multi-user filesystem boundary. Sources and roots must not be edited during an operation. Readers must ignore stages and consume only completed directories. Recovery deliberately refuses symlinks and invalid markers; do not broaden its deletion rules to clean unrelated data.

Native parsing, post-parser object limits, RLIMIT settings and a monotonic deadline reduce exposure but do not establish hard pre-parser memory safety. Cleanup may be delayed by kernel uninterruptible I/O. A cleanup failure prevents publication. Never add a runtime arbitrary-executable, shell-command or tool-path override to solve integration problems.

For general contact, use the official homepage: https://lofranotecnologia.com.br.
