<?php
namespace com\mikebevz\xsd2php;

/**
 * Copyright 2010 Mike Bevz <myb@mikebevz.com>
 * 
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 * 
 *   http://www.apache.org/licenses/LICENSE-2.0
 * 
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

require_once dirname(__FILE__).'/Common.php';
require_once dirname(__FILE__).'/NullLogger.php';

/**
 * PHP to XML converter
 * 
 * @author Mike Bevz <myb@mikebevz.com>
 * @version 0.0.4
 */
class Php2Xml extends Common {
    /**
     * Php class to convert to XML
     * @var Object
     */
    private $phpClass = null;
    
    
    /**
     * 
     * @var DOMElement
     */
    private $root;
    
    
    protected $rootTagName;
    
    private $logger;
    
    public function __construct($phpClass = null) {
        if ($phpClass != null) {
            $this->phpClass = $phpClass;
        }
        
        // @todo implement logger injection
        try {
            if (class_exists('\Zend_Registry')) { // this does not work due to PHP bug @see http://bugs.php.net/bug.php?id=46813
                $this->logger = \Zend_Registry::get('logger');
            } else {
                $this->logger = new NullLogger();
            }
        } catch (\Exception $zendException) {
            $this->logger = new NullLogger();
        }
        
        $this->buildXml();
    }
    
    public function getXml($phpClass = null) {
        if ($this->phpClass == null && $phpClass == null) {
            throw new \RuntimeException("Php class is not set");
        }
        
        if ($phpClass != null) {
            $this->phpClass = $phpClass;
        }
        
        $propDocs = $this->parseClass($this->phpClass, $this->dom, true);
        
        foreach ($propDocs as $name => $data) {
            if (isset($data['value']) && is_array($data['value'])) {
                $elName = array_reverse(explode("\\",$name));
                $namespace = isset($data['xmlNamespace']) ? $data['xmlNamespace'] : null;
                $elementName = $elName[0];
                if ($namespace !== null && !empty($namespace)) {
                    $code = $this->getNsCode($namespace);
                    $elementName = $code . ':' . $elementName;
                }
                foreach ($data['value'] as $arrEl) {
                    //@todo fix this workaroung. it's only works for one level array
                    $dom = $this->dom->createElement($elementName);
                    $this->parseObjectValue($arrEl, $dom);
                    $this->root->appendChild($dom); 
                }
            } else {
                $this->addProperty($data, $this->root);
            }
        }
        $xml = $this->dom->saveXML();
        //$xml = utf8_encode($xml);
        
        return $xml;
        
    }
    
    
    private function parseClass($object, $dom, $rt = false) {
        $refl           = new \ReflectionClass($object);
        $docs           = $this->parseDocComments($refl->getDocComment());
        $xmlName        = isset($docs['xmlName']) ? $docs['xmlName'] : null;
        $xmlNamespace   = isset($docs['xmlNamespace']) ? $docs['xmlNamespace'] : null;

        if ($xmlNamespace !== null && $xmlNamespace != '') {
            $code = '';
            if (is_object($this->root)) { // root initialized
                $code = $this->getNsCode($xmlNamespace);
                $root = $this->dom->createElement($code.":".$xmlName);
            } else { // creating root element
                $code = $this->getNsCode($xmlNamespace, true);
                $root = $this->dom->createElementNS($xmlNamespace, $code.":".$xmlName);
            }
            
            $dom->appendChild($root);
        } else {
            //print_r("No Namespace found \n");
            $root = $this->dom->createElement($xmlName);
            $dom->appendChild($root);
        }
        
        if ($rt === true) {
            $this->rootTagName = $xmlName;
            $this->rootNsName = $xmlNamespace;
            $this->root = $root;
        }
        
        $properties = $refl->getProperties();
        
        $propDocs = array();
        foreach ($properties as $prop) {
            $pDocs = $this->parseDocComments($prop->getDocComment());
            $propDocs[$prop->getName()] = $pDocs;
            $propDocs[$prop->getName()]['value'] = $prop->getValue($object);
        }
        
        return $propDocs;
    }
    
    private function buildXml() {
        $this->dom = new \DOMDocument('1.0', 'UTF-8');
        $this->dom->formatOutput = true;
        $this->dom->preserveWhiteSpace = false;
        $this->dom->recover = false;
        $this->dom->encoding = 'UTF-8';
        
    }
    
    
    
    private function addProperty($docs, $dom) {
        $value          = isset($docs['value']) ? $docs['value'] : null;
        $xmlName        = isset($docs['xmlName']) ? $docs['xmlName'] : null;
        $xmlNamespace   = isset($docs['xmlNamespace']) ? $docs['xmlNamespace'] : null;

        if ($value !== null && $value != '') {
            $el = "";
            
            if (array_key_exists('xmlNamespace', $docs)) {
                $code = $this->getNsCode($xmlNamespace);
                $el = $this->dom->createElement($code.":".$xmlName);
            } else {
                $el = $this->dom->createElement($xmlName);
            }
            
            if (is_object($value)) {
                //print_r("Value is object \n");
                $el = $this->parseObjectValue($value, $el);
            } elseif (is_string($value)) {
                if (array_key_exists('xmlNamespace', $docs)) {
                    $code = $this->getNsCode($xmlNamespace);
                    $el = $this->dom->createElement($code.":".$xmlName, $value);
                } else {
                    $el = $this->dom->createElement($xmlName, $value);
                }
            } else {
                //print_r("Value is not string");
            }
            
            $dom->appendChild($el);
        }
    }
  
    /**
     * 
     * Parse value of the object
     * 
     * @param Object $obj
     * @param DOMElement $element
     * 
     * @return DOMElement
     */
    private function parseObjectValue($obj, $element) {
        
        $this->logger->debug("Start with:".$element->getNodePath());
        
        $refl = new \ReflectionClass($obj);
        
        $classDocs  = $this->parseDocComments($refl->getDocComment());
        $classProps = $refl->getProperties(); 
        $namespace = isset($classDocs['xmlNamespace']) ? $classDocs['xmlNamespace'] : null;
        //print_r($classProps);
        foreach($classProps as $prop) {
            $propDocs           = $this->parseDocComments($prop->getDocComment());
            $propXmlNamespace   = isset($propDocs['xmlNamespace']) ? $propDocs['xmlNamespace'] : null;
            $propXmlName        = isset($propDocs['xmlName']) ? $propDocs['xmlName'] : null;
            $propXmlType        = isset($propDocs['xmlType']) ? $propDocs['xmlType'] : null;
            $propVar            = isset($propDocs['var']) ? $propDocs['var'] : null;
            if (is_string($propXmlNamespace) && $propXmlNamespace !== '') {
                $code = $this->getNsCode($propXmlNamespace);
                $propXmlName = $code.":".$propXmlName;
            }
            //print_r($prop->getDocComment());
            if (is_object($prop->getValue($obj))) {
                //print($propDocs['xmlName']."\n");
                $el = $this->dom->createElement($propXmlName);
                $el = $this->parseObjectValue($prop->getValue($obj), $el);
                //print_r("Value is object in Parse\n");
                
                $element->appendChild($el);
            } else {
                if ($prop->getValue($obj) != '') {
                    if ($propXmlType == 'element') {
                        $el = '';
                        $value = $prop->getValue($obj);
                        
                        if (is_array($value)) {
                            $this->logger->debug("Creating element:".$propXmlName);
                            $this->logger->debug(print_r($value, true));
                            // if PHP type of the property is array, convert it recursively into appropriate XML tree
                            if ($propVar === 'array') {
                                $this->appendArrayToDomElement($value, $element, $this->dom);
                            } else {
                                foreach ($value as $node) {
                                    $this->logger->debug(print_r($node, true));
                                    $el = $this->dom->createElement($propXmlName);
                                    $arrNode = $this->parseObjectValue($node, $el);
                                    $element->appendChild($arrNode);
                                }
                            }

                        } else {
                            $el = $this->dom->createElement($propXmlName, $value);
                            $element->appendChild($el);
                        }
                        //print_r("Added element ".$propDocs['xmlName']." with NS = ".$propDocs['xmlNamespace']." \n");
                    } elseif ($propXmlType == 'attribute') {
                        $atr = $this->dom->createAttribute($propXmlName);
                        $text = $this->dom->createTextNode($prop->getValue($obj));
                        $atr->appendChild($text);
                        $element->appendChild($atr);
                    } elseif ($propXmlType == 'value') {
                        
                        $this->logger->debug(print_r($prop->getValue($obj), true));

                        // handle CDATA, if CDATA tags are available
                        if (
                            strpos($prop->getValue($obj), '<![CDATA[') === 0
                            && strpos($prop->getValue($obj), ']]>') !== false
                        ) {
                            // replace text <![CDATA[ and ]]>
                            $cdataValue = str_replace(
                                array('<![CDATA[', ']]>'),
                                array('', ''),
                                $prop->getValue($obj)
                            );
                            $txtNode = $this->dom->createCDATASection($cdataValue);
                        } else {
                            // normal handling
                            $txtNode = $this->dom->createTextNode($prop->getValue($obj));
                        }

                        $element->appendChild($txtNode);
                    } 
                }
            }
        }
        
        return $element;
    }

    protected function appendArrayToDomElement(array $data, \DOMElement $element, \DOMDocument $dom = null)
    {
        if (!$dom instanceof \DOMDocument) {
            $dom = new \DOMDocument();
        }

        foreach ($data as $key => $value) {
            if (is_int($key)) {
                $parentElement = $element->parentNode;
                if ($parentElement === null) {
                    $key = 'index_' . $key;
                    if (is_array($value)) {
                        $newElement = $dom->createElement($key);
                        $element->appendChild($newElement);
                        $this->appendArrayToDomElement($value, $newElement, $dom);
                        if( is_int(key($value)) ) {
                            $element->removeChild($newElement);
                        }
                    } else {
                        $this->appendNewTextElement($dom, $element, $key, $value);
                    }
                } elseif( is_array($value) ) {
                    $newElement = $dom->createElement($element->nodeName);
                    $parentElement->appendChild($newElement);
                    $this->appendArrayToDomElement($value, $newElement, $dom);
                } else {
                    $this->appendNewTextElement($dom, $parentElement, $element->nodeName, $value);
                }
            } elseif (is_array($value)) {
                $newElement = $dom->createElement($key);
                $element->appendChild($newElement);
                $this->appendArrayToDomElement($value, $newElement, $dom);
                if( is_int(key($value)) ) {
                    $element->removeChild($newElement);
                }
            } else {
                $this->appendNewTextElement($dom, $element, $key, $value);
            }
        }
    }

    /**
     * @param \DOMDocument $dom
     * @param \DOMElement $parent
     * @param string $key
     * @param string $value
     */
    protected function appendNewTextElement(\DOMDocument $dom, \DOMElement $parent, $key, $value)
    {
        $encoded = \htmlentities((string) $value, ENT_XML1|ENT_COMPAT);
        $newElement = $dom->createElement($key, $encoded);
        $parent->appendChild($newElement);
    }
}