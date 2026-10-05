<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Fonts;

use Thallo\Core\Content\Fonts\Brotli\BrotliDecoder;

/**
 * Reads a WOFF2 file's weight, style and weight range from its own tables (OS/2, head, fvar), so a
 * face is described by the file rather than by labels (block typeface spec §2.3).
 *
 * WOFF2 (W3C Recommendation): a 48-byte header, a table directory, then one Brotli stream holding
 * every table. OS/2, head and fvar are never transformed, so they are read straight from the stream.
 */
final class Woff2FaceReader
{
    private const SIGNATURE = 'wOF2';
    private const HEADER_SIZE = 48;
    private const UNREADABLE = 'Couldn\'t read this font\'s data';

    /** The WOFF2 known-table tags, indexed by the directory entry's 6-bit tag index. */
    private const KNOWN_TAGS = [
        'cmap', 'head', 'hhea', 'hmtx', 'maxp', 'name', 'OS/2', 'post', 'cvt ', 'fpgm', 'glyf', 'loca',
        'prep', 'CFF ', 'VORG', 'EBDT', 'EBLC', 'gasp', 'hdmx', 'kern', 'LTSH', 'PCLT', 'VDMX', 'vhea',
        'vmtx', 'BASE', 'GDEF', 'GPOS', 'GSUB', 'EBSC', 'JSTF', 'MATH', 'CBDT', 'CBLC', 'COLR', 'CPAL',
        'SVG ', 'sbix', 'acnt', 'avar', 'bdat', 'bloc', 'bsln', 'cvar', 'fdsc', 'feat', 'fmtx', 'fvar',
        'gvar', 'hsty', 'just', 'lcar', 'mort', 'morx', 'opbd', 'prop', 'trak', 'Zapf', 'Silf', 'Glat',
        'Gloc', 'Feat', 'Sill',
    ];

    /** @param int $maxDecompressed the most table data a font may hold once decompressed, in bytes */
    public function __construct(
        private readonly BrotliDecoder $brotli,
        private readonly int $maxDecompressed = 33554432,
    ) {
    }

    /** @throws UnreadableFont with the reason to show the person who uploaded the file */
    public function read(string $path): FaceMetadata
    {
        $font = is_file($path) ? @file_get_contents($path) : false;
        if ($font === false) {
            throw new UnreadableFont(self::UNREADABLE);
        }
        if (strlen($font) < 4 || substr($font, 0, 4) !== self::SIGNATURE) {
            throw new UnreadableFont('Not a WOFF2 font');
        }
        if (strlen($font) < self::HEADER_SIZE || substr($font, 4, 4) === 'ttcf') {
            throw new UnreadableFont(self::UNREADABLE); // too short for a header, or a font collection
        }
        $numTables = self::uint16($font, 12);
        $totalCompressedSize = self::uint32($font, 20);

        // The table directory: where each table sits in the decompressed stream.
        $pos = self::HEADER_SIZE;
        $offset = 0;
        $tables = [];
        for ($i = 0; $i < $numTables; $i++) {
            $flags = self::byte($font, $pos++);
            $tagIndex = $flags & 0x3F;
            $version = $flags >> 6;
            if ($tagIndex === 63) {
                $tag = substr($font, $pos, 4);
                $pos += 4;
            } else {
                $tag = self::KNOWN_TAGS[$tagIndex];
            }
            $origLength = self::base128($font, $pos);
            $isGlyfOrLoca = $tag === 'glyf' || $tag === 'loca';
            $transformed = $isGlyfOrLoca ? $version === 0 : $version !== 0;
            $length = $transformed ? self::base128($font, $pos) : $origLength;
            $tables[$tag] = [$offset, $length];
            $offset += $length;
        }

        // Decode at most what the directory itself says the tables add up to.
        if ($offset > $this->maxDecompressed) {
            throw new UnreadableFont('This font is too large to read');
        }
        if ($pos + $totalCompressedSize > strlen($font)) {
            throw new UnreadableFont(self::UNREADABLE);
        }
        $stream = $this->brotli->decode(substr($font, $pos, $totalCompressedSize), $offset);
        if (strlen($stream) !== $offset) {
            throw new UnreadableFont(self::UNREADABLE);
        }

        $os2 = self::table($stream, $tables, 'OS/2', 64);
        $head = self::table($stream, $tables, 'head', 46);
        if ($os2 !== null) {
            $weight = max(1, min(1000, self::uint16($os2, 4)));   // usWeightClass
            $italic = (self::uint16($os2, 62) & 0x1) !== 0;        // fsSelection bit 0: ITALIC
        } elseif ($head !== null) {
            $macStyle = self::uint16($head, 44);
            $weight = ($macStyle & 0x1) !== 0 ? 700 : 400;          // macStyle bit 0: bold
            $italic = ($macStyle & 0x2) !== 0;                      // macStyle bit 1: italic
        } else {
            throw new UnreadableFont('Couldn\'t read this font\'s weight table');
        }

        $range = self::weightAxis(self::table($stream, $tables, 'fvar', 16));
        if ($range === null) {
            return new FaceMetadata($weight, $weight, $italic, false);
        }
        return new FaceMetadata($range[0], $range[1], $italic, true);
    }

    /**
     * The `wght` axis of an fvar table, rounded and clamped to 1–1000.
     *
     * @return array{int, int}|null
     */
    private static function weightAxis(?string $fvar): ?array
    {
        if ($fvar === null) {
            return null;
        }
        $axesArrayOffset = self::uint16($fvar, 4);
        $axisCount = self::uint16($fvar, 8);
        $axisSize = self::uint16($fvar, 10);
        if ($axisSize < 20) {
            throw new UnreadableFont(self::UNREADABLE);
        }
        for ($i = 0; $i < $axisCount; $i++) {
            $axis = $axesArrayOffset + $i * $axisSize;
            if ($axis + 20 > strlen($fvar)) {
                throw new UnreadableFont(self::UNREADABLE);
            }
            if (substr($fvar, $axis, 4) === 'wght') {
                $min = (int) round(self::fixed($fvar, $axis + 4));
                $max = (int) round(self::fixed($fvar, $axis + 12));
                $min = max(1, min(1000, $min));
                $max = max(1, min(1000, $max));
                return [min($min, $max), max($min, $max)];
            }
        }
        return null;
    }

    /**
     * A table's bytes from the decompressed stream, or null when the font has no such table.
     *
     * @param array<string, array{int, int}> $tables
     */
    private static function table(string $stream, array $tables, string $tag, int $minLength): ?string
    {
        if (!isset($tables[$tag])) {
            return null;
        }
        [$offset, $length] = $tables[$tag];
        if ($length < $minLength) {
            throw new UnreadableFont(self::UNREADABLE);
        }
        return substr($stream, $offset, $length);
    }

    /** UIntBase128 (WOFF2 §4.1): at most five bytes, no leading zero byte, no value past 2^32 - 1. */
    private static function base128(string $data, int &$pos): int
    {
        $value = 0;
        for ($i = 0; $i < 5; $i++) {
            $byte = self::byte($data, $pos++);
            if ($i === 0 && $byte === 0x80) {
                throw new UnreadableFont(self::UNREADABLE);
            }
            if (($value & 0xFE000000) !== 0) {
                throw new UnreadableFont(self::UNREADABLE);
            }
            $value = ($value << 7) | ($byte & 0x7F);
            if (($byte & 0x80) === 0) {
                return $value;
            }
        }
        throw new UnreadableFont(self::UNREADABLE);
    }

    private static function byte(string $data, int $at): int
    {
        if ($at >= strlen($data)) {
            throw new UnreadableFont(self::UNREADABLE);
        }
        return ord($data[$at]);
    }

    private static function uint16(string $data, int $at): int
    {
        if ($at + 2 > strlen($data)) {
            throw new UnreadableFont(self::UNREADABLE);
        }
        return (ord($data[$at]) << 8) | ord($data[$at + 1]);
    }

    private static function uint32(string $data, int $at): int
    {
        if ($at + 4 > strlen($data)) {
            throw new UnreadableFont(self::UNREADABLE);
        }
        return (self::uint16($data, $at) << 16) | self::uint16($data, $at + 2);
    }

    /** A Fixed (signed 16.16) value. */
    private static function fixed(string $data, int $at): float
    {
        $raw = self::uint32($data, $at);
        if ($raw >= 0x80000000) {
            $raw -= 0x100000000;
        }
        return $raw / 65536;
    }
}
