<?php

class SwiftClientGenerator extends ClientGeneratorFromXml 
{
	private $_csprojIncludes = array();
	protected $_baseClientPath = "KalturaClient";
	protected static $reservedWords = array('protocol', 'repeat', 'extension');
	protected $xpath;
	protected $pluginName = null;
	
	function __construct($xmlPath, Zend_Config $config, $sourcePath = "swift")
	{
		parent::__construct($xmlPath, $sourcePath, $config);
	}
	
	function getSingleLineCommentMarker()
	{
		return '//';
	}
	
	public function generate() 
	{
		parent::generate();
		
		$this->xpath = new DOMXPath($this->_doc);
		
		$this->generatePlugin();
		
		$configurationNodes = $this->xpath->query("/xml/configurations/*");
		$this->writeMainClient($configurationNodes);
		$this->writeRequestBuilderData($configurationNodes);
		
		$pluginNodes = $this->xpath->query("/xml/plugins/*");
		foreach($pluginNodes as $pluginNode) {
			$this->pluginName = $pluginNode->getAttribute("name");
			$pluginName = ucfirst($this->pluginName);
			$this->_baseClientPath = "plugins/KalturaClient{$pluginName}Plugin";
			$this->generatePlugin();
		}
	}
	
	private function generatePlugin() {
		
		$enumNodes = $this->xpath->query("/xml/enums/enum");
		foreach($enumNodes as $enumNode)
		{
			$this->writeEnum($enumNode);
		}
		
		$classNodes = $this->xpath->query("/xml/classes/class");
		foreach($classNodes as $classNode)
		{
			$this->writeClass($classNode);
		}
		
		$serviceNodes = $this->xpath->query("/xml/services/service");
		foreach($serviceNodes as $serviceNode)
		{
			$this->writeService($serviceNode);
		}
	}
	
	//Private functions
	/////////////////////////////////////////////////////////////
	private function addDescription($propertyNode, $prefix) {
		
		if($propertyNode->hasAttribute("description"))
		{
			$desc = $propertyNode->getAttribute("description");
			$desc = str_replace(array("&", "<", ">"), array("&amp;", "&lt;", "&gt;"), $desc);
			$formatDesc = wordwrap(str_replace(array("\t", "\n", "\r"), " ", $desc), 80, "\n" . $prefix . "  ");
			if($desc)
				return($prefix . "/**  $formatDesc  */");
		}
		return "";
	}
	
	function writeEnum(DOMElement $enumNode) 
	{
		$enumName = $enumNode->getAttribute("name");
		if(!$this->shouldIncludeType($enumName) || $enumName === 'KalturaNullableBoolean') {
			return;
		}
		
		if($enumNode->getAttribute("plugin") != $this->pluginName) {
			return;
		}
		
		$enumName = $this->getSwiftTypeName($enumName);
		
		$str = "";
		$str .= $this->getBanner();
		
		$desc = $this->addDescription($enumNode, "");
		if($desc) {
			$str .= $desc . "\n";
		}
		
		if($enumNode->childNodes->length) {
			$enumType = $enumNode->getAttribute("enumType");
			$baseInterface = ($enumType == "string") ? "String": "Int";
			$str .= "public enum $enumName: $baseInterface {\n";
		}
		else {
			$str .= "public enum $enumName {\n";
		}
		
		// Print enum values
		$enumCount = $this->generateEnumValues($enumNode, $str);
		
		$str .= "}\n";
		$file = $this->_baseClientPath . "/Classes/Model/Enums/$enumName.swift";
		$this->addFile($file, $str);
	}
	
	function generateEnumValues($enumNode, &$str)
	{
		$enumType = $enumNode->getAttribute("enumType");
		$enumCount = 0;
		$enumValues = array();
		$processedValues = array();
		
		foreach($enumNode->childNodes as $constNode)
		{
			if($constNode->nodeType != XML_ELEMENT_NODE)
				continue;
				
			$propertyName = $constNode->getAttribute("name");
			$propertyValue = $constNode->getAttribute("value");
			
			if(in_array($propertyValue, $processedValues))
				continue;			// Swift does not allow duplicate values in enums
			$processedValues[] = $propertyValue;

			if($enumType == "string")
			{
				$propertyValue = "\"$propertyValue\"";
			}
			$enumValues[] = "case $propertyName = $propertyValue";
		}
		
		if(count($enumValues) == 0)
			$str .= "	/** Place holder for future values */";
		else  {
			$enums = implode("\n	", $enumValues);
			$str .= "	$enums";
		}
		
		$str .= "\n";
		return count($enumValues);
	}
	
	function writeClass(DOMElement $classNode) 
	{
		$type = $classNode->getAttribute("name");
		if(!$this->shouldIncludeType($type) || $type === 'KalturaObject' || preg_match('/ListResponse$/', $type)) {
			return;
		}	
			
		if($classNode->getAttribute("plugin") != $this->pluginName) {
			return;
		}

		$type = $this->getSwiftTypeName($type);
		
		// File name
		$file = $this->_baseClientPath . "/Classes/Model/Objects/$type.swift";
		
		// Add Banner
		$this->startNewTextBlock();
		$this->appendLine("");
		$this->appendLine($this->getBanner());
		
		$desc = $this->addDescription($classNode, "");
		if($desc)
			$this->appendLine($desc);
		
		$baseClass = $classNode->hasAttribute("base") ? $this->getSwiftTypeName($classNode->getAttribute("base")) : 'ObjectBase';
		$this->appendLine("open class $type: $baseClass {");

		// Generate parameters declaration
		$this->generateParametersDeclaration($classNode);
		$this->appendLine("");
		
		// class definition
		$public= 'public';
		if($classNode->hasAttribute("abstract")) {
			$public= 'internal';
		}
			
// 		// Generate empty constructor
// 		$this->appendLine("	$public override init() {");
// 		$this->appendLine("		super.init()");
// 		$this->appendLine("	}");
// 		$this->appendLine("");
		
		// Generate Full constructor
		$this->generatePopulate($classNode);
		$this->appendLine("");

		// Generate to params method
		$this->generateToDictionaryMethod($classNode);
		
		// close class
		$this->appendLine("}");
		$this->appendLine();
		
		$this->addFile($file, $this->getTextBlock());
	}
	
	public function replaceReservedWords($name, $additionalValues = array()) {
		if(in_array($name, self::$reservedWords) || in_array($name, $additionalValues)) {
			return $name . '_';
		}
		
		return $name;
	}
	
	public function generateParametersDeclaration($classNode) {

		$needsArrayList = false;
		$needsHashMap = false;
		$arrImportsEnums = array();
		$arrFunctions = array();

		$this->appendLine("");
		foreach($classNode->childNodes as $propertyNode) 
		{
			if($propertyNode->nodeType != XML_ELEMENT_NODE)
				continue;
			
			$propName = $this->replaceReservedWords($propertyNode->getAttribute("name"));
			$propType = $propertyNode->getAttribute("type");
			$isEnum = $propertyNode->hasAttribute("enumType");
			
			$swiftType = $this->getSwiftType($propertyNode, true);

			if($isEnum && $swiftType != 'Bool') 
				$arrImportsEnums[] = $this->getSwiftTypeName($swiftType); 
			
			if($propType == "array")
				$needsArrayList = true;
			
			if($propType == "map")
				$needsHashMap = true;
				
			if(strpos($propType, 'Kaltura') === 0)
			{
				$propType = $this->getSwiftTypeName($propType);
			}
						
			$propertyLine = "public var $propName: $swiftType?";
			
			$initialValue = 'nil';
			if($initialValue != "") 
				$propertyLine .= " = " . $initialValue;
			
			$desc = $this->addDescription($propertyNode,"\t");
			if($desc)
				$this->appendLine($desc);
			
			$this->appendLine("	$propertyLine");
		}

		$this->appendLine("");
		foreach($arrFunctions as $arrFunctionsLine){		
			$this->appendLine($arrFunctionsLine);
		}
	}
	
	public function generateToDictionaryMethod($classNode) 
	{	
		$hasProperties = false;
		foreach($classNode->childNodes as $propertyNode)
		{
			if($propertyNode->nodeType != XML_ELEMENT_NODE) {
				continue;
			}
			
			$propReadOnly = $propertyNode->getAttribute("readOnly");
			if($propReadOnly == "1") {
				continue;
			}
			
			$hasProperties = true;
			break;
		}
		if(!$hasProperties) {
			return;
		}
		
		$type = $classNode->getAttribute("name");
		$this->appendLine("	public override func toDictionary() -> [String: Any] {");//throws APIException
		$this->appendLine("		var dict: [String: Any] = super.toDictionary()");
		
		foreach($classNode->childNodes as $propertyNode) 
		{
			if($propertyNode->nodeType != XML_ELEMENT_NODE) {
				continue;
			}
			
			$propReadOnly = $propertyNode->getAttribute("readOnly");
			if($propReadOnly == "1") {
				continue;
			}
			
			$propType = $propertyNode->getAttribute("type");
			$apiPropName = $propertyNode->getAttribute("name");
			$enumType= $propertyNode->getAttribute("enumType");
			$propName = $this->replaceReservedWords($apiPropName);
			
			$this->appendLine("		if($propName != nil) {");
			if($enumType && $enumType != 'KalturaNullableBoolean') {
				$this->appendLine("			dict[\"$apiPropName\"] = $propName!.rawValue");
			}
			elseif($this->isSimpleType($propType)) {
				$this->appendLine("			dict[\"$apiPropName\"] = $propName!");
			}
			elseif($propType == 'array') {
				$this->appendLine("			dict[\"$apiPropName\"] = $propName!.map { value in value.toDictionary() }");
			}
			elseif($propType == 'map') {
				$this->appendLine("			dict[\"$apiPropName\"] = $propName!.map { key, value in (key, value.toDictionary()) }");
			}
			else {
				$this->appendLine("			dict[\"$apiPropName\"] = $propName!.toDictionary()");
			}
			$this->appendLine("		}");
		}
		$this->appendLine("		return dict");
		$this->appendLine("	}");
	}

	public function generatePopulate($classNode)
	{
		$type = $this->getSwiftTypeName($classNode->getAttribute("name"));
		$this->appendLine("	internal override func populate(_ dict: [String: Any]) throws {");
		$this->appendLine("		try super.populate(dict);");

		if($classNode->childNodes->length)
		{
			/*$this->appendLine("		$type temp = gson.fromJson(json, $type.class);\n");
			$this->appendLine("		if(temp == null) return;\n");*/

			$propBlock = "		// set members values:\n";

			foreach($classNode->childNodes as $propertyNode)
			{
				if($propertyNode->nodeType != XML_ELEMENT_NODE)
					continue;
					
				$apiPropName = $propertyNode->getAttribute("name");
				$propName = $this->replaceReservedWords($apiPropName);
				$propType = $propertyNode->getAttribute("type");

				$propBlock .= $this->getPropertyValue($propName, $apiPropName, $propType, $propertyNode)."\n";

			}

			$this->appendLine($propBlock);
		}
		$this->appendLine("	}");
	}
	
	public function getPropertyValue($propName, $apiPropName, $propType, $propertyNode) {
		$propEnumType = null;
		$primitiveType = "";
		
		$ret = "		if dict[\"$apiPropName\"] != nil {\n";
		
		switch($propType) {
			case "bigint":
			case "time":
				$primitiveType = "Int64";
				break;
			case "bool":
				$primitiveType = "Bool";
				break;
			case "float":
				$primitiveType = "Double";
				break;
			case "int":
			case "string":
				$primitiveType = $propType;
				$propEnumType = $propertyNode->hasAttribute("enumType") ? $this->getSwiftTypeName($propertyNode->getAttribute("enumType")): null;
				if($propEnumType === 'Boolean') 
				{
					$primitiveType = 'Bool';
					$propEnumType = null;
				}
			break;

			case "map":
				$propArrayType = $this->getSwiftTypeName($propertyNode->getAttribute("arrayType"));
				$ret .="			$propName = try JSONParser.parse(map: dict[\"".$apiPropName."\"] as! [String: Any])\n";
				$ret .= "		}";
				return $ret;
				break;

			case "array":
				$propArrayType = $this->getSwiftTypeName($propertyNode->getAttribute("arrayType"));
				$ret .="			$propName = try JSONParser.parse(array: dict[\"".$apiPropName."\"] as! [Any])\n";
				$ret .= "		}";
				return $ret;
				break;
		}

		if($primitiveType != ""){
			if($propEnumType == 'Bool') {
				$primitiveType = 'Bool';
				$propEnumType = null;
			}
			$primitiveType = ucfirst($primitiveType);
			$parsedProperty = "dict[\"".$apiPropName."\"] as? $primitiveType";
			if($propEnumType != null && $propEnumType != 'Bool') {
				$ret = "		if dict[\"$apiPropName\"] != nil {\n";
				if($primitiveType == 'String') {
					$ret .= "			$propName = $propEnumType(rawValue: \"\\(dict[\"$apiPropName\"]!)\")\n";
				}
				else {
					$ret .= "			$propName = $propEnumType(rawValue: ($parsedProperty)!)\n";
				}
			}
			else {
				$ret .="			$propName = $parsedProperty\n";
			}
		} else {
			$propType = $this->getSwiftTypeName($propType);
			$ret .="		$propName = try JSONParser.parse(object: dict[\"".$apiPropName."\"] as! [String: Any])";
		}
		$ret .= "		}";
		return $ret;
	}


	/**
	 * @param propType
	 */
	public function handleSinglePropByType($propertyNode, &$propBlock, &$txtIsUsed) {
		
		$propType = $this->getSwiftTypeName($propertyNode->getAttribute("type"));
		$propName = $propertyNode->getAttribute("name");
		$isEnum = $propertyNode->hasAttribute("enumType");
		$propBlock .= "this.$propName = ";
		
		switch($propType) 
		{
			case "bigint":
			case "time":
			case "int":
			case "string":
			case "bool":
			case "float":
				if($propType == "float")
				{
					$propType = "double";
				}
				if($propType == "time")
				{
					$propType = "long";//"bigint";
				}

				$txtIsUsed = true;
				$parsedProperty = "GsonParser.parse".ucfirst($propType)."(txt)";
				if($isEnum) 
				{
					$enumType = $this->getSwiftTypeName($propertyNode->getAttribute("enumType"));
					$propBlock .= "$enumType.get($parsedProperty);\n";
				} 
				else
				{
					$propBlock .= "$parsedProperty;\n";
				}
				break;
				
			case "array":
				$arrayType = $this->getSwiftTypeName($propertyNode->getAttribute("arrayType"));
				$propBlock .= "GsonParser.parseArray(aNode, $arrayType.class);\n";
				break;
				
			case "map":
				$arrayType = $this->getSwiftTypeName($propertyNode->getAttribute("arrayType"));
				$propBlock .= "GsonParser.parseMap(aNode, $arrayType.class);\n";
				break;
				
			default: // sub object
				$propBlock .= "GsonParser.parseObject(aNode, $propType.class);\n";
				break;
		}
	}

	function writeService(DOMElement $serviceNode) 
	{
		$serviceId = $serviceNode->getAttribute("id");
		if(!$this->shouldIncludeService($serviceId)) {
			return;
		}
		
		if($serviceNode->getAttribute("plugin") != $this->pluginName) {
			return;
		}
		
		$serviceName = $serviceNode->getAttribute("name");
		
		$swiftServiceName = $this->upperCaseFirstLetter($serviceName) . "Service";
		
		$this->startNewTextBlock();
		$this->appendLine();
		$this->appendLine($this->getBanner());
		$desc = $this->addDescription($serviceNode, "");
		if($desc)
			$this->appendLine($desc);
		
		$this->appendLine("public final class $swiftServiceName{");
		
		$actionNodes = $serviceNode->childNodes;
		
		foreach($actionNodes as $actionNode) 
		{
			if($actionNode->nodeType != XML_ELEMENT_NODE) 
				continue;
			
			try 
			{
				$this->writeAction($serviceId, $actionNode);
			}
			catch(Exception $e) 
			{
				KalturaLog::err($e->getMessage());
			}
		}
		$this->appendLine("}");
		
		$file = $this->_baseClientPath . "/Classes/Services/$swiftServiceName.swift";
		$this->addFile($file, $this->getTextBlock());
	}
	
	function getListResponseInternalType($listResponseType) 
	{
		$objectsNodes = $this->xpath->query("/xml/classes/class[@name = 'Kaltura{$listResponseType}']/property[@name = 'objects']");
		if(!$objectsNodes->length)
		{
			throw new Exception('List response type [$listResponseType] has no objects array property');
		}
		
		$objectsNode = $objectsNodes->item(0);
		if(!$objectsNode->hasAttribute('arrayType'))
		{
			throw new Exception('List response type [$listResponseType] objects has no array type');
		}
		
		return $this->getSwiftTypeName($objectsNode->getAttribute('arrayType'));
	}
	
	function writeAction($serviceId, DOMElement $actionNode) 
	{
		$action = $actionNode->getAttribute("name");
		if(!$this->shouldIncludeAction($serviceId, $action))
			return;
			
		$paramNodes = $actionNode->getElementsByTagName("param");
		$paramNodesArr = array();
		foreach($paramNodes as $paramNode)
		{
			$paramType = $paramNode->getAttribute("type");
			if($paramType == 'file') {
				return;
			}
			$paramNodesArr[] = $paramNode;
		}
		
		$action = $this->replaceReservedWords($action);
		
		$resultNode = $actionNode->getElementsByTagName("result")->item(0);
		$resultType = $this->getSwiftTypeName($resultNode->getAttribute("type"));
		if($resultType == 'file') {
			return;
		}
		
		$returnType = 'Void';
		$arrayType = '';
		$fallbackClass = null;
		if($resultType) {
			$fallbackClass = $this->getObjectType($resultType);
			$returnType = $fallbackClass;
		}
		
		if($resultType == "file") {
			$returnType = 'String';
			$fallbackClass = null;
		}
		
		if($resultType == "array") {
			$arrayType = $this->getSwiftTypeName($resultNode->getAttribute("arrayType"));
			$fallbackClass = $arrayType;
			$returnType = "Array<$arrayType>";
		}
		elseif($resultType == "map") {
			$arrayType = $this->getSwiftTypeName($resultNode->getAttribute("arrayType"));
			$fallbackClass = $arrayType;
			$returnType = "Dictionary<$arrayType>";
		}
		elseif($resultType && ($resultType != 'file') && !$this->isSimpleType($resultType))
		{
			if(preg_match('/ListResponse$/', $resultType))
			{
				$arrayType = $this->getListResponseInternalType($resultType);
				$resultType = 'ListResponse';
				$fallbackClass = $arrayType;
				$returnType = "ListResponse<$arrayType>";
			}
		}
		
		$signaturePrefix = "public static func $action";
		
		$swiftOutputType = $this->getResultType($resultType, $arrayType);
		
		$this->writeActionOverloads($signaturePrefix, $action, $paramNodesArr, $swiftOutputType, $returnType);
		
		$signature = $this->getSignature($action, $paramNodesArr, array('' => 'FileHolder'));
		
		$this->appendLine();
		
		$desc = $this->addDescription($actionNode, "\t");
		if($desc) {
			$this->appendLine($desc);
		}
		
		$this->appendLine("	$signaturePrefix($signature) -> RequestBuilder<$returnType> {");//throws APIException
		$this->generateActionBodyServiceCall($serviceId, $action, $paramNodesArr, $swiftOutputType, $fallbackClass);
		$this->appendLine("	}");
	}

	public function writeActionOverloads($signaturePrefix, $action, $paramNodes, $swiftOutputType, $returnType)
	{
		// split the parameters into mandatory and optional
		$mandatoryParams = array();
		$optionalParams = array();
		foreach($paramNodes as $paramNode) 
		{
			$optional = $paramNode->getAttribute("optional");
			if($optional == "1")
				$optionalParams [] = $paramNode;
			else
				$mandatoryParams [] = $paramNode;
		}
		
		for($overloadNumber = 0; $overloadNumber < count($optionalParams) + 1; $overloadNumber ++) 
		{
			$prototypeParams = array_slice($paramNodes, 0, count($mandatoryParams) + $overloadNumber);
			$callParams = array_slice($paramNodes, 0, count($mandatoryParams) + $overloadNumber + 1);
			
			// find which file overloads need to be generated
			$hasFiles = false;
			foreach($prototypeParams as $paramNode)
			{
				if($paramNode->getAttribute("type") == "file") {
					$hasFiles = true;
					break;
				}
			}

			if($hasFiles)
			{
				$fileOverloads = array(  
					array('' => 'FileHolder'),
					array('' => 'File'),
					array('' => 'InputStream', 'MimeType' => 'String', 'Name' => 'String', 'Size' => 'long'),
					array('' => 'FileInputStream', 'MimeType' => 'String', 'Name' => 'String'),
				);
			}
			else
			{
				$fileOverloads = array(
					array('' => 'FileHolder'),
				);
			}

			foreach($fileOverloads as $fileOverload)
			{
				if(reset($fileOverload) == 'FileHolder' && $overloadNumber == count($optionalParams)){
					continue;			// this is the main overload
				}
				
				// build the function prototype
				$signature = $this->getSignature($action, $prototypeParams, $fileOverload);
								
				// build the call parameters
				$params = array();
				foreach($callParams as $paramNode) 
				{
					$optional = $paramNode->getAttribute("optional");
					$paramName = $this->replaceReservedWords($paramNode->getAttribute("name"), array($action));
					$paramType = $paramNode->getAttribute("type");
					
					if(/*$optional == "1" &&*/ ! in_array($paramNode, $prototypeParams, true))
					{
						$params[] = "$paramName: " . $this->getDefaultParamValue($paramNode);
						continue;
					} 
						
					if($paramType != "file" || reset($fileOverload) == 'FileHolder')
					{
						$params[] = "$paramName: $paramName";
						continue;
					}
					
					$fileParams = array();
					foreach($fileOverload as $namePostfix => $paramType)
					{
						$fileParams[] = "$paramName: {$paramName}{$namePostfix}";
					}
					$params[] = "$paramName: new FileHolder(" . implode(', ', $fileParams) . ")";
				}				
				$paramsStr = implode(', ', $params);
				
				// write the result
				$this->appendLine();
				$this->appendLine("	$signaturePrefix($signature) -> RequestBuilder<$returnType> {"); // throws APIException
				$this->appendLine("		return $action($paramsStr)");
				$this->appendLine("	}");
			}
		}
	}
	
	public function generateActionBodyServiceCall($serviceId, $action, $paramNodes, $swiftOutputType)
	{
		$this->appendLine("		let request: $swiftOutputType = $swiftOutputType(service: \"$serviceId\", action: \"$action\")");
		foreach($paramNodes as $paramNode)
		{
			$paramType = $paramNode->getAttribute("type");
			$apiParamName = $paramNode->getAttribute("name");
			$paramName = $this->replaceReservedWords($apiParamName, array($action));
			$isEnum = $paramNode->hasAttribute("enumType");
				
			if($paramType == "file") {
				$this->appendLine("			.setFile(key: \"$apiParamName\", value: $paramName)");
			}
			elseif($isEnum && $paramNode->getAttribute("enumType") != 'KalturaNullableBoolean') {
				$optional = $paramNode->getAttribute("optional");
				if($optional == "1") {
					$paramName.= '?';
				}
				$this->appendLine("			.setBody(key: \"$apiParamName\", value: $paramName.rawValue)");
			}
			else {
				$this->appendLine("			.setBody(key: \"$apiParamName\", value: $paramName)");
			}
		}

		$this->appendLine("\n		return request");
	}
	
	function writeRequestBuilderData(DOMNodeList $configurationNodes)
	{
		$imports = "";
		$imports .= "package com.kaltura.client.utils.request;\n";
		$imports .= "\n";
		$imports .= "import com.kaltura.client.Params;\n";
		
		$this->startNewTextBlock();
		$this->appendLine($this->getBanner());
		$this->appendLine("public class RequestBuilderData {");
		$this->appendLine("	");
		$this->appendLine("	protected Params params;");
		$this->appendLine("	");
		$this->appendLine("	protected RequestBuilderData(Params params) {");
		$this->appendLine("		this.params = params;");
		$this->appendLine("	}");
		$this->appendLine("	");
		
	
		//$volatileProperties = array();
		foreach($configurationNodes as $configurationNode)
		{
			/* @var $configurationNode DOMElement */
			$configurationName = $configurationNode->nodeName;
			$attributeName = lcfirst($configurationName) . "Configuration";

			$constantsPropertiesKeys = "";

			foreach($configurationNode->childNodes as $configurationPropertyNode)
			{
				/* @var $configurationPropertyNode DOMElement */
				
				if($configurationPropertyNode->nodeType != XML_ELEMENT_NODE)
					continue;
			
				$configurationProperty = $configurationPropertyNode->localName;

				$constantsPropertiesKeys .= "public static final String ". ucwords($configurationName)." = \"$configurationName\";\n";

				$type = $configurationPropertyNode->getAttribute("type");
				if(!$this->isSimpleType($type) && !$this->isArrayType($type))
				{
					$type = $this->getSwiftTypeName($type);
					$imports .= "import com.kaltura.client.types.$type;\n";
				}
				
				$type = $this->getSwiftType($configurationPropertyNode, true);
				$description = null;
				
				if($configurationPropertyNode->hasAttribute('description'))
				{
					$description = $configurationPropertyNode->getAttribute('description');
				}
				
				$this->writeConfigurationParam($configurationProperty, $configurationProperty, $type, $description);
				
				if($configurationPropertyNode->hasAttribute('alias'))
				{
					$this->writeConfigurationParam($configurationPropertyNode->getAttribute('alias'), $configurationProperty, $type, $description);					
				}
			}
		}
		
		$this->appendLine("}");
		
		$imports .= "\n";
		
		$this->addFile($this->_baseClientPath . "/utils/request/RequestBuilderData.swift", $imports . $this->getTextBlock());
	}
	
	function writeMainClient(DOMNodeList $configurationNodes) 
	{
		$apiVersion = $this->_doc->documentElement->getAttribute('apiVersion'); //located at input file top
		$date = date('y-m-d');
				
		$this->startNewTextBlock();
		$this->appendLine($this->getBanner());
		$this->appendLine("@objc public class Client: RequestBuilderData {");
		$this->appendLine("	public var configuration: ConnectionConfiguration");
		$this->appendLine("	");
		$this->appendLine("	@objc public init(_ config: ConnectionConfiguration) {");
		$this->appendLine("		configuration = config");
		$this->appendLine("		");
		$this->appendLine("		super.init()");
		$this->appendLine("		");
		$this->appendLine("		clientTag = \"swift:$date\"");
		$this->appendLine("		apiVersion = \"$apiVersion\"");
		$this->appendLine("	}");
		$this->appendLine("}");
		
		$this->appendLine();
		$this->appendLine("extension RequestBuilderData{");
		
		$params = array();
		foreach ($configurationNodes as $configurationNode) {
			/* @var $configurationNode DOMElement */
			
			foreach ($configurationNode->childNodes as $configurationPropertyNode) {
				/* @var $configurationPropertyNode DOMElement */
				
				if ($configurationPropertyNode->nodeType != XML_ELEMENT_NODE) {
					continue;
				}
				
				$configurationProperty = $configurationPropertyNode->localName;
				
				if($configurationPropertyNode->getAttribute("volatile") != '1') {
					$params[] = $configurationProperty;
				}
				
				$type = $configurationPropertyNode->getAttribute("type");
				if (! $this->isSimpleType ($type) && ! $this->isArrayType ($type)) {
					$type = $this->getSwiftTypeName ($type);
				}
				
				$type = $this->getSwiftType ($configurationPropertyNode, true);
				$description = null;
				
				if ($configurationPropertyNode->hasAttribute('description')) {
					$description = $configurationPropertyNode->getAttribute ('description');
				}
				
				$this->writeConfigurationParam($configurationProperty, $configurationProperty, $type, $description);
				
				if ($configurationPropertyNode->hasAttribute ('alias')) {
					$this->writeConfigurationParam($configurationPropertyNode->getAttribute ('alias'), $configurationProperty, $type, $description);
				}
			}
		}
		
		$this->appendLine("	public func applyParams(_ requestBuilder: RequestBuilderData) {");
		foreach($params as $param) {
			$this->appendLine("		if requestBuilder.$param == nil {");
			$this->appendLine("			requestBuilder.$param = $param");
			$this->appendLine("		}");
		}
		$this->appendLine("	}");
		
		$this->appendLine("}");
		
		$this->addFile($this->_baseClientPath . "/Classes/Core/Client.swift", $this->getTextBlock());
	}
	
	protected function writeConfigurationParam($name, $paramName, $type, $description)
	{
		$methodsName = ucfirst($name);
		
		$this->appendLine("	/**");
		if($description)
		{
			$this->appendLine("	 * $description");
		}
		$this->appendLine("	 */");
		$this->appendLine("	public var $name: $type?{");
		$this->appendLine("		get{");
		$this->appendLine("			return params[\"$paramName\"] as? $type");
		$this->appendLine("		}");
		$this->appendLine("		set(value){");
		$this->appendLine("			params[\"$paramName\"] = value");
		$this->appendLine("		}");
		$this->appendLine("	}");
		$this->appendLine("	");
	}
	
	function getSignature($action, $paramNodes, $fileOverload) 
	{
		$signature = array();
		foreach($paramNodes as $paramNode) 
		{
			$paramType = $paramNode->getAttribute("type");
			$paramName = $this->replaceReservedWords($paramNode->getAttribute("name"), array($action));
			$arrayType = $paramNode->getAttribute("arrayType");
			$enumType = $paramNode->getAttribute("enumType");
			$optional = $paramNode->getAttribute("optional");

			if($paramType == "array")
			{
				$arrayType = $this->getSwiftTypeName($arrayType);
			}	
			elseif($paramType == "map")
			{
				$arrayType = $this->getSwiftTypeName($arrayType);
			}	
			elseif($enumType)
			{
				$enumType = $this->getSwiftTypeName($enumType);
			}
			
			if($paramType == "file")
			{
				foreach($fileOverload as $namePostfix => $paramType)
				{
					$signature[] = "{$paramName}{$namePostfix}: {$paramType}";
				}
				continue;
			}
			
			if(strpos($paramType, 'Kaltura') === 0 && !$enumType)
			{
				$paramType = $this->getSwiftTypeName($paramType);
			}
			
			$swiftType = $this->getSwiftType($paramNode, false);
			if($optional == "1") {
				$swiftType .= '?';
			}
			
			$signature[] = "$paramName: $swiftType";
		}
		return implode(', ', $signature);
	}
	
	private function getBanner() 
	{
		$currentFile = $_SERVER ["SCRIPT_NAME"];
		$parts = Explode('/', $currentFile);
		$currentFile = $parts [count($parts) - 1];
		
		$banner = "";
		$banner .= "/**\n";
		$banner .= " * This class was generated using $currentFile\n";
		$banner .= " * against an XML schema provided by Kaltura.\n";
		$banner .= " * \n";
		$banner .= " * MANUAL CHANGES TO THIS CLASS WILL BE OVERWRITTEN.\n";
		$banner .= " */\n";
		
		return $banner;
	}

	public function getDefaultValue($resultType) 
	{
		switch($resultType)
		{
		case "":
			return '';
		
		case "int":
		case "float":
		case "bigint":
		case "time":
		case "Integer":
			return '0';
			
		case "bool":
			return 'false';
			
		default:
			return 'null';				
		}
	}
	
	public function getDefaultParamValue($paramNode)
	{
		$type = $paramNode->getAttribute("type");
		$defaultValue = $paramNode->getAttribute("default");
		$value = trim($defaultValue);
		if($defaultValue == 'null')
			return 'nil';
		
		switch($type)
		{
		case "string": 
			return "\"" . $defaultValue . "\"";
		case "bigint":
		case "time":
			return $value;
		case "int": 
			if($paramNode->hasAttribute("enumType")) 
			{
				$enumType = $this->getSwiftTypeName($paramNode->getAttribute("enumType"));
				if($enumType === 'Bool')
				{
					return $defaultValue == 1 ? 'true' : ($defaultValue == 0 ? 'false' : 'null');
				}
				else 
				{
					return "$enumType(rawValue: $value)";
				}
			}
			else
			{
				return strlen($value) ? $value : 'nil';
			}
				
		case "file":
			return 'nil';
		
		default:
			return $defaultValue;
		}
	}
	
	public function getResultType($resultType, $arrayType) 
	{
		switch($resultType)
		{
		case null:
			return "NullRequestBuilder";
			
		case "ListResponse":
			return("ListResponseRequestBuilder<" . $arrayType . ">");
			
		case "array":
			return("ArrayRequestBuilder<" . $arrayType . ">");
			
		case "map":
			return("MapRequestBuilder<" . $arrayType . ">");

		case "int":
			return("RequestBuilder<Int>");

		case "bigint":
		case "time":
			return("RequestBuilder<Int64>");
		
		case "bool":
			return("RequestBuilder<Bool>");
			
		case "string":
			return("RequestBuilder<String>");
			
		case "file":
			return("ServeRequestBuilder");
			
		default:
			return("RequestBuilder<$resultType>");
		}
	}
	
	public function getSwiftTypeName($type)
	{
		if($type === 'KalturaNullableBoolean'){
			return 'Bool';
		}
		if($type === 'KalturaString'){
			$type = 'KalturaStringHolder';
		}
		elseif($type === 'KalturaObject'){
			$type = 'KalturaObjectBase';
		}
		
		return preg_replace('/^Kaltura/', '', $type);
	}
	
	public function getObjectType($type)
	{
		switch($type) 
		{
		case "bool":
			return "Bool";

		case "float":
			return "Double";

		case "bigint":
			return "Int64";
			
		case "time":
			return "UInt64";
			
		case "int":
			return "Int";

		case "string":
			return "String";

		case "file":
			return "FileHolder";
			
		default:
			return $this->getSwiftTypeName($type);
		}
	}
	
	public function getSwiftType($propertyNode, $enforceObject = false)
	{
		$propType = $propertyNode->getAttribute("type");
		$isEnum = $propertyNode->hasAttribute("enumType");
		
		switch($propType) 
		{
		case "int":
			if($isEnum) 
				return $this->getSwiftTypeName($propertyNode->getAttribute("enumType"));
			else 
				return $this->getObjectType($propType, $enforceObject);

		case "string":
			if($isEnum) 
				return $this->getSwiftTypeName($propertyNode->getAttribute("enumType"));
			else 
				return $this->getObjectType($propType, $enforceObject);

		case "array":
			$arrayType = $this->getObjectType($propertyNode->getAttribute("arrayType"), $enforceObject);
			return "Array<$arrayType>";

		case "map":
			$arrayType = $this->getObjectType($propertyNode->getAttribute("arrayType"), $enforceObject);
			return "Dictionary<String, $arrayType>";
			
		default:
			return $this->getObjectType($propType, $enforceObject);
		}
	}
}
