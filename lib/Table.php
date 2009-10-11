<?php
/**
 * @package ActiveRecord
 */
namespace ActiveRecord;
use DateTime;

/**
 *
 */
require_once 'Relationship.php';

/**
 * Manages reading and writing to a database table.
 *
 * This class manages a database table and is used by the Model class for
 * reading and writing to its database table. There is one instance of Table
 * for every table you have a model for.
 *
 * @package ActiveRecord
 */
class Table
{
	private static $cache = array();

	public $class;
	public $conn;
	public $pk;
	public $last_sql;

	// Name/value pairs of columns in this table
	public $columns = array();

	/**
	 * Name of the table.
	 */
	public $table;

	/**
	 * Name of the database (optional)
	 */
	public $db_name;

	/**
	 * Name of the sequence for this table (optional). Defaults to {$table}_seq
	 */
	public $sequence;

	/**
	 * A instance of CallBack for this model/table
	 * @static
	 * @var object ActiveRecord\CallBack
	 */
	public $callback;

	/**
	 * List of relationships for this table.
	 */
	private $relationships = array();

	public static function load($model_class_name)
	{
		if (!isset(self::$cache[$model_class_name]))
		{
			/* do not place set_assoc in constructor..it will lead to infinite loop due to
			   relationships requesting the model's table, but the cache hasn't been set yet */
			self::$cache[$model_class_name] = new Table($model_class_name);
			self::$cache[$model_class_name]->set_associations();
		}

		return self::$cache[$model_class_name];
	}

	public static function clear_cache()
	{
		self::$cache = array();
	}

	public function __construct($class_name)
	{
		$this->class = Reflections::instance()->add($class_name)->get($class_name);

		// if connection name property is null the connection manager will use the default connection
		$connection = $this->class->getStaticPropertyValue('connection',null);

		$this->conn = ConnectionManager::get_connection($connection);
		$this->set_table_name();
		$this->set_sequence_name();
		$this->get_meta_data();
		$this->set_primary_key();
		$this->set_delegates();
		$this->set_setters();

		$this->callback = new CallBack($class_name);
		$this->callback->register('before_save', function(Model $model) { $model->set_timestamps(); }, array('prepend' => true));
		$this->callback->register('after_save', function(Model $model) { $model->reset_dirty(); }, array('prepend' => true));
	}

	public function create_joins($joins)
	{
		if (!is_array($joins))
			return $joins;

		$self = $this->table;
		$ret = $space = '';

		foreach ($joins as $value)
		{
			$ret .= $space;

			if (stripos($value,'JOIN ') === false)
			{
				if (array_key_exists($value, $this->relationships))
					$ret .= $this->get_relationship($value)->construct_inner_join_sql($this);
				else
					throw new RelationshipException("Relationship named $value has not been declared for class: {$this->class->getName()}");
			}
			else
				$ret .= $value;

			$space = ' ';
		}
		return $ret;
	}

	public function find($options)
	{
		$table = array_key_exists('from', $options) ? $options['from'] : $this->get_fully_qualified_table_name();
		$sql = new SQLBuilder($this->conn, $table);

		if (array_key_exists('joins',$options))
		{
			$sql->joins($this->create_joins($options['joins']));

			// by default, an inner join will not fetch the fields from the joined table
			if (!array_key_exists('select', $options))
				$options['select'] = $this->get_fully_qualified_table_name() . '.*';
		}

		if (array_key_exists('select',$options))
			$sql->select($options['select']);

		if (array_key_exists('conditions',$options))
		{
			if (!is_hash($options['conditions']))
			{
				if (is_string($options['conditions']))
					$options['conditions'] = array($options['conditions']);

				call_user_func_array(array($sql,'where'),$options['conditions']);
			}
			else
			{
				if (!empty($options['mapped_names']))
					$options['conditions'] = $this->map_names($options['conditions'],$options['mapped_names']);

				$sql->where($options['conditions']);
			}
		}

		if (array_key_exists('order',$options))
			$sql->order($options['order']);

		if (array_key_exists('limit',$options))
			$sql->limit($options['limit']);

		if (array_key_exists('offset',$options))
			$sql->offset($options['offset']);

		if (array_key_exists('group',$options))
			$sql->group($options['group']);

		if (array_key_exists('having',$options))
			$sql->having($options['having']);
			
		if (array_key_exists('models_for_eager_load', $options))
			$models_for_eager_load = $options['models_for_eager_load'];
		else
			$models_for_eager_load = array(); 	
			
		$eager_load = array_key_exists('include',$options) ? $options['include'] : null;
		$readonly = (array_key_exists('readonly',$options) && $options['readonly']) ? true : false;

		return $this->find_by_sql($sql->to_s(),$sql->get_where_values(), $readonly, $eager_load);
	}

	public function find_by_sql($sql, $values=null, $readonly=false, $eager_load=null, $models_for_eager_load=array())
	{
		$this->last_sql = $sql;
		
		$collect_pk_for_eager = is_null($eager_load) ? false : true;
		$attach_associations_from_eager = empty($models_for_eager_load) : false : true;
			
		$list = $pk_for_eager = array();
		$sth = $this->conn->query($sql,$values);

		while (($row = $sth->fetch()))
		{
			$model = new $this->class->name($row,false,true,false);

			if ($readonly)
				$model->readonly();
				
			if ($collect_pk_for_eager)
				$pk_for_eager[] = $model->values_for_pk();
				
			if ($attach_associations_from_eager)
			{
				foreach ($related_models as $related)
				{
					if ($related->$fk === $pk_match)
						$relationships[] = $related;
				}
			
				if (!empty($relationships))
					$model->set_relationship($relationships, $this->attribute_na);
			}
				
			$list[] = $model;
		}
		
		if ($use_eager_load)
			$this->execute_eager_load($list, $pk_for_eager, $eager_load);
			
		return $list;
	}
	
	private function execute_eager_load($models=array(), $primary_keys, $includes)
	{
		foreach ($includes as $name)
		{
			if (!($rel = $this->get_relationship($name)))
				throw new \Exception("Relationship: '$name' not found on model: {$this->class->name}");	
				
			$rel->load_eagerly($models, $primary_keys, $this);
		}
	}

	public function get_fully_qualified_table_name()
	{
		$table = $this->conn->quote_name($this->table);

		if ($this->db_name)
			$table = $this->conn->quote_name($this->db_name) . ".$table";

		return $table;
	}

	public function get_relationship($name)
	{
		if (isset($this->relationships[$name]))
			return $this->relationships[$name];
	}

	public function insert(&$data)
	{
		$data = $this->process_data($data);

		$sql = new SQLBuilder($this->conn,$this->get_fully_qualified_table_name());
		$sql->insert($data,$this->pk[0],$this->sequence);

		$values = array_values($data);
		return $this->conn->query(($this->last_sql = $sql->to_s()),$values);
	}

	public function update(&$data, $where)
	{
		$data = $this->process_data($data);

		$sql = new SQLBuilder($this->conn,$this->get_fully_qualified_table_name());
		$sql->update($data)->where($where);

		$values = $sql->bind_values();
		return $this->conn->query(($this->last_sql = $sql->to_s()),$values);
	}

	public function delete($data)
	{
		$data = $this->process_data($data);

		$sql = new SQLBuilder($this->conn,$this->get_fully_qualified_table_name());
		$sql->delete($data);

		$values = $sql->bind_values();
		return $this->conn->query(($this->last_sql = $sql->to_s()),$values);
	}

	/**
	 * Add a relationship.
	 *
	 * @param Relationship $relationship a Relationship object
	 */
	private function add_relationship($relationship)
	{
		$this->relationships[$relationship->attribute_name] = $relationship;
	}

	private function get_meta_data()
	{
		$this->columns = $this->conn->columns($this->get_fully_qualified_table_name());
	}

	/**
	 * Replaces any aliases used in a hash based condition.
	 *
	 * @param $hash array A hash
	 * @param $map array Hash of used_name => real_name
	 * @return array Array with any aliases replaced with their read field name
	 */
	private function map_names(&$hash, &$map)
	{
		$ret = array();

		foreach ($hash as $name => &$value)
		{
			if (array_key_exists($name,$map))
				$name = $map[$name];

			$ret[$name] = $value;
		}
		return $ret;
	}

	private function &process_data($hash)
	{
		foreach ($hash as $name => &$value)
		{
			// TODO this will probably need to be changed for oracle
			if ($value instanceof DateTime)
				$hash[$name] = $value->format('Y-m-d H:i:s T');
			else
				$hash[$name] = $value;
		}
		return $hash;
	}

	private function set_primary_key()
	{
		if (($pk = $this->class->getStaticPropertyValue('pk',null)) || ($pk = $this->class->getStaticPropertyValue('primary_key',null)))
			$this->pk = is_array($pk) ? $pk : array($pk);
		else
		{
			$this->pk = array();

			foreach ($this->columns as $c)
			{
				if ($c->pk)
					$this->pk[] = $c->name;
			}
		}
	}

	private function set_table_name()
	{
		if (($table = $this->class->getStaticPropertyValue('table',null)) || ($table = $this->class->getStaticPropertyValue('table_name',null)))
			$this->table = $table;
		else
		{
			// infer table name from the class name
			$this->table = Inflector::instance()->tableize($this->class->getName());
		}

		if(($db = $this->class->getStaticPropertyValue('db',null)) || ($db = $this->class->getStaticPropertyValue('db_name',null)))
			$this->db_name = $db;
	}

	private function set_sequence_name()
	{
		$this->sequence = $this->class->getStaticPropertyValue('sequence',$this->conn->get_sequence_name($this->table));
	}

	private function set_associations()
	{
		foreach ($this->class->getStaticProperties() as $name => $definitions)
		{
			if (!$definitions || !is_array($definitions))
				continue;

			foreach ($definitions as $definition)
			{
				$relationship = null;

				switch ($name)
				{
					case 'has_many':
						$relationship = new HasMany($definition);
						break;

					case 'has_one':
						$relationship = new HasOne($definition);
						break;

					case 'belongs_to':
						$relationship = new BelongsTo($definition);
						break;

					case 'has_and_belongs_to_many':
						$relationship = new HasAndBelongsToMany($definition);
						break;
				}

				if ($relationship)
					$this->add_relationship($relationship);
			}
		}
	}

	/**
	 * Rebuild the delegates array into format that we can more easily work with in Model.
	 * Will end up consisting of array of:
	 *
	 * array('delegate' => array('field1','field2',...),
	 *       'to'       => 'delegate_to_relationship',
	 *       'prefix'	=> 'prefix')
	 */
	private function set_delegates()
	{
		$delegates = $this->class->getStaticPropertyValue('delegate',array());
		$new = array();

		if (!array_key_exists('processed', $delegates))
			$delegates['processed'] = false;

		if (!empty($delegates) && !$delegates['processed'])
		{
			foreach ($delegates as &$delegate)
			{
				if (!is_array($delegate) || !isset($delegate['to']))
					continue;

				if (!isset($delegate['prefix']))
					$delegate['prefix'] = null;

				$new_delegate = array(
					'to'		=> $delegate['to'],
					'prefix'	=> $delegate['prefix'],
					'delegate'	=> array());

				foreach ($delegate as $name => $value)
				{
					if (is_numeric($name))
						$new_delegate['delegate'][] = $value;
				}

				$new[] = $new_delegate;
			}

			$new['processed'] = true;
			$this->class->setStaticPropertyValue('delegate',$new);
		}
	}

	/**
	 * Rebuilds the setters array to prepend set_ to the method names.
	 */
	private function set_setters()
	{
		$setters = array();

		foreach ($this->class->getStaticPropertyValue('setters',array()) as $method)
			$setters[] = (substr($method,0,4) != "set_" ? "set_$method" : $method);

		$this->class->setStaticPropertyValue('setters',$setters);
	}
};
?>