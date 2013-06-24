<?php

/**
    Cortex - a general purpose mapper for the PHP Fat-Free Framework

    The contents of this file are subject to the terms of the GNU General
    Public License Version 3.0. You may not use this file except in
    compliance with the license. Any of the license terms and conditions
    can be waived if you get permission from the copyright holder.

    crafted by   __ __     __
                |__|  |--.|  |--.-----.-----.
                |  |    < |    <|  -__|-- __|
                |__|__|__||__|__|_____|_____|

    Copyright (c) 2013 by ikkez
    Christian Knuth <ikkez0n3@gmail.com>
    https://github.com/ikkez/F3-Sugar/

        @package DB
        @version 0.8.2
        @date 17.01.2013
 **/

namespace DB;

class Cortex extends \DB\Cursor {

    protected
        $mapper,    // ORM object
        $db,        // DB object
        $table,     // selected table
        $dbsType;   // mapper engine type [Jig, SQL, Mongo]

    protected static
        $fieldConf; // field configuration

    const
        // special datatypes
        DT_TEXT_SERIALIZED = 1,
        DT_TEXT_JSON = 2,

        // error messages
        E_ARRAYDATATYPE = 'Unable to save an Array in field %s. Use DT_SERIALIZED or DT_JSON.';

    /**
     * init the ORM, based on given DBS
     * @param      $table
     * @param null $db
     */
    public function __construct($db, $table)
    {
        $this->db = $db;
        $this->table = strtolower($table);
        $this->dbsType = get_class($db);
        switch ($this->dbsType) {
            case 'DB\Jig':
                $this->mapper = new \DB\Jig\Mapper($this->db, $this->table);
                break;
            case 'DB\SQL':
                $this->mapper = new \DB\SQL\Mapper($this->db, $this->table);
                break;
            case 'DB\Mongo':
                $this->mapper = new \DB\Mongo\Mapper($this->db, $this->table);
                break;
            default:
                trigger_error('Unknown DB system not supported: '.$this->dbsType);
        }
        $this->reset();
        if(!empty(static::$fieldConf))
            foreach(static::$fieldConf as $key=>&$conf)
                $conf=self::resolveRelations($conf);
    }

    /**
     * set model definition
     *
     * field example:
     *  array('title' => array(
     *          'type' => \DT::TEXT16,
     *          'default' => 'new record title'
     *  ))
     *
     * @param array $config
     */
    function setFieldConfiguration(array $config) {
        static::$fieldConf = $config;
        $this->reset();
    }

    /**
     * setup / update table schema
     * @static
     * @param $db
     * @param $table
     * @param $fields
     * @return bool
     */
    static public function setup($db, $table, $fields=null)
    {
        if(is_null($fields))
            if(!empty(static::$fieldConf))
                    $fields = static::$fieldConf;
            else {
                trigger_error('no field setup defined');
                return false;
            }
        $table = strtolower($table);
        $dbsType = get_class($db);
        if ($dbsType == 'DB\SQL') {
            $schema = new \DB\SQL\Schema($db);
            // prepare field configuration
            foreach($fields as &$field) {
                // relation field types
                $field = self::resolveRelations($field);
                // transform array fields
                if(in_array($field['type'], array(self::DT_TEXT_JSON, self::DT_TEXT_SERIALIZED)))
                    $field['type']=$schema::DT_TEXT;
                // defaults values
                if(!array_key_exists('nullable', $field)) $field['nullable'] = true;
               // if(!array_key_exists('default', $field)) $field['default'] = NULL;
            }
            if (!in_array($table, $schema->getTables())) {
                $table=$schema->createTable($table);
                foreach ($fields as $field_key => $field_conf) {
                    $table->addColumn($field_key, $field_conf);
                }
                $table->build();
            } else {
                $table = $schema->alterTable($table);
                $existingCols = $table->getCols();
                // add missing fields
                foreach ($fields as $field_key => $field_conf)
                    if (!in_array($field_key, $existingCols))
                        $table->addColumn($field_key, $field_conf);
                // remove unused fields
//                foreach ($existingCols as $col)
//                    if (!in_array($col, array_keys($fields)) && $col!='id')
//                        $schema->dropColumn($col);
            }
        }
        return true;
    }

    /**
     * erase all model data, handle with care
     */
    static public function setdown($db, $table)
    {
        $table = strtolower($table);
        $dbsType = get_class($db);
        switch ($dbsType) {
            case 'DB\Jig':
                $refl = new \ReflectionObject($db);
                $prop = $refl->getProperty('dir');
                $prop->setAccessible(true);
                $dir = $prop->getValue($db);
                if(file_exists($dir.$table))
                    unlink($dir.$table);
                break;
            case 'DB\SQL':
                $schema = new \DB\SQL\Schema($db);
                if(in_array($table, $schema->getTables()))
                    $schema->dropTable($table);
                break;
            case 'DB\Mongo':
                $db->{$table}->drop();
                break;
        }
    }

    protected static function resolveRelations($field) {
        // relation field types
        if (array_key_exists('has-one', $field)) {
            if (!is_array($hasOne = $field['has-one']))
                $hasOne = array($hasOne, 'id');
            if ($hasOne[1] == 'id') $field['type'] = \DB\SQL\Schema::DT_INT16;
            else {
                $refl = new \ReflectionClass($hasOne[0]);
                $fc = $refl->getDefaultProperties();
                $field['type'] = $fc['fieldConf'][$hasOne[1]];
            }
            $field['nullable'] = true;
            $field['default'] = null;
        }
        return $field;
    }

    /**
     * converts the given filter array to fit the used DBS
     *
     * example filter:
     *   array('text = ? AND num = ?','bar',5)
     *   array('num > ? AND num2 <= ?',5,10)
     *   array('num1 > num2')
     *   array('text like ?','%foo%')
     *   array('(text like ? OR text like ?) AND num != ?','foo%','%bar',23)
     *
     * @param array $cond
     * @return array|bool|null
     */
    private function prepareFilter($cond = NULL)
    {
        if (is_null($cond)) return $cond;
        $ops = array('<=', '>=', '<>', '<', '>', '!=', '==', '=', 'like');
        foreach ($ops as &$op) $op = preg_quote($op);
        $op_quote = implode('|', $ops);

        switch ($this->dbsType) {
            case 'DB\Jig':
                return $this->_jig_parse_filter($cond);
                break;

            case 'DB\Mongo':
                $parts = preg_split("/\s*(\)|\(|AND|OR)\s*/i", array_shift($cond), -1,
                    PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
                foreach ($parts as &$part)
                    if (preg_match('/'.$op_quote.'/i', $part, $match)) {
                        $bindValue = is_int(strpos($part,'?')) ? array_shift($cond) : null;
                        $part = $this->_mongo_parse_relational_op($part, $bindValue);
                    }
                $ncond = $this->_mongo_parse_logical_op($parts);
                return $ncond;
                break;

            case 'DB\SQL':
                // no need to change anything yet
                return $cond;
                break;
        }
    }

    /**
     * convert filter array to jig syntax
     * @param $cond
     * @return array
     */
    private function _jig_parse_filter($cond){
        // split logical
        $parts = preg_split("/\s*(\)|\(|AND|OR)\s*/i", array_shift($cond), -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $ncond = array();
        foreach ($parts as &$part) {
            if (in_array(strtoupper($part), array('AND', 'OR')))
                continue;
            // prefix field names
            $part = preg_replace('/([a-z]+)/i', '@$1', $part, -1, $count);
            // value comparison
            if (is_int(strpos($part, '?'))) {
                $val = array_shift($cond);
                preg_match('/(@\w+)/i', $part, $match);
                // find like operator
                if (is_int(strpos(strtoupper($part), ' @LIKE '))) {
                    // %var% -> /var/
                    if (substr($val, 0, 1) == '%' && substr($val, -1, 1) == '%')
                        $val = str_replace('%', '/', $val);
                    // var%  -> /^var/
                    elseif (substr($val, -1, 1) == '%')
                        $val = '/^'.str_replace('%', '', $val).'/';
                    // %var  -> /var$/
                    elseif (substr($val, 0, 1) == '%')
                        $val = '/'.substr($val, 1).'$/';
                    $part = 'preg_match(?,'.$match[0].')';
                }
                // add existence check
                $part = '(isset('.$match[0].') && '.$part.')';
                $ncond[] = $val;
            } elseif ($count == 2) {
                // field comparison
                preg_match_all('/(@\w+)/i', $part, $matches);
                $part = '(isset('.$matches[0][0].') && isset('.$matches[0][1].') && ('.$part.'))';
            }
        }
        array_unshift($ncond, implode(' ', $parts));
        return $ncond;
    }

    /**
     * find and wrap logical operators AND, OR, (, )
     * @param $parts
     * @return array
     */
    private function _mongo_parse_logical_op($parts)
    {
        $b_offset = 0;
        $ncond = array();
        $child = array();
        for ($i = 0, $max = count($parts); $i < $max; $i++) {
            $part = $parts[$i];
            if ($part == '(') {
                // add sub-bracket to parse array
                if ($b_offset > 0)
                    $child[] = $part;
                $b_offset++;
            } elseif ($part == ')') {
                $b_offset--;
                // found closing bracket
                if ($b_offset == 0) {
                    $ncond[] = ($this->_mongo_parse_logical_op($child));
                    $child = array();
                } elseif ($b_offset < 0)
                    trigger_error('unbalanced brackets'); else
                    // add sub-bracket to parse array
                    $child[] = $part;
            } // add to parse array
            elseif ($b_offset > 0)
                $child[] = $part; // condition type
            elseif (!is_array($part)) {
                if (strtoupper($part) == 'AND')
                    $add = true;
                elseif (strtoupper($part) == 'OR')
                    $or = true;
            } else // skip
                $ncond[] = $part;
        }
        if ($b_offset > 0)
            trigger_error('unbalanced brackets');
        if (isset($add))
            return array('$and' => $ncond);
        elseif (isset($or))
            return array('$or' => $ncond); else
            return $ncond[0];
    }

    /**
     * find and convert relational operators
     * @param $cond
     * @param $var
     * @return array|null
     */
    private function _mongo_parse_relational_op($cond, $var)
    {
        if (is_null($cond)) return $cond;
        $ops = array('<=', '>=', '<>', '<', '>', '!=', '==', '=', 'like');
        foreach ($ops as &$op) $op = preg_quote($op);
        $op_quote = implode('|', $ops);
        if (preg_match('/'.$op_quote.'/i', $cond, $match)) {
            $exp = explode($match[0], $cond);
            // unbound value
            if (is_numeric($exp[1]))
                $var = $exp[1];
            // field comparison
            elseif(!is_int(strpos($exp[1], '?')))
                return array('$where' => 'this.'.trim($exp[0]).' '.$match[0].' this.'.trim($exp[1]));
            // find like operator
            if (strtoupper($match[0]) == 'LIKE') {
                // %var% -> /var/
                if (substr($var, 0, 1) == '%' && substr($var, -1, 1) == '%')
                    $rgx = str_replace('%', '/', $var);
                // var%  -> /^var/
                elseif (substr($var, -1, 1) == '%')
                    $rgx = '/^'.str_replace('%', '', $var).'/';
                // %var  -> /var$/
                elseif (substr($var, 0, 1) == '%')
                    $rgx = '/'.substr($var, 1).'$/';
                $var = new \MongoRegex($rgx);
            } // translate operators
            elseif (!in_array($match[0], array('==', '='))) {
                $opr = str_replace(array('<>', '<', '>', '!', '='),
                    array('$ne', '$lt', '$gt', '$n', 'e'), $match[0]);
                $var = array($opr => (strtolower($var) == 'null') ? null : $var + 0);
            } elseif(trim($exp[0]) == '_id' && !$var instanceof \MongoId)
                $var = new \MongoId($var);
            return array(trim($exp[0]) => $var);
        }
        return $cond;
    }

    /**
     * convert options array syntax
     *
     * example:
     *   array('order'=>'location') // default direction is ASC
     *   array('order'=>'num1 desc, num2 asc')
     *
     * @param $options
     * @return array|null
     */
    private function prepareOptions($options)
    {
        if (!empty($options) && is_array($options)) {
            switch ($this->dbsType) {
                case 'DB\Jig':
                    if (array_key_exists('order', $options))
                        $options['order'] = str_replace(array('asc', 'desc'),
                            array('SORT_ASC', 'SORT_DESC'), strtolower($options['order']));
                    break;
            }
            switch ($this->dbsType) {
                case 'DB\Mongo':
                    if (array_key_exists('order', $options)) {
                        $sorts = explode(',', $options['order']);
                        $sorting = array();
                        foreach ($sorts as $sort) {
                            $sp = explode(' ', trim($sort));
                            $sorting[$sp[0]] = (array_key_exists(1, $sp) &&
                                strtoupper($sp[1]) == 'DESC') ? -1 : 1;
                        }
                        $options['order'] = $sorting;
                    }
                    break;
            }
            return $options;
        } else
            return null;
    }

    public function afind($filter = NULL, array $options = NULL, $ttl = 0)
    {
        return array_map(array($this,'cast'),$this->find($filter,$options,$ttl));
    }

    /************************\
     *
     *  ORM specific methods
     *
    \************************/

    /**
     * Return an array of objects matching criteria
     * @param array|null $filter
     * @param array|null $options
     * @param int        $ttl
     * @return array
     */
    public function find($filter = NULL, array $options = NULL, $ttl = 0)
    {
        $filter = $this->prepareFilter($filter);
        $options = $this->prepareOptions($options);
        $result = $this->mapper->find($filter, $options, $ttl);
        foreach($result as &$mapper)
            $mapper = $this->factory($mapper);
        return $result;
    }

    /**
     * Retrieve first object that satisfies criteria
     * @param null  $filter
     * @param array $options
     * @return \Axon|\Jig|\M2
     */
    public function load($filter = NULL, array $options = NULL)
    {
        $filter = $this->prepareFilter($filter);
        $options = $this->prepareOptions($options);
        $result = $this->mapper->load($filter, $options);
        return $result;
    }

    /**
     * Delete object/s and reset ORM
     * @param $filter
     * @return void
     */
    public function erase($filter = null)
    {
        $filter = $this->prepareFilter($filter);
        $this->mapper->erase($filter);
    }

    /**
     * Save mapped record
     * @return mixed
     **/
    function save()
    {
        return $this->dry() ? $this->insert() : $this->update();
    }

    public function count($filter = NULL)
    {
        $filter = $this->prepareFilter($filter);
        return $this->mapper->count($filter);
    }

    /**
     * Bind value to key
     * @return mixed
     * @param $key string
     * @param $val mixed
     */
    function set($key, $val)
    {
        $fields = static::$fieldConf;
        if(!empty($fields) && !in_array($key,array_keys($fields)))
            trigger_error(sprintf('Field %s does not exist in %s.',$key,get_class($this)));
        // handle relations
        if (is_array($fields[$key]) && array_key_exists('has-one', $fields[$key])) {
            // fetch index value
            if (!$val instanceof self || $val->dry())
                trigger_error('You can only save hydrated mapper objects');
            else {
                $hasOne = $fields[$key]['has-one'];
                $rel_field = (is_array($hasOne) ? $hasOne[1] : '_id');
                $val = $val->get($rel_field);
            }
        }
        // convert array content
        if (is_array($val) && $this->dbsType == 'DB\SQL' && !empty($fields)) {
            if($fields[$key]['type'] == self::DT_TEXT_SERIALIZED)
                $val = serialize($val);
            elseif($fields[$key]['type'] == self::DT_TEXT_JSON)
                $val = json_encode($val);
            else
                trigger_error(sprintf(self::E_ARRAYDATATYPE, $key));
        }
        // add polyfills
        if ($this->dbsType == 'DB\Jig' || $this->dbsType == 'DB\Mongo') {
            if (!empty($fields) && array_key_exists('nullable', $fields[$key]))
                    if($fields[$key]['nullable'] === false && $val === false)
                        trigger_error('unable to set a not nullable field to null');
            if ($this->dbsType == 'DB\Mongo' && $key == '_id' && !$val instanceof \MongoId)
                $val = new \MongoId($val);
        }
        return $this->mapper->{$key} = $val;
    }

    /**
     * Retrieve contents of key
     * @return mixed
     * @param $key string
     */
    function get($key)
    {
        $fields = static::$fieldConf;
        if(!empty($fields) && array_key_exists($key, $fields)) {
            // load relations
            if (is_array($fields[$key]) && array_key_exists('has-one', $fields[$key])) {
                $class = (is_array($hasOne = $fields[$key]['has-one'])) ? $hasOne[0] : $hasOne;
                $rel = new $class;
                if (!$rel instanceof self) trigger_error('Relations only works with Cortex');
                $rel_field = (is_array($hasOne) ? $hasOne[1] :
                    (($this->dbsType == 'DB\SQL') ? 'id' : '_id'));
                $rel->load(array($rel_field.' = ?', $this->mapper->{$key}));
                return (!$rel->dry()) ? $rel : null;
            }
            // resolve array fields
            if ($this->dbsType == 'DB\SQL' && array_key_exists('type', $fields[$key])) {
                if ($fields[$key]['type'] == self::DT_TEXT_SERIALIZED)
                    return unserialize($this->mapper->{$key});
                elseif ($fields[$key]['type'] == self::DT_TEXT_JSON)
                    return json_decode($this->mapper->{$key},true);
            }
        }
        return $this->mapper->{$key};
    }

    /**
     * Return fields of mapper object as an associative array
     * @return array
     * @param      $obj object
     * @param bool $relations resolve relations
     */
    function cast($obj = NULL, $relations = TRUE)
    {
        $fields = $this->mapper->cast( ($obj) ? $obj->mapper : null );
        if ($this->dbsType == 'DB\SQL' && !empty(static::$fieldConf))
            foreach ($fields as $key => &$val)
                if(array_key_exists($key, static::$fieldConf)) {
                    if($relations && array_key_exists('has-one', static::$fieldConf[$key])) {
                        $val = ( !is_null( $obj = $this->get($key) )) ? $obj->cast() : null;
                    }
                    elseif(array_key_exists('type', static::$fieldConf[$key]))
                        if (static::$fieldConf[$key]['type'] == self::DT_TEXT_SERIALIZED)
                            $val=unserialize($this->mapper->{$key});
                        elseif (static::$fieldConf[$key]['type'] == self::DT_TEXT_JSON)
                            $val=json_decode($this->mapper->{$key}, true);
                }
        return $fields;
    }

    /**
     * wrap result mapper
     * @param $mapper
     * @return Cortex
     */
    protected function factory($mapper)
    {
        $cx = clone($this);
        $cx->reset();
        $cx->mapper = $mapper;
        return $cx;
    }

    public function dry() {
        return $this->mapper->dry();
    }

    public function copyfrom($key) {
        $this->mapper->copyfrom($key);
    }

    public function copyto($key) {
        $this->mapper->copyto($key);
    }

    public function skip($ofs = 1) {
        return $this->mapper->skip($ofs);
    }

    public function first() {
        return $this->mapper->first();
    }

    public function last() {
        return $this->mapper->last();
    }

    public function reset() {
        $this->mapper->reset();
        // set default values
        if($this->dbsType == 'DB\Jig' || $this->dbsType == 'DB\Mongo') {
            if(!empty(static::$fieldConf))
                foreach(static::$fieldConf as $field_key => $field_conf) {
                    if(array_key_exists('default',$field_conf))
                        $this->{$field_key} = $field_conf['default'];
                }
        }
    }

    function exists($key) {
        return $this->mapper->exists($key);
    }

    function clear($key) {
        $this->mapper->clear($key);
    }

    function insert() {
        return $this->mapper->insert();
    }

    function update() {
        return $this->mapper->update();
    }

    /**
     * cleanup on destruct
     */
    public function __destruct()
    {
        unset($this->mapper);
    }
}