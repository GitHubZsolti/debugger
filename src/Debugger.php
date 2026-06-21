<?php

namespace NetRoam\Debug;

use Closure;
use ReflectionClass;
use ReflectionMethod;
use ReflectionObject;
use ReflectionFunction;
use ReflectionProperty;
use ReflectionException;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;

class Debugger {
    private static ?Debugger $instance = null;
    private string $stylesheet;
    private string $script;
    private bool $cyclicCheck = true;
    private bool $withMethods = false;
    private int $objectCounter = 0;
    private array $seenObjects = [];
    private array $processedObjects = [];
    private int $autoOpenLevel = 1;

    public function __construct() {
        $this->stylesheet = file_get_contents(__DIR__ . "/assets/debug.css");
        $this->script = file_get_contents(__DIR__ . "/assets/debug.js");
        echo("<style type=\"text/css\">{$this->stylesheet}</style>");
    }

    public function __destruct() {
        echo "<script language=\"javascript\" type=\"text/javascript\" src=\"https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js\"></script>";
        echo "<script language=\"javascript\" type=\"text/javascript\">{$this->script}</script>";
    }

    public static function getInstance() {
        if(is_null(self::$instance)) {
            self::$instance = new Debugger();
        }
        return self::$instance;
    }

    public function dump(mixed ...$vars): void {
        $this->processedObjects = [];
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)[1];
        $output = "<div class=\"dbg-window\">";
        $variables = $vars[0];
        $varCount = count($variables);

        $output .= "<span class=\"called-by\">{$caller["file"]} at line {$caller["line"]}</span>";

        foreach($variables as $key => $var) {
            if($varCount === 1) {
                $output .= $this->formatValue($var);
            } else {
                $output .= "<table cellpadding=\"0\" cellspacing=\"2\">";
                $output .= "<tr><td>" . ++$key . "</td><td>" . $this->formatValue($var) . "</td></tr>";
                $output .= "</table>";
            }
        }

        $output .= "</div>";
        echo $output;
    }
    public function withMethods(): self {
        $this->withMethods = true;
        return $this;
    } 
    private function formatArray(array $var, int $level = 0): string {
        $randID = strtoupper(md5(rand(1, 20000000)));
        $output = "<span class=\"var-array\">";

        if(count($var) === 0) $output .= "array: []"; else {
            $arrowStr = "<span class=\"arrow array ";
            $arrowStr .= ($level < $this->autoOpenLevel) ? "" : "down";
            $arrowStr .= " btn-{$randID}\" onclick='toggle(\"{$randID}\")'>" . $this->drawArrow() . "</span>";

            $output .= "array: " . count($var) . " [" . $arrowStr;

            if($level < $this->autoOpenLevel) {
                $output .= "<span id=\"{$randID}\"><br />";
            } else {
                $output .= "<span id=\"{$randID}\" style=\"display: none;\"><br />";
            }
            foreach($var as $key => $value) {
                $output .= $this->getIndent($level + 1) . $this->formatString("[ {$key} ] => ", false) . $this->formatValue($value, $level + 1);
                if(array_key_last($var) !== $key) $output .= ",";
                $output .= "<br />";
            }
            $output .= $this->getIndent($level);
            $output .= "</span>";

            $output .= "]";
        }

        $output .= "</span>";
        return $output;
    }

    private function formatStdObject(object $var, int $level = 0, int $id = -1): string {
        $sum = count((array)$var);
        $randID = strtoupper(md5(rand(20000000, 40000000)));
        $output = "<span class=\"var-object\">stdClass ";
        if($sum > 0) $output .= "({$sum}) ";
        $output .= "{ (#{$id})";
        if($sum > 0) {
            $arrowStr = "<span class=\"arrow object ";
            $arrowStr .= ($level < $this->autoOpenLevel) ? "" : "down";
            $arrowStr .= " btn-{$randID}\" onclick='toggle(\"{$randID}\")'>" . $this->drawArrow() . "</span>";
            $output .= $arrowStr;

            if($level < $this->autoOpenLevel) {
                $output .= "<span id=\"{$randID}\"><br />";
            } else {
                $output .= "<span id=\"{$randID}\" style=\"display: none;\"><br />";
            }

            foreach($var as $key => $value) {
                $output .= $this->getIndent($level + 1) . $this->formatString("{$key}: ") . $this->formatValue($value, $level + 1);
                $output .= "<br />";
            }

            $output .= $this->getIndent($level) . "</span>";
        }

        $output .= "}</span>";
        return $output;
    }
    private function formatObject(object $var, int $level = 0, bool $noCyclicCheck = true): string {
        $id = $this->getObjectId($var);
        if($var instanceof \stdClass) return $this->formatStdObject($var, $level, $id);

        if($this->cyclicCheck === true || $noCyclicCheck === false) {
            if (in_array($var, $this->processedObjects)) {
                return "Object(" . get_class($var) . ") {#{$id}} (cyclic)";
            }
        }

        $this->processedObjects[] = $var;
        $class = new ReflectionClass($var);

        $randID = strtoupper(md5(rand(40000000, 80000000)));
/*
        if(count((array) $var) === 0) {
            return "<span class=\"var-object\">(empty) Object {$class->getName()} {(#{$id})}</span>";
        }
*/
        $arrowStr = "<span class=\"arrow object ";
        $arrowStr .= ($level < $this->autoOpenLevel) ? "" : "down";
        $arrowStr .= " btn-{$randID}\" onclick='toggle(\"{$randID}\")'>" . $this->drawArrow() . "</span>";

        $output = "<span class=\"var-object\">" . $class->getName() . " {(#{$id}){$arrowStr}";

        if($level < $this->autoOpenLevel) {
            $output .= "<span id=\"{$randID}\">";
        } else {
            $output .= "<span id=\"{$randID}\" style=\"display: none;\">";
        }

        $output .= "<br />";

        $output .= $this->getPropertyList($var, ++$level);

        if($this->withMethods) {
            $output .= $this->getMethodList($var, $level);
        }

        $output .= $this->getIndent(--$level) . "</span>";

        $output .= "}</span>";

        return $output;
    }
    private function formatClosure(Closure $variable, int $level = 0) {
        $id = $this->getObjectId($variable);
        $ref = new ReflectionFunction($variable);

        $randID = strtoupper(md5(rand(10000000, 20000000)));
        $closureStr = "<span class=\"var-closure\">Closure {#{$id}}: {";

        $arrowStr = "<span class=\"arrow closure ";
        $arrowStr .= ($level < $this->autoOpenLevel) ? "" : "down";
        $arrowStr .= " btn-{$randID}\" onclick='toggle(\"{$randID}\")'>" . $this->drawArrow() . "</span>";

        $closureStr .= $arrowStr;

        if($level <= $this->autoOpenLevel) {
            $closureStr .= "<span id=\"$randID\"><br />";
        } else {
            $closureStr .= "<span id=\"$randID\" style=\"display: none;\"><br />";
        }

        $that = $ref->getClosureThis();
        $scope = $ref->getClosureScopeClass();
        $useVars = $ref->getStaticVariables();

        if(!is_null($that)) {
            $closureStr .= "<span>";
            $closureStr .= $this->getIndent(++$level) . "class: ";
            $closureStr .= $this->formatString($scope->getName(), false);
            $closureStr .= "</span><br />";
            $closureStr .= "<span>";
            $closureStr .= $this->getIndent($level) . "this: ";
            $closureStr .= $this->formatObject($that, $level, false);
            $closureStr .= "</span><br />";

            if(count($useVars)) {
                $closureStr .= "<span>";
                $closureStr .= $this->getIndent($level++) . "use: {<br />";

                foreach($useVars as $name => $value) {
                    $closureStr .= $this->getIndent($level) . "\${$name} = " . $this->formatValue($value, $level) . "<br />";
                }

                $closureStr .= $this->getIndent(--$level) . "}";
                $closureStr .= "</span><br />";
            }
        }

        $closureStr .= $this->getIndent(--$level) . "</span>";
        $closureStr .= "}</span>";
        return $closureStr;
    }
    private function formatBoolean(bool $var): string {
        $output = "<span class=\"var-bool\">";
        $output .= ($var === true) ? "true" : "false";
        $output .= "</span>";

        return $output;
    }

    private function formatInteger(int|float $var): string {
        return "<span class=\"var-int\">{$var}</span>";
    }

    private function formatString(string $var, bool $withQuotes = false): string {
        $output = "<span class=\"var-string\">";
        if($withQuotes === true) {
            $output .= "\"";
        }

        $output .= $var;
        if($withQuotes === true) {
            $output .= "\"";
        }
        $output .= "</span>";

        return $output;
    }

    private function formatNull(): string {
        return "<span class=\"var-null\">null</span>";    
    }
    private function formatParameter(ReflectionParameter $property, object $object, int $level = 0): string {
        if(method_exists($property, "isInitialized")) {
            if(!$property->isInitialized($object)) {
                return $this->getIndent($level) . "<span class=\"var-visibility\">{$this->getVisibility($property)} </span><span class=\"var-property-name\">\${$property->getName()} . </span> = <span class=\"var-uninitialized\">uninitialized</span><br />";
            }
        }
        
        $type = $property->getType();
        
        try {
            $actualValue = $property->getDefaultValue();
        } catch(ReflectionException $e) {
            $actualValue = null;
        }

        $output = "";

        if(!is_null($type)) {
            if(!is_null($this->getPropertyType($property))) {
                $output .= "<span class=\"var-property\">{$property->getType()}</span>" . chr(32);
            }
        };

        $output .= "<span class=\"var-property-name\">\${$property->getName()}</span>";
        if(!is_null($actualValue)) {
            $output .= "<span class=\"var-property\"> = </span>";
            $output .= "<span class=\"var-property\">";
            $output .= $this->formatValue($actualValue, $level++);
            $output .= "</span>";            
        }

        return $output;
    }
    private function formatProperty(ReflectionProperty $property, object $object, int $level = 0): string {
        if(method_exists($property, "isInitialized")) {
            if(!$property->isInitialized($object)) {
                return $this->getIndent($level) . "<span class=\"var-visibility\">{$this->getVisibility($property)} </span><span class=\"var-property-name\">\${$property->getName()} . </span> = <span class=\"var-uninitialized\">uninitialized</span><br />";
            }
        }

        $type = $property->getType();
        try {
            $actualValue = $property->getValue($object);
        } catch (ReflectionException $e) {
            $actualValue = $this->getDefValue($property);
        }

        $output = "";

        $output .= $this->getIndent($level) . "<span class=\"var-visibility\">{$this->getVisibility($property)} </span>";

        if(!is_null($type)) {
            if(!is_null($this->getPropertyType($property))) {
                $output .= "<span class=\"var-property\">{$property->getType()} </span>";
            }
        };

        $output .= "<span class=\"var-property-name\">\${$property->getName()}</span>";
        $output .= "<span class=\"var-property\"> = </span>";
        $output .= "<span class=\"var-property\">";
        $output .= $this->formatValue($actualValue, $level++);
        $output .= "</span>";

        $output .= "<br />";

        return $output;
    }
    private function formatMethod(ReflectionMethod $method, object $object, int $level = 0): string {
        $visibility = $this->getIndent($level) . "<span class=\"var-visibility\">{$this->getVisibility($method)}</span>";

        $output = $visibility . chr(32) . "function" . chr(32) . $method->getName() . "(";
        
        $params = $method->getParameters();
        foreach($params as $key => $param) {
            $output .= $this->formatParameter($param, $object, $level);
            if(array_key_last($params) !== $key) $output .= "," . chr(32);
        }

        $output .= ")";
        $output .= "<br />";
        return $output;
    }
    private function formatValue(mixed $var, int $level = 0): mixed {
        return match(true) {
            $var instanceof Closure => $this->formatClosure($var, $level),
            is_array($var) => $this->formatArray($var, $level),
            is_object($var) => $this->formatObject($var, $level),
            default => $this->formatScalar($var)
        };

//        return $var instanceof \Closure;
    }
    private function formatScalar(mixed $var, $withQuotes = true) {
        return match(true) {
            is_bool($var) => $this->formatBoolean($var),
            is_numeric($var) => $this->formatInteger($var),
            is_string($var) => $this->formatString($var, $withQuotes),
            is_null($var) => $this->formatNull(),
            default => "<pre>" . print_r(gettype($var), true) . "</pre>"
        };
    }
    private function getIndent(int $level): string {
        if($level < 0) $level = 0;
        return str_repeat("&nbsp;&nbsp;&nbsp;", $level);
    }
    private function drawArrow() {
        return '<svg xmlns="http://www.w3.org/2000/svg" shape-rendering="geometricPrecision" text-rendering="geometricPrecision" image-rendering="optimizeQuality" fill-rule="evenodd" clip-rule="evenodd" viewBox="0 0 463.96 512"><path fill-rule="nonzero" d="M332.67 512V268.5h92.3c15.48-.68 26.47-5.77 32.82-15.42 17.21-25.8-5.25-52.31-22.6-69.25L261.61 14.33c-17.29-19.11-41.93-19.11-59.22 0L24.42 188.72C8.03 204.78-9.67 229.27 6.21 253.08c6.35 9.65 17.34 14.74 32.81 15.42h92.31V512h201.34z"/></svg>';
    }
    private function getObjectId(object $obj): int {
        $oid = spl_object_id($obj);

        if (!isset($this->seenObjects[$oid])) {
            $this->seenObjects[$oid] = ++$this->objectCounter;
        }

        return $this->seenObjects[$oid];
    }
    private function getPropertyList(object $obj, int $level = 0): string {
        $output = "";

        $class = new ReflectionClass($obj);
        $props = $class->getProperties();

        foreach($props as $property) {
            $output .= $this->formatProperty($property, $obj, $level);
        }

        if(!empty($output) && $this->withMethods) {
            $output .= "<br />";            
        }

        return $output;
    }

    private function getMethodList(object $obj, int $level = 0): string {
        $output = "";

        $class = new ReflectionClass($obj);
        $methods = $class->getMethods();        

        foreach($methods as $method) {
            $output .= $this->formatMethod($method, $obj, $level);
        }

        return $output;
    }

    private function getDefValue(ReflectionProperty $param) {
        return ($param->hasDefaultValue() === true) ? $param->getDefaultValue() : "<i>DEFAULT</i>";
    }

    private function getVisibility(ReflectionProperty|ReflectionParameter|ReflectionMethod $item): string {
        $output = "";
        if($item->isFinal()) $output .= "final ";

        if ($item->isPublic()) {
            $output .= "public";
        } elseif ($item->isProtected()) {
            $output .= "protected";
        } else {
            $output .= "private";
        }

        return $output;
    }
    private function getPropertyType(ReflectionProperty|ReflectionParameter $property) {
        $type = $property->getType();
        if ($type instanceof ReflectionNamedType) {
            return ($type->allowsNull() ? '?' : '') . $type->getName() . chr(32);
        } elseif ($type instanceof ReflectionUnionType) {
            $types = array_map(fn($t) => ($t->allowsNull() ? '?' : '') . $t->getName(), $type->getTypes());
            return implode(' | ', $types) . chr(32);
        }

        return null;
    }    
}