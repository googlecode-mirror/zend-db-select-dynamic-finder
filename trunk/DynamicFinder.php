<?php
/**
 * Class DynamicFinder
 * 
 * Zend_Db_Select - based dynamic finder
 *
 * @author Max Yemets (MYem), max.yemets<at>gmail.com
 *
 * Usage:
 * <code>
 *  //model, that uses DynamicFinder:
 *  public function  __construct() {
 *      if (!class_exists('DynamicFinder',false)){
 *          require_once 'path_to' . '/DynamicFinder.php'; ;
 *      }
 *
 *      $this->_dynFinder = new DynamicFinder();
 *  }
 *
 *  // Example for handling in model as own finder via __call:
 *  public function __call($name, $arguments)
 *  {
 *      $this->_dynFinder->select = $this->getTable()->select();
 *      $this->_dynFinder->allowedFields = $this->getTable()->info(Zend_Db_Table_Abstract::COLS);
 *
 *      $select =  call_user_func_array(array($this->_dynFinder, $name), $arguments);
 *
 *      if (strpos($name,'getOneBy')===0){
 *          return $this->getTable()->fetchRow($select);
 *      } else {
 *          return $this->getTable()->fetchAll($select);
 *      }
 *  }
 *
 *  // Requesting the rowset
 *  ...// somwhere in model / controller / view methods:
 *  $result = $this->getAllByTag(new Zend_Db_Expr(sprintf(" LIKE '%s%%'" , $beginStr)));
 *  $data = $tagObj->getAllByTag(new Zend_Db_Expr(sprintf(" LIKE '%s%%'" , $beginStr)));
 *
 *  //...
 *  $result = $this->getOneByTag($tag);
 *  $data = $tagObj->getOneByTag($tag);
 *  $user->getOneByLoginAndPassword($login, $password);
 *
 *  //...
 *  $ret = $this->getAllByPaperId(
 *          array('values'=>array($paperId),
 *              'options'=>array(
 *                   'order'=>array('orig_filename ASC'),
 *                   'offset' => $offset,
 *                   'limit' => 10
 *              ) ) );
 *
 *  //...
 *  $ret = $this->getByPaperId($paperId);
 *
 * </code>
 */    
class DynamicFinder
{
    /**
     * <code>
     *  $dynFinder->select = $dbTable->select();
     * </code>
     * Zend_Db_Select or
     * @var Zend_Db_Table_Select 
     */    
    public $select;
    
    /**  
     * for assignment the current db table rows - 
     * <code>
     *  $dynFinder->allowedFields = $dbTable->info(Zend_Db_Table_Abstract::COLS);
     * </code>
     * @var array 
     */
    public $allowedFields;
    
    /**
     * To substitute _camelCase2underscore method if needed. Proposed by Murzik (Max Lozovoy).
     * Set this parameter in the format of call_user_func() callback array somewhere
     * before the Dynamic Finder __call() method called.
     *
     * @var array
     */
    public $columnParseCallback = array();

    /**
     * Unexistent methods handler
     *
     * @param string $name Dynamic-constructored method name
     * @param mixed $arguments Dynamic-constructored method arguments 
     * @return Zend_Db_Select | false
     */
    public function __call($name, $arguments)
    {
        if ( (empty($this->select) ||
                !($this->select instanceof Zend_Db_Select && $this->select instanceof Zend_Db_Table_Select ))
            || empty($this->allowedFields) || !is_array($this->allowedFields) )
        {
            throw new Exception('Dynamic Finder: Required properties not provided!');
        }

        //handles get by dynamic finder like getByNameAndPasswordOrDate()
        if (strpos($name,'getBy')===0) { //most common case
            array_unshift($arguments, str_replace('getBy', '', $name));           
            //used call_user_func as $this->_getByColumsParser
            //as argument must be an array
            return call_user_func(
                array($this, '_getByColumsParser'),                
                $arguments
            );
        } elseif (strpos($name,'getAllBy')===0) { //findAll case - in fact the same as getBy
            array_unshift($arguments, str_replace('getAllBy', '', $name));            
            return call_user_func(
                array($this, '_getByColumsParser'),                
                $arguments
            );
        } elseif (strpos($name,'getOneBy')===0) { //findOne case
            array_unshift($arguments, str_replace('getOneBy', '', $name));            
            return call_user_func_array(
                array($this, '_getOneByColumsParser'),                
                $arguments
            );
        }
        else {
            return false;
        }
    }

    /**
     * Standard Zend_Db_Select assemble
     * 
     * @return string
     */
    public function assemble()
    {
        return $this->select->assemble();
    }
    
    /**
     * Forms select with one-row request
     *
     * @return Zend_Db_Select
     */
    private function _getOneByColumsParser()
    {
        $args = func_get_args();        

        if (count($args)==2 && is_array($args[1]) && isset($args[1]['values'])) {            
            $args[1]['options']['limit']=1; 
            $this->_getByColumsParser($args);
        } else {
            $this->_getByColumsParser($args);
            $this->_buildSelectObjLimitExpr(array('limit' => 1));
        }
        
        return $this->select;
    }

    /**
     * Main Zend_Db_Select object builder
     *
     * @param array $args
     * @return Zend_Db_Select
     */
    private function _getByColumsParser($args)
    {
        $funcName = array_shift($args);
        
        $whereArray = array();
        $optionsArray = array();
        if (count($args)==1 && is_array($args[0]) && isset($args[0]['values'])) { //array args case
            $args = $args[0];
            $whereArray = $this->_getSelectWhereArray($funcName, $args['values']);
            
            if (isset($args['options'])) {
                //excluding nonexistent ['options']['order'] columns
                if (isset($args['options']['order'])) {
                    foreach ($args['options']['order'] as $key => $val) {
                        if ( ($spacePos = strpos($val,' '))!==FALSE ) {
                            $column = substr($val, 0, $spacePos);
                        } else {
                            $column = substr($val, 0, strlen($column));
                        }
                        if (!in_array($column, $this->allowedFields)){
                            unset($args['options']['order'][$key]);
                        }
                    }
                }

                //checking the values for limit, offset
                foreach (array('limit','offset') as $key => $val) {
                    if (isset($args['options'][$key]) ) {
                        $val = intval($val);
                        if ($val == 0){
                            unset($args['options'][$key]);
                        } else {
                            $args['options'][$key] = $val;
                        }
                    }
                }
                
                $optionsArray = $args['options'];
            }

        } else { //values only args case
            $whereArray = $this->_getSelectWhereArray($funcName, $args);
        }

        $this->_buildSelectWhereClause($whereArray, $optionsArray);

        return $this->select;
    }
    
    /**
     * Main function to build select where clause plus order, limit
     *
     * @param array $whereArray
     * @param array $options
     *
     * @return Zend_Db_Select
     */
    private function _buildSelectWhereClause($whereArray = array(), $options = array() )
    {
        if (!empty ($whereArray))
            $this->_buildSelectObjWhereExpr($whereArray);

        if (!empty ($options) && isset ($options['order']) && count($options['order'])>0)
            $this->_buildSelectObjOrderExpr($options['order']);

        if (!empty ($options) && isset($options['limit']))
            $this->_buildSelectObjLimitExpr($options);

        return $this->select;
    }

    /**
     * Adds to Zend_Db_Select object WHERE expr - parses $fieldArg (columns)
     * part of finder expr     
     *
     * @param array $whereArray
     * 
     * @return Zend_Db_Select
     */
    private function _buildSelectObjWhereExpr($whereArray)
    {
        //builds where expression
        foreach ($whereArray as $inner) {
            //preparing args
            if ($inner['column_value'] instanceof Zend_Db_Expr) {
                $args = array($inner['column_name'] . ' '  . $inner['column_value']->__toString());
            } else {
                $args = array($inner['column_name'] . ' = ?', $inner['column_value']);
            }

            //use ['cond'] for current where
            if ($inner['cond'] == 'or'){
                call_user_func_array(array($this->select,'orWhere'), $args);
            } else {
                call_user_func_array(array($this->select,'where'), $args);
            }
        }

        return $this->select;
    }

    /**
     * Builds select order expression
     *
     * @param array $orderArray
     * 
     * @return Zend_Db_Select
     */
    private function _buildSelectObjOrderExpr($orderArray=array())
    {
        if (!empty($orderArray)) {
            $this->select->order($orderArray);
        }
        return $this->select;
    }

    /**
     * Builds order expression
     *
     * @param array $limitArray
     * 
     * @return Zend_Db_Select
     */
    private function _buildSelectObjLimitExpr($limitArray)
    {
        if (isset($limitArray['offset'])){
            $this->select->limit($limitArray['limit'], $limitArray['offset']);
        } else {
            $this->select->limit($limitArray['limit']);
        }

        return $this->select;
    }
  
    /**
     * Parses field argument derived from function name and function values like 
     * <code>
     *  getByNameAndPasswordOrDate('john','mypwd','1989-10-10 00:00:00')
     * </code>
     * and forms select-build-ready (see _buildSelectObjWhereExpr() ) array
     * 
     * @param string $fieldArg
     * @param array $values
     * 
     * @return array
     */
    private function _getSelectWhereArray($fieldArg, $values)
    {
        $fields = $this->allowedFields;
        $whereArray = array();
        //parsing the getBy clause
        $colNum = 0;        
        $regexp = "/(And|Or|^)(.+)(?=And|Or|$)/U";
        preg_match_all($regexp, $fieldArg, $parsedFields, PREG_SET_ORDER);

        if ($parsedFields && is_array($parsedFields) ) {
            foreach ($parsedFields as $val) {
                if (is_array($val) && isset($val[2]) && !empty($val[2])){
                    
                    //condition and|or set
                    switch(strtolower($val[1])){
                        case 'or':
                            $cond = 'or' ;
                            break;

                        case 'and':
                        default:
                            $cond = 'and' ;
                    }

                    if (!empty($this->columnParseCallback) && is_callable($this->columnParseCallback)) {
                        $column = call_user_func($this->columnParseCallback,$val[2]);
                    } else {
                        $column = $this->_camelCase2underscore($val[2]);
                    }
                    
                    if (in_array($column, $fields) ){
                        $whereArray[] = array(
                            'cond' => $cond,
                            'column_name' => $column,
                            'column_value' => $values[$colNum] );
                    }
                }
                $colNum++;
            }
        }        
        return $whereArray;
    }
    
    
    /**
     * Returns underscored DB tables column names instead of camelcased names
     * LastUpdateDateTime => last_update_date_time
     *
     * @param string $column
     * 
     * @return string
     */
    private function _camelCase2underscore($column)
    {
        $column = strtolower($column[0]) . substr($column, 1);
        $column = preg_replace_callback("/[A-Z]/",
            create_function(
                '$matches',
                'return "_" . strtolower($matches[0]);'
            ),
            $column);

        return $column;        
    }
}
