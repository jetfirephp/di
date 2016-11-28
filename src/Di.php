<?php

namespace JetFire\Di;

/**
 * Class Di
 * @package JetFire\Di
 */
class Di {
    /**
     * @var array
     */
    private $rules = [];
    /**
     * @var array
     */
    private $alias = [];
    /**
     * @var array
     */
    private $cache = [];
    /**
     * @var array
     */
    protected $instances = [];

    /**
     * @param $name
     * @param array $rule
     */
    public function addRule($name, array $rule) {
        $this->rules[ltrim(strtolower($name), '\\')] = array_merge($this->getRule($name), $rule);
    }

    /**
     * @param $name
     * @param $instance
     * @internal param array $rule
     */
    public function addInstance($name, $instance) {
        $this->instances[$name] = $instance;
    }

    /**
     * @param $name
     */
    public function removeRule($name) {
        unset($this->rules[ltrim(strtolower($name), '\\')]);
    }

    /**
     * @param array $rules
     * @param array $params
     */
    public function addRules(array $rules,array $params = []){
        $this->alias = $rules;
        foreach($rules as $alias => $rule){
            if(isset($rule['rule']['construct']) && is_array($rule['rule']['construct']))
                foreach($rule['rule']['construct'] as $key1 => $value1)
                    if (!is_null($result1 = $this->getValue($key1,$value1, $params)))
                        $rule['rule']['construct'][$key1] = $result1;
            if(isset($rule['rule']['call'])) {
                foreach ($rule['rule']['call'] as $method => $functions) {
                    foreach ($functions as $key2 => $value2) {
                        if (!is_null($result2 = $this->getValue($key2, $value2, $params)))
                            $rule['rule']['call'][$method][$key2] = $result2;
                    }
                }
            }
            $this->addRule($rule['use'],$rule['rule']);
        }
    }

    /**
     * @param $key
     * @param $class
     */
    public function register($key,$class = null){
        if(is_null($class)){
            $class = $key;
            $key = get_class($class);
        }
        if(!isset($this->instances[$key]))
            $this->instances[$key] = $class;
    }

    /**
     * @param $key
     * @param $value
     * @param array $params
     * @return null
     */
    private function getValue($key,$value,array $params = []){
        $result = null;
        if(is_numeric($key) && substr($value,0,1) === "#") {
            $parsed = explode('.', str_replace('#','',$value));
            $result = $params;
            while ($parsed) {
                $next = array_shift($parsed);
                if (isset($result[$next]))
                    $result = $result[$next];
            }
        }
        return $result;
    }

    /**
     * @param $alias
     * @param $class
     */
    public function addAlias($alias,$class){
        $this->alias[$alias]['use'] = $class;
    }

    /**
     * @param $name
     * @return array
     */
    public function getRule($name) {
        $lcName = strtolower(ltrim($name, '\\'));
        if (isset($this->rules[$lcName])) return $this->rules[$lcName];

        foreach ($this->rules as $key => $rule) {
            if (empty($rule['instanceOf']) && $key !== '*' && is_subclass_of($name, $key) && (!array_key_exists('inherit', $rule) || $rule['inherit'] === true )) return $rule;
        }
        return isset($this->rules['*']) ? $this->rules['*'] : [];
    }

    /**
     * @param $name
     * @param array $args
     * @param array $share
     * @return mixed
     */
    public function get($name, array $args = [], array $share = []) {
        if (isset($this->alias[$name])) $name = $this->alias[$name]['use'];
        if (!empty($this->instances[$name])) return $this->instances[$name];
        if (empty($this->cache[$name])) $this->cache[$name] = $this->getClosure($name, $this->getRule($name));
        return $this->cache[$name]($args, $share);
    }

    /**
     * @param $name
     * @param array $rule
     * @return callable
     */
    private function getClosure($name, array $rule) {
        $class = new \ReflectionClass(isset($rule['instanceOf']) ? $rule['instanceOf'] : $name);
        $constructor = $class->getConstructor();
        $params = $constructor ? $this->getParams($constructor, $rule) : null;

        if (isset($rule['shared']) && $rule['shared'] === true ) $closure = function (array $args, array $share) use ($class, $name, $constructor, $params) {
            try {
                $this->instances[$name] = $this->instances[ltrim($name, '\\')] = $class->newInstanceWithoutConstructor();
            }
            catch (\ReflectionException $e) {
                $this->instances[$name] = $this->instances[ltrim($name, '\\')] = $class->newInstanceArgs($params($args, $share));
            }

            if ($constructor) $constructor->invokeArgs($this->instances[$name], $params($args, $share));
            return $this->instances[$name];
        };
        else if ($params) $closure = function (array $args, array $share) use ($class, $params) { return $class->newInstanceArgs($params($args, $share)); };

        else $closure = function () use ($class) { return new $class->name;	};

        return isset($rule['call']) ? function (array $args, array $share) use ($closure, $class, $rule) {
            $object = $closure($args, $share);
            foreach ($rule['call'] as $method => $args) call_user_func_array([$object, $method] , $this->getParams($class->getMethod($method), $rule)->__invoke($this->expand($args)));
            return $object;
        } : $closure;
    }


    /**
     * @param $param
     * @param array $share
     * @param bool $createFromString
     * @return array|mixed|string
     */
    private function expand($param, array $share = [], $createFromString = false) {
        if (is_array($param) && isset($param['instance'])) {
            if (is_callable($param['instance'])) return call_user_func_array($param['instance'], (isset($param['params']) ? $this->expand($param['params']) : []));
            else return $this->get($param['instance'], $share);
        }
        else if (is_array($param)) foreach ($param as &$value) $value = $this->expand($value, $share);
        return is_string($param) && $createFromString ? $this->get($param) : $param;
    }

    /**
     * @param \ReflectionMethod $method
     * @param array $rule
     * @return callable
     */
    private function getParams(\ReflectionMethod $method, array $rule) {
        $paramInfo = [];
        foreach ($method->getParameters() as $param) {
            $class = $param->getClass() ? $param->getClass()->name : null;
            $paramInfo[] = [$class, $param, isset($rule['substitutions']) && array_key_exists($class, $rule['substitutions'])];
        }
        return function (array $args, array $share = []) use ($paramInfo, $rule) {
            if (isset($rule['shareInstances'])) $share = array_merge($share, array_map([$this, 'get'], $rule['shareInstances']));
            if ($share || isset($rule['construct'])) $args = array_merge($args, isset($rule['construct']) ? $this->expand($rule['construct'], $share) : [], $share);
            $parameters = [];

            foreach ($paramInfo as $p) {
                list($class, $param, $sub) = $p;
                if ($args) foreach ($args as $i => $arg) {
                    if ($class && ($arg instanceof $class || ($arg === null && $param->allowsNull()))) {
                        $parameters[] = array_splice($args, $i, 1)[0];
                        continue 2;
                    }
                }
                if ($class) $parameters[] = $sub ? $this->expand($rule['substitutions'][$class], $share, true) : $this->get($class, [], $share);
                else if ($args) $parameters[] = $this->expand(array_shift($args));
                else $parameters[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
            }
            return $parameters;
        };
    }
}
