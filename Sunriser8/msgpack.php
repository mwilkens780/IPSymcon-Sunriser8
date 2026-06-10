<?php

declare(strict_types=1);

/**
 * Minimal MessagePack encoder/decoder for the SunRiser 8 API.
 * Handles all types used by the SunRiser firmware.
 */
class MsgPack
{
    // ─── Encode ──────────────────────────────────────────────────────────────

    public static function encode(mixed $val): string
    {
        if ($val === null)  return "\xc0";
        if ($val === false) return "\xc2";
        if ($val === true)  return "\xc3";

        if (is_int($val)) {
            if ($val >= 0 && $val <= 0x7f)     return chr($val);
            if ($val >= -32 && $val < 0)        return chr($val & 0xff);
            if ($val >= 0 && $val <= 0xff)      return "\xcc" . chr($val);
            if ($val >= 0 && $val <= 0xffff)    return "\xcd" . pack('n', $val);
            if ($val >= 0)                      return "\xce" . pack('N', $val);
            if ($val >= -128)                   return "\xd0" . chr($val & 0xff);
            if ($val >= -32768)                 return "\xd1" . pack('n', $val & 0xffff);
            return "\xd2" . pack('N', $val & 0xffffffff);
        }

        if (is_float($val)) {
            $b = pack('d', $val);
            if (pack('v', 1) === "\x01\x00") $b = strrev($b); // LE → BE
            return "\xcb" . $b;
        }

        if (is_string($val)) {
            $len = strlen($val);
            if ($len <= 31)      return chr(0xa0 | $len) . $val;
            if ($len <= 0xff)    return "\xd9" . chr($len) . $val;
            if ($len <= 0xffff)  return "\xda" . pack('n', $len) . $val;
            return "\xdb" . pack('N', $len) . $val;
        }

        if (is_array($val)) {
            // Associative → map; sequential → array
            $isSeq = array_is_list($val);
            $count = count($val);

            if ($isSeq) {
                $out = $count <= 15
                    ? chr(0x90 | $count)
                    : "\xdc" . pack('n', $count);
                foreach ($val as $item) {
                    $out .= self::encode($item);
                }
                return $out;
            } else {
                $out = $count <= 15
                    ? chr(0x80 | $count)
                    : "\xde" . pack('n', $count);
                foreach ($val as $k => $v) {
                    $out .= self::encode($k) . self::encode($v);
                }
                return $out;
            }
        }

        return "\xc0"; // null fallback
    }

    // ─── Decode ──────────────────────────────────────────────────────────────

    public static function decode(string $data): mixed
    {
        $pos = 0;
        return self::decodeAt($data, $pos);
    }

    private static function decodeAt(string $data, int &$pos): mixed
    {
        if ($pos >= strlen($data)) {
            return null;
        }

        $b = ord($data[$pos++]);

        // Positive fixint (0xxxxxxx)
        if ($b <= 0x7f) return $b;

        // Fixmap (1000xxxx)
        if (($b & 0xf0) === 0x80) {
            return self::readMap($data, $pos, $b & 0x0f);
        }

        // Fixarray (1001xxxx)
        if (($b & 0xf0) === 0x90) {
            return self::readArray($data, $pos, $b & 0x0f);
        }

        // Fixstr (101xxxxx)
        if (($b & 0xe0) === 0xa0) {
            $len = $b & 0x1f;
            $s   = substr($data, $pos, $len);
            $pos += $len;
            return $s;
        }

        // Negative fixint (111xxxxx)
        if (($b & 0xe0) === 0xe0) return $b - 256;

        switch ($b) {
            case 0xc0: return null;
            case 0xc2: return false;
            case 0xc3: return true;

            case 0xca: // float32
                $f = unpack('f', self::readBE($data, $pos, 4));
                return (float) $f[1];
            case 0xcb: // float64
                $f = unpack('d', self::readBE($data, $pos, 8));
                return (float) $f[1];

            case 0xcc: return ord($data[$pos++]);
            case 0xcd: $v = unpack('n', substr($data, $pos, 2))[1]; $pos += 2; return $v;
            case 0xce: $v = unpack('N', substr($data, $pos, 4))[1]; $pos += 4; return $v;

            case 0xd0:
                $v = ord($data[$pos++]);
                return $v >= 128 ? $v - 256 : $v;
            case 0xd1:
                $v = unpack('n', substr($data, $pos, 2))[1]; $pos += 2;
                return $v >= 32768 ? $v - 65536 : $v;
            case 0xd2:
                $v = unpack('N', substr($data, $pos, 4))[1]; $pos += 4;
                return $v >= 0x80000000 ? (int) ($v - 0x100000000) : $v;

            case 0xd9: $len = ord($data[$pos++]); $s = substr($data, $pos, $len); $pos += $len; return $s;
            case 0xda: $len = unpack('n', substr($data, $pos, 2))[1]; $pos += 2; $s = substr($data, $pos, $len); $pos += $len; return $s;
            case 0xdb: $len = unpack('N', substr($data, $pos, 4))[1]; $pos += 4; $s = substr($data, $pos, $len); $pos += $len; return $s;

            case 0xdc: $n = unpack('n', substr($data, $pos, 2))[1]; $pos += 2; return self::readArray($data, $pos, $n);
            case 0xdd: $n = unpack('N', substr($data, $pos, 4))[1]; $pos += 4; return self::readArray($data, $pos, $n);
            case 0xde: $n = unpack('n', substr($data, $pos, 2))[1]; $pos += 2; return self::readMap($data, $pos, $n);
            case 0xdf: $n = unpack('N', substr($data, $pos, 4))[1]; $pos += 4; return self::readMap($data, $pos, $n);
        }

        return null;
    }

    private static function readArray(string $data, int &$pos, int $count): array
    {
        $arr = [];
        for ($i = 0; $i < $count; $i++) {
            $arr[] = self::decodeAt($data, $pos);
        }
        return $arr;
    }

    private static function readMap(string $data, int &$pos, int $count): array
    {
        $map = [];
        for ($i = 0; $i < $count; $i++) {
            $k       = self::decodeAt($data, $pos);
            $map[$k] = self::decodeAt($data, $pos);
        }
        return $map;
    }

    private static function readBE(string $data, int &$pos, int $bytes): string
    {
        $chunk = substr($data, $pos, $bytes);
        $pos  += $bytes;
        if (pack('v', 1) === "\x01\x00") {
            $chunk = strrev($chunk); // LE system → reverse to native for unpack
        }
        return $chunk;
    }
}
