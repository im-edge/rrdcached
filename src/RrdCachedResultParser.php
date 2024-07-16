<?php

namespace IMEdge\RrdCached;

use function array_map;
use function preg_split;
use function sort;

class RrdCachedResultParser
{
    public static function extractAvailableCommandsFromHelp(array $resultRows): array
    {
        $result = array_map(static function ($value) {
            return preg_replace('/\s.+$/', '', $value);
        }, self::fixHelpOutputV18($resultRows));
        sort($result);

        return $result;
    }

    public static function fixHelpOutputV18(array $resultRows): array
    {
        // v1.8 showed 'TUNE <filename> [options]FLUSH <filename>', has been fixed with f142cc1
        $result = [];
        foreach ($resultRows as $row) {
            if (preg_match('/^(TUNE <filename> \[options])(FLUSH.+)$/', $row, $match)) {
                $result[] = $match[1];
                $result[] = $match[2];
            } else {
                $result[] = $row;
            }
        }

        return $result;
    }
}
