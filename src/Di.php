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
    private $instances = [];

    /**
     * @param $name
     * @param array $rule
     */
    public function register($name, array $rule) {
        $this->rules[ltrim(strtolower($name), '\\')] = array_merge($this->getRule($name), $rule);
    }

    /**
     * @param array $rules
     */
    public function registerCollection(array $rules){
        $this->alias = $rules;
        foreach($rules as $alias => $rule){
            $this->register($rule['use'],$rule['rule']);
        }
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
            foreach ($rule['call'] as $call) call_user_func_array([$object, $call[0]] , $this->getParams($class->getMethod($call[0]), $rule)->__invoke($this->expand($call[1])));
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
            if (isset($rule['shareInstances'])) $share = array_merge($share, array_map([$this, 'create'], $rule['shareInstances']));
            if ($share || isset($rule['constructParams'])) $args = array_merge($args, isset($rule['constructParams']) ? $this->expand($rule['constructParams'], $share) : [], $share);
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
