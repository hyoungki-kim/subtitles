<?php

namespace Done\Subtitles\Code\Converters;

class DfxpConverter implements ConverterContract
{
    public function canParseFileContent($file_content)
    {
        return
            (preg_match('/xmlns="http:\/\/www\.w3\.org\/ns\/ttml"/m', $file_content) === 1 && preg_match('/xml:id="d1"/m', $file_content) === 1) // old netflix format;
            || (strpos($file_content, 'http://netflix.com/ttml/profile/dfxp-ls-sdh') !== false) // new netflix format
        ;
    }

    public function fileContentToInternalFormat($file_content)
    {
        preg_match_all('/<p.+begin="(?<start>[^"]+).*end="(?<end>[^"]+)[^>]*>(?<text>(?!<\/p>).+)<\/p>/', $file_content, $matches, PREG_SET_ORDER);

        $internal_format = [];
        foreach ($matches as $block) {
            $block['text'] = preg_replace('/<br\s*\/?>/', '<br>', $block['text']); // normalize <br>
            $internal_format[] = [
                'start' => static::dfxpTimeToInternal($block['start']),
                'end' => static::dfxpTimeToInternal($block['end']),
                'lines' => explode('<br>', $block['text']),
            ];
        }

        return $internal_format;
    }

    public function internalFormatToFileContent(array $internal_format)
    {
        $file_content = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<tt xmlns:tt="http://www.w3.org/ns/ttml" xmlns:ttm="http://www.w3.org/ns/ttml#metadata" xmlns:ttp="http://www.w3.org/ns/ttml#parameter" xmlns:tts="http://www.w3.org/ns/ttml#styling" ttp:tickRate="10000000" ttp:timeBase="media" xmlns="http://www.w3.org/ns/ttml">
<head>
<ttp:profile use="http://netflix.com/ttml/profile/dfxp-ls-sdh"/>
<styling>
<style tts:backgroundColor="transparent" tts:textAlign="center" xml:id="style0"/>
<style tts:color="white" tts:fontSize="100%" tts:fontWeight="normal" xml:id="style1"/>
<style tts:color="white" tts:fontSize="100%" tts:fontStyle="italic" tts:fontWeight="normal" xml:id="style2"/>
</styling>
<layout>
<region tts:displayAlign="after" xml:id="region0"/>
</layout>
</head>
<body>
<div xml:space="preserve">
';

        foreach ($internal_format as $k => $block) {
            $nr = $k + 1;
            $start = static::internalTimeToDfxp($block['start']);
            $end = static::internalTimeToDfxp($block['end']);
            $lines = implode("<br/>", $block['lines']);

            $file_content .= "    <p xml:id=\"p{$nr}\" begin=\"{$start}\" end=\"{$end}\" region=\"bottomCenter\">{$lines}</p>\n";
        }

        $file_content .= '  </div>
  </body>
</tt>';


        $file_content = str_replace("\r", "", $file_content);
        $file_content = str_replace("\n", "\r\n", $file_content);

        return $file_content;
    }

    // ---------------------------------- private ----------------------------------------------------------------------

    protected static function internalTimeToDfxp($internal_time)
    {
        return ($internal_time * 10000000) . 't';
    }

    protected static function dfxpTimeToInternal($dfxp_time)
    {
        if (substr($dfxp_time, -1) === 't') { // if last symbol is "t"
            // parses 340400000t
            return substr($dfxp_time, 0, -1) / 10000000;
        } else {
            // parses 00:00:34,040
            $parts = explode(',', $dfxp_time);

            $only_seconds = strtotime("1970-01-01 {$parts[0]} UTC");
            $milliseconds = (float)('0.' . $parts[1]);

            $time = $only_seconds + $milliseconds;

            return $time;
        }
    }
}