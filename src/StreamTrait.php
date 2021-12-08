<?php

namespace ShInUeXx\Streams;

use GMP;
use InvalidArgumentException;
use OverflowException;
use ReflectionClass;

use function is_resource, get_resource_type, fread, fwrite, fseek, ord, chr, fclose, strlen, min, error_get_last, sprintf, strrev;
use function gmp_init, gmp_intval, gmp_or;
use function current, pack, unpack;
use const PHP_INT_SIZE, SEEK_SET;

trait StreamTrait
{
    private static ReflectionClass $_reflection;
    protected $resource;

    public static function FromPath(string $path): self
    {
        $obj = static::GetReflection()->newInstanceWithoutConstructor();
        $obj->setResource(fopen($path, 'r+'));
        return $obj;
    }

    public static function FromString(string $data): self
    {
        $fp = fopen('php://memory', 'w+');
        fwrite($fp, $data);
        fseek($fp, 0);
        $obj = static::GetReflection()->newInstanceWithoutConstructor();
        $obj->setResource($fp);
        return $obj;
    }

    private static function GetReflection(): ReflectionClass
    {
        if (!isset(self::$_reflection)) {
            self::$_reflection = new ReflectionClass(static::class);
        }
        return self::$_reflection;
    }

    /**
     * Set resource
     */
    protected function setResource($resource): void
    {
        if (!is_resource($resource) || get_resource_type($resource) !== 'stream') throw new StreamException('$resource is not a stream');
        $this->resource = $resource;
    }

    /**
     * Read from stream
     * @throws StreamException
     */
    public function read(int $size): string
    {
        $txt = @fread($this->resource, $size);
        if ($txt === false) {
            $errstr = error_get_last()['message'];
            throw new StreamException(sprintf('Could not read: %s', $errstr));
        }
        return $txt;
    }

    /**
     * Write to stream
     * @throws StreamException
     */
    public function write(string $buffer, int $length = null): int
    {
        $written = @fwrite($buffer, $buffer, $length);
        if ($written === false) {
            $errstr = error_get_last()['message'];
            throw new StreamException(sprintf('Could not write: %s', $errstr));
        }
        return $written;
    }

    /**
     * Seek position in stream
     * @throws StreamException
     */
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $r = @fseek($this->resource, $offset, $whence);
        if ($r == -1) {
            $errstr = error_get_last()['message'];
            throw new StreamException(sprintf('Could not seek: %s', $errstr));
        }
    }

    /**
     * Get current position from stream
     * @throws StreamException
     */
    public function tell(): int
    {
        $position = @ftell($this->resource);
        if ($position === false) {
            $errstr = error_get_last()['message'];
            throw new StreamException(sprintf('Could not get position: %s', $errstr));
        }
        return $position;
    }

    /**
     * Check for end-of-stream
     */
    public function eof(): bool
    {
        return @feof($this->resource);
    }

    /**
     * Read one byte from stream
     * @throws StreamException
     */
    public function readByte(): int
    {
        $txt = $this->read(1);
        return $txt == '' ? -1 : ord($txt);
    }

    /**
     * Write one byte to stream
     * @throws StreamException
     */
    public function writeByte(int $byte): int
    {
        $chr = chr($byte & 0xff);
        return $this->write($chr);
    }

    /**
     * Read integer from stream
     * @throws StreamException
     * @throws OverflowException
     */
    public function readInt(int $size, bool $isBigEndian = true): int
    {
        if (PHP_INT_SIZE < $size) throw new OverflowException(sprintf('PHP_INT_SIZE(%d) is smaller than $size(%d)', PHP_INT_SIZE, $size));
        $buff = $this->read($size);
        if (!$isBigEndian) $buff = strrev($buff);
        $out = 0;
        $c = min(strlen($buff), $size);
        for ($i = 0; $i < $c; ++$i) {
            $out = ($out << 8) | ord($buff[$i]);
        }
        return $out;
    }

    /**
     * Write integer to stream
     * @throws StreamException
     */
    public function writeInt(int $value, int $size, bool $isBigEndian = true): int
    {
        $buff = '';
        for ($i = 0; $i < $size; $i++) {
            $buff .= chr($value & 0xff);
            $value >>= 8;
        }
        if ($isBigEndian) $buff = strrev($buff);
        return $this->write($buff);
    }

    /**
     * Read integer as GMP value
     * @throws StreamException
     */
    public function readIntGMP(int $size, bool $isBigEndian = true): GMP
    {
        $buff = $this->read($size);
        if (!$isBigEndian) $buff = strrev($buff);
        $out = gmp_init(0);
        for ($i = 0; $i < $size; $i++) {
            $out = gmp_or(gmp_mul($out, 256), chr($buff[$i]));
        }
        return $out;
    }

    /**
     * Write GMP value to stream
     * @throws StreamException
     */
    public function writeIntGMP(GMP $value, int $size, bool $isBigEndian = true): int
    {
        $buff = '';
        for ($i = 0; $i < $size; $i++) {
            $buff .= chr(gmp_intval(gmp_and($value, 0xff)));
            $value = gmp_div($value, 256);
        }
        if ($isBigEndian) $buff = strrev($buff);
        return $this->write($buff);
    }

    /**
     * Read variable length integer
     * @throws StreamException
     */
    public function readVariableLengthInt(): int
    {
        $out = 0;
        do {
            $c = $this->readByte();
            $out = ($out << 7) | ($c & 0x7f);
        } while ($c & 0x80);
        return $out;
    }

    /**
     * Write variable length integer
     * @throws StreamException
     */
    public function writeVariableLengthInt(int $value): int
    {
        if ($value < 0) throw new InvalidArgumentException('$value cannot be negative');
        $buff = chr($value & 0x7f);
        while ($value >>= 7) {
            $c = ($value & 0x7f) | (0x80);
            $buff .= chr($c);
        }
        $buff = strrev($buff);
        return $this->write($buff);
    }

    /**
     * Close stream
     */
    public function close(): void
    {
        @fclose($this->resource);
        $this->resource = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    static private function floatToBits(float $value): int
    {
        return current(unpack('L', pack('f', $value)));
    }

    static private function bitsToFloat(int $value): float
    {
        return current(unpack('f', pack('L', $value)));
    }

    static private function doubleToBits(float $value): int
    {
        return current(unpack('Q', pack('d', $value)));
    }

    static private function bitsToDouble(int $value): float
    {
        return current(unpack('d', pack('Q', $value)));
    }

    /**
     * Read float as float 32 bits
     * @throws StreamException
     */
    public function readFloat(bool $isBigEndian = true): float
    {
        $int = $this->readInt(4, $isBigEndian);
        return self::bitsToFloat($int);
    }

    /**
     * Read float as double 64 bits
     * @throws StreamException
     */
    public function readDouble(bool $isBigEndian = true): float
    {
        $int = $this->readInt(8, $isBigEndian);
        return self::bitsToDouble($int);
    }

    /**
     * Write float as float 32 bits
     * @throws StreamException
     */
    public function writeFloat(float $value, bool $isBigEndian = true): int
    {
        $int = self::floatToBits($value);
        return $this->writeInt($int, 4, $isBigEndian);
    }

    /**
     * Write float as double 64 bits
     * @throws StreamException
     */
    public function writeDouble(float $value, bool $isBigEndian = true): int
    {
        $int = self::doubleToBits($value);
        return $this->writeInt($int, 8, $isBigEndian);
    }

    /**
     * Read bool from stream
     * @throws StreamException
     */
    public function readBool(): bool
    {
        return $this->readByte() != 0;
    }

    /**
     * Write bool to stream
     * @throws StreamException
     */
    public function writeBool(bool $value): int
    {
        return $this->writeByte($value ? 1 : 0);
    }

    /**
     * Read from stream until $delimiter is found
     * @param string $delimiter
     * @throws StreamException
     */
    public function readUntil(string $delimiter): string
    {
        $buff = '';
        while (strpos($buff, $delimiter) === false) {
            $buff .= $this->read(1);
        }
        return $buff;
    }

    /**
     * Read from stream until new line is found
     */
    public function readLine(): string
    {
        $txt = @fgets($this->resource);
        if ($txt === false) {
            $errstr = error_get_last()['message'];
            throw new StreamException(sprintf('Could not read: %s', $errstr));
        }
        return $txt;
    }

    /**
     * Write line to stream 
     * @param string $txt
     * @param string|null $newLine
     * @throws StreamException
     */
    public function writeLine(string $txt, string $newLine = PHP_EOL): int
    {
        return $this->write($txt . $newLine);
    }
}
