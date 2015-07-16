<?php
namespace Zephir\Backends\ZendEngine3;

use Zephir\Variable;
use Zephir\CompiledExpression;
use Zephir\Compiler;
use Zephir\CompilerException;
use Zephir\CompilationContext;
use Zephir\ClassMethod;
use Zephir\FunctionDefinition;
use Zephir\Backends\ZendEngine2\Backend as BackendZendEngine2;
use Zephir\BaseBackend;
use Zephir\GlobalConstant;
use Zephir\Utils;

class Backend extends BackendZendEngine2
{
    protected $name = 'ZendEngine3';

    public function getVariableCode(Variable $variable)
    {
        if (in_array($variable->getName(), array('this_ptr', 'return_value')) || in_array($variable->getType(), array('zval_ptr', 'int'))) {
            return $variable->getName();
        }
        return '&' . $variable->getName();
    }

    public function getVariableCodePointer(Variable $variable)
    {
        return $this->getVariableCode($variable);
    }

    public function getBoolCode(Variable $variable, CompilationContext $context, $useCodePrinter = true)
    {
        $code = '(Z_TYPE_P(' . $this->getVariableCode($variable) . ') == IS_TRUE)';
        if ($useCodePrinter) {
            $compilationContext->codePrinter->output($code);
        }
        return $code;
    }

    public function getStringsManager()
    {
        return new StringsManager();
    }

    public function getTypeDefinition($type)
    {
        switch ($type) {
            case 'zval_ptr':
                return array('*', 'zval');
            case 'zend_ulong':
                return array('', 'zend_ulong');
            case 'zend_string':
                return array('*', 'zend_string');
        }
        list ($pointer, $code) = parent::getTypeDefinition($type);
        if ($code == 'zval') {
            return array('', 'zval');
        }
        return array($pointer, $code);
    }

    public function onPreInitVar($method, CompilationContext $context)
    {
        if ($method instanceof FunctionDefinition) {
            return;
        }
        if (!$method->isInternal()) {
            return "\t" . 'zval *this_ptr = getThis();' . PHP_EOL; //TODO: think about a better way to solve this.
        }
    }

    public function onPreCompile($method, CompilationContext $context)
    {
        $codePrinter = $context->codePrinter;
        /**
         * Initialize the properties within create_object, handler code
         */
        if (preg_match('/^zephir_init_properties/', $method->getName())) {
            $codePrinter->increaseLevel();
            $codePrinter->output('{');
            $codePrinter->increaseLevel();
            $codePrinter->output('zval local_this_ptr, *this_ptr = &local_this_ptr;');
            $codePrinter->output('ZEPHIR_CREATE_OBJECT(this_ptr, class_type);');
            $codePrinter->decreaseLevel();
        }
    }

    public function onPostCompile($method, CompilationContext $context)
    {
        $codePrinter = $context->codePrinter;
        if (preg_match('/^zephir_init_properties/', $method->getName())) {
            $codePrinter->increaseLevel();
            $codePrinter->output('return Z_OBJ_P(this_ptr);');
            $codePrinter->decreaseLevel();
            $codePrinter->output('}');
            $codePrinter->decreaseLevel();
        }
    }

    public function generateInitCode(&$groupVariables, $type, $pointer, Variable $variable)
    {

        $isComplex = ($type == 'variable' || $type == 'string' || $type == 'array' || $type == 'resource' || $type == 'callable' || $type == 'object');
        if ($isComplex) { /* && $variable->mustInitNull() */
            $groupVariables[] = $variable->getName();
            return "\t".'ZVAL_UNDEF(&' . $variable->getName() . ');';
        }

        if ($variable->isLocalOnly()) {
            $groupVariables[] = $variable->getName();
            return;
        }

        if ($variable->isDoublePointer()) {
            if ($variable->mustInitNull()) {
                $groupVariables[] = $pointer . $pointer . $variable->getName() . ' = NULL';
            } else {
                $groupVariables[] = $pointer . $pointer . $variable->getName();
            }
            return;
        }

        $defaultValue = $variable->getDefaultInitValue();
        if ($defaultValue !== null) {
            switch ($type) {
                case 'variable':
                case 'string':
                case 'array':
                case 'resource':
                case 'callable':
                case 'object':
                    $groupVariables[] = $pointer . $variable->getName();
                    break;

                case 'char':
                    if (strlen($defaultValue) > 4) {
                        if (strlen($defaultValue) > 10) {
                            throw new CompilerException("Invalid char literal: '" . substr($defaultValue, 0, 10) . "...'", $variable->getOriginal());
                        } else {
                            throw new CompilerException("Invalid char literal: '" . $defaultValue . "'", $variable->getOriginal());
                        }
                    }
                    /* no break */

                default:
                    $groupVariables[] = $pointer . $variable->getName() . ' = ' . $defaultValue;
                    break;
            }

            return;
        }

        if ($variable->mustInitNull() && $pointer) {
            $groupVariables[] = $pointer . $variable->getName() . ' = NULL';
            return;
        }

        $groupVariables[] = $pointer . $variable->getName();
    }

    public function declareConstant($type, $name, $value, CompilationContext $context)
    {
        $ce = $context->classDefinition->getClassEntry($context);
        $dType = null;
        switch ($type) {
            case 'bool':
                $value = $value == 'false' ? '0' : 1;
                break;
            case 'long':
            case 'int':
                $dType = 'long';
                break;
            case 'double':
                break;
            case 'string':
            case 'char':
                if ($type == 'string' || $type == 'char') {
                    $value = "\"" . Utils::addSlashes($value) . "\"";
                }
                $dType = 'string';
                break;
        }
        if (!isset($dType)) {
            $dType = $type;
        }

        if ($dType == 'null') {
            $context->codePrinter->output('zephir_declare_class_constant_null(' . $ce . ', SL("' . $name . '"));');
        } else {
            $context->codePrinter->output('zephir_declare_class_constant_' . $dType . '(' . $ce . ', SL("' . $name . '"), ' . $value . ');');
        }
    }

    /**
     * Returns the signature of an internal method
     */
    public function getInternalSignature(ClassMethod $method, CompilationContext $context)
    {
        if ($method->isInitializer() && !$method->isStatic()) {
            return 'static zend_object *' . $method->getName() . '(zend_class_entry *class_type TSRMLS_DC)';
        }

        if ($method->isInitializer() && $method->isStatic()) {
            return 'void ' . $method->getName() . '(TSRMLS_D)';
        }

        $signatureParameters = array();
        $parameters = $method->getParameters();
        if (is_object($parameters)) {
            foreach ($parameters->getParameters() as $parameter) {
                switch ($parameter['data-type']) {
                    case 'int':
                    case 'uint':
                    case 'long':
                    case 'double':
                    case 'bool':
                    case 'char':
                    case 'uchar':
                        $signatureParameters[] = 'zval *' . $parameter['name'] . '_param_ext';
                        break;

                    default:
                        $signatureParameters[] = 'zval *' . $parameter['name'] . '_ext';
                        break;
                }
            }
        }

        if (count($signatureParameters)) {
            return 'static void ' . $method->getInternalName() . '(int ht, zval *return_value, zval **return_value_ptr, zval *this_ptr, int return_value_used, ' . join(', ', $signatureParameters) . ' TSRMLS_DC)';
        }

        return 'static void ' . $method->getInternalName() . '(int ht, zval *return_value, zval **return_value_ptr, zval *this_ptr, int return_value_used TSRMLS_DC)';
    }

    /* Assign value to variable */
    public function assignString(Variable $variable, $value, CompilationContext $context, $useCodePrinter = true, $doCopy = null)
    {
        return $this->assignHelper('ZVAL_STRING', '&' . $variable->getName(), $value, $context, $useCodePrinter, null);
    }

    public function assignZval(Variable $variable, $code, CompilationContext $context)
    {
        $code = $this->resolveValue($code, $context);
        $context->codePrinter->output('ZVAL_COPY_VALUE(' . $this->getVariableCode($variable) . ', ' . $code . ');');
    }

    public function returnString($value, CompilationContext $context, $useCodePrinter = true)
    {
        return $this->returnHelper('RETURN_MM_STRING', $value, $context, $useCodePrinter, null);
    }

    public function createClosure(Variable $variable, $classDefinition, CompilationContext $context)
    {
        $symbol = $this->getVariableCode($variable);
        $context->codePrinter->output('zephir_create_closure_ex(' . $symbol . ', NULL, ' . $classDefinition->getClassEntry() . ', SL("__invoke"));');
    }

    public function addArrayEntry(Variable $variable, $key, $value, CompilationContext $context, $statement = null, $useCodePrinter = true)
    {
        $type = null;
        $keyType = 'assoc';

        if (!isset($key)) {
            $keyType = 'append';
        } else if ($key instanceof CompiledExpression && in_array($key->getType(), array('int', 'uint', 'long', 'ulong'))) {
            $keyType = 'index';
        }
        if ($value == 'null') {
            if (!isset($key)) {
                $value = $this->resolveValue('null', $context);
                $context->codePrinter->output('zephir_array_append(' . $this->getVariableCode($variable) . ', '. $value . ', PH_SEPARATE, "' . Compiler::getShortUserPath($statement['file']) . '", ' . $statement['line'] . ');');
                return;
            }
        }
        switch ($value->getType()) {
            case 'int':
            case 'uint':
            case 'long':
            case 'ulong':
                $type = 'long';
                break;
            case 'string':
                $type = 'stringl';
                break;
            case 'variable':
            case 'array':
                $type = 'zval';
                break;
        }
        if (!$type) {
            throw new CompilerException("Unknown type mapping: " . $value->getType());
        }

        if (isset($key)) {
            $keyStr = $key->getType() == 'string' ? 'SL("' . $key->getCode() . '")' : $key->getCode();
        }
        if ($type == 'stringl') {
            if ($value instanceof Variable) {
                $valueStr = 'Z_STRVAL_P(' . $this->getVariableCode($value) . '), Z_STRLEN_P(' . $this->getVariableCode($value) . ')';
            } else {
                $valueStr = 'SL("' . $value->getCode()  . '")';
            }
        } else if ($type == 'zval') {
            $valueStr = $this->getVariableCode($value);
        } else {
            $valueStr = $value->getCode();
        }

        if ($keyType == 'assoc') {
            $output = 'add_assoc_' . $type . '_ex(' . $this->getVariableCode($variable) . ', ' . $keyStr . ', ' . $valueStr . ');';
        } else if ($keyType == 'append') {
            $output = 'zephir_array_append(' . $this->getVariableCode($variable) . ', ' . $this->resolveValue($value, $context) . ', PH_SEPARATE, "' . Compiler::getShortUserPath($statement['file']) . '", ' . $statement['line'] . ');';
        } else {
            $output = 'add_index_' . $type . '(' . $this->getVariableCode($variable) . ', ' . $keyStr . ', '. $valueStr . ');';
        }

        if ($useCodePrinter) {
            $context->codePrinter->output($output);
        }
        return $output;
    }

    public function arrayIsset(Variable $var, $resolvedExpr, $expression, CompilationContext $context)
    {
        if (!($resolvedExpr instanceof Variable)) {
            if ($resolvedExpr->getType() == 'string') {
                return new CompiledExpression('bool', 'zephir_array_isset_string(' . $this->getVariableCode($var) . ', SL("' . $resolvedExpr->getCode() . '"))', $expression);
            }
        }
        return parent::arrayIsset($var, $resolvedExpr, $expression, $context);
    }

    public function propertyIsset(Variable $var, $key, CompilationContext $context)
    {
        return new CompiledExpression('bool', 'zephir_isset_property(' . $this->getVariableCode($var) . ', SL("' . $key . '"))', null);
    }

    public function arrayUnset(Variable $variable, $exprIndex, $flags, CompilationContext $context)
    {
        $context->headersManager->add('kernel/array');
        if ($exprIndex->getType()) {
            $context->codePrinter->output('zephir_array_unset_string(&' . $variable->getName() . ', SL("' . $exprIndex->getCode() . '"), ' . $flags . ');');
            return;
        }
        return parent::arrayUnset($variable, $exprIndex, $flags, $context);
    }

    public function fetchGlobal(Variable $globalVar, CompilationContext $compilationContext, $useCodePrinter = true)
    {
        $name = $globalVar->getName();
        $output = 'zephir_get_global(&' . $name . ', SL("' . $name . '"));';
        if ($useCodePrinter) {
            $codePrinter->output($output);
        }
        return $output;
    }

    public function fetchClass(Variable $zendClassEntry, $className, $guarded, CompilationContext $context)
    {
        $context->headersManager->add('kernel/object');
        if ($guarded) {
            $context->codePrinter->output('if (!' . $zendClassEntry->getName() . ') {');
        }
        $context->codePrinter->output($zendClassEntry->getName() . ' = zephir_fetch_class_str_ex(' . $className . ', ZEND_FETCH_CLASS_AUTO);');
        if ($guarded) {
            $context->codePrinter->output('}');
        }
    }

    public function fetchProperty(Variable $symbolVariable, Variable $variableVariable, $property, $readOnly, CompilationContext $context, $useOptimized = false)
    {
        $flags = 'PH_NOISY_CC';
        if ($readOnly) {
            $flags .= ' | PH_READONLY';
        }
        $variableCode = $context->backend->getVariableCode($variableVariable);
        //TODO: maybe optimizations (read_nproperty/quick) for thisScope access in NG (as in ZE2 - if necessary)
        if ($property instanceof Variable) {
            $context->codePrinter->output('zephir_read_property_zval(&' . $symbolVariable->getName() . ', ' . $variableCode . ', ' . $this->getVariableCode($property) . ', ' . $flags . ');');
        } else {
            $context->codePrinter->output('zephir_read_property(&' . $symbolVariable->getName() . ', ' . $variableCode . ', SL("' . $property . '"), ' . $flags . ');');
        }
    }

    public function fetchStaticProperty(Variable $symbolVariable, $classDefinition, $property, $readOnly, CompilationContext $context)
    {
        $flags = 'PH_NOISY_CC';
        if ($readOnly) {
            $flags .= ' | PH_READONLY';
        }
        //TODO: maybe optimizations aswell as above
        $context->codePrinter->output('zephir_read_static_property_ce(&' . $symbolVariable->getName() . ', ' . $classDefinition->getClassEntry() . ', SL("' . $property . '"), ' . $flags . ');');
    }

    public function resolveValue($value, CompilationContext $context, $usePointer = false)
    {
        if ($value instanceof GlobalConstant) {
            switch ($value->getName()) {
                case 'ZEPHIR_GLOBAL(global_null)':
                    $value = 'null';
                    break;
                case 'ZEPHIR_GLOBAL(global_true)':
                    $value = 'true';
                    break;
                case 'ZEPHIR_GLOBAL(global_false)':
                    $value = 'false';
                    break;
                default:
                    throw new CompilerException('ZE3: Unknown constant '.$value->getName());
            }
        }

        if ($value == 'null') {
            $tempVariable = $context->symbolTable->getTempVariableForWrite('variable', $context);
            $this->assignNull($tempVariable, $context);
            $value = $this->getVariableCode($tempVariable);
        } else if ($value == 'true' || $value == 'false') {
            $tempVariable = $context->symbolTable->getTempVariableForWrite('variable', $context);
            $this->assignBool($tempVariable, $value == 'true' ? '1' : '0', $context);
            $value = $this->getVariableCode($tempVariable);
        } else if ($value instanceof CompiledExpression) {
            if ($value->getType() == 'array') {
                $value = $context->symbolTable->getVariableForWrite($value->getCode(), $context, null);
            } else if ($value->getType() == 'variable') {
                $value = $context->symbolTable->getVariableForWrite($value->getCode(), $context);
            } else {
                return $value->getCode();
            }
        }
        if ($value instanceof Variable) {
            $value = $this->getVariableCode($value);
        }
        return $value;
    }

    public function updateProperty(Variable $symbolVariable, $propertyName, $value, CompilationContext $context)
    {
        //TODO: maybe optimizations aswell as above
        $value = $this->resolveValue($value, $context);
        $context->codePrinter->output('zephir_update_property_zval(' . $this->getVariableCode($symbolVariable) . ', SL("' . $propertyName . '"), ' . $value . ');');
    }

    public function updateStaticProperty($classEntry, $property, $value, CompilationContext $context)
    {
        $value = $this->resolveValue($value, $context);
        $context->codePrinter->output('zephir_update_static_property_ce(' . $classEntry .', SL("' . $property . '"), ' . $value . ');');
    }

    public function callMethod($symbolVariable, Variable $variable, $methodName, $cachePointer, $params, CompilationContext $context)
    {
        $paramStr = $params != null ? ', ' . join(', ', $params) : '';
        if (!isset($symbolVariable)) {
            $context->codePrinter->output('ZEPHIR_CALL_METHOD(NULL, ' . $this->getVariableCode($variable) . ', "' . $methodName . '", ' . $cachePointer . $paramStr . ');');
        } else if ($symbolVariable->getName() == 'return_value') {
            $context->codePrinter->output('ZEPHIR_RETURN_CALL_METHOD(' . $this->getVariableCode($variable) . ', "' . $methodName . '", ' . $cachePointer . $paramStr . ');');
        } else {
            $context->codePrinter->output('ZEPHIR_CALL_METHOD(&' . $symbolVariable->getName() . ', ' . $this->getVariableCode($variable) . ', "' . $methodName . '", ' . $cachePointer . $paramStr . ');');
        }
    }

    public function setSymbolIfSeparated(Variable $variableTempSeparated, Variable $variable, CompilationContext $context)
    {
        $context->codePrinter->output('if (' . $variableTempSeparated->getName() . ') {');
        $context->codePrinter->output("\t" . 'ZEPHIR_SET_SYMBOL(&EG(symbol_table), "' . $variable->getName() . '", &' . $variable->getName() . ');');
        $context->codePrinter->output('}');
    }

    public function copyOnWrite(Variable $target, $var, CompilationContext $context)
    {
        if ($var == 'EG(exception)') {
            $context->codePrinter->output('ZVAL_OBJ(' . $this->getVariableCode($target) . ', EG(exception));');
            $context->codePrinter->output('Z_ADDREF_P(' . $this->getVariableCode($target) . ');');
            return;
        }
        return parent::copyOnWrite($target, $var, $context);
    }

    public function forStatement(Variable $exprVariable, $keyVariable, $variable, $duplicateKey, $duplicateHash, $statement, $statementBlock, CompilationContext $compilationContext)
    {
        /**
         * Create a hash table and hash pointer temporary variables
         */
        //$arrayPointer = $compilationContext->symbolTable->addTemp('HashPosition', $compilationContext);
        //$arrayHash = $compilationContext->symbolTable->addTemp('HashTable', $compilationContext);
        /**
         * Create a temporary zval to fetch the items from the hash
         */
        $tempVariable = $compilationContext->symbolTable->addTemp('zval_ptr', $compilationContext);
        $codePrinter = $compilationContext->codePrinter;

        $codePrinter->output('zephir_is_iterable(' . $this->getVariableCode($exprVariable) . ', ' . $duplicateHash . ', "' . Compiler::getShortUserPath($statement['file']) . '", ' . $statement['line'] . ');');

        $macro = null;
        $reverse = $statement['reverse'] ? 'REVERSE_' : '';

        if (isset($keyVariable)) {
            $arrayNumKey = $compilationContext->symbolTable->addTemp('zend_ulong', $compilationContext);
            $arrayStrKey = $compilationContext->symbolTable->addTemp('zend_string', $compilationContext);
        }
        $variable->observeVariant($compilationContext);

        if (isset($keyVariable) && isset($variable)) {
            $macro = 'ZEND_HASH_' . $reverse . 'FOREACH_KEY_VAL';
            $codePrinter->output($macro . '(Z_ARRVAL_P(' . $this->getVariableCode($exprVariable) . '), ' . $arrayNumKey->getName() . ', ' . $arrayStrKey->getName() . ', ' . $tempVariable->getName() . ')');
        } else if (isset($keyVariable)) {
            $macro = 'ZEND_HASH_' . $reverse . 'FOREACH_KEY';
            $codePrinter->output($macro . '(Z_ARRVAL_P(' . $this->getVariableCode($exprVariable) . '), ' . $arrayNumKey->getName() . ', ' . $arrayStrKey->getName() . ')');
        } else {
            $macro = 'ZEND_HASH_' . $reverse . 'FOREACH_VAL';
            $codePrinter->output($macro . '(Z_ARRVAL_P(' . $this->getVariableCode($exprVariable) . '), ' . $tempVariable->getName() . ')');
        }

        $codePrinter->output('{');

        if (isset($keyVariable)) {
            $codePrinter->increaseLevel();

            $codePrinter->output('if (' . $arrayStrKey->getName() . ' != NULL) { ');
            $codePrinter->increaseLevel();
            if ($duplicateKey) {
                $compilationContext->symbolTable->mustGrownStack(true);
                $codePrinter->output('ZVAL_NEW_STR(' . $this->getVariableCode($keyVariable) . ', ' . $arrayStrKey->getName() . ');');
                //TODO: If it causes bugs duplicate it
            } else {
                $codePrinter->output('ZVAL_STR(' . $this->getVariableCode($keyVariable) . ', ' . $arrayStrKey->getName() . ');');
            }
            $codePrinter->decreaseLevel();
            $codePrinter->output('} else {');
            $codePrinter->increaseLevel();
            $codePrinter->output('ZVAL_LONG(' . $this->getVariableCode($keyVariable) . ', ' . $arrayNumKey->getName() . ');');
            $codePrinter->decreaseLevel();
            $codePrinter->output('}');
            $codePrinter->decreaseLevel();
        }

        if (isset($variable)) {
            $compilationContext->symbolTable->mustGrownStack(true);
            $codePrinter->increaseLevel();
            $variable->initVariant($compilationContext);
            $codePrinter->output('ZVAL_COPY(' . $this->getVariableCode($variable) . ', ' . $this->getVariableCode($tempVariable) . ');');
            $codePrinter->decreaseLevel();
        }

        /**
         * Compile statements in the 'for' block
         */
        if (isset($statement['statements'])) {
            $statementBlock->isLoop(true);
            if (isset($statement['key'])) {
                $statementBlock->getMutateGatherer()->increaseMutations($statement['key']);
            }
            $statementBlock->getMutateGatherer()->increaseMutations($statement['value']);
            $statementBlock->compile($compilationContext);
        }

        $codePrinter->output('} ZEND_HASH_FOREACH_END();');
    }

    public function ifVariableValueUndefined(Variable $var, CompilationContext $context, $useCodePrinter = true)
    {
        $output = 'if (Z_TYPE_P(' . $this->getVariableCode($var) . ') == IS_UNDEF) {';
        if ($useCodePrinter) {
            $context->codePrinter->output($output);
        }
        return $output;
    }

    public function ifVariableIsNotBool(Variable $var, CompilationContext $context, $useCodePrinter = true)
    {
        $varCode = $this->getVariableCode($var);
        $output = "if (unlikely(Z_TYPE_P(" . $varCode . ') != IS_TRUE && Z_TYPE_P(' . $varCode . ') != IS_FALSE)) {';
        if ($useCodePrinter) {
            $context->codePrinter->output($output);
        }
        return $output;
    }

    public function fetchClassEntry($str)
    {
        return 'zephir_get_internal_ce(SL("' . $str . '"))';
    }
}
