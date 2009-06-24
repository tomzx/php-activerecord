<?php
namespace Smoothie;

include 'helpers/config.php';
require_once dirname(__FILE__) . '/../lib/Inflector.php';

context("Inflector", function() {
	setup(function() { transient()->inflector = \ActiveRecord\Inflector::instance(); });

	should("variablize", function() {
		assert_equals("rm_name_bob", transient()->inflector->variablize("rm--name  bob"));
	});

	should("underscorify", function() {
		assert_equals('One_Two_Three', transient()->inflector->underscorify("OneTwoThree"));
	});

	context("tableize", function() {
		should("a camel cased string", function() {
			assert_equals("angry_people", transient()->inflector->tableize("AngryPerson"));
		});

		should("a camel cased string with consecutive caps", function() {
			assert_equals("my_sqlservers", transient()->inflector->tableize("MySQLServer"));
		});
	});
});
?>