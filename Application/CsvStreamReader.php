<?php

namespace App\GP247\Plugins\DataImport\Application;

use App\GP247\Plugins\DataImport\Domain\SourceEncoding;

/**
 * Reads a CSV file record by record from a byte offset, in constant memory, and returns each
 * record decoded to UTF-8 with the byte offset that follows it (the run's cursor).
 *
 * RFC 4180: cells may be enclosed in `"`, an enclosed `""` is a quote, an enclosed cell may hold
 * line breaks. No backslash escaping (see SourceEncoding — 0x5C is part of CP932 characters).
 * Limits guard against hostile files: a record over MAX_RECORD_BYTES, a cell over
 * MAX_CELL_BYTES or more than MAX_COLUMNS cells becomes an error record, and reading goes on.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-staging-validation, US-data-import-encoding-detection
 * @aidlc-adr data-import_file-formats
 */
final class CsvStreamReader
{
    public const MAX_RECORD_BYTES = 1048576;
    public const MAX_CELL_BYTES = 65536;
    public const MAX_COLUMNS = 500;
    private const BLOCK = 262144;

    /** @var resource */
    private $handle;
    private string $buffer = '';
    private int $bufferStart;
    private bool $eof = false;

    /**
     * @param string $path      File to read.
     * @param string $encoding  An allowed SourceEncoding name.
     * @param string $delimiter One of SourceEncoding::DELIMITERS.
     * @param int    $offset    Byte offset to start at (after a BOM for the first call).
     * @throws \InvalidArgumentException On an unknown set / delimiter or an unreadable file.
     */
    public function __construct(string $path, private readonly string $encoding, private readonly string $delimiter, int $offset = 0)
    {
        if (!SourceEncoding::allowed($encoding) || !in_array($delimiter, SourceEncoding::DELIMITERS, true)) {
            throw new \InvalidArgumentException('Unsupported encoding or delimiter.');
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \InvalidArgumentException('The source file cannot be read.');
        }
        $this->handle = $handle;
        fseek($this->handle, $offset);
        $this->bufferStart = $offset;
    }

    /**
     * Close the file.
     */
    public function __destruct()
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    /**
     * The next record.
     *
     * @return array{cells: array<int, string>, end: int, error: string|null}|null Null at the end of the file.
     *         `error`: 'encoding' | 'too_long' | 'too_many_columns' (cells are empty then).
     */
    public function next(): ?array
    {
        $cells = [];
        $cell = '';
        $quoted = false;
        $pos = 0;
        $length = 0;
        $error = null;
        $started = false;
        $stops = $this->delimiter . "\"
";

        while (true) {
            if ($pos >= strlen($this->buffer) && !$this->fill()) {
                break;
            }
            $started = true;
            if (!$quoted) {
                // Fast path: copy the run of plain bytes up to the next CSV syntax byte.
                $run = strcspn($this->buffer, $stops, $pos);
                if ($run > 0) {
                    if ($error === null) {
                        $cell .= substr($this->buffer, $pos, $run);
                    }
                    $pos += $run;
                    $length += $run;
                    if ($pos >= strlen($this->buffer)) {
                        continue;
                    }
                }
            }
            $char = $this->buffer[$pos];
            $pos++;
            $length++;

            if ($quoted) {
                if ($char === '"') {
                    if ($pos >= strlen($this->buffer)) {
                        $this->fill();
                    }
                    if (($this->buffer[$pos] ?? '') === '"') {
                        $cell .= '"';
                        $pos++;
                        $length++;
                    } else {
                        $quoted = false;
                    }
                } elseif ($error === null) {
                    $cell .= $char;
                }
            } elseif ($char === '"' && $cell === '') {
                $quoted = true;
            } elseif ($char === $this->delimiter) {
                $cells[] = $cell;
                $cell = '';
            } elseif ($char === "
" || $char === "") {
                if ($char === "") {
                    if ($pos >= strlen($this->buffer)) {
                        $this->fill();
                    }
                    if (($this->buffer[$pos] ?? '') === "
") {
                        $pos++;
                    }
                }
                break;
            } elseif ($error === null) {
                $cell .= $char;
            }

            if ($error === null && ($length > self::MAX_RECORD_BYTES || strlen($cell) > self::MAX_CELL_BYTES)) {
                $error = 'too_long';
            }
            if ($error !== null) {
                // Keep scanning to the end of the record, but stop holding its bytes.
                $cell = '';
                $cells = [];
                $this->bufferStart += $pos;
                $this->buffer = substr($this->buffer, $pos);
                $pos = 0;
            }
        }

        if (!$started) {
            return null;
        }
        $cells[] = $cell;
        $end = $this->bufferStart + $pos;
        $this->buffer = substr($this->buffer, $pos);
        $this->bufferStart = $end;

        if ($error === null && count($cells) > self::MAX_COLUMNS) {
            $error = 'too_many_columns';
        }
        if ($error !== null) {
            return ['cells' => [], 'end' => $end, 'error' => $error];
        }

        $decoded = [];
        foreach ($cells as $raw) {
            $text = SourceEncoding::toUtf8($raw, $this->encoding);
            if ($text === null) {
                return ['cells' => [], 'end' => $end, 'error' => 'encoding'];
            }
            $decoded[] = $text;
        }

        return ['cells' => $decoded, 'end' => $end, 'error' => null];
    }

    /**
     * Append the next block of the file to the buffer.
     *
     * @return bool False at the end of the file.
     */
    private function fill(): bool
    {
        if ($this->eof) {
            return false;
        }
        $block = fread($this->handle, self::BLOCK);
        if ($block === false || $block === '') {
            $this->eof = true;

            return false;
        }
        $this->buffer .= $block;

        return true;
    }
}
