<?php
namespace Wirebids\Upload\Test\TestCase\File\Path;

use Cake\TestSuite\TestCase;
use Wirebids\Upload\File\Path\DefaultProcessor;
use Wirebids\Upload\File\Path\ProcessorInterface;

class DefaultProcessorTest extends TestCase
{
    public function testIsProcessorInterface()
    {
        $table = $this->getMock('Cake\ORM\Table');
        $entity = $this->getMock('Cake\ORM\Entity');
        $data = ['name' => 'filename'];
        $field = 'field';
        $settings = [];
        $processor = new DefaultProcessor($table, $entity, $data, $field, $settings);
        $this->assertInstanceOf('Wirebids\Upload\File\Path\ProcessorInterface', $processor);
    }
}
