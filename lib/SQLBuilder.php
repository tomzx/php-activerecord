<?php
namespace ActiveRecord;

/**
 * Helper class for building sql statements progmatically.
 */
class SQLBuilder
{
	private $connection;
	private $operation = 'SELECT';
	private $table;
	private $select = '*';
	private $joins;
	private $order;
	private $limit;
	private $offset;
	private $group;

	// for where
	private $where;
	private $where_values = array();

	// for insert/update
	private $data;

	public function __construct($connection, $table)
	{
		if (!$connection)
			throw new ActiveRecordException('A valid database connection is required.');

		$this->connection	= $connection;
		$this->table		= $table;
	}

	public function __toString()
	{
		return $this->to_s();
	}

	public function to_s()
	{
		$func = 'build_' . strtolower($this->operation);
		return $this->$func();
	}

	public function bind_values()
	{
		$ret = array();

		if ($this->data)
			$ret = array_values($this->data);

		if ($this->get_where_values())
			$ret = array_merge($ret,$this->get_where_values());

		return array_flatten($ret);
	}

	public function get_where_values()
	{
		return $this->where_values;
	}

	public function where(/* (conditions, values) || (hash) */)
	{
		$this->apply_where_conditions(func_get_args());
		return $this;
	}

	public function order($order)
	{
		$this->order = $order;
		return $this;
	}

	public function group($group)
	{
		$this->group = $group;
		return $this;
	}

	public function limit($limit)
	{
		$this->limit = intval($limit);
		return $this;
	}

	public function offset($offset)
	{
		$this->offset = intval($offset);
		return $this;
	}

	public function select($select)
	{
		$this->operation = 'SELECT';
		$this->select = $select;
		return $this;
	}

	public function joins($joins)
	{
		$this->joins = $joins;
		return $this;
	}

	public function insert($hash)
	{
		if (!is_hash($hash))
			throw new ActiveRecordException('Inserting requires a hash.');

		$this->operation = 'INSERT';
		$this->data = $hash;
		return $this;
	}

	public function update($hash)
	{
		if (!is_hash($hash))
			throw new ActiveRecordException('Updating requires a hash.');

		$this->operation = 'UPDATE';
		$this->data = $hash;
		return $this;
	}

	public function delete()
	{
		$this->operation = 'DELETE';
		$this->apply_where_conditions(func_get_args());
		return $this;
	}

	/**
	 * Reverses an order clause.
	 */
	public static function reverse_order($order)
	{
		if (!trim($order))
			return $order;

		$parts = explode(',',$order);

		for ($i=0,$n=count($parts); $i<$n; ++$i)
		{
			$v = strtolower($parts[$i]);

			if (strpos($v,' asc') !== false)
				$parts[$i] = preg_replace('/asc/i','DESC',$parts[$i]);
			elseif (strpos($v,' desc') !== false)
				$parts[$i] = preg_replace('/desc/i','ASC',$parts[$i]);
			else
				$parts[$i] .= ' DESC';
		}
		return join(',',$parts);
	}

	/**
	 * Converts a string like "id_and_name_or_z" into a conditions value like array("id=? AND name=? OR z=?", values, ...)
	 *
	 * @param $name Underscored string
	 * @param $args Reference to the values for the field names. This is used
	 *   to determine what kind of bind marker to use: =?, IN(?), IS NULL
	 * @param $map A hash of "mapped_column_name" => "real_column_name"
	 * @return A conditions array in the form array(sql_string, value1, value2,...)
	 */
	public static function create_conditions_from_underscored_string($name, &$values=array(), &$map=null)
	{
		if (!$name)
			return null;

		$parts = preg_split('/(_and_|_or_)/i',$name,-1,PREG_SPLIT_DELIM_CAPTURE);
		$num_values = count($values);
		$conditions = array('');

		for ($i=0,$j=0,$n=count($parts); $i<$n; $i+=2,++$j)
		{
			if ($i >= 2)
				$conditions[0] .= preg_replace(array('/_and_/i','/_or_/i'),array(' AND ',' OR '),$parts[$i-1]);

			$bind = '=?';

			if ($j < $num_values)
			{
				if (is_array($values[$j]))
					$bind = ' IN(?)';
				elseif (is_null($values[$j]))
					$bind = ' IS NULL';
			}
			else
				$bind = ' IS NULL';

			if ($bind != ' IS NULL')
				$conditions[] = $values[$j];

			// map to correct name if $map was supplied
			$name = $map && array_key_exists($parts[$i],$map) ? $map[$parts[$i]] : $parts[$i];

			$conditions[0] .= $name . $bind;
		}
		return $conditions;
	}

	private function apply_where_conditions($args)
	{
		$num_args = count($args);

		if ($num_args == 1 && is_hash($args[0]))
		{
			$e = new Expressions($args[0]);
			$e->set_connection($this->connection);
			$this->where = $e->to_s();
			$this->where_values = array_flatten($e->values());
		}
		elseif ($num_args > 0)
		{
			$e = new Expressions($args[0]);
			$e->set_connection($this->connection);
			$e->bind_values(array_slice($args,1));
			$this->where = $e->to_s();
			$this->where_values = array_flatten($e->values());
		}
	}

	private function build_delete()
	{
		$sql = "DELETE FROM $this->table";

		if ($this->where)
			$sql .= " WHERE $this->where";

		return $sql;
	}

	private function build_insert()
	{
		$keys = join(',',array_keys($this->data));
		$e = new Expressions("INSERT INTO $this->table($keys) VALUES(?)",array_values($this->data));
		$e->set_connection($this->connection);
		return $e->to_s();
	}

	private function build_select()
	{
		$sql = "SELECT $this->select FROM $this->table";

		if ($this->joins)
			$sql .= ' ' . $this->joins;

		if ($this->where)
			$sql .= " WHERE $this->where";

		if ($this->order)
			$sql .= " ORDER BY $this->order";

		if ($this->group)
			$sql .= " GROUP BY $this->group";

		if ($this->limit || $this->offset)
			$sql = $this->connection->limit($sql,$this->offset,$this->limit);

		return $sql;
	}

	private function build_update()
	{
		$fields = array_keys($this->data);
		$sql = "UPDATE $this->table SET " . join('=?, ',$fields) . '=?';

		if ($this->where)
			$sql .= " WHERE $this->where";

		return $sql;
	}
}
?>