# Brotli decoder port — provenance

`Decoder.php` is Thallo's PHP port of Google's Brotli decoder, written for reading WOFF2 font files
(block typeface plan, Task 1). Thallo maintains it; it is reached only through
`Thallo\Core\Content\Fonts\Brotli\BrotliDecoder` (`PurePhpBrotliDecoder`).

## Upstream

- Repository: https://github.com/google/brotli
- Pinned commit: `42a2ed4355bc6287da6bb6319f090b499cba4550` (2026-09-30)
- Ported from: `java/org/brotli/dec/` — `Decode.java`, `BitReader.java`, `Huffman.java`,
  `Transform.java`, `Dictionary.java`, `DictionaryData.java` (size bits), `Context.java`, `State.java`,
  `BrotliError.java` (error code names).
- Licence: MIT, `LICENSE` in this directory (copied unchanged from the pinned commit).
- Dictionary: `dictionary.bin` is `c/common/dictionary.bin` at the pinned commit, byte for byte
  (122,784 bytes, SHA-256 `20e42eb1b511c21806d4d227d07e5dd06877d8ce7b3a817f378f313653f35c70`,
  checked by `BrotliDecoderTest::testTheDictionaryIsUpstreamsByteForByte`). Upstream's Java decoder
  builds the same bytes from the encoded strings in `DictionaryData.java`.

States, helpers and table names follow upstream so a change there can be traced here. To follow an
upstream change, diff `java/org/brotli/dec` between the pinned commit and the new one, apply it here,
update the pin above, and rebuild the differential corpus (`scripts/build-brotli-vectors.py`).

## Upstream tests preserved

- `tests/testdata/*.compressed*` at the pinned commit (all 45 streams), with upstream's expected
  outputs recorded by length and SHA-256: `tests/fixtures/brotli/upstream/` and `upstream.json`.
- `SynthTest.java`'s 45 enabled vectors (one is disabled upstream): `tests/fixtures/brotli/synth.json`.
- Both, plus streams from the C encoder and truncated or mutated streams judged by the C decoder,
  run in `tests/Unit/Fonts/BrotliDifferentialTest.php`.

## Intentional departures

1. **Whole input, no streaming.** Upstream reads an `InputStream` through a 4 KiB buffer
   (`readMoreInput`, `halfOffset`, `tailBytes`). Here the whole stream is in memory, followed by 24
   zero bytes; the accumulator holds up to 56 bits, least significant first. Reading past the end is
   detected from the consumed-bit position (`checkHealth`) and by a guard once 16 bytes past the end
   have been fetched. Bytes left after the last meta-block are always refused
   (`BROTLI_ERROR_UNUSED_BYTES_AFTER_END`); upstream's Java decoder only sees them when they fall in
   its buffered tail, while the C decoder always refuses them — this port matches the C decoder.
2. **An output cap.** There is no caller-supplied output buffer or eager mode. Each ring-buffer
   flush (`writeRingBuffer`) is appended to the output string only if the total stays within
   `$maxOutput`; otherwise `OutputLimitExceeded` is thrown before anything past the cap exists.
3. **A smaller ring buffer when the cap allows.** The ring buffer is at most
   `min(window, the smallest power of two above $maxOutput)`. Because that size exceeds the cap, the
   first flush of a full buffer refuses the stream, so a smaller buffer never has to serve a backward
   reference past its own wrap. `maxBackwardDistance` still comes from the stream's window, so
   dictionary references resolve exactly as upstream.
4. **An allocation budget.** Every ring buffer, Huffman tree group and context map is sized from the
   (format-bounded) header values and charged to a 40 MiB budget **before** it is allocated, counting
   PHP's power-of-two sizing of packed arrays and the old ring buffer while it is copied
   (`AllocationLimitExceeded`).
5. **A deadline.** `checkDeadline()` runs every 256 state transitions, every 65,536 literals and at
   every ring-buffer flush (`DeadlineExceeded`).
6. **Errors are exceptions.** Upstream records a negative running state; here `CorruptStream` carries
   upstream's error code name.
7. **Not ported** (unreachable for WOFF2): large-window streams (refused as the reserved window value,
   as upstream does unless `enableLargeWindow` is called); compound and custom dictionaries
   (`attachDictionaryChunk`, `Dictionary.setData`, so `doUseDictionary`'s compound branch); the
   `SHIFT_FIRST`/`SHIFT_ALL` transforms, which only custom dictionaries use (the RFC transforms'
   largest operator is 20); `BrotliInputStream`, eager output, and the `BIT_READER_DEBUG` checks.
8. **PHP representation and speed.** Byte arrays (the ring buffer, the dictionary, context modes and
   maps) are PHP strings, one byte per byte; Huffman tables are int arrays. `COPY_LOOP` copies exactly
   the copy length byte by byte (upstream copies whole quads; the extra bytes are overwritten before
   they are read). The literal loop (`insertLiterals`) and the copy loop keep their state in locals
   and inline `fillBitWindow`/`readSymbol`; the logic is upstream's.
