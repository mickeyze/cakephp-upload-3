<?php
namespace Wirebids\Upload\Test\TestCase\File\Path\Filename;

use Cake\TestSuite\TestCase;
use Wirebids\Upload\File\Path\Filename\DefaultTrait;

class DefaultTraitTest extends TestCase
{
    public function testFilename()
    {
        $mock = $this->getMockForTrait('Wirebids\Upload\File\Path\Filename\DefaultTrait');
        $mock->settings = [];
        $mock->data = [
            'name' => 'filename',
        ];
        $this->assertEquals('filename', $mock->filename());

        $mock = $this->getMockForTrait('Wirebids\Upload\File\Path\Filename\DefaultTrait');
        $mock->settings = [
            'nameCallback' => 'not_callable',
        ];
        $mock->data = ['name' => 'filename'];
        $this->assertEquals('filename', $mock->filename());

        $mock = $this->getMockForTrait('Wirebids\Upload\File\Path\Filename\DefaultTrait');
        $mock->settings = [
            'nameCallback' => function ($data, $settings) {
                return $data;
            },
        ];
        $mock->data = ['name' => 'filename'];
        $this->assertEquals(['name' => 'filename'], $mock->filename());
    }
}