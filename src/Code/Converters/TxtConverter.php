<?php

namespace Done\Subtitles\Code\Converters;

use Done\Subtitles\Code\UserException;

class TxtConverter implements ConverterContract
{
    public static $time_regexp = '/(?<!\d)(?:\d{2}:)?(?:\d{1,2}:)?(?:\d{1,2}:)\d{1,2}(?:[.,]\d+)?(?!\d)|\d{1,5}[.,]\d{1,3}/';
    private static $any_letter_regex = '/\p{L}/u';

    public function canParseFileContent($file_content)
    {
        return self::hasText($file_content);
    }

    public function fileContentToInternalFormat($file_content)
    {
        // just text lines
        // timestamps on the same line
        // numbered file
        // timestamps on separate line

        $file_content2 = trim($file_content);
        $file_content2 = preg_replace("/\n+/", "\n", $file_content2);
        $lines = mb_split("\n", $file_content2);

        if (!self::doesFileUseTimestamps($lines)) {
            if (self::areEmptyLinesUsedAsSeparators($file_content)) {
                return self::twoLinesSeparatedByEmptyLine($file_content);
            }

            return self::withoutTimestampsInternalFormat($lines);
        }

        $colon_count = self::detectMostlyUsedTimestampType($lines);

        // line parts to array
        $array = [];
        foreach ($lines as $line) {
            $tmp = self::getLineParts($line) + ['line' => $line];
            if ($tmp['start'] !== null) { // only if timestamp format matches add timestamps
                if (substr_count($tmp['start'], ':') >= $colon_count) {
                    $tmp['start'] = self::timeToInternal($tmp['start']);
                    $tmp['end'] = $tmp['end'] != null ? self::timeToInternal($tmp['end']) : null;
                } else {
                    $tmp['start'] = null;
                    $tmp['end'] = null;
                    $tmp['text'] = $tmp['line'];
                }
            }
            $array[] = $tmp;
        }

        $data = [];
        for ($i = 0; $i < count($array); $i++) {
            $row = $array[$i];
            if (!isset($row['text'])) {
                continue;
            }
            if (preg_match('/^[0-9]+$/', $row['text'])) { // only number on the line
                if (isset($array[$i + 1]['start']) && $array[$i + 1]['start'] !== null) { // timestamp
                    continue; // probably a number from an srt file, because after the number goes the timestamp
                }
            }

            $start = null;
            $end = null;
            if (isset($row['start'])) {
                $start = $row['start'];
                $end = $row['end'] ?? null;
            }
            if (isset($array[$i - 1]['start']) && $array[$i - 1]['text'] === null) {
                $start = $array[$i - 1]['start'];
                $end = $array[$i - 1]['end'] ?? null;
            }
            if (isset($array[$i - 2]['start']) && $array[$i - 2]['text'] === null) {
                $start = $array[$i - 2]['start'];
                $end = $array[$i - 2]['end'] ?? null;
            }

            $data[] = [
                'start' => $start,
                'end' => $end,
                'text' => $row['text'],
            ];
        }

        // merge lines with same timestamps
        $internal_format = [];
        $j = 0;
        foreach ($data as $k => $row) {
            for ($i = 1; $i <= 10; $i++) { // up to 10 lines
                if (
                    isset($data[$k - $i]['start'])
                    && ($data[$k - $i]['start'] === $row['start'] || $row['start'] === null)
                ) {
                    $internal_format[$j - 1]['lines'][] = $row['text'];
                    continue 2;
                }
            }

            $internal_format[$j] =  [
                'start' => $row['start'],
                'end' => $row['end'],
                'lines' => [$row['text']],
            ];
            $j++;
        }

        return self::fillStartAndEndTimes($internal_format);
    }

    private static function detectMostlyUsedTimestampType(array $lines)
    {

        $counts = [];
        foreach ($lines as $line) {
            $parts = self::getLineParts($line);
            if (!$parts['start']) {
                continue;
            }
            $count = substr_count($parts['start'], ':');
            if (!isset($counts[$count])) {
                $counts[$count] = 0;
            }
            $counts[$count]++;
        }
        $max_number = max($counts);
        foreach ($counts as $count => $number) {
            if ($number === $max_number) {
                return $count;
            }
        }

        throw new \Exception('no timestamps found');
    }

    private static function fillStartAndEndTimes(array $internal_format)
    {
        if (count($internal_format) === 0) {
            throw new UserException("Subtitles were not found in this file");
        }

        // fill starts
        $last_start = -1;
        foreach ($internal_format as $k => $row) {
            if (!isset($row['start'])) {
                $last_start++;
                $internal_format[$k]['start'] = $last_start;
            } else {
                $last_start = $row['start'];
            }
        }

        // fill ends
        foreach ($internal_format as $k => $row) {
            if (!isset($row['end'])) {
                if (isset($internal_format[$k + 1]['start'])) {
                    $internal_format[$k]['end'] = $internal_format[$k + 1]['start'];
                } else {
                    $internal_format[$k]['end'] = $internal_format[$k]['start'] + 1;
                }
            }
        }
        if (!isset($row['end'])) {
            $internal_format[$k]['end'] = $row['start'] + 1;
        }

        return $internal_format;
    }

    public function internalFormatToFileContent(array $internal_format)
    {
        $file_content = '';

        foreach ($internal_format as $block) {
            $line = implode(" ", $block['lines']);

            $file_content .= $line . "\r\n";
        }

        return trim($file_content);
    }

    public static function getLineParts($line)
    {
        $matches = [
            'start' => null,
            'end' => null,
            'text' => null,
        ];
        preg_match_all(self::$time_regexp . 'm', $line, $timestamps);

        // there shouldn't be any text before the timestamp
        // if there is text before it, then maybe it is not a timestamp
        $right_timestamp = '';
        if (isset($timestamps[0][0])) {
            $text_before_timestamp = substr($line, 0, strpos($line, $timestamps[0][0]));
            if (!self::hasText($text_before_timestamp)) {
                if (isset($timestamps[0][0])) {
                    // start
                    $matches['start'] = $timestamps[0][0];
                    $right_timestamp = $matches['start'];
                }
                if (isset($timestamps[0][1])) {
                    // end
                    $matches['end'] = $timestamps[0][1];
                    $right_timestamp = $matches['end'];
                }
            }
        }

        // check if there is any text after the timestamp
        $right_text = strstr($line, $right_timestamp);
        if ($right_text) {
            $right_text = substr($right_text, strlen($right_timestamp));
        }
        if (self::hasText($right_text) || self::hasDigit($right_text)) {
            $matches['text'] = $right_text;
        }

        return $matches;
    }

    public static function timeToInternal($time)
    {
        $time = trim($time);
        $time_parts = explode(':', $time);
        $total_parts = count($time_parts);

        if ($total_parts === 1) {
            $tmp = (float) str_replace(',', '.', $time_parts[0]);
            return $tmp;
        } elseif ($total_parts === 2) { // minutes:seconds format
            list($minutes, $seconds) = array_map('intval', $time_parts);
            $tmp = (float) str_replace(',', '.', $time_parts[1]);
            $milliseconds = $tmp - floor($tmp);
            return ($minutes * 60) + $seconds + $milliseconds;
        } elseif ($total_parts === 3) { // hours:minutes:seconds,milliseconds format
            list($hours, $minutes, $seconds) = array_map('intval', $time_parts);
            $tmp = (float) str_replace(',', '.', $time_parts[2]);
            $milliseconds = $tmp - floor($tmp);
            return ($hours * 3600) + ($minutes * 60) + $seconds + $milliseconds;
        } elseif ($total_parts === 4) { // hours:minutes:seconds:frames format
            list($hours, $minutes, $seconds, $frames) = array_map('intval', $time_parts);
            $milliseconds = $frames / 25; // 25 frames
            return ($hours * 3600) + ($minutes * 60) + $seconds + $milliseconds;
        } else {
            throw new \InvalidArgumentException("Invalid time format: $time");
        }
    }

    private static function doesFileUseTimestamps(array $lines)
    {
        $lines_count = count($lines);
        $lines_with_timestamp_count = 0;
        foreach ($lines as $line) {
            $parts = self::getLineParts($line);
            if ($parts['start'] !== null) {
                $lines_with_timestamp_count++;
            }
        }
        return $lines_with_timestamp_count >= ($lines_count * 0.2); // if there 20% or more lines with timestamps
    }

    public static function withoutTimestampsInternalFormat(array $lines)
    {
        $internal_format = [];
        foreach ($lines as $line) {
            $internal_format[] = ['lines' => [$line]];
        }
        $internal_format = self::fillStartAndEndTimes($internal_format);
        return $internal_format;
    }

    private static function areEmptyLinesUsedAsSeparators(string $file_content)
    {
        $counts = self::countLinesWithEmptyLines($file_content);
        return
            $counts['double_text_lines'] > $counts['lines'] * 0.01
            && $counts['single_empty_lines'] > $counts['lines'] * 0.05
        ;
    }

    private static function countLinesWithEmptyLines($file_content) {
        $file_content = trim($file_content);
        $lines = mb_split("\n", $file_content);
        $single_empty_lines = 0;
        $double_text_lines = 0;
        foreach ($lines as &$line) {
            $line = trim($line);
        }
        unset($line);

        foreach ($lines as $k => $line) {
            if ($line === '') {
                continue;
            }

            $last_empty_line = isset($lines[$k - 1]) && $lines[$k - 1] === '';
            $last2_empty_line = isset($lines[$k - 2]) && $lines[$k - 2] === '';

            if (!$last_empty_line && $last2_empty_line) {
                $double_text_lines++;
            }
            if ($last_empty_line && !$last2_empty_line) {
                $single_empty_lines++;
            }
        }

        return [
            'lines' => count($lines),
            'double_text_lines' => $double_text_lines,
            'single_empty_lines' => $single_empty_lines,
        ];
    }

    private static function twoLinesSeparatedByEmptyLine(string $file_content)
    {
        $lines = mb_split("\n", $file_content);
        $internal_format = [];
        $i = 0;
        foreach ($lines as $k => $line) {
            $is_empty = trim($line) === '';
            $last_empty_line = isset($lines[$k - 1]) && trim($lines[$k - 1]) === '';
            if ($is_empty) {
                continue;
            }

            if ($last_empty_line) {
                $internal_format[$i] = ['lines' => [$line]];
                $i++;
            } else {
                if (isset($internal_format[$i - 1])) {
                    $internal_format[$i - 1]['lines'][] = $line;
                } else {
                    $internal_format[$i] = ['lines' => [$line]];
                    $i++;
                }
            }
        }

        return self::fillStartAndEndTimes($internal_format);
    }

    private static function hasTime($line)
    {
        return preg_match(self::$time_regexp, $line) === 1;
    }

    private static function hasText($line)
    {
        return preg_match(self::$any_letter_regex, $line) === 1;
    }

    private static function hasDigit($line)
    {
        return preg_match('/\d/', $line) === 1;
    }
}
