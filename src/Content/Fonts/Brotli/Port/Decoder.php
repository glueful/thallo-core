<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Fonts\Brotli\Port;

/**
 * A Brotli (RFC 7932) decoder: Thallo's PHP port of google/brotli's Java decoder
 * (java/org/brotli/dec at 42a2ed4355bc6287da6bb6319f090b499cba4550, MIT — see LICENSE).
 *
 * The states and helpers keep upstream's names (Decode, BitReader, Huffman, Transform, Dictionary,
 * Context), so a change upstream can be followed here. PROVENANCE.md lists every intentional
 * departure; the main ones are that the whole input is in memory (no streaming), output is capped,
 * working memory is charged to a budget before it is allocated, and decoding has a deadline.
 *
 * Byte buffers (the ring buffer, the dictionary, context maps) are PHP strings, one byte per byte.
 */
final class Decoder
{
    // RunningState (Decode.java). Errors are exceptions here, so there are no negative states.
    private const BLOCK_START = 2;
    private const COMPRESSED_BLOCK_START = 3;
    private const MAIN_LOOP = 4;
    private const READ_METADATA = 5;
    private const COPY_UNCOMPRESSED = 6;
    private const INSERT_LOOP = 7;
    private const COPY_LOOP = 8;
    private const USE_DICTIONARY = 9;
    private const FINISHED = 10;
    private const INIT_WRITE = 12;
    private const WRITE = 13;

    private const DEFAULT_CODE_LENGTH = 8;
    private const CODE_LENGTH_REPEAT_CODE = 16;
    private const NUM_LITERAL_CODES = 256;
    private const NUM_COMMAND_CODES = 704;
    private const NUM_BLOCK_LENGTH_CODES = 26;
    private const LITERAL_CONTEXT_BITS = 6;
    private const DISTANCE_CONTEXT_BITS = 2;

    private const HUFFMAN_TABLE_BITS = 8;
    private const HUFFMAN_TABLE_MASK = 0xFF;

    /** Maximum Huffman table size for an alphabet of (index * 32) symbols, code length 15, root bits 8. */
    private const MAX_HUFFMAN_TABLE_SIZE = [
        256, 402, 436, 468, 500, 534, 566, 598, 630, 662, 694, 726, 758, 790, 822,
        854, 886, 920, 952, 984, 1016, 1048, 1080,
    ];

    private const HUFFMAN_TABLE_SIZE_26 = 396;
    private const HUFFMAN_TABLE_SIZE_258 = 632;

    private const CODE_LENGTH_CODES = 18;
    private const CODE_LENGTH_CODE_ORDER = [1, 2, 3, 4, 0, 5, 17, 6, 16, 7, 8, 9, 10, 11, 12, 13, 14, 15];

    private const NUM_DISTANCE_SHORT_CODES = 16;
    private const DISTANCE_SHORT_CODE_INDEX_OFFSET = [0, 3, 2, 1, 0, 0, 0, 0, 0, 0, 3, 3, 3, 3, 3, 3];
    private const DISTANCE_SHORT_CODE_VALUE_OFFSET = [0, 0, 0, 0, -1, 1, -2, 2, -3, 3, -1, 1, -2, 2, -3, 3];

    /** Static Huffman code for the code length code lengths. */
    private const FIXED_TABLE = [
        0x020000, 0x020004, 0x020003, 0x030002, 0x020000, 0x020004, 0x020003, 0x040001,
        0x020000, 0x020004, 0x020003, 0x030002, 0x020000, 0x020004, 0x020003, 0x040005,
    ];

    private const MAX_TRANSFORMED_WORD_LENGTH = 5 + 24 + 8;
    private const MAX_DISTANCE_BITS = 24;
    private const MAX_ALLOWED_DISTANCE = 0x7FFFFFFC;

    private const BLOCK_LENGTH_OFFSET = [
        1, 5, 9, 13, 17, 25, 33, 41, 49, 65, 81, 97, 113, 145, 177, 209, 241, 305, 369, 497,
        753, 1265, 2289, 4337, 8433, 16625,
    ];
    private const BLOCK_LENGTH_N_BITS = [
        2, 2, 2, 2, 3, 3, 3, 3, 4, 4, 4, 4, 5, 5, 5, 5, 6, 6, 7, 8, 9, 10, 11, 12, 13, 24,
    ];
    private const INSERT_LENGTH_N_BITS = [
        0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x01, 0x01, 0x02, 0x02, 0x03, 0x03,
        0x04, 0x04, 0x05, 0x05, 0x06, 0x07, 0x08, 0x09, 0x0A, 0x0C, 0x0E, 0x18,
    ];
    private const COPY_LENGTH_N_BITS = [
        0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x01, 0x01, 0x02, 0x02,
        0x03, 0x03, 0x04, 0x04, 0x05, 0x05, 0x06, 0x07, 0x08, 0x09, 0x0A, 0x18,
    ];

    // Transform.java: the RFC 7932 transforms, as upstream encodes them.
    private const NUM_RFC_TRANSFORMS = 121;
    private const OMIT_FIRST_LAST_LIMIT = 9;
    private const UPPERCASE_FIRST = 10;
    private const UPPERCASE_ALL = 11;
    private const OMIT_FIRST_BASE = 11;
    private const PREFIX_SUFFIX_SRC = "# #s #, #e #.# the #.com/#\xC2\xA0# of # and # in # to #\"#\">#\x0A#]# for # a "
        . "# that #. # with #'# from # by #. The # on # as # is #ing #\x0A\x09#:#ed #(# at #ly #=\"# of the #. This "
        . "#,# not #er #al #='#ful #ive #less #est #ize #ous #";
    private const TRANSFORMS_SRC = "     !! ! ,  *!  &!  \" !  ) *   * -  ! # !  #!*!  +  ,\$ !  -  %  .  / #   0  1 . "
        . " \"   2  3!*   4%  ! # /   5  6  7  8 0  1 &   \$   9 +   :  ;  < '  !=  >  ?! 4  @ 4  2  &   A *# "
        . "(   B  C& ) %  ) !*# *-% A +! *.  D! %'  & E *6  F  G% ! *A *%  H! D  I!+!  J!+   K +- *4! A  "
        . "L!*4  M  N +6  O!*% +.! K *G  P +%(  ! G *D +D  Q +# *K!*G!+D!+# +G +A +4!+% +K!+4!*D!+K!*K";

    // Context.java.
    private const UTF_MAP = "         !!  !                  \"#\$##%#\$&'##(#)#++++++++++((&*'##"
        . ",---,---,-----,-----,-----&#'###.///.///./////./////./////&#'# ";
    private const UTF_RLE = "A/*  ':  & : \$  \x81 @";

    // Dictionary.java / DictionaryData.java: word length → log2 of the number of words of that length.
    private const MIN_DICTIONARY_WORD_LENGTH = 4;
    private const MAX_DICTIONARY_WORD_LENGTH = 31;
    private const SIZE_BITS_DATA = 'AAAAKKLLKKKKKJJIHHIHHGGFF';
    private const DICTIONARY_SIZE = 122784;

    /** Working memory the stream may make the decoder allocate (ring buffer + tables), in bytes. */
    private const ALLOCATION_BUDGET = 40 * 1024 * 1024;

    /** Input bytes of zero padding appended past the end; reading further is reading after the end. */
    private const PADDING = 24;

    /** @var list<int>|null */
    private static ?array $cmdLookup = null;
    /** @var list<int>|null */
    private static ?array $contextLookup = null;
    /** @var list<int>|null */
    private static ?array $transformTriplets = null;
    private static string $prefixSuffixStorage = '';
    /** @var list<int> */
    private static array $prefixSuffixHeads = [];
    private static ?string $dictionary = null;
    /** @var list<int> */
    private static array $dictionaryOffsets = [];
    /** @var list<int> */
    private static array $dictionarySizeBits = [];
    /** @var list<string> */
    private static array $chr = [];

    // BitReader: the whole input, the next byte to load, and the pre-fetched bits (LSB first).
    private string $in;
    private int $inLen;
    private int $p = 0;
    private int $acc = 0;
    private int $bits = 0;

    private string $ringBuffer = '';
    private int $ringBufferSize = 0;
    private int $maxRingBufferSize = 0;
    private int $maxBackwardDistance = 0;
    private int $maxDistance = 0;
    private int $expectedTotalSize = 0;
    private int $pos = 0;
    private int $ringBufferBytesWritten = 0;
    private int $ringBufferBytesReady = 0;

    private string $output = '';
    private int $outputLength = 0;

    private int $runningState = self::BLOCK_START;
    private int $nextRunningState = 0;
    private int $metaBlockLength = 0;
    private int $inputEnd = 0;
    private int $isUncompressed = 0;
    private int $isMetadata = 0;
    private int $literalBlockLength = 0;
    private int $numLiteralBlockTypes = 0;
    private int $commandBlockLength = 0;
    private int $numCommandBlockTypes = 0;
    private int $distanceBlockLength = 0;
    private int $numDistanceBlockTypes = 0;
    private int $distRbIdx = 3;
    /** @var list<int> */
    private array $rings = [16, 15, 11, 4, 0, 0, 0, 0, 0, 0];
    private int $trivialLiteralContext = 0;
    private int $literalTreeIdx = 0;
    private int $commandTreeIdx = 0;
    private int $j = 0;
    private int $insertLength = 0;
    private int $contextMapSlice = 0;
    private int $distContextMapSlice = 0;
    private int $contextLookupOffset1 = 0;
    private int $contextLookupOffset2 = 0;
    private int $distanceCode = 0;
    private int $numDirectDistanceCodes = 0;
    private int $distancePostfixBits = 0;
    private int $distance = 0;
    private int $copyLength = 0;

    /** @var array<int, int> */
    private array $blockTrees;
    /** @var array<int, int> */
    private array $literalTreeGroup = [];
    /** @var array<int, int> */
    private array $commandTreeGroup = [];
    /** @var array<int, int> */
    private array $distanceTreeGroup = [];
    private string $contextModes = '';
    private string $contextMap = '';
    private string $distContextMap = '';
    /** @var array<int, int> */
    private array $distExtraBits;
    /** @var array<int, int> */
    private array $distOffset;

    private int $deadline;
    private int $work = 0;
    private int $ringBytes = 0;
    private int $tableBytes = 0;
    /** Bytes charged for the tables that live as long as the decoder. */
    private int $baseBytes = 0;

    /**
     * Decodes a complete Brotli stream.
     *
     * @throws CorruptStream when the stream breaks the format (including truncation and trailing bytes)
     * @throws OutputLimitExceeded before the output would exceed $maxOutput bytes
     * @throws AllocationLimitExceeded when the headers ask for more working memory than the budget
     * @throws DeadlineExceeded when decoding runs past $timeLimitSeconds
     */
    public static function decompress(string $input, int $maxOutput, float $timeLimitSeconds): string
    {
        self::initTables();
        return (new self($input, $maxOutput, $timeLimitSeconds))->run();
    }

    private function __construct(string $input, private readonly int $maxOutput, float $timeLimitSeconds)
    {
        $this->inLen = strlen($input);
        $this->in = $input . str_repeat("\0", self::PADDING);
        $this->deadline = hrtime(true) + (int) ($timeLimitSeconds * 1e9);
        // initState: 6 trees + 1 extra "offset" slot to simplify table decoding logic.
        $this->blockTrees = $this->allocateInts(7 + 3 * (self::HUFFMAN_TABLE_SIZE_258 + self::HUFFMAN_TABLE_SIZE_26));
        $this->blockTrees[0] = 7;
        $limit = self::calculateDistanceAlphabetLimit(self::MAX_ALLOWED_DISTANCE, 3, 15 << 3);
        $this->distExtraBits = $this->allocateInts($limit);
        $this->distOffset = $this->allocateInts($limit);
        $this->baseBytes = $this->tableBytes;
    }

    //----------------------------------------------------------------------------------------------
    // Static tables
    //----------------------------------------------------------------------------------------------

    private static function initTables(): void
    {
        if (self::$cmdLookup !== null) {
            return;
        }
        for ($i = 0; $i < 256; $i++) {
            self::$chr[$i] = chr($i);
        }
        self::$cmdLookup = self::unpackCommandLookupTable();
        self::$contextLookup = self::unpackLookupTable();
        self::unpackTransforms();
        $dictionary = file_get_contents(__DIR__ . '/dictionary.bin');
        if ($dictionary === false || strlen($dictionary) !== self::DICTIONARY_SIZE) {
            throw new \LogicException('The Brotli dictionary is missing or damaged');
        }
        // Dictionary.setData: offsets from sizeBits.
        $pos = 0;
        for ($i = 0; $i < 32; $i++) {
            $bits = $i < strlen(self::SIZE_BITS_DATA) ? ord(self::SIZE_BITS_DATA[$i]) - 65 : 0;
            self::$dictionarySizeBits[$i] = $bits;
            self::$dictionaryOffsets[$i] = $pos;
            if ($bits !== 0) {
                $pos += $i << $bits;
            }
        }
        if ($pos !== self::DICTIONARY_SIZE) {
            throw new \LogicException('The Brotli dictionary size bits are inconsistent');
        }
        self::$dictionary = $dictionary;
    }

    /** @return list<int> */
    private static function unpackCommandLookupTable(): array
    {
        $insertLengthOffsets = array_fill(0, 24, 0);
        $copyLengthOffsets = array_fill(0, 24, 0);
        $copyLengthOffsets[0] = 2;
        for ($i = 0; $i < 23; $i++) {
            $insertLengthOffsets[$i + 1] = $insertLengthOffsets[$i] + (1 << self::INSERT_LENGTH_N_BITS[$i]);
            $copyLengthOffsets[$i + 1] = $copyLengthOffsets[$i] + (1 << self::COPY_LENGTH_N_BITS[$i]);
        }
        $cmdLookup = array_fill(0, self::NUM_COMMAND_CODES * 4, 0);
        for ($cmdCode = 0; $cmdCode < self::NUM_COMMAND_CODES; $cmdCode++) {
            $rangeIdx = $cmdCode >> 6;
            // -4 turns any regular distance code to negative.
            $distanceContextOffset = -4;
            if ($rangeIdx >= 2) {
                $rangeIdx -= 2;
                $distanceContextOffset = 0;
            }
            $insertCode = (((0x29850 >> ($rangeIdx * 2)) & 0x3) << 3) | (($cmdCode >> 3) & 7);
            $copyCode = (((0x26244 >> ($rangeIdx * 2)) & 0x3) << 3) | ($cmdCode & 7);
            $copyLengthOffset = $copyLengthOffsets[$copyCode];
            $distanceContext = $distanceContextOffset + min($copyLengthOffset, 5) - 2;
            $index = $cmdCode * 4;
            $cmdLookup[$index] = self::INSERT_LENGTH_N_BITS[$insertCode] | (self::COPY_LENGTH_N_BITS[$copyCode] << 8);
            $cmdLookup[$index + 1] = $insertLengthOffsets[$insertCode];
            $cmdLookup[$index + 2] = $copyLengthOffset;
            $cmdLookup[$index + 3] = $distanceContext;
        }
        return $cmdLookup;
    }

    /** Context.unpackLookupTable: the context lookup for every context mode. @return list<int> */
    private static function unpackLookupTable(): array
    {
        $lookup = array_fill(0, 2048, 0);
        // LSB6, MSB6, SIGNED
        for ($i = 0; $i < 256; $i++) {
            $lookup[$i] = $i & 0x3F;
            $lookup[512 + $i] = $i >> 2;
            $lookup[1792 + $i] = 2 + ($i >> 6);
        }
        // UTF8
        for ($i = 0; $i < 128; $i++) {
            $lookup[1024 + $i] = 4 * (ord(self::UTF_MAP[$i]) - 32);
        }
        for ($i = 0; $i < 64; $i++) {
            $lookup[1152 + $i] = $i & 1;
            $lookup[1216 + $i] = 2 + ($i & 1);
        }
        $offset = 1280;
        for ($k = 0; $k < 19; $k++) {
            $value = $k & 3;
            $rep = ord(self::UTF_RLE[$k]) - 32;
            for ($i = 0; $i < $rep; $i++) {
                $lookup[$offset++] = $value;
            }
        }
        // SIGNED
        for ($i = 0; $i < 16; $i++) {
            $lookup[1792 + $i] = 1;
            $lookup[2032 + $i] = 6;
        }
        $lookup[1792] = 0;
        $lookup[2047] = 7;
        for ($i = 0; $i < 256; $i++) {
            $lookup[1536 + $i] = $lookup[1792 + $i] << 3;
        }
        return $lookup;
    }

    private static function unpackTransforms(): void
    {
        $heads = [0];
        $storage = '';
        $n = strlen(self::PREFIX_SUFFIX_SRC);
        for ($i = 0; $i < $n; $i++) {
            $c = self::PREFIX_SUFFIX_SRC[$i];
            if ($c === '#') {
                $heads[] = strlen($storage);
            } else {
                $storage .= $c;
            }
        }
        $triplets = [];
        for ($i = 0; $i < self::NUM_RFC_TRANSFORMS * 3; $i++) {
            $triplets[] = ord(self::TRANSFORMS_SRC[$i]) - 32;
        }
        self::$prefixSuffixStorage = $storage;
        self::$prefixSuffixHeads = $heads;
        self::$transformTriplets = $triplets;
    }

    private static function log2floor(int $i): int
    {
        $result = -1;
        $step = 16;
        $v = $i;
        while ($step > 0) {
            $next = $v >> $step;
            if ($next !== 0) {
                $result += $step;
                $v = $next;
            }
            $step >>= 1;
        }
        return $result + $v;
    }

    private static function calculateDistanceAlphabetSize(int $npostfix, int $ndirect, int $maxndistbits): int
    {
        return self::NUM_DISTANCE_SHORT_CODES + $ndirect + 2 * ($maxndistbits << $npostfix);
    }

    private static function calculateDistanceAlphabetLimit(int $maxDistance, int $npostfix, int $ndirect): int
    {
        $offset = (($maxDistance - $ndirect) >> $npostfix) + 4;
        $ndistbits = self::log2floor($offset) - 1;
        $group = (($ndistbits - 1) << 1) | (($offset >> $ndistbits) & 1);
        return (($group - 1) << $npostfix) + (1 << $npostfix) + $ndirect + self::NUM_DISTANCE_SHORT_CODES;
    }

    //----------------------------------------------------------------------------------------------
    // The allocation budget
    //----------------------------------------------------------------------------------------------

    /** An int array of $n zeroes, charged to the budget first (PHP sizes packed arrays in powers of two). */
    private function allocateInts(int $n): array
    {
        $slots = 8;
        while ($slots < $n) {
            $slots <<= 1;
        }
        $this->charge($slots * 16);
        return array_fill(0, max($n, 1), 0);
    }

    private function charge(int $bytes): void
    {
        $this->tableBytes += $bytes;
        if ($this->ringBytes + $this->tableBytes > self::ALLOCATION_BUDGET) {
            throw new AllocationLimitExceeded('The stream asks for more working memory than the decoder allows');
        }
    }

    private function checkDeadline(): void
    {
        if (hrtime(true) > $this->deadline) {
            throw new DeadlineExceeded('Decoding ran past its deadline');
        }
    }

    //----------------------------------------------------------------------------------------------
    // BitReader
    //----------------------------------------------------------------------------------------------

    /** fillBitWindow: guarantees at least 32 pre-fetched bits (at most 56, so the int stays positive). */
    private function fillBitWindow(): void
    {
        if ($this->bits < 32) {
            do {
                $this->acc |= ord($this->in[$this->p++]) << $this->bits;
                $this->bits += 8;
            } while ($this->bits <= 48);
            if ($this->p > $this->inLen + self::PADDING - 8) {
                // More than 16 bytes fetched past the end: at least one bit past the end was consumed.
                throw new CorruptStream('BROTLI_ERROR_TRUNCATED_INPUT');
            }
        }
    }

    private function readFewBits(int $n): int
    {
        $v = $this->acc & ((1 << $n) - 1);
        $this->acc >>= $n;
        $this->bits -= $n;
        return $v;
    }

    /** Bits consumed so far (fetched bytes minus the bits still waiting in the accumulator). */
    private function bitPosition(): int
    {
        return ($this->p << 3) - $this->bits;
    }

    private function jumpToByteBoundary(): void
    {
        $padding = $this->bits & 7;
        if ($padding !== 0 && $this->readFewBits($padding) !== 0) {
            throw new CorruptStream('BROTLI_ERROR_CORRUPTED_PADDING_BITS');
        }
    }

    /** checkHealth(endOfStream): no bit was read past the end, and at the end no byte is left over. */
    private function checkHealth(bool $endOfStream): void
    {
        $byteOffset = ($this->bitPosition() + 7) >> 3;
        if ($byteOffset > $this->inLen) {
            throw new CorruptStream('BROTLI_ERROR_READ_AFTER_END');
        }
        if ($endOfStream && $byteOffset !== $this->inLen) {
            throw new CorruptStream('BROTLI_ERROR_UNUSED_BYTES_AFTER_END');
        }
    }

    /** copyRawBytes into the ring buffer; the reader is byte-aligned (after jumpToByteBoundary). */
    private function copyRawBytes(int $offset, int $length): void
    {
        $pos = $offset;
        $len = $length;
        // Drain the accumulator.
        while ($this->bits >= 8 && $len !== 0) {
            $this->ringBuffer[$pos++] = self::$chr[$this->acc & 0xFF];
            $this->acc >>= 8;
            $this->bits -= 8;
            $len--;
        }
        $this->checkHealth(false);
        if ($len === 0) {
            return;
        }
        if ($this->p + $len > $this->inLen) {
            throw new CorruptStream('BROTLI_ERROR_TRUNCATED_INPUT');
        }
        $in = $this->in;
        $p = $this->p;
        for ($end = $pos + $len; $pos < $end; $pos++) {
            $this->ringBuffer[$pos] = $in[$p++];
        }
        $this->p = $p;
    }

    //----------------------------------------------------------------------------------------------
    // Huffman
    //----------------------------------------------------------------------------------------------

    private static function getNextKey(int $key, int $len): int
    {
        $step = 1 << ($len - 1);
        while (($key & $step) !== 0) {
            $step >>= 1;
        }
        return ($key & ($step - 1)) + $step;
    }

    /** @param array<int, int> $table */
    private static function replicateValue(array &$table, int $offset, int $step, int $end, int $item): void
    {
        $pos = $end;
        while ($pos > 0) {
            $pos -= $step;
            $table[$offset + $pos] = $item;
        }
    }

    /** @param array<int, int> $count */
    private static function nextTableBitSize(array $count, int $len, int $rootBits): int
    {
        $bits = $len;
        $left = 1 << ($bits - $rootBits);
        while ($bits < 15) {
            $left -= $count[$bits];
            if ($left <= 0) {
                break;
            }
            $bits++;
            $left <<= 1;
        }
        return $bits - $rootBits;
    }

    /**
     * Huffman.buildHuffmanTable: builds a lookup table from code lengths in symbol order.
     *
     * @param array<int, int> $tableGroup
     * @param array<int, int> $codeLengths
     * @return int the number of slots the table uses
     */
    private static function buildHuffmanTable(
        array &$tableGroup,
        int $tableIdx,
        int $rootBits,
        array $codeLengths,
        int $codeLengthsSize,
    ): int {
        $tableOffset = $tableGroup[$tableIdx];
        $sorted = array_fill(0, max($codeLengthsSize, 1), 0);
        $count = array_fill(0, 16, 0);
        $offset = array_fill(0, 16, 0);

        for ($sym = 0; $sym < $codeLengthsSize; $sym++) {
            $count[$codeLengths[$sym]]++;
        }
        $offset[1] = 0;
        for ($len = 1; $len < 15; $len++) {
            $offset[$len + 1] = $offset[$len] + $count[$len];
        }
        for ($sym = 0; $sym < $codeLengthsSize; $sym++) {
            if ($codeLengths[$sym] !== 0) {
                $sorted[$offset[$codeLengths[$sym]]++] = $sym;
            }
        }

        $tableBits = $rootBits;
        $tableSize = 1 << $tableBits;
        $totalSize = $tableSize;

        // Special case code with only one value.
        if ($offset[15] === 1) {
            for ($k = 0; $k < $totalSize; $k++) {
                $tableGroup[$tableOffset + $k] = $sorted[0];
            }
            return $totalSize;
        }

        // Fill in root table.
        $key = 0;
        $symbol = 0;
        $step = 1;
        for ($len = 1; $len <= $rootBits; $len++) {
            $step <<= 1;
            while ($count[$len] > 0) {
                $item = $len << 16 | $sorted[$symbol++];
                self::replicateValue($tableGroup, $tableOffset + $key, $step, $tableSize, $item);
                $key = self::getNextKey($key, $len);
                $count[$len]--;
            }
        }

        // Fill in 2nd level tables and add pointers to root table.
        $mask = $totalSize - 1;
        $low = -1;
        $currentOffset = $tableOffset;
        $step = 1;
        for ($len = $rootBits + 1; $len <= 15; $len++) {
            $step <<= 1;
            while ($count[$len] > 0) {
                if (($key & $mask) !== $low) {
                    $currentOffset += $tableSize;
                    $tableBits = self::nextTableBitSize($count, $len, $rootBits);
                    $tableSize = 1 << $tableBits;
                    $totalSize += $tableSize;
                    $low = $key & $mask;
                    $tableGroup[$tableOffset + $low] =
                        ($tableBits + $rootBits) << 16 | ($currentOffset - $tableOffset - $low);
                }
                self::replicateValue(
                    $tableGroup,
                    $currentOffset + ($key >> $rootBits),
                    $step,
                    $tableSize,
                    ($len - $rootBits) << 16 | $sorted[$symbol++],
                );
                $key = self::getNextKey($key, $len);
                $count[$len]--;
            }
        }
        return $totalSize;
    }

    //----------------------------------------------------------------------------------------------
    // Decode
    //----------------------------------------------------------------------------------------------

    /** Reads the stream header's "window bits" (large windows are not accepted). */
    private function decodeWindowBits(): int
    {
        $this->fillBitWindow();
        if ($this->readFewBits(1) === 0) {
            return 16;
        }
        $n = $this->readFewBits(3);
        if ($n !== 0) {
            return 17 + $n;
        }
        $n = $this->readFewBits(3);
        if ($n !== 0) {
            if ($n === 1) {
                return -1; // Reserved value (large-window streams) in a regular brotli stream.
            }
            return 8 + $n;
        }
        return 17;
    }

    /** Decodes a number in the range [0..255], by reading 1 - 11 bits. */
    private function decodeVarLenUnsignedByte(): int
    {
        $this->fillBitWindow();
        if ($this->readFewBits(1) !== 0) {
            $n = $this->readFewBits(3);
            if ($n === 0) {
                return 1;
            }
            return $this->readFewBits($n) + (1 << $n);
        }
        return 0;
    }

    private function decodeMetaBlockLength(): void
    {
        $this->fillBitWindow();
        $this->inputEnd = $this->readFewBits(1);
        $this->metaBlockLength = 0;
        $this->isUncompressed = 0;
        $this->isMetadata = 0;
        if ($this->inputEnd !== 0 && $this->readFewBits(1) !== 0) {
            return;
        }
        $sizeNibbles = $this->readFewBits(2) + 4;
        if ($sizeNibbles === 7) {
            $this->isMetadata = 1;
            if ($this->readFewBits(1) !== 0) {
                throw new CorruptStream('BROTLI_ERROR_CORRUPTED_RESERVED_BIT');
            }
            $sizeBytes = $this->readFewBits(2);
            if ($sizeBytes === 0) {
                return;
            }
            for ($i = 0; $i < $sizeBytes; $i++) {
                $this->fillBitWindow();
                $bits = $this->readFewBits(8);
                if ($bits === 0 && $i + 1 === $sizeBytes && $sizeBytes > 1) {
                    throw new CorruptStream('BROTLI_ERROR_EXUBERANT_NIBBLE');
                }
                $this->metaBlockLength += $bits << ($i * 8);
            }
        } else {
            for ($i = 0; $i < $sizeNibbles; $i++) {
                $this->fillBitWindow();
                $bits = $this->readFewBits(4);
                if ($bits === 0 && $i + 1 === $sizeNibbles && $sizeNibbles > 4) {
                    throw new CorruptStream('BROTLI_ERROR_EXUBERANT_NIBBLE');
                }
                $this->metaBlockLength += $bits << ($i * 4);
            }
        }
        $this->metaBlockLength++;
        if ($this->inputEnd === 0) {
            $this->isUncompressed = $this->readFewBits(1);
        }
    }

    /**
     * Decodes the next Huffman code from the bit-stream.
     *
     * @param array<int, int> $tableGroup
     */
    private function readSymbol(array $tableGroup, int $tableIdx): int
    {
        $offset = $tableGroup[$tableIdx];
        $v = $this->acc;
        $offset += $v & self::HUFFMAN_TABLE_MASK;
        $entry = $tableGroup[$offset];
        $bits = $entry >> 16;
        if ($bits <= self::HUFFMAN_TABLE_BITS) {
            $this->acc >>= $bits;
            $this->bits -= $bits;
            return $entry & 0xFFFF;
        }
        $offset += $entry & 0xFFFF;
        $mask = (1 << $bits) - 1;
        $offset += ($v & $mask) >> self::HUFFMAN_TABLE_BITS;
        $entry = $tableGroup[$offset];
        $n = ($entry >> 16) + self::HUFFMAN_TABLE_BITS;
        $this->acc >>= $n;
        $this->bits -= $n;
        return $entry & 0xFFFF;
    }

    /** @param array<int, int> $tableGroup */
    private function readBlockLength(array $tableGroup, int $tableIdx): int
    {
        $this->fillBitWindow();
        $code = $this->readSymbol($tableGroup, $tableIdx);
        $n = self::BLOCK_LENGTH_N_BITS[$code];
        $this->fillBitWindow();
        return self::BLOCK_LENGTH_OFFSET[$code] + $this->readFewBits($n);
    }

    private static function inverseMoveToFrontTransform(string &$v, int $vLen): void
    {
        $mtf = range(0, 255);
        for ($i = 0; $i < $vLen; $i++) {
            $index = ord($v[$i]);
            $value = $mtf[$index];
            $v[$i] = self::$chr[$value];
            if ($index !== 0) {
                // moveToFront
                for ($k = $index; $k > 0; $k--) {
                    $mtf[$k] = $mtf[$k - 1];
                }
                $mtf[0] = $value;
            }
        }
    }

    /**
     * @param array<int, int> $codeLengthCodeLengths
     * @param array<int, int> $codeLengths
     */
    private function readHuffmanCodeLengths(array $codeLengthCodeLengths, int $numSymbols, array &$codeLengths): void
    {
        $symbol = 0;
        $prevCodeLen = self::DEFAULT_CODE_LENGTH;
        $repeat = 0;
        $repeatCodeLen = 0;
        $space = 32768;
        $table = array_fill(0, 32 + 1, 0); // Speculative single entry table group.
        $tableIdx = 32;
        self::buildHuffmanTable($table, $tableIdx, 5, $codeLengthCodeLengths, self::CODE_LENGTH_CODES);

        while ($symbol < $numSymbols && $space > 0) {
            $this->fillBitWindow();
            $p = $this->acc & 31;
            $n = $table[$p] >> 16;
            $this->acc >>= $n;
            $this->bits -= $n;
            $codeLen = $table[$p] & 0xFFFF;
            if ($codeLen < self::CODE_LENGTH_REPEAT_CODE) {
                $repeat = 0;
                $codeLengths[$symbol++] = $codeLen;
                if ($codeLen !== 0) {
                    $prevCodeLen = $codeLen;
                    $space -= 32768 >> $codeLen;
                }
            } else {
                $extraBits = $codeLen - 14;
                $newLen = 0;
                if ($codeLen === self::CODE_LENGTH_REPEAT_CODE) {
                    $newLen = $prevCodeLen;
                }
                if ($repeatCodeLen !== $newLen) {
                    $repeat = 0;
                    $repeatCodeLen = $newLen;
                }
                $oldRepeat = $repeat;
                if ($repeat > 0) {
                    $repeat -= 2;
                    $repeat <<= $extraBits;
                }
                $this->fillBitWindow();
                $repeat += $this->readFewBits($extraBits) + 3;
                $repeatDelta = $repeat - $oldRepeat;
                if ($symbol + $repeatDelta > $numSymbols) {
                    throw new CorruptStream('BROTLI_ERROR_CORRUPTED_CODE_LENGTH_TABLE');
                }
                for ($i = 0; $i < $repeatDelta; $i++) {
                    $codeLengths[$symbol++] = $repeatCodeLen;
                }
                if ($repeatCodeLen !== 0) {
                    $space -= $repeatDelta << (15 - $repeatCodeLen);
                }
            }
        }
        if ($space !== 0) {
            throw new CorruptStream('BROTLI_ERROR_UNUSED_HUFFMAN_SPACE');
        }
        for ($i = $symbol; $i < $numSymbols; $i++) {
            $codeLengths[$i] = 0;
        }
    }

    /**
     * Reads up to 4 symbols directly and applies predefined histograms.
     *
     * @param array<int, int> $tableGroup
     */
    private function readSimpleHuffmanCode(
        int $alphabetSizeMax,
        int $alphabetSizeLimit,
        array &$tableGroup,
        int $tableIdx,
    ): int {
        $codeLengths = array_fill(0, $alphabetSizeLimit, 0);
        $symbols = [0, 0, 0, 0];
        $maxBits = 1 + self::log2floor($alphabetSizeMax - 1);

        $numSymbols = $this->readFewBits(2) + 1;
        for ($i = 0; $i < $numSymbols; $i++) {
            $this->fillBitWindow();
            $symbol = $this->readFewBits($maxBits);
            if ($symbol >= $alphabetSizeLimit) {
                throw new CorruptStream('BROTLI_ERROR_SYMBOL_OUT_OF_RANGE');
            }
            $symbols[$i] = $symbol;
        }
        // checkDupes
        for ($i = 0; $i < $numSymbols - 1; $i++) {
            for ($k = $i + 1; $k < $numSymbols; $k++) {
                if ($symbols[$i] === $symbols[$k]) {
                    throw new CorruptStream('BROTLI_ERROR_DUPLICATE_SIMPLE_HUFFMAN_SYMBOL');
                }
            }
        }

        $histogramId = $numSymbols;
        if ($numSymbols === 4) {
            $histogramId += $this->readFewBits(1);
        }
        switch ($histogramId) {
            case 1:
                $codeLengths[$symbols[0]] = 1;
                break;
            case 2:
                $codeLengths[$symbols[0]] = 1;
                $codeLengths[$symbols[1]] = 1;
                break;
            case 3:
                $codeLengths[$symbols[0]] = 1;
                $codeLengths[$symbols[1]] = 2;
                $codeLengths[$symbols[2]] = 2;
                break;
            case 4: // uniform 4-symbol histogram
                $codeLengths[$symbols[0]] = 2;
                $codeLengths[$symbols[1]] = 2;
                $codeLengths[$symbols[2]] = 2;
                $codeLengths[$symbols[3]] = 2;
                break;
            case 5: // prioritized 4-symbol histogram
                $codeLengths[$symbols[0]] = 1;
                $codeLengths[$symbols[1]] = 2;
                $codeLengths[$symbols[2]] = 3;
                $codeLengths[$symbols[3]] = 3;
                break;
        }
        return self::buildHuffmanTable(
            $tableGroup,
            $tableIdx,
            self::HUFFMAN_TABLE_BITS,
            $codeLengths,
            $alphabetSizeLimit,
        );
    }

    /**
     * Decodes Huffman-coded code lengths.
     *
     * @param array<int, int> $tableGroup
     */
    private function readComplexHuffmanCode(int $alphabetSizeLimit, int $skip, array &$tableGroup, int $tableIdx): int
    {
        $codeLengths = array_fill(0, $alphabetSizeLimit, 0);
        $codeLengthCodeLengths = array_fill(0, self::CODE_LENGTH_CODES, 0);
        $space = 32;
        $numCodes = 0;
        for ($i = $skip; $i < self::CODE_LENGTH_CODES; $i++) {
            $codeLenIdx = self::CODE_LENGTH_CODE_ORDER[$i];
            $this->fillBitWindow();
            $p = $this->acc & 15;
            $n = self::FIXED_TABLE[$p] >> 16;
            $this->acc >>= $n;
            $this->bits -= $n;
            $v = self::FIXED_TABLE[$p] & 0xFFFF;
            $codeLengthCodeLengths[$codeLenIdx] = $v;
            if ($v !== 0) {
                $space -= (32 >> $v);
                $numCodes++;
                if ($space <= 0) {
                    break;
                }
            }
        }
        if ($space !== 0 && $numCodes !== 1) {
            throw new CorruptStream('BROTLI_ERROR_CORRUPTED_HUFFMAN_CODE_HISTOGRAM');
        }
        $this->readHuffmanCodeLengths($codeLengthCodeLengths, $alphabetSizeLimit, $codeLengths);
        return self::buildHuffmanTable(
            $tableGroup,
            $tableIdx,
            self::HUFFMAN_TABLE_BITS,
            $codeLengths,
            $alphabetSizeLimit,
        );
    }

    /**
     * Decodes a Huffman table from the bit-stream.
     *
     * @param array<int, int> $tableGroup
     * @return int the number of slots the table uses
     */
    private function readHuffmanCode(
        int $alphabetSizeMax,
        int $alphabetSizeLimit,
        array &$tableGroup,
        int $tableIdx,
    ): int {
        $this->fillBitWindow();
        $simpleCodeOrSkip = $this->readFewBits(2);
        if ($simpleCodeOrSkip === 1) {
            return $this->readSimpleHuffmanCode($alphabetSizeMax, $alphabetSizeLimit, $tableGroup, $tableIdx);
        }
        return $this->readComplexHuffmanCode($alphabetSizeLimit, $simpleCodeOrSkip, $tableGroup, $tableIdx);
    }

    /** @return int the number of trees the map refers to */
    private function decodeContextMap(int $contextMapSize, string &$contextMap): int
    {
        $numTrees = $this->decodeVarLenUnsignedByte() + 1;
        if ($numTrees === 1) {
            $contextMap = str_repeat("\0", $contextMapSize);
            return $numTrees;
        }

        $this->fillBitWindow();
        $useRleForZeros = $this->readFewBits(1);
        $maxRunLengthPrefix = 0;
        if ($useRleForZeros !== 0) {
            $maxRunLengthPrefix = $this->readFewBits(4) + 1;
        }
        $alphabetSize = $numTrees + $maxRunLengthPrefix;
        $tableSize = self::MAX_HUFFMAN_TABLE_SIZE[($alphabetSize + 31) >> 5];
        $table = $this->allocateInts($tableSize + 1); // Speculative single entry table group.
        $tableIdx = $tableSize;
        $this->readHuffmanCode($alphabetSize, $alphabetSize, $table, $tableIdx);
        $i = 0;
        while ($i < $contextMapSize) {
            $this->fillBitWindow();
            $code = $this->readSymbol($table, $tableIdx);
            if ($code === 0) {
                $contextMap[$i] = "\0";
                $i++;
            } elseif ($code <= $maxRunLengthPrefix) {
                $this->fillBitWindow();
                $reps = (1 << $code) + $this->readFewBits($code);
                while ($reps !== 0) {
                    if ($i >= $contextMapSize) {
                        throw new CorruptStream('BROTLI_ERROR_CORRUPTED_CONTEXT_MAP');
                    }
                    $contextMap[$i] = "\0";
                    $i++;
                    $reps--;
                }
            } else {
                $contextMap[$i] = self::$chr[$code - $maxRunLengthPrefix];
                $i++;
            }
        }
        $this->fillBitWindow();
        if ($this->readFewBits(1) === 1) {
            self::inverseMoveToFrontTransform($contextMap, $contextMapSize);
        }
        return $numTrees;
    }

    private function decodeBlockTypeAndLength(int $treeType, int $numBlockTypes): int
    {
        $offset = 4 + $treeType * 2;
        $this->fillBitWindow();
        $blockType = $this->readSymbol($this->blockTrees, 2 * $treeType);
        $result = $this->readBlockLength($this->blockTrees, 2 * $treeType + 1);

        if ($blockType === 1) {
            $blockType = $this->rings[$offset + 1] + 1;
        } elseif ($blockType === 0) {
            $blockType = $this->rings[$offset];
        } else {
            $blockType -= 2;
        }
        if ($blockType >= $numBlockTypes) {
            $blockType -= $numBlockTypes;
        }
        $this->rings[$offset] = $this->rings[$offset + 1];
        $this->rings[$offset + 1] = $blockType;
        return $result;
    }

    private function decodeLiteralBlockSwitch(): void
    {
        $this->literalBlockLength = $this->decodeBlockTypeAndLength(0, $this->numLiteralBlockTypes);
        $literalBlockType = $this->rings[5];
        $this->contextMapSlice = $literalBlockType << self::LITERAL_CONTEXT_BITS;
        $this->literalTreeIdx = ord($this->contextMap[$this->contextMapSlice]);
        $contextMode = ord($this->contextModes[$literalBlockType]);
        $this->contextLookupOffset1 = $contextMode << 9;
        $this->contextLookupOffset2 = $this->contextLookupOffset1 + 256;
    }

    private function decodeCommandBlockSwitch(): void
    {
        $this->commandBlockLength = $this->decodeBlockTypeAndLength(1, $this->numCommandBlockTypes);
        $this->commandTreeIdx = $this->rings[7];
    }

    private function decodeDistanceBlockSwitch(): void
    {
        $this->distanceBlockLength = $this->decodeBlockTypeAndLength(2, $this->numDistanceBlockTypes);
        $this->distContextMapSlice = $this->rings[9] << self::DISTANCE_CONTEXT_BITS;
    }

    private function maybeReallocateRingBuffer(): void
    {
        $newSize = $this->maxRingBufferSize;
        if ($newSize > $this->expectedTotalSize) {
            $minimalNewSize = $this->expectedTotalSize;
            while (($newSize >> 1) > $minimalNewSize) {
                $newSize >>= 1;
            }
            if ($this->inputEnd === 0 && $newSize < 16384 && $this->maxRingBufferSize >= 16384) {
                $newSize = 16384;
            }
        }
        if ($newSize <= $this->ringBufferSize) {
            return;
        }
        $ringBufferSizeWithSlack = $newSize + self::MAX_TRANSFORMED_WORD_LENGTH;
        // The old buffer is alive while the new one is filled: charge both.
        $old = $this->ringBytes;
        $this->ringBytes = $old + $ringBufferSizeWithSlack;
        $this->charge(0);
        $this->ringBuffer = substr($this->ringBuffer, 0, $this->ringBufferSize)
            . str_repeat("\0", $ringBufferSizeWithSlack - $this->ringBufferSize);
        $this->ringBytes = $ringBufferSizeWithSlack;
        $this->ringBufferSize = $newSize;
    }

    private function readNextMetablockHeader(): void
    {
        if ($this->inputEnd !== 0) {
            $this->nextRunningState = self::FINISHED;
            $this->runningState = self::INIT_WRITE;
            return;
        }
        $this->literalTreeGroup = [];
        $this->commandTreeGroup = [];
        $this->distanceTreeGroup = [];
        $this->tableBytes = $this->baseBytes;

        $this->decodeMetaBlockLength();
        if ($this->metaBlockLength === 0 && $this->isMetadata === 0) {
            return;
        }
        if ($this->isUncompressed !== 0 || $this->isMetadata !== 0) {
            $this->jumpToByteBoundary();
            $this->runningState = $this->isMetadata === 0 ? self::COPY_UNCOMPRESSED : self::READ_METADATA;
        } else {
            $this->runningState = self::COMPRESSED_BLOCK_START;
        }

        if ($this->isMetadata !== 0) {
            return;
        }
        $this->expectedTotalSize += $this->metaBlockLength;
        if ($this->expectedTotalSize > 1 << 30) {
            $this->expectedTotalSize = 1 << 30;
        }
        if ($this->ringBufferSize < $this->maxRingBufferSize) {
            $this->maybeReallocateRingBuffer();
        }
    }

    private function readMetablockPartition(int $treeType, int $numBlockTypes): int
    {
        $offset = $this->blockTrees[2 * $treeType];
        if ($numBlockTypes <= 1) {
            $this->blockTrees[2 * $treeType + 1] = $offset;
            $this->blockTrees[2 * $treeType + 2] = $offset;
            return 1 << 28;
        }

        $blockTypeAlphabetSize = $numBlockTypes + 2;
        $offset += $this->readHuffmanCode(
            $blockTypeAlphabetSize,
            $blockTypeAlphabetSize,
            $this->blockTrees,
            2 * $treeType,
        );
        $this->blockTrees[2 * $treeType + 1] = $offset;

        $blockLengthAlphabetSize = self::NUM_BLOCK_LENGTH_CODES;
        $offset += $this->readHuffmanCode(
            $blockLengthAlphabetSize,
            $blockLengthAlphabetSize,
            $this->blockTrees,
            2 * $treeType + 1,
        );
        $this->blockTrees[2 * $treeType + 2] = $offset;

        return $this->readBlockLength($this->blockTrees, 2 * $treeType + 1);
    }

    private function calculateDistanceLut(int $alphabetSizeLimit): void
    {
        $npostfix = $this->distancePostfixBits;
        $ndirect = $this->numDirectDistanceCodes;
        $postfix = 1 << $npostfix;
        $bits = 1;
        $half = 0;

        // Skip short codes.
        $i = self::NUM_DISTANCE_SHORT_CODES;

        // Fill direct codes.
        for ($j = 0; $j < $ndirect; $j++) {
            $this->distExtraBits[$i] = 0;
            $this->distOffset[$i] = $j + 1;
            $i++;
        }

        // Fill regular distance codes.
        while ($i < $alphabetSizeLimit) {
            $base = $ndirect + ((((2 + $half) << $bits) - 4) << $npostfix) + 1;
            // Always fill the complete group.
            for ($j = 0; $j < $postfix; $j++) {
                $this->distExtraBits[$i] = $bits;
                $this->distOffset[$i] = $base + $j;
                $i++;
            }
            $bits += $half;
            $half ^= 1;
        }
    }

    private static function huffmanTreeGroupAllocSize(int $alphabetSizeLimit, int $n): int
    {
        $maxTableSize = self::MAX_HUFFMAN_TABLE_SIZE[($alphabetSizeLimit + 31) >> 5];
        return $n + $n * $maxTableSize;
    }

    /** @param array<int, int> $group */
    private function decodeHuffmanTreeGroup(int $alphabetSizeMax, int $alphabetSizeLimit, int $n, array &$group): void
    {
        $next = $n;
        for ($i = 0; $i < $n; $i++) {
            $group[$i] = $next;
            $next += $this->readHuffmanCode($alphabetSizeMax, $alphabetSizeLimit, $group, $i);
        }
    }

    private function readMetablockHuffmanCodesAndContextMaps(): void
    {
        $this->numLiteralBlockTypes = $this->decodeVarLenUnsignedByte() + 1;
        $this->literalBlockLength = $this->readMetablockPartition(0, $this->numLiteralBlockTypes);
        $this->numCommandBlockTypes = $this->decodeVarLenUnsignedByte() + 1;
        $this->commandBlockLength = $this->readMetablockPartition(1, $this->numCommandBlockTypes);
        $this->numDistanceBlockTypes = $this->decodeVarLenUnsignedByte() + 1;
        $this->distanceBlockLength = $this->readMetablockPartition(2, $this->numDistanceBlockTypes);

        $this->fillBitWindow();
        $this->distancePostfixBits = $this->readFewBits(2);
        $this->numDirectDistanceCodes = $this->readFewBits(4) << $this->distancePostfixBits;
        $this->charge($this->numLiteralBlockTypes);
        $contextModes = '';
        for ($i = 0; $i < $this->numLiteralBlockTypes; $i++) {
            $this->fillBitWindow();
            $contextModes .= self::$chr[$this->readFewBits(2)];
        }
        $this->contextModes = $contextModes;

        $contextMapLength = $this->numLiteralBlockTypes << self::LITERAL_CONTEXT_BITS;
        $this->charge($contextMapLength);
        $this->contextMap = str_repeat("\0", $contextMapLength);
        $numLiteralTrees = $this->decodeContextMap($contextMapLength, $this->contextMap);
        $this->trivialLiteralContext = 1;
        for ($j = 0; $j < $contextMapLength; $j++) {
            if (ord($this->contextMap[$j]) !== $j >> self::LITERAL_CONTEXT_BITS) {
                $this->trivialLiteralContext = 0;
                break;
            }
        }

        $distContextMapLength = $this->numDistanceBlockTypes << self::DISTANCE_CONTEXT_BITS;
        $this->charge($distContextMapLength);
        $this->distContextMap = str_repeat("\0", $distContextMapLength);
        $numDistTrees = $this->decodeContextMap($distContextMapLength, $this->distContextMap);

        $this->literalTreeGroup = $this->allocateInts(
            self::huffmanTreeGroupAllocSize(self::NUM_LITERAL_CODES, $numLiteralTrees),
        );
        $this->decodeHuffmanTreeGroup(
            self::NUM_LITERAL_CODES,
            self::NUM_LITERAL_CODES,
            $numLiteralTrees,
            $this->literalTreeGroup,
        );
        $this->commandTreeGroup = $this->allocateInts(
            self::huffmanTreeGroupAllocSize(self::NUM_COMMAND_CODES, $this->numCommandBlockTypes),
        );
        $this->decodeHuffmanTreeGroup(
            self::NUM_COMMAND_CODES,
            self::NUM_COMMAND_CODES,
            $this->numCommandBlockTypes,
            $this->commandTreeGroup,
        );
        $distanceAlphabetSize = self::calculateDistanceAlphabetSize(
            $this->distancePostfixBits,
            $this->numDirectDistanceCodes,
            self::MAX_DISTANCE_BITS,
        );
        $this->distanceTreeGroup = $this->allocateInts(
            self::huffmanTreeGroupAllocSize($distanceAlphabetSize, $numDistTrees),
        );
        $this->decodeHuffmanTreeGroup(
            $distanceAlphabetSize,
            $distanceAlphabetSize,
            $numDistTrees,
            $this->distanceTreeGroup,
        );
        $this->calculateDistanceLut($distanceAlphabetSize);

        $this->contextMapSlice = 0;
        $this->distContextMapSlice = 0;
        $this->contextLookupOffset1 = ord($this->contextModes[0]) * 512;
        $this->contextLookupOffset2 = $this->contextLookupOffset1 + 256;
        $this->literalTreeIdx = 0;
        $this->commandTreeIdx = 0;

        $this->rings[4] = 1;
        $this->rings[5] = 0;
        $this->rings[6] = 1;
        $this->rings[7] = 0;
        $this->rings[8] = 1;
        $this->rings[9] = 0;
    }

    private function copyUncompressedData(): void
    {
        // Could happen if block ends at ring buffer end.
        if ($this->metaBlockLength <= 0) {
            $this->runningState = self::BLOCK_START;
            return;
        }
        $chunkLength = min($this->ringBufferSize - $this->pos, $this->metaBlockLength);
        $this->copyRawBytes($this->pos, $chunkLength);
        $this->metaBlockLength -= $chunkLength;
        $this->pos += $chunkLength;
        if ($this->pos === $this->ringBufferSize) {
            $this->nextRunningState = self::COPY_UNCOMPRESSED;
            $this->runningState = self::INIT_WRITE;
            return;
        }
        $this->runningState = self::BLOCK_START;
    }

    /** writeRingBuffer: appends the ready bytes to the output, refusing to pass the output cap. */
    private function writeRingBuffer(): void
    {
        $toWrite = $this->ringBufferBytesReady - $this->ringBufferBytesWritten;
        if ($toWrite > 0) {
            if ($this->outputLength + $toWrite > $this->maxOutput) {
                throw new OutputLimitExceeded('Decoding would exceed the output cap');
            }
            $this->output .= substr($this->ringBuffer, $this->ringBufferBytesWritten, $toWrite);
            $this->outputLength += $toWrite;
            $this->ringBufferBytesWritten += $toWrite;
        }
    }

    private function doUseDictionary(int $fence): void
    {
        if ($this->distance > self::MAX_ALLOWED_DISTANCE) {
            throw new CorruptStream('BROTLI_ERROR_INVALID_BACKWARD_REFERENCE');
        }
        // No compound dictionary is attached, so the address is never negative.
        $address = $this->distance - $this->maxDistance - 1;
        $wordLength = $this->copyLength;
        if ($wordLength > self::MAX_DICTIONARY_WORD_LENGTH) {
            throw new CorruptStream('BROTLI_ERROR_INVALID_BACKWARD_REFERENCE');
        }
        $shift = self::$dictionarySizeBits[$wordLength];
        if ($shift === 0) {
            throw new CorruptStream('BROTLI_ERROR_INVALID_BACKWARD_REFERENCE');
        }
        $offset = self::$dictionaryOffsets[$wordLength];
        $mask = (1 << $shift) - 1;
        $wordIdx = $address & $mask;
        $transformIdx = $address >> $shift;
        $offset += $wordIdx * $wordLength;
        if ($transformIdx >= self::NUM_RFC_TRANSFORMS) {
            throw new CorruptStream('BROTLI_ERROR_INVALID_BACKWARD_REFERENCE');
        }
        $len = $this->transformDictionaryWord($this->pos, $offset, $wordLength, $transformIdx);
        $this->pos += $len;
        $this->metaBlockLength -= $len;
        if ($this->pos >= $fence) {
            $this->nextRunningState = self::MAIN_LOOP;
            $this->runningState = self::INIT_WRITE;
            return;
        }
        $this->runningState = self::MAIN_LOOP;
    }

    /** Transform.transformDictionaryWord for the RFC transforms (none of which shift). */
    private function transformDictionaryWord(int $dstOffset, int $srcOffset, int $wordLen, int $transformIndex): int
    {
        $triplets = self::$transformTriplets ?? [];
        $storage = self::$prefixSuffixStorage;
        $heads = self::$prefixSuffixHeads;
        $dictionary = self::$dictionary ?? '';
        $offset = $dstOffset;
        $transformOffset = 3 * $transformIndex;
        $prefixIdx = $triplets[$transformOffset];
        $transformType = $triplets[$transformOffset + 1];
        $suffixIdx = $triplets[$transformOffset + 2];
        $prefix = $heads[$prefixIdx];
        $prefixEnd = $heads[$prefixIdx + 1];
        $suffix = $heads[$suffixIdx];
        $suffixEnd = $heads[$suffixIdx + 1];

        $omitFirst = $transformType - self::OMIT_FIRST_BASE;
        $omitLast = $transformType;
        if ($omitFirst < 1 || $omitFirst > self::OMIT_FIRST_LAST_LIMIT) {
            $omitFirst = 0;
        }
        if ($omitLast < 1 || $omitLast > self::OMIT_FIRST_LAST_LIMIT) {
            $omitLast = 0;
        }

        // Copy prefix.
        while ($prefix !== $prefixEnd) {
            $this->ringBuffer[$offset++] = $storage[$prefix++];
        }

        $len = $wordLen;
        // Copy trimmed word.
        if ($omitFirst > $len) {
            $omitFirst = $len;
        }
        $dictOffset = $srcOffset + $omitFirst;
        $len -= $omitFirst;
        $len -= $omitLast;
        $i = $len;
        while ($i > 0) {
            $this->ringBuffer[$offset++] = $dictionary[$dictOffset++];
            $i--;
        }

        // Ferment.
        if ($transformType === self::UPPERCASE_FIRST || $transformType === self::UPPERCASE_ALL) {
            $uppercaseOffset = $offset - $len;
            if ($transformType === self::UPPERCASE_FIRST) {
                $len = 1;
            }
            while ($len > 0) {
                $c0 = ord($this->ringBuffer[$uppercaseOffset]);
                if ($c0 < 0xC0) {
                    if ($c0 >= 97 && $c0 <= 122) { // in [a..z] range
                        $this->ringBuffer[$uppercaseOffset] = self::$chr[$c0 ^ 32];
                    }
                    $uppercaseOffset += 1;
                    $len -= 1;
                } elseif ($c0 < 0xE0) {
                    $c1 = ord($this->ringBuffer[$uppercaseOffset + 1]);
                    $this->ringBuffer[$uppercaseOffset + 1] = self::$chr[$c1 ^ 32];
                    $uppercaseOffset += 2;
                    $len -= 2;
                } else {
                    $c2 = ord($this->ringBuffer[$uppercaseOffset + 2]);
                    $this->ringBuffer[$uppercaseOffset + 2] = self::$chr[$c2 ^ 5];
                    $uppercaseOffset += 3;
                    $len -= 3;
                }
            }
        }

        // Copy suffix.
        while ($suffix !== $suffixEnd) {
            $this->ringBuffer[$offset++] = $storage[$suffix++];
        }

        return $offset - $dstOffset;
    }

    /**
     * INSERT_LOOP's literal runs. Upstream's two loops (trivial and context-dependent literal trees),
     * with the hot state in locals and fillBitWindow / readSymbol inlined: literals are the slowest
     * path in PHP, and a stream of zero-bit literals must not be able to outrun the deadline check.
     *
     * @param list<int> $contextLookup
     * @param list<string> $chr
     */
    private function insertLiterals(int $fence, int $ringBufferMask, array $contextLookup, array $chr): void
    {
        $ringBuffer = &$this->ringBuffer;
        $in = $this->in;
        $guard = $this->inLen + self::PADDING - 8;
        $tree = $this->literalTreeGroup;
        $contextMap = $this->contextMap;
        $pos = $this->pos;
        $j = $this->j;
        $insertLength = $this->insertLength;
        $acc = $this->acc;
        $bits = $this->bits;
        $p = $this->p;
        $blockLength = $this->literalBlockLength;
        $trivial = $this->trivialLiteralContext !== 0;
        $prevByte1 = ord($ringBuffer[($pos - 1) & $ringBufferMask]);
        $prevByte2 = ord($ringBuffer[($pos - 2) & $ringBufferMask]);
        while ($j < $insertLength) {
            if ($blockLength === 0) {
                [$this->acc, $this->bits, $this->p] = [$acc, $bits, $p];
                $this->decodeLiteralBlockSwitch();
                [$acc, $bits, $p, $blockLength] = [$this->acc, $this->bits, $this->p, $this->literalBlockLength];
            }
            if ($trivial) {
                $literalTreeIdx = $this->literalTreeIdx;
            } else {
                $literalContext = $contextLookup[$this->contextLookupOffset1 + $prevByte1]
                    | $contextLookup[$this->contextLookupOffset2 + $prevByte2];
                $literalTreeIdx = ord($contextMap[$this->contextMapSlice + $literalContext]);
            }
            $blockLength--;
            // fillBitWindow
            if ($bits < 32) {
                do {
                    $acc |= ord($in[$p++]) << $bits;
                    $bits += 8;
                } while ($bits <= 48);
                if ($p > $guard) {
                    throw new CorruptStream('BROTLI_ERROR_TRUNCATED_INPUT');
                }
            }
            // readSymbol
            $offset = $tree[$literalTreeIdx] + ($acc & self::HUFFMAN_TABLE_MASK);
            $entry = $tree[$offset];
            $n = $entry >> 16;
            if ($n > self::HUFFMAN_TABLE_BITS) {
                $offset += ($entry & 0xFFFF) + (($acc & ((1 << $n) - 1)) >> self::HUFFMAN_TABLE_BITS);
                $entry = $tree[$offset];
                $n = ($entry >> 16) + self::HUFFMAN_TABLE_BITS;
            }
            $acc >>= $n;
            $bits -= $n;
            $prevByte2 = $prevByte1;
            $prevByte1 = $entry & 0xFFFF;
            $ringBuffer[$pos++] = $chr[$prevByte1];
            $j++;
            if (($j & 0xFFFF) === 0) {
                $this->checkDeadline();
            }
            if ($pos >= $fence) {
                $this->nextRunningState = self::INSERT_LOOP;
                $this->runningState = self::INIT_WRITE;
                break;
            }
        }
        unset($ringBuffer);
        [$this->acc, $this->bits, $this->p] = [$acc, $bits, $p];
        $this->literalBlockLength = $blockLength;
        $this->pos = $pos;
        $this->j = $j;
    }

    /** Decode.decompress, for a stream whose input is complete. */
    private function run(): string
    {
        $windowBits = $this->decodeWindowBits();
        if ($windowBits === -1) { // Reserved case for future expansion.
            throw new CorruptStream('BROTLI_ERROR_INVALID_WINDOW_BITS');
        }
        // The ring buffer never needs to exceed the output cap: the first flush of a buffer larger
        // than the cap refuses the stream, so no byte is ever read back from a wrapped buffer.
        $ringCap = 1;
        while ($ringCap <= $this->maxOutput && $ringCap < 1 << 30) {
            $ringCap <<= 1;
        }
        $this->maxRingBufferSize = min(1 << $windowBits, $ringCap);
        $this->maxBackwardDistance = (1 << $windowBits) - 16;

        $cmdLookup = self::$cmdLookup ?? [];
        $contextLookup = self::$contextLookup ?? [];
        $chr = self::$chr;
        $fence = $this->ringBufferSize;
        $ringBufferMask = $this->ringBufferSize - 1;

        while ($this->runningState !== self::FINISHED) {
            if ((++$this->work & 255) === 0) {
                $this->checkDeadline();
            }
            switch ($this->runningState) {
                case self::BLOCK_START:
                    if ($this->metaBlockLength < 0) {
                        throw new CorruptStream('BROTLI_ERROR_INVALID_METABLOCK_LENGTH');
                    }
                    $this->readNextMetablockHeader();
                    // Ring-buffer would be reallocated here.
                    $fence = $this->ringBufferSize;
                    $ringBufferMask = $this->ringBufferSize - 1;
                    break;

                case self::COMPRESSED_BLOCK_START:
                    $this->readMetablockHuffmanCodesAndContextMaps();
                    $this->runningState = self::MAIN_LOOP;
                    break;

                case self::MAIN_LOOP:
                    if ($this->metaBlockLength <= 0) {
                        $this->runningState = self::BLOCK_START;
                        break;
                    }
                    if ($this->commandBlockLength === 0) {
                        $this->decodeCommandBlockSwitch();
                    }
                    $this->commandBlockLength--;
                    $this->fillBitWindow();
                    $cmdCode = $this->readSymbol($this->commandTreeGroup, $this->commandTreeIdx) << 2;
                    $insertAndCopyExtraBits = $cmdLookup[$cmdCode];
                    $insertLengthOffset = $cmdLookup[$cmdCode + 1];
                    $copyLengthOffset = $cmdLookup[$cmdCode + 2];
                    $this->distanceCode = $cmdLookup[$cmdCode + 3];
                    $this->fillBitWindow();
                    $this->insertLength = $insertLengthOffset + $this->readFewBits($insertAndCopyExtraBits & 0xFF);
                    $this->fillBitWindow();
                    $this->copyLength = $copyLengthOffset + $this->readFewBits($insertAndCopyExtraBits >> 8);
                    $this->j = 0;
                    $this->runningState = self::INSERT_LOOP;
                    break;

                case self::INSERT_LOOP:
                    $this->insertLiterals($fence, $ringBufferMask, $contextLookup, $chr);
                    if ($this->runningState !== self::INSERT_LOOP) {
                        break;
                    }
                    $this->metaBlockLength -= $this->insertLength;
                    if ($this->metaBlockLength <= 0) {
                        $this->runningState = self::MAIN_LOOP;
                        break;
                    }
                    $distanceCode = $this->distanceCode;
                    if ($distanceCode < 0) {
                        // distanceCode is untouched; assigning it 0 won't affect distance ring buffer rolling.
                        $this->distance = $this->rings[$this->distRbIdx];
                    } else {
                        if ($this->distanceBlockLength === 0) {
                            $this->decodeDistanceBlockSwitch();
                        }
                        $this->distanceBlockLength--;
                        $this->fillBitWindow();
                        $distTreeIdx = ord($this->distContextMap[$this->distContextMapSlice + $distanceCode]);
                        $distanceCode = $this->readSymbol($this->distanceTreeGroup, $distTreeIdx);
                        if ($distanceCode < self::NUM_DISTANCE_SHORT_CODES) {
                            $index = ($this->distRbIdx + self::DISTANCE_SHORT_CODE_INDEX_OFFSET[$distanceCode]) & 0x3;
                            $this->distance = $this->rings[$index]
                                + self::DISTANCE_SHORT_CODE_VALUE_OFFSET[$distanceCode];
                            if ($this->distance < 0) {
                                throw new CorruptStream('BROTLI_ERROR_NEGATIVE_DISTANCE');
                            }
                        } else {
                            $extraBits = $this->distExtraBits[$distanceCode];
                            $this->fillBitWindow();
                            $bits = $this->readFewBits($extraBits);
                            $this->distance = $this->distOffset[$distanceCode] + ($bits << $this->distancePostfixBits);
                        }
                    }

                    if ($this->maxDistance !== $this->maxBackwardDistance && $this->pos < $this->maxBackwardDistance) {
                        $this->maxDistance = $this->pos;
                    } else {
                        $this->maxDistance = $this->maxBackwardDistance;
                    }

                    if ($this->distance > $this->maxDistance) {
                        $this->runningState = self::USE_DICTIONARY;
                        break;
                    }

                    if ($distanceCode > 0) {
                        $this->distRbIdx = ($this->distRbIdx + 1) & 0x3;
                        $this->rings[$this->distRbIdx] = $this->distance;
                    }

                    if ($this->copyLength > $this->metaBlockLength) {
                        throw new CorruptStream('BROTLI_ERROR_INVALID_BACKWARD_REFERENCE');
                    }
                    $this->j = 0;
                    $this->runningState = self::COPY_LOOP;
                    break;

                case self::COPY_LOOP:
                    // Upstream's loops, with the hot state in locals and the buffer by reference
                    // (written in place); copies run byte by byte, so an overlapping copy repeats its
                    // source exactly as upstream's does.
                    $ringBuffer = &$this->ringBuffer;
                    $pos = $this->pos;
                    $distance = $this->distance;
                    $copyLength = $this->copyLength - $this->j;
                    $src = ($pos - $distance) & $ringBufferMask;
                    if ($src + $copyLength < $ringBufferMask && $pos + $copyLength < $ringBufferMask) {
                        for ($end = $pos + $copyLength; $pos < $end;) {
                            $ringBuffer[$pos++] = $ringBuffer[$src++];
                        }
                        $this->runningState = self::MAIN_LOOP;
                    } else {
                        // Up to the fence, where the buffer is written out and wraps.
                        $copyLength = min($copyLength, $fence - $pos);
                        for ($end = $pos + $copyLength; $pos < $end; $pos++) {
                            $ringBuffer[$pos] = $ringBuffer[($pos - $distance) & $ringBufferMask];
                        }
                        if ($pos >= $fence) {
                            $this->nextRunningState = self::COPY_LOOP;
                            $this->runningState = self::INIT_WRITE;
                        } else {
                            $this->runningState = self::MAIN_LOOP;
                        }
                    }
                    unset($ringBuffer);
                    $this->j += $copyLength;
                    $this->metaBlockLength -= $copyLength;
                    $this->pos = $pos;
                    break;

                case self::USE_DICTIONARY:
                    $this->doUseDictionary($fence);
                    break;

                case self::READ_METADATA:
                    while ($this->metaBlockLength > 0) {
                        $this->fillBitWindow();
                        $this->readFewBits(8);
                        $this->metaBlockLength--;
                    }
                    $this->runningState = self::BLOCK_START;
                    break;

                case self::COPY_UNCOMPRESSED:
                    $this->copyUncompressedData();
                    break;

                case self::INIT_WRITE:
                    $this->ringBufferBytesReady = min($this->pos, $this->ringBufferSize);
                    $this->runningState = self::WRITE;
                    break;

                case self::WRITE:
                    $this->checkDeadline();
                    $this->writeRingBuffer();
                    if ($this->pos >= $this->maxBackwardDistance) {
                        $this->maxDistance = $this->maxBackwardDistance;
                    }
                    // Wrap the ringBuffer.
                    if ($this->pos >= $this->ringBufferSize) {
                        for ($k = $this->ringBufferSize; $k < $this->pos; $k++) {
                            $this->ringBuffer[$k - $this->ringBufferSize] = $this->ringBuffer[$k];
                        }
                        $this->pos &= $ringBufferMask;
                        $this->ringBufferBytesWritten = 0;
                    }
                    $this->runningState = $this->nextRunningState;
                    break;

                default:
                    throw new \LogicException('Unexpected decoder state ' . $this->runningState);
            }
        }
        if ($this->metaBlockLength < 0) {
            throw new CorruptStream('BROTLI_ERROR_INVALID_METABLOCK_LENGTH');
        }
        $this->jumpToByteBoundary();
        $this->checkHealth(true);
        return $this->output;
    }
}
