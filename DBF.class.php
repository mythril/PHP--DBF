<?php
/**	Utility class to write complete DBF files. This is not a database interaction
  * layer, it treats the database file as an all-at-once output.
  * <pre>
  * Schemas:
  * 	schemas are supplied to this library as php arrays as follows:
  * 	array(
  * 		'name' => 'COLUMN B'
  * 		'type' => 'C',
  * 		'size' => 4,
  * 		'declength' => 2,
  * 		'NOCPTRANS' => true,
  * 	);
  * 
  * 	'name': column name, 10 characters max, padded with null bytes if less than 10, truncated if more than 10
  * 	'type' : a single character representing the DBF field type, the following are supported:
  * 		'C': character data stored as 8 bit ascii
  * 		'N': numeric character data stored as 8 bit ascii, when 'declength' is 
  * 			present, declength + 1 bytes are consumed to store the decimal values
  * 		'D': 8 character date specification (YYYYMMDD)
  * 			always 8 bytes
  * 		'L': single character boolean, 'T' or 'F' or a space ' ' for unintialized
  * 			always 1 byte
  * 		'T': 8 byte binary packed date stamp and milliseconds stamp
  * 			first four bytes: integer representing days since Jan 1 4713 (julian calendar)
  * 			second four bytes: integer milliseconds ellapsed since prior midnight
  * 			always 8 bytes
  * 
  * 	'size': number of bytes this field occupies
  * 	'declength': only applicable to type 'N', indicates how many of the alloted spaces are for
  * 		decimal places and the decimal point
  * 	'NOCPTRANS': Specifies the fields that should not be translated to another code page.
  * 
  * Records:
  * 	records are supplied as arrays keyed by column name with values conforming to these type specs:
  * 		'C': character data, will be truncated at field size, padded with spaces on the right to field size
  * 		'N': numeric character data, will be truncated at field size,
  * 			padded with spaces on the left to field size converts numeric 
  * 			native types to numeric character data automatically
  * 		'D': accepts a unix timestamp, a structure resembling 
  * 			<code>getdate()</code>'s return format, or a 8 length string
  * 			containing YYYYMMDD
  * 		'L': accepts and converts ('T' and true) to 'T', ('F' and false) to
  * 			'F' and everything else to ' '
  * 		'T': accepts a unix timestamp, a structure resembling 
  * 			<code>getdate()</code>'s return format or an array containing
  * 			'jd' => (julian date representation)
  * 			'js' => milleseconds elapsed since prior midnight
  * </pre>
  */
class DBF {
	// utility function to ouput a string of binary digits for inspection
	private static function binout($bin) {
		echo "data:", $bin, "<br />";
		foreach(unpack('C*', $bin) as $byte) {
			printf('%b', $byte);
		}
		echo "<br />";
		return $bin;
	}
	
	/**	Writes a DBF file to the provided location {@link $filename}, with a given
	  * {@link $schema} containing the DBF formatted <code>$records</code>
	  * marked with the 'last updated' mark <code>$date</code> or a current timestamp if last 
	  * update is not provided.
	  * @see DBF
	  * @param string $filename a writable path to place the DBF
	  * @param array $schema an array containing DBF field specifications for each 
	  * 	field in the DBF file (see <code>class DBF</code documentation)
	  * @param array $records an array of fields given in the same order as the 
	  * 	field specifications given in the schema
	  * @param array $date an array matching the return structure of <code>getdate()</code>
	  * 	or null to use the current timestamp
	  */
	public static function write($filename, array $schema, array $records, $date=null) {
		file_put_contents($filename, self::getBinary($schema, $records, $date));
	}
	
	/** Gets the DBF file as a binary string
	  * @see DBF::write()
	  * @return string a binary string containing the DBF file.
	  */
	public static function getBinary(array $schema, array $records, $pDate) {
		if (is_numeric($pDate)) {
			$date = getDate($pDate);
		} elseif ($pDate == null) {
			$date = getDate();
		} else {
			$date = $pDate;
		}
		return self::makeHeader($date, $schema, $records) . self::makeSchema($schema) . self::makeRecords($schema, $records);
	}
	
	/** Convert a unix timestamp, or the return structure of the <code>getdate()</code>
	  * function into a (binary) DBF timestamp.
	  * @param mixed $date a unix timestamp or the return structure of the <code>getdate()</code>
	  * @param number $milleseconds the number of milleseconds elapsed since 
	  * 	midnight on the day before the date in question. If omitted a second
	  * 	accurate rounding will be constructed from the $date parameter
	  * @return string a binary string containing the DBF formatted timestamp
	  */
	public static function toTimeStamp($date, $milleseconds = null) {
		if (is_array($date)) {
			if (isset($date['jd'])) {
				$jd = $date['jd'];
			}
		
			if (isset($date['js'])) {
				$js = $date['js'];
			}
		}
		
		if (!isset($jd)) {
			$pDate = self::toDate($date);
			$year = substr($pDate, 0, 4);
			$month = substr($pDate, 4, 2);
			$day = substr($pDate, 6, 2);
			$jd = gregoriantojd(intval($month), intval($day), intval($year));
		}
		
		if (!isset($js)) {
			if ($milleseconds === null) {
				if (is_numeric($date) || empty($date)) {
					$utime = getdate(intval($date));
				} else {
					$utime = $date;
				}
				
				$ms = (
					//FIXME: grumble grumble seems to be 9 hours off,
					// no idea where the 9 came from
					$utime['hours'] * 60 * 60 * 1000 + 
					$utime['minutes'] * 60 * 1000 +
					$utime['seconds'] * 1000
				);
				$js = $ms;
			} else {
				$js = $milleseconds;
			}
		}
		
		return (pack('V', $jd)) . (pack('V', $js));
	}
	
	/** Converts a unix timestamp to the type of date expected by this file writer.
	  * @param integer $timestamp a unix timestamp, or the return format of <code>getdate()</code>
	  * @return string a date formatted to DBF expectations (8 byte string: YYYYMMDD);
	  */
	public static function toDate($timestamp) {
		if (empty($timestamp)) {
			$timestamp = 0;
		}
		
		if (!is_numeric($timestamp) && !is_array($timestamp)) {
			throw new InvalidArgumentException('$timestamp was not in expected format(s).');
		}
		
		if (is_array($timestamp) && (!isset($timestamp['year']) || !isset($timestamp['mon']) || !isset($timestamp['mday']))) {
			throw new InvalidArgumentException('$timestamp array did not contain expected key(s).');
		}
		
		if (is_string($timestamp) && strlen($timestamp) === 8 && self::validate_date_string($timestamp)) {
			return $timestamp;
		}
		
		if (!is_array($timestamp)) {
			$date = getdate($timestamp);
		} else {
			$date = $timestamp;
		}
		
		return substr(str_pad($date['year'], 4, '0', STR_PAD_LEFT), 0, 4) .
			substr(str_pad($date['mon'], 2, '0', STR_PAD_LEFT), 0, 2) .
			substr(str_pad($date['mday'], 2, '0', STR_PAD_LEFT), 0, 2);
	}
	
	/** Convert a boolean value into DBF equivalent, preserving the meaning of 'T' or 'F'
	  * 	non-booleans will be converted to ' ' (unintialized) with the exception of
	  * 	'T' or 'F', which will be kept as is.
	  * 	booleans will be converted to their respective meanings (true = 'T', false = 'F')
	  * 	
	  * @param mixed $value value to be converted
	  * @return string length 1 string containing 'T', 'F' or uninitialized ' '
	  */
	public static function toLogical($value) {
		if ($value === 'F' || $value === false) {
			return 'F';
		}
		
		if (is_string($value)) {
			if (preg_match("#^\\ +$#", $value)) {
				return ' ';
			}
		}
		
		if ($value === 'T' || $value === true) {
			return 'T';
		}
		
		return ' ';
	}
	
	//calculates the size of a single record
	private static function getRecordSize($schema) {
		$size = 1;//FIXME: I have no idea why this is 1 instead of 0
		
		foreach ($schema as $field) {
			$size += $field['size'];
		}
		
		return $size;
	}
	
	//assembles a string into DBF format truncating and padding, where required
	private static function character($data, $fieldInfo) {
		return substr(str_pad(strval($data), $fieldInfo['size'], " "), 0, $fieldInfo['size']);
	}
	
	private static function validate_date_string($string) {
		$time = mktime (
			0,
			0,
			0,
			intval(substr($string, 4, 2)),
			intval(substr($string, 6, 2)),
			intval(substr($string, 0, 4))
		);
		if ($time === false || $time === -1) {
			return false;
		}
		return true;
	}
	
	//assembles a date into DBF format
	private static function date($data) {
		if (is_int($data)) {
			$tmp = strval($data);
			if (strlen($tmp) == 8 && self::validate_date_string($tmp)) {
				$data = $tmp;
			}
		}
		
		return self::toDate($data);
	}
	
	//assembles a number into DBF format, truncating and padding where required
	private static function numeric($data, $fieldInfo) {
		if (isset($fieldInfo['declength']) && $fieldInfo['declength'] > 0) {
			$cleaned = str_pad(number_format(floatval($data), $fieldInfo['declength']), $fieldInfo['size'], ' ', STR_PAD_LEFT);
		} else {
			$cleaned = str_pad(strval(intval($data)), $fieldInfo['size'], ' ', STR_PAD_LEFT);
		}
		return substr($cleaned, 0, $fieldInfo['size']);
	}
	
	//assembles a boolean into DBF format or ' ' for uninitialized
	private static function logical($data) {
		return self::toLogical($data);
	}
	
	//assembles a timestamp into DBF format 
	private static function timeStamp($data) {
		return self::toTimeStamp($data);
	}
	
	//assembles a single field 
	private static function makeField($data, $fieldInfo) {
		//FIXME: support all the types (that make sense)
		switch ($fieldInfo['type']) {
		case 'C':
			return self::character($data, $fieldInfo);
			break;
		case 'D':
			return self::date($data);
			break;
		case 'N':
			return self::numeric($data, $fieldInfo);
			break;
		case 'L':
			return self::logical($data);
			break;
		case 'T':
			return self::timeStamp($data);
			break;
		default:
			return "";
		}
	}
	
	//assembles a single record 
	private static function makeRecord($schema, $record) {
		$out = " ";
		
		//foreach($record as $column => $data) {
		foreach($schema as $column => $declaration) {
			//$out .= self::makeField($data, $schema[$column]);
			$out .= self::makeField(
				$record[$column],
				$declaration
			);
		}
		
		return $out;
	}
	
	//assembles all the records 
	private static function makeRecords($schema, $records) {
		$out = "";
		
		foreach ($records as $record) {
			$out .= self::makeRecord($schema, $record);
		}
		
		return $out . "\x1a"; //FIXME: I have no idea why the end of the file is marked with 0x1a
	}
	
	//assembles binary field definition
	private static function makeFieldDef($fieldDef, &$location) {
		//0+11
		$out = substr(str_pad($fieldDef['name'], 11, "\x00"), 0 , 11);
		//11+1
		$out .= substr($fieldDef['type'], 0, 1);
		//12+4
		$out .= (pack('V', $location));
		//16+1
		$out .= (pack('C', $fieldDef['size']));
		//17+1
		$out .= (pack('C', @$fieldDef['declength']));
		//18+1
		$out .= (pack('C', @$fieldDef['NOCPTRANS'] === true ? 4 : 0));
		//19+13
		$out .= (pack('x13'));
		
		
		$location += $fieldDef['size'];
		return $out;
	}
	
	//assembles binary schema header 
	private static function makeSchema($schema) {
		$out = "";
		$location = 1;//FIXME: explain why this is 1 instead of 0
		
		foreach ($schema as $key => $fieldDef) {
			$out .= self::makeFieldDef($fieldDef, $location);
		}
		
		$out .= (pack('C', 13)); // marks the end of the schema portion of the file
		
		$out .= str_repeat(chr(0), 263); //FIXME: I gues filenames are sometimes stored here
		
		return $out;
	}
	
	//makes partial file header
	private static function makeHeader($date, $schema, $records) {
		//0+1
		$out = (pack('C', 0x30)); // version 001; dbase 5
		//1+2
		$out .= (pack('C3', $date['year'] - 1900, $date['mon'], $date['mday']));
		//4+4
		$out .= (pack('V', count($records)));//number of records
		//8+2
		$out .= (pack('v', self::getTotalHeaderSize($schema))); //bytes in the header
		//10+2
		$out .= (pack('v', self::getRecordSize($schema))); //bytes in each record
		//12+17
		$out .= (pack('x17')); //reserved for zeros (unused)
		//29+1
		$out .= (pack('C', 3)); //FIXME: language? i have no idea
		//30+2
		$out .= (pack('x2')); //empty
		return $out;
	}
	
	//calculates the total size of the header, given the number of columns
	private static function getTotalHeaderSize($schema) {
		//file header is 32 bytes
		//field definitions are 32 bytes each
		//end of schema definition marker is 1 byte
		//263 extra bytes for file name 
		return (count($schema) * 32) + 32 + 1 + 263; 
	}
	
}

