<?php
require_once 'Log.php';
require_once dirname(__FILE__) . '/../ActiveRecord.php';

ActiveRecord\Config::initialize(function($cfg)
{
	$cfg->set_model_directory(realpath(dirname(__FILE__) . '/models'));
	$cfg->set_connections(array(
		'mysql'		=> 'mysql://test:test@127.0.0.1/test',
		'pgsql'		=> 'pgsql://test:test@127.0.0.1/test',
		'oci'		=> 'oci://test:test@127.0.0.1/xe',
		'sqlite'	=> 'sqlite://test.db'));
	$cfg->set_default_connection('mysql');
});
?>
