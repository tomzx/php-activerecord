<?php
namespace Smoothie;
use ActiveRecord\Column;

require_once 'TestHelper.php';

context("a Column", function() {
	function assert_mapped_type($type, $raw_type)
	{
		transient()->column->raw_type = $raw_type;
		assert_equals($type,transient()->column->map_raw_type());
	}

	function assert_cast($type, $casted_value, $original_value)
	{
		transient()->column->type = $type;
		$value = transient()->column->cast($original_value);

		if ($original_value != null && ($type == Column::DATETIME || $type == Column::DATE))
			assert_instance_of('\DateTime',$value);
		else
			assert_identical($casted_value,$value);
	}

	setup(function() { transient()->column = new Column(); });
	
	should("map dates", function() {
		assert_mapped_type(Column::DATETIME, 'datetime');
		assert_mapped_type(Column::DATE, 'date');
	});

	should("map ints", function() {
		assert_mapped_type(Column::INTEGER,'integer');
		assert_mapped_type(Column::INTEGER,'int');
		assert_mapped_type(Column::INTEGER,'tinyint');
		assert_mapped_type(Column::INTEGER,'smallint');
		assert_mapped_type(Column::INTEGER,'mediumint');
		assert_mapped_type(Column::INTEGER,'bigint');
	});

	should("map decimals", function() {
		assert_mapped_type(Column::DECIMAL,'float');
		assert_mapped_type(Column::DECIMAL,'double');
		assert_mapped_type(Column::DECIMAL,'numeric');
		assert_mapped_type(Column::DECIMAL,'dec');
	});

	should("map strings", function() {
		assert_mapped_type(Column::STRING,'string');
		assert_mapped_type(Column::STRING,'varchar');
		assert_mapped_type(Column::STRING,'text');
	});

	should("map unknown types to string", function() {
		assert_mapped_type(Column::STRING,'bajdslfjasklfjlksfd');
	});

	should("change integer to int", function() {
		transient()->column->raw_type = 'integer';
		transient()->column->map_raw_type();
		assert_equals('int',transient()->column->raw_type);
	});

	should("typecast values", function() {
		assert_cast(Column::INTEGER,1,'1');
		assert_cast(Column::INTEGER,1,'1.5');
		assert_cast(Column::DECIMAL,1.5,'1.5');
		assert_cast(Column::DATETIME,new \DateTime('2001-01-01'),'2001-01-01');
		assert_cast(Column::DATE,new \DateTime('2001-01-01'),'2001-01-01');
		assert_cast(Column::STRING,'bubble tea','bubble tea');
	});

	should("not try and cast null values", function() {
		$types = array(
			Column::STRING,
			Column::INTEGER,
			Column::DECIMAL,
			Column::DATETIME,
			Column::DATE);

		foreach ($types as $type)
			assert_cast($type,null,null);
	});
});
?>