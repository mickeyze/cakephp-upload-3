<?php
namespace Wirebids\Upload\Model\Behavior;

use ArrayObject;
use Cake\Collection\Collection;
use Cake\Database\Type;
use Cake\Event\Event;
use Cake\ORM\Behavior;
use Cake\ORM\Entity;
use Cake\Utility\Hash;
use Exception;
use Wirebids\Upload\File\Path\DefaultProcessor;
use Wirebids\Upload\File\Transformer\DefaultTransformer;
use Wirebids\Upload\File\Writer\DefaultWriter;
use UnexpectedValueException;
use Cake\Core\Configure;

use Aws\S3\S3Client;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use League\Flysystem\Filesystem;

class UploadBehavior extends Behavior
{
 
    /**
     * Initialize hook
     *
     * @param array $config The config for this behavior.
     * @return void
     */
    public function initialize(array $config)
    {
        $configs = [];
        foreach ($config as $field => $settings) {
            if (is_int($field)) {
                $configs[$settings] = [];
            } else {
                $configs[$field] = $settings;
            }
        }

        $this->config($configs);
        $this->config('className', null);

        Type::map('upload.file', 'Wirebids\Upload\Database\Type\FileType');
        $schema = $this->_table->schema();
        foreach (array_keys($this->config()) as $field) {
            $schema->columnType($field, 'upload.file');
        }
        $this->_table->schema($schema);
    }

    /**
     * Modifies the data being marshalled to ensure invalid upload data is not inserted
     *
     * @param \Cake\Event\Event $event an event instance
     * @param \ArrayObject $data data being marshalled
     * @param \ArrayObject $options options for the current event
     * @return void
     */
    public function beforeMarshal(Event $event, ArrayObject $data, ArrayObject $options)
    {
        $validator = $this->_table->validator();
        $dataArray = $data->getArrayCopy();
        foreach (array_keys($this->config()) as $field) {
            if (!$validator->isEmptyAllowed($field, false)) {
                continue;
            }
            if (Hash::get($dataArray, $field . '.error') !== UPLOAD_ERR_NO_FILE) {
                continue;
            }
            unset($data[$field]);
        }
    }

    /**
     * Modifies the entity before it is saved so that uploaded file data is persisted
     * in the database too.
     *
     * @param \Cake\Event\Event $event The beforeSave event that was fired
     * @param \Cake\ORM\Entity $entity The entity that is going to be saved
     * @param \ArrayObject $options the options passed to the save method
     * @return void|false
     */
    public function beforeSave(Event $event, Entity $entity, ArrayObject $options)
    {

        foreach ($this->config() as $field => $settings) {
  
            if ( $entity->has($field) && 
                $entity->get($field) &&
                is_array($entity->get($field)) &&
                isset($entity->get($field)['error']) && 
                $entity->get($field)['error'] === UPLOAD_ERR_OK
            ) { 
                $entity->dirty($field, false);     
                $data = $entity->get($field);
                $path = $this->getPathProcessor($entity, $data, $field, $settings);
                $basepath = $path->basepath();
                $filename = $path->filename();
                $data['name'] = $filename;
                $files = $this->constructFiles($entity, $data, $field, $settings, $basepath);                
                $writer = $this->getWriter($entity, $data, $field, $settings);
                $success = $writer->write($files); 
 
                if ((new Collection($success))->contains(false)) {
                    return false;
                }  

                $i = 0;
     			foreach($files as $file){

                    if(!Configure::read('S3.enabled'))
                        $file["name"] = str_replace("webroot/", "", $file["name"]);

     				if($i == 0){
    		            $entity->set($field, $file["name"]);
    		            $entity->set('type', $file["type"]);
                        $entity->set('position', 0);
                        $entity->set('block', 0);
    		            //$entity->set(Hash::get($settings, 'fields.dir', 'dir'), $basepath);
    		            //$entity->set(Hash::get($settings, 'fields.size', 'size'), $data['size']);
    		        	//$entity->set(Hash::get($settings, 'fields.type', 'type'), $data['type']); 
    				}else{
    					$newEntity = $this->_table->newEntity();
                        foreach($entity->getDirty() as $property) {
                            if( !in_array($property, [$field, 'created', 'modified', 'id', 'position'])){
                                $newEntity->set($property, $entity->get($property)); 
                            }
                        } 
    					$newEntity->set($field, $file["name"]);
                        $newEntity->dirty($field, true);
    		            $newEntity->set('type', $file["type"]);   
                        $newEntity->set('position', 0);  
                        $newEntity->set('block', 0);
                		$this->_table->save($newEntity );   
    				}
    				$i++;
    	        }
            } 
        }
    }
 
    /**
     * Deletes the files after the entity is deleted
     *
     * @param \Cake\Event\Event $event The afterDelete event that was fired
     * @param \Cake\ORM\Entity $entity The entity that was deleted
     * @param \ArrayObject $options the options passed to the delete method
     * @return void|false
     */
    public function afterDelete(Event $event, Entity $entity, ArrayObject $options)
    {

        foreach ($this->config() as $field => $settings) {
            if (Hash::get($settings, 'keepFilesOnDelete', false)) {
                continue;
            } 
            
            //$path = $this->getPathProcessor($entity, $entity->{$field}, $field, $settings)->basepath();
            //$file = [$path . $entity->{$field}];
            $file = [$entity->{$field}]; 

            if(!Configure::read('S3.enabled')){
                $file[0] = "webroot/" . $file[0];
            } 

            $writer = $this->getWriter($entity, [], $field, $settings);
            $success = $writer->delete($file);

            if ((new Collection($success))->contains(false)) {
                return false;
            }
        }
    }

    /**
     * Retrieves an instance of a path processor which knows how to build paths
     * for a given file upload
     *
     * @param \Cake\ORM\Entity $entity an entity
     * @param array $data the data being submitted for a save
     * @param string $field the field for which data will be saved
     * @param array $settings the settings for the current field
     * @return \Wirebids\Upload\File\Path\AbstractProcessor
     */
    public function getPathProcessor(Entity $entity, $data, $field, $settings)
    {
        $default = 'Wirebids\Upload\File\Path\DefaultProcessor';
        $processorClass = Hash::get($settings, 'pathProcessor', $default);
        if (is_subclass_of($processorClass, 'Wirebids\Upload\File\Path\ProcessorInterface')) {
            return new $processorClass($this->_table, $entity, $data, $field, $settings);
        }

        throw new UnexpectedValueException(sprintf(
            "'pathProcessor' not set to instance of ProcessorInterface: %s",
            $processorClass
        ));
    }

    /**
     * Retrieves an instance of a file writer which knows how to write files to disk
     *
     * @param \Cake\ORM\Entity $entity an entity
     * @param array $data the data being submitted for a save
     * @param string $field the field for which data will be saved
     * @param array $settings the settings for the current field
     * @return \Wirebids\Upload\File\Path\AbstractProcessor
     */
    public function getWriter(Entity $entity, $data, $field, $settings)
    {/*
        $s3 = Hash::get($settings, 's3');
        
        if($s3){

            return $this->s3Uploader($this->_table, $entity, $data, $field, $settings);

        }else{*/

            $default = 'Wirebids\Upload\File\Writer\DefaultWriter';
            $writerClass = Hash::get($settings, 'writer', $default);
            if (is_subclass_of($writerClass, 'Wirebids\Upload\File\Writer\WriterInterface')) {
                return new $writerClass($this->_table, $entity, $data, $field, $settings);
            }

        //}

        throw new UnexpectedValueException(sprintf(
            "'writer' not set to instance of WriterInterface: %s",
            $writerClass
        ));
    }

    public function s3Uploader(
        \Cake\Datasource\RepositoryInterface $table, 
        \Cake\Datasource\EntityInterface $entity, 
        $data, 
        $field, 
        $settings
    ){
        $client = \Aws\S3\S3Client::factory([
            'credentials' => [
                'key'    => Configure::read('S3.key'),
                'secret' => Configure::read('S3.secret'),
            ],
            'region' => Configure::read('S3.region'),
            'version' => Configure::read('S3.version'),
        ]);
        $adapter = new \League\Flysystem\AwsS3v3\AwsS3Adapter(
            $client,
            Configure::read('S3.bucket')
        );

        return new Filesystem($adapter); 
    }


    private function createThumbnails (
        \Cake\Datasource\RepositoryInterface $table, 
        \Cake\Datasource\EntityInterface $entity, 
        $data, 
        $field, 
        $settings, 
        $sizes
    ){  
        $output = [
            $data['tmp_name'] => [
                'name' => $data['name'],
                'type' => 'original'
            ]
        ];
        //Create thumbnails
        foreach($sizes as $thumbnailType => $size){ 

            if($thumbnailType == 'original')
                continue;

            $extension = pathinfo($data['name'], PATHINFO_EXTENSION);
            // Store the thumbnail in a temporary file
            $tmp = tempnam(sys_get_temp_dir(), 'upload') . '.' . $extension;
            // Use the Imagine library to DO THE THING
            $size = new \Imagine\Image\Box($size['h'], $size['w']);
            $mode = \Imagine\Image\ImageInterface::THUMBNAIL_INSET;
            $imagine = new \Imagine\Gd\Imagine();
            // Save that modified file to our temp file
            $imagine->open($data['tmp_name'])
                    ->thumbnail($size, $mode)
                            ->save($tmp);
 
            $output[$tmp] = [
                'name' => $thumbnailType . '-' . $data['name'],
                'type' => $thumbnailType
            ]; 
        }
        // Now return the original *and* the thumbnail
        return $output;
    }

    /**
     * Creates a set of files from the initial data and returns them as key/value
     * pairs, where the path on disk maps to name which each file should have.
     * This is done through an intermediate transformer, which should return
     * said array. Example:
     *
     *   [
     *     '/tmp/path/to/file/on/disk' => 'file.pdf',
     *     '/tmp/path/to/file/on/disk-2' => 'file-preview.png',
     *   ]
     *
     * A user can specify a callable in the `transformer` setting, which can be
     * used to construct this key/value array. This processor can be used to
     * create the source files.
     *
     * @param \Cake\ORM\Entity $entity an entity
     * @param array $data the data being submitted for a save
     * @param string $field the field for which data will be saved
     * @param array $settings the settings for the current field
     * @param string $basepath a basepath where the files are written to
     * @return array key/value pairs of temp files mapping to their names
     */
    public function constructFiles(Entity $entity, $data, $field, $settings, $basepath)
    { 

        $results = [];
        $basepath = (substr($basepath, -1) == DS ? $basepath : $basepath . DS);
        $default = 'Wirebids\Upload\File\Transformer\DefaultTransformer';
        $transformerClass = Hash::get($settings, 'transformer', $default); 
        $thumbnails = Hash::get($settings, 'thumbnails'); 

        //WIREBIDS CUSTOM THUMBNAILS SETTINGS
        if($thumbnails)
        {    
            $results = $this->createThumbnails($this->_table, $entity, $data, $field, $settings, $thumbnails);
            foreach ($results as $key => $value) {
                $results[$key]['name'] = $basepath . $value['name'];
            }
        }else{
            //DEFAULT BEHAVIOR
            if (is_subclass_of($transformerClass, 'Wirebids\Upload\File\Transformer\TransformerInterface')) {
                $transformer = new $transformerClass($this->_table, $entity, $data, $field, $settings);
                $results = $transformer->transform();
                foreach ($results as $key => $value) {
                    $results[$key] = $basepath . $value;
                }
            } elseif (is_callable($transformerClass)) {

                $results = $transformerClass($this->_table, $entity, $data, $field, $settings);
                foreach ($results as $key => $value) { 
                    $results[$key]['name'] = $basepath . $value['name'];
                    $results[$key]['type'] = $value['type'];
                } 

            } else {
                throw new UnexpectedValueException(sprintf(
                    "'transformer' not set to instance of TransformerInterface: %s",
                    $transformerClass
                ));
            } 
        }
        return $results;
    }
}
