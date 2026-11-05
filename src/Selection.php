<?php
declare(strict_types=1);
namespace Lofrano\Booklet;

final class Selection
{
    /** @return list<array{ordinal:int,page:int,label:?string}> */
    public static function parse(array $pages): array
    {
        Failure::need(array_is_list($pages) && count($pages) >= 1 && count($pages) <= 64, 'selection_limit');
        $result = [];
        foreach ($pages as $i => $entry) {
            $label = null;
            if (is_array($entry)) {
                Failure::need(!array_is_list($entry) && count(array_diff(array_keys($entry), ['page', 'label'])) === 0 && array_key_exists('page', $entry), 'selection_record');
                $page = $entry['page'];
                $label = $entry['label'] ?? null;
                Failure::need($label === null || (is_string($label) && strlen($label) <= 128 && !str_contains($label, "\0") && preg_match('//u', $label) === 1), 'selection_label');
            } else {
                $page = $entry;
            }
            Failure::need(is_int($page) && $page >= 1 && $page <= 4096, 'selection_page');
            $result[] = ['ordinal' => $i + 1, 'page' => $page, 'label' => $label];
        }
        return $result;
    }

    /** CLI ranges are ascending, one-based and inclusive. */
    public static function ranges(string $text): array
    {
        Failure::need(strlen($text) <= 1024 && $text !== '', 'selection_syntax');
        $pages = [];
        foreach (explode(',', $text) as $part) {
            Failure::need(preg_match('/^([1-9][0-9]{0,3})(?:-([1-9][0-9]{0,3}))?$/D', $part, $m) === 1, 'selection_syntax');
            $first = (int) $m[1];
            $last = isset($m[2]) ? (int) $m[2] : $first;
            Failure::need($last >= $first && $last <= 4096 && count($pages) + $last - $first + 1 <= 64, 'selection_limit');
            for ($p = $first; $p <= $last; ++$p) {
                $pages[] = $p;
            }
        }
        return $pages;
    }
}
