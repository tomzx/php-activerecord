<?php
namespace Smoothie;
use ActiveRecord as AR;

require_once 'TestHelper.php';

context("Utils", function() {
	context("collect", function() {
		context("with an array of objects", function() {
			setup(function() {
				$object_array = array();
				$object_array[0] = new \stdClass();
				$object_array[0]->a = "0a";
				$object_array[0]->b = "0b";
				$object_array[1] = new \stdClass();
				$object_array[1]->a = "1a";
				$object_array[1]->b = "1b";
				transient()->object_array = $object_array;
			});

			should("work using a closure", function() {
				assert_equals(array("0a","1a"),AR\collect(transient()->object_array,function($obj) { return $obj->a; }));
			});

			should("work using a string", function() {
				assert_equals(array("0a","1a"),AR\collect(transient()->object_array,"a"));
			});
		});

		context("with an array hash", function() {
			setup(function() {
				transient()->array_hash = array(
					array("a" => "0a", "b" => "0b"),
					array("a" => "1a", "b" => "1b"));
			});

			should("work using a closure", function() {
				assert_equals(array("0a","1a"),AR\collect(transient()->array_hash,function($item) { return $item["a"]; }));
			});

			should("work using a string", function() {
				assert_equals(array("0a","1a"),AR\collect(transient()->array_hash,"a"));
			});
		});
	});

	should("flatten an array", function() {
		assert_equals(array(), AR\array_flatten(array()));
		assert_equals(array(1), AR\array_flatten(array(1)));
		assert_equals(array(1), AR\array_flatten(array(array(1))));
		assert_equals(array(1, 2), AR\array_flatten(array(array(1, 2))));
		assert_equals(array(1, 2), AR\array_flatten(array(array(1), 2)));
		assert_equals(array(1, 2), AR\array_flatten(array(1, array(2))));
		assert_equals(array(1, 2, 3), AR\array_flatten(array(1, array(2), 3)));
		assert_equals(array(1, 2, 3, 4), AR\array_flatten(array(1, array(2, 3), 4)));
	});

	context("all", function() {
		should("return true if all elements in an array match", function() {
			assert_true(AR\all(null,array(null,null)));
			assert_true(AR\all(1,array(1,1)));
		});

		should("return false if all elements do not match", function() {
			assert_false(AR\all(1,array(1,'1')));
			assert_false(AR\all(null,array('',null)));
		});
	});

	context("classify", function() {
		should("work", function() {
			assert_equals("UbuntuRox", AR\classify("ubuntu_rox"));
			assert_equals("StopTheSnakeCase", AR\classify("stop_the_Snake_Case"));
			assert_equals("CamelCased", AR\classify("CamelCased"));
			assert_equals("CamelCased", AR\classify("camelCased"));
		});

		should("singularize it", function() {
			assert_equals("Event", AR\classify("events",true));
			assert_equals("HappyPerson", AR\classify("happy_People",true));
		});
	});
});
?>