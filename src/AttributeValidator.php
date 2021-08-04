<?php
declare(strict_types = 1);

namespace NotionCommotion\AttributeValidator;

use NotionCommotion\AttributeValidator\AttributeValidatorException;
use JsonSerializable;
use ReflectionClass;
use RegexIterator;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use RecursiveRegexIterator;

class AttributeValidator implements JsonSerializable
{
    private $classesWithUndeclaredAttributes = [];
    private $classesWithoutUndeclaredAttributes = [];
    private $suspectClasses=[];
    private $notFoundClasses=[];
    private $trait=[];
    private $interface=[];
    private $abstract=[];

    public static function create(string $path):self
    {
        return new self($path);
    }

    public function __construct(string $path)
    {
        $iterator = $this->getIterator($path);

        foreach($iterator as $files) {
            foreach($files as $filename) {

                $rs = $this->parseFile($filename);
                if($this->isSuspect($rs)) {
                    $this->suspectClasses[$filename] = $rs;
                    continue;
                }
                $this->addOtherItems($rs, 'trait')->addOtherItems($rs, 'interface')->addOtherItems($rs, 'abstract');

                foreach($rs['class'] as $class) {
                    $fqcn = ($rs['namespace']?$rs['namespace'].'\\':'').$class;
                    if(!class_exists($fqcn)) {
                        $this->notFoundClasses[$fqcn] = $rs;
                        continue;
                    }
                    $arr = [];
                    $reflectionClass = new ReflectionClass($fqcn);

                    if($rs = $this->getClasslessAttributes($reflectionClass->getAttributes())) {
                        $arr['classAttributes'] = $rs;
                    }
                    if($rs = $this->processAttributeCollection($reflectionClass->getProperties())) {
                        $arr['propertyAttributes'] = $rs;
                    }
                    $methods = $reflectionClass->getMethods();
                    if($rs = $this->processAttributeCollection($methods)) {
                        $arr['methodAttributes'] = $rs;
                    }
                    foreach($methods as $method) {
                        $methodName = $method->getName();
                        if($rs = $this->processAttributeCollection($method->getParameters())) {
                            $arr['parameterAttributes'][$methodName] = $rs;
                        }
                    }
                    if($rs = $this->processAttributeCollection($reflectionClass->getReflectionConstants())) {
                        $arr['classConstantAttributes'] = $rs;
                    }
                    // Future.  Include attributes on constants and functions?

                    if($arr) {
                        $this->classesWithUndeclaredAttributes[$fqcn] = array_merge(['fqcn'=>$fqcn, 'fileName'=>$filename], $arr);
                    }
                    else {
                        $this->classesWithoutUndeclaredAttributes[$fqcn] = $filename;
                    }
                }
            }
        }
    }

    public function validate():array
    {
        return array_filter([
            'classesWithUndeclaredAttributes'=>array_values($this->classesWithUndeclaredAttributes),
            'suspectClasses'=>array_values($this->suspectClasses),
            'notFoundClasses'=>$this->notFoundClasses,
        ]);
    }

    public function getClassesWithUndeclaredAttributes():array
    {
        return array_values($this->classesWithUndeclaredAttributes);
    }
    public function getClassesWithoutUndeclaredAttributes():array
    {
        return array_values($this->classesWithoutUndeclaredAttributes);
    }
    public function getSuspectClasses():array
    {
        return array_values($this->suspectClasses);
    }
    public function getNotFoundClasses():array
    {
        return $this->notFoundClasses;
    }

    public function getTraits():array
    {
        return $this->getItems('trait');
    }
    public function getInterfaces():array
    {
        return $this->getItems('interface');
    }
    public function getAbstracts():array
    {
        return $this->getItems('abstract');
    }
    private function getItems(string $type):array
    {
        $arr = [];
        foreach($this->$type as $item) {
            foreach($item['items'] as $name) {
                $arr[] = ['namespace'=>$item['namespace'], 'filename'=>$item['filename'], 'name'=>$name];
            }
        }
        return $arr;
    }

    public function jsonSerialize() {
        return [
            'classesWithUndeclaredAttributes'=>array_values($this->classesWithUndeclaredAttributes),
            'classesWithoutUndeclaredAttributes'=>$this->classesWithoutUndeclaredAttributes,
            'suspectClasses'=>array_values($this->suspectClasses),
            'notFoundClasses'=>$this->notFoundClasses,
            'trait'=>$this->trait,
            'interface'=>$this->interface,
            'abstract'=>$this->abstract,
        ];
    }

    public function debugSuspectFiles():array
    {
        $test=[];
        foreach($this->suspectClasses as $filename => $rs) {
            $test[$filename][] = self::debugFile($filename);
        }
        return $test;
    }

    public static function debugFile(string $filename):array
    {
        $filename = self::getPath($filename);
        if(!is_file($filename) || pathinfo($filename, PATHINFO_EXTENSION)!=='php') {
            throw new AttributeValidatorException($filename.' is not a valid php file');
        }
        $test=[];
        foreach (\PhpToken::tokenize(file_get_contents($filename)) as $token) {
            if(!$token->isIgnorable()) {
                $test[] = ['name'=>$token->getTokenName(), 'text'=>$token->text ];
            }
        }
        return $test;
    }

    private static function getPath(string $path):string
    {
        if(substr($path, 0, 1)!=='/'){
            $path = __DIR__.'/'.$path;
        }
        return $path;
    }

    private function addOtherItems(array $rs, string $type):self
    {
        if($rs[$type]) {
            $this->$type[] = ['namespace'=>$rs['namespace'], 'filename'=>$rs['filename'], 'items'=>$rs[$type]];            
        }
        return $this;
    }

    private function getIterator(string $path)
    {
        $path = self::getPath($path);
        if (!file_exists($path)) {
            throw new AttributeValidatorException($path.' is not valid');
        }
        if(is_file($path)) {
            if(pathinfo($path, PATHINFO_EXTENSION)!=='php') {
                throw new AttributeValidatorException($path.' is not a valid php file');
            }
            $iterator=[[$path]];
        }
        else {
            $iterator = new RegexIterator(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)), '/^.+\.php$/i', RecursiveRegexIterator::GET_MATCH);
        }
        return $iterator;
    }

    private function getClasslessAttributes(array $attributes):array
    {
        $arr = [];
        foreach ($attributes as $attribute) {
            $name = $attribute->getName();
            if(!class_exists($name)) {
                $arr[] = $name;
            }
        }
        return $arr;
    }

    private function processAttributeCollection(array $collection):array
    {
        $arr = [];
        foreach ($collection as $item) {
            $name = $item->getName();
            foreach($this->getClasslessAttributes($item->getAttributes()) as $attributeName) {
                $arr[$attributeName][] = $name;
            }
        }
        return $arr;
    }

    private function isSuspect(array $rs):bool
    {
        return (count($rs['class']) + count($rs['trait']) + count($rs['interface']) + count($rs['abstract']))!==1;
    }

    private function parseFile(string $filename):array
    {
        $getNext=null;
        $getNextNamespace=false;
        $skipNext=false;
        $isAbstract = false;
        $rs = ['namespace'=>null, 'class'=>[], 'trait'=>[], 'interface'=>[], 'abstract'=>[]];
        foreach (\PhpToken::tokenize(file_get_contents($filename)) as $token) {
            if(!$token->isIgnorable()) {
                $name = $token->getTokenName();
                switch($name){
                    case 'T_NAMESPACE':
                        $getNextNamespace=true;
                        break;
                    case 'T_EXTENDS':
                    case 'T_USE':
                    case 'T_IMPLEMENTS':
                        //case 'T_ATTRIBUTE':
                        $skipNext = true;
                        break;
                    case 'T_ABSTRACT':
                        $isAbstract = true;
                        break;
                    case 'T_CLASS':
                    case 'T_TRAIT':
                    case 'T_INTERFACE':
                        if($skipNext) {
                            $skipNext=false;
                        }
                        else {
                            $getNext = strtolower(substr($name, 2));
                        }
                        break;
                    case 'T_NAME_QUALIFIED':
                    case 'T_STRING':
                        if($getNextNamespace) {
                            if(array_filter($rs)) {
                                throw new AttributeValidatorException(sprintf('Namespace mus be defined first in %s', $filename));
                            }
                            else {
                                $rs['namespace'] = $token->text;
                            }
                            $getNextNamespace=false;
                        }
                        elseif($skipNext) {
                            $skipNext=false;
                        }
                        elseif($getNext) {
                            if(in_array($token->text,  $rs[$getNext])) {
                                throw new AttributeValidatorException(sprintf('%s %s has already been found in %s', $rs[$getNext], $token->text, $filename));
                            }
                            if($isAbstract) {
                                $isAbstract=false;
                                $getNext = 'abstract';
                            }
                            $rs[$getNext][]=$token->text;
                            $getNext=null;
                        }
                        break;
                    default:
                        $getNext=null;
                }
            }
        }
        $rs['filename'] = $filename;
        return $rs;
    }
}