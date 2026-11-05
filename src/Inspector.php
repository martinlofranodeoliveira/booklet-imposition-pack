<?php
declare(strict_types=1);
namespace Lofrano\Booklet;

/** @internal Consumes qpdf JSON, never raw PDF syntax. */
final class Inspector
{
    public static function inspect(string $json): int
    {
        Failure::need(strlen($json) <= 33554432, 'inspection_limit');
        try {
            $j = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new Failure('inspection_json');
        }
        Failure::need(is_array($j) && ($j['version'] ?? null) === 2 && ($j['qpdf'][0]['jsonversion'] ?? null) === 2 && count($j['qpdf'] ?? []) === 2, 'inspection_schema');
        Failure::need(($j['encrypt']['encrypted'] ?? null) === false, 'unsupported_encryption');
        Failure::need(($j['acroform']['hasacroform'] ?? null) === false, 'unsupported_interactive');
        Failure::need(isset($j['attachments'], $j['outlines']) && count($j['attachments']) === 0 && count($j['outlines']) === 0, 'unsupported_interactive');
        Failure::need(is_array($j['pages'] ?? null) && count($j['pages']) >= 1 && count($j['pages']) <= 4096, 'source_page_count');
        $objects = $j['qpdf'][1] ?? null;
        Failure::need(is_array($objects) && count($objects) <= 32769 && is_int($j['qpdf'][0]['maxobjectid'] ?? null) && $j['qpdf'][0]['maxobjectid'] <= 32768, 'inspection_limit');
        $nodes = 0;
        foreach ($objects as $key => $object) {
            Failure::need($key === 'trailer' || preg_match('/^obj:[1-9][0-9]* [0-9]+ R$/D', (string) $key) === 1, 'inspection_schema');
            Failure::need(is_array($object) && (array_key_exists('value', $object) || isset($object['stream']['dict'])), 'inspection_schema');
            self::walk($object, 0, $nodes);
        }
        return count($j['pages']);
    }

    private static function name(string $value): string
    {
        if (!str_starts_with($value, '/')) {
            return $value;
        }
        return preg_replace_callback('/#([0-9a-fA-F]{2})/', static fn(array $m): string => chr(hexdec($m[1])), $value);
    }

    private static function walk(mixed $value, int $depth, int &$nodes): void
    {
        Failure::need(++$nodes <= 250000 && $depth <= 100, 'inspection_limit');
        if (!is_array($value)) {
            return;
        }
        $forbidden = ['/Annots', '/AcroForm', '/A', '/AA', '/OpenAction', '/JS', '/JavaScript', '/URI', '/Launch', '/SubmitForm', '/ResetForm', '/ImportData', '/RichMedia', '/EmbeddedFiles', '/EF', '/XFA', '/OCProperties', '/OC', '/Collection', '/AF', '/Names', '/Outlines', '/Dests', '/PresSteps', '/Ref', '/OPI', '/F'];
        $types = ['/Action', '/Filespec', '/EmbeddedFile', '/OCG', '/OCMD', '/Widget', '/Link', '/Screen', '/Movie', '/Sound', '/FileAttachment', '/RichMedia', '/3D'];
        $actions = ['/GoTo', '/GoToR', '/GoToE', '/Launch', '/Thread', '/URI', '/Sound', '/Movie', '/Hide', '/Named', '/SubmitForm', '/ResetForm', '/ImportData', '/JavaScript', '/SetOCGState', '/Rendition', '/Trans', '/GoTo3DView'];
        foreach ($value as $key => $item) {
            $key = is_string($key) ? self::name($key) : $key;
            Failure::need(!in_array($key, $forbidden, true), 'unsupported_interactive');
            if (($key === '/Type' || $key === '/Subtype') && is_string($item)) {
                Failure::need(!in_array(self::name($item), $types, true), 'unsupported_interactive');
            }
            if ($key === '/S' && is_string($item)) {
                Failure::need(!in_array(self::name($item), $actions, true), 'unsupported_interactive');
            }
            if ($key === '/UserUnit') {
                Failure::need((is_int($item) || is_float($item)) && (float) $item === 1.0, 'unsupported_user_unit');
            }
            if ($key === '/Rotate') {
                Failure::need(is_int($item) && abs($item) <= 360 && $item % 90 === 0, 'unsupported_rotation');
            }
            self::walk($item, $depth + 1, $nodes);
        }
    }
}
