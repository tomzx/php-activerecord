<?php
require_once 'Log.php';
require_once 'PHPUnit/Framework/TestCase.php';
require_once 'SnakeCase_PHPUnit_Framework_TestCase.php';
require_once 'DatabaseTest.php';
require_once 'AdapterTest.php';
require_once dirname(__FILE__) . '/../../ActiveRecord.php';

\I18n\I18n::push_load_path(dirname(__FILE__) . '/../locales/en.yml');

$GLOBALS['ACTIVERECORD_LOGGER'] = Log::singleton('file', dirname(__FILE__) . '/../log/query.log','ident',array('mode' => 0664, 'timeFormat' =>  '%Y-%m-%d %H:%M:%S'));

if (getenv('LOG') !== 'false')
	DatabaseTest::$log = true;

ActiveRecord\Config::initialize(function($cfg)
{
	$cfg->set_model_directory(realpath(dirname(__FILE__) . '/../models'));
	$cfg->set_connections(array(
		'mysql'		=> 'mysql://root:root@127.0.0.1/ar_test',
//		'pgsql'		=> 'pgsql://test:test@127.0.0.1/test',
//		'oci'		=> 'oci://test:test@127.0.0.1/xe',
//		'sqlite'	=> 'sqlite://test.db'));
));
	$cfg->set_default_connection('mysql');
});
?>
