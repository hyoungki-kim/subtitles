<?php

namespace Tests\Formats;

use Done\Subtitles\Code\Converters\StlConverter;
use Done\Subtitles\Code\Helpers;
use Done\Subtitles\Subtitles;
use PHPUnit\Framework\TestCase;
use Helpers\AdditionalAssertionsTrait;

class StlTest extends TestCase {

    use AdditionalAssertionsTrait;

    public function testRecognizesStl()
    {
        $content = file_get_contents('./tests/files/stl.stl');
        $converter = Helpers::getConverterByFileContent($content);
        $this->assertTrue(get_class($converter) === StlConverter::class);
    }

    public function testConvertFromSrtToStl()
    {
        $srt_path = './tests/files/srt.srt';
        $stl_path = './tests/files/stl.stl';
        $temporary_stl_path = './tests/files/tmp/stl.stl';

        @unlink($temporary_stl_path);

        // srt to stl
        Subtitles::convert($srt_path, $temporary_stl_path);
        $this->assertFileEqualsIgnoringLineEndings($stl_path, $temporary_stl_path);

        unlink($temporary_stl_path);
    }

    // stl lost some data, so we won't get back our original srt file
    public function testConvertFromStlToSrt()
    {
        $srt_path = './tests/files/srt.srt';
        $stl_path = './tests/files/stl.stl';

        // stl to srt
        $stl_object = Subtitles::loadFromFile($stl_path);
        $stl_internal_format = $stl_object->getInternalFormat();

        $srt_object = Subtitles::loadFromFile($srt_path);
        $srt_internal_format = $srt_object->getInternalFormat();

        // compare both internal formats
        foreach ($srt_internal_format as $block_key => $srt_block) {
            $start_time_diff = abs($srt_block['start'] - $stl_internal_format[$block_key]['start']);
            $this->assertLessThan(0.1, $start_time_diff);

            $end_time_diff = abs($srt_block['end'] - $stl_internal_format[$block_key]['end']);
            $this->assertLessThan(0.1, $end_time_diff);

            foreach ($srt_block['lines'] as $line_key => $srt_line) {
                $this->assertEquals($srt_line, $stl_internal_format[$block_key]['lines'][$line_key]);
            }
        }
    }

    public function testParsesFilesWithComments()
    {
        // checking if no exceptions are thrown
        Subtitles::loadFromFile('./tests/files/stl_with_comments.stl')->content('stl');

        // phpunit complains if no assertions are made
        $this->assertTrue(true);
    }

    public function testTimesBiggerThan24HoursThrowException()
    {
        $this->expectException(\Exception::class);

        $subtitles = new Subtitles();
        $subtitles->add(0, 3600 * 24 * 10, 'text');
        $subtitles->content('stl');
    }

}