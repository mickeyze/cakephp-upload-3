<?php
namespace Wirebids\Upload\Test\Stub;

use Wirebids\Upload\Model\Behavior\UploadBehavior;

class ChildBehavior extends UploadBehavior
{
    protected $_defaultConfig = ['key' => 'value'];
}
