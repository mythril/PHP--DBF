<?php
require "DBF.class.php";

class DBFTest extends PHPUnit_Framework_TestCase {
	public function testToLogical() {
		$this->assertEquals('T', DBF::toLogical('T'));
		$this->assertEquals('T', DBF::toLogical(true));
		$this->assertEquals('F', DBF::toLogical('F'));
		$this->assertEquals('F', DBF::toLogical(false));
		$this->assertEquals(' ', DBF::toLogical(' '));
		$this->assertEquals(' ', DBF::toLogical(array()));
		$this->assertEquals(' ', DBF::toLogical('TT'));
		$this->assertEquals(' ', DBF::toLogical('t'));
		$this->assertEquals(' ', DBF::toLogical(0));
		$this->assertEquals(' ', DBF::toLogical(1));
		$this->assertEquals(' ', DBF::toLogical('FF'));
		$this->assertEquals(' ', DBF::toLogical('f'));
		$this->assertEquals(' ', DBF::toLogical('false'));
		$this->assertEquals(' ', DBF::toLogical('true'));
		$this->assertEquals(' ', DBF::toLogical(new stdClass()));
	}
	
	public function testToDate() {
		$this->assertEquals('20111024', DBF::toDate(strtotime('Mon Oct 24 09:48:33 PDT 2011')));
		$this->assertEquals('20111024', DBF::toDate(getdate(strtotime('Mon Oct 24 09:48:33 PDT 2011'))));
		$this->assertEquals('20111024', DBF::toDate('20111024'));
	}
	
	public function testToTimeStampDate() {
		//does not test milleseconds calculation, because I have no idea what is up with that
		$timestamp = "\x18\x79\x25\x00";//"\xC0\xD2\x1F\x02"; //09/27/11 06:54 PM
		$this->assertEquals(substr($timestamp, 0, 4), substr(DBF::toTimeStamp(strtotime('09/27/11 06:54 PM')), 0, 4));
	}
	
	public function testToTimeStampMS() {
		//fails, needs to be corrected.
		$timestamp = "\x18\x79\x25\x00\xC0\xD2\x1F\x02"; //09/27/11 06:54 PM
		$this->assertEquals($timestamp, DBF::toTimeStamp(strtotime('09/27/11 06:54 PM')));
	}
}

