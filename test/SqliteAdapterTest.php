<?php
include 'helpers/config.php';
require_once dirname(__FILE__) . '/../lib/adapters/SqliteAdapter.php';

class SqliteAdapterTest extends AdapterTest
{
	public function setUp($connection_name=null)
	{
		if (ActiveRecord\Config::instance()->get_connection('sqlite') === null)
			$this->mark_test_skipped('SQLite connection not defined in config file.');

		parent::setUp('sqlite');
	}

	public function tearDown()
	{
		parent::tearDown();

		@unlink($this->db);
		@unlink(self::InvalidDb);
	}

	public function testConnectToInvalidDatabaseShouldNotCreateDbFile()
	{
		try
		{
			ActiveRecord\Connection::instance("sqlite://" . self::InvalidDb);
			$this->assertFalse(true);
		}
		catch (ActiveRecord\DatabaseException $e)
		{
			$this->assertFalse(file_exists(dirname(__FILE__) . "/" . self::InvalidDb));
		}
	}

	// not supported
	public function testCompositeKey() {}
	public function testConnectWithPort() {}
}
?>