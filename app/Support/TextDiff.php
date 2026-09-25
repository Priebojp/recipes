<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Tiny word-level diff for showing AI suggestions next to the original text.
 */
class TextDiff
{
    public static function html(string $old, string $new): HtmlString
    {
        $a = preg_split('/(\s+)/u', $old, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $b = preg_split('/(\s+)/u', $new, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];

        $n = count($a);
        $m = count($b);
        if ($n * $m > 250000) {
            return new HtmlString(e($new));
        }

        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $out = '';
        $i = $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $out .= e($a[$i]);
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $out .= '<del class="bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300">'.e($a[$i]).'</del>';
                $i++;
            } else {
                $out .= '<ins class="bg-green-100 text-green-800 no-underline dark:bg-green-900/40 dark:text-green-300">'.e($b[$j]).'</ins>';
                $j++;
            }
        }
        while ($i < $n) {
            $out .= '<del class="bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300">'.e($a[$i++]).'</del>';
        }
        while ($j < $m) {
            $out .= '<ins class="bg-green-100 text-green-800 no-underline dark:bg-green-900/40 dark:text-green-300">'.e($b[$j++]).'</ins>';
        }

        return new HtmlString($out);
    }
}
