<?php
/**
 * These two classes have been <i>heavily borrowed</i> from Ruby on Rails' ActiveRecord so much that
 * this piece can be considered a straight port. The reason for this is that the vaildation process is
 * tricky due to order of operations/events. The former combined with PHP's odd typecasting means
 * that it was easier to formulate this piece base on the rails code.
 *
 * @package ActiveRecord
 */

namespace ActiveRecord;
use ActiveRecord\Model;
use IteratorAggregate;
use ArrayIterator;
use Closure;
use I18n\I18n;

/**
 * Manages validations for a {@link Model}.
 *
 * This class isn't meant to be directly used. Instead you define
 * validators thru static variables in your {@link Model}. Example:
 *
 * <code>
 * class Person extends ActiveRecord\Model {
 *   static $validates_length_of = array(
 *     array('name', 'within' => array(30,100),
 *     array('state', 'is' => 2)
 *   );
 * }
 *
 * $person = new Person();
 * $person->name = 'Tito';
 * $person->state = 'this is not two characters';
 *
 * if (!$person->is_valid())
 *   print_r($person->errors);
 * </code>
 *
 * @package ActiveRecord
 * @see Errors
 * @link http://www.phpactiverecord.org/guides/validations
 */
class Validations
{
	private $model;
	private $options = array();
	private $validators = array();
	private $record;

	private static $VALIDATION_FUNCTIONS = array(
		'validates_presence_of',
		'validates_size_of',
		'validates_length_of',
		'validates_inclusion_of',
		'validates_exclusion_of',
		'validates_format_of',
		'validates_numericality_of',
		'validates_uniqueness_of'
	);

	private static $DEFAULT_VALIDATION_OPTIONS = array(
		'on' => 'save',
		'allow_null' => false,
		'allow_blank' => false,
		'message' => null,
	);

	private static  $ALL_RANGE_OPTIONS = array(
		'is' => null,
		'within' => null,
		'in' => null,
		'minimum' => null,
		'maximum' => null,
	);

	private static $ALL_NUMERICALITY_CHECKS = array(
		'greater_than' => null,
		'greater_than_or_equal_to'  => null,
		'equal_to' => null,
		'less_than' => null,
		'less_than_or_equal_to' => null,
		'odd' => null,
		'even' => null
	);

	/**
	 * Constructs a {@link Validations} object.
	 *
	 * @param Model $model The model to validate
	 * @return Validations
	 */
	public function __construct(Model $model)
	{
		$this->model = $model;
		$this->record = new Errors($this->model);
		$this->validators = array_intersect(array_keys(Reflections::instance()->get(get_class($this->model))->getStaticProperties()), self::$VALIDATION_FUNCTIONS);
	}

	/**
	 * Returns validator data.
	 *
	 * @return array
	 */
	public function rules()
	{
		$data = array();
		$reflection = Reflections::instance()->get(get_class($this->model));

		foreach ($this->validators as $validate)
		{
			$attrs = $reflection->getStaticPropertyValue($validate);

			foreach ($attrs as $attr)
			{
				$field = $attr[0];

				if (!is_array($data[$field]))
					$data[$field] = array();

				$attr['validator'] = $validate;
				unset($attr[0]);
				array_push($data[$field],$attr);
			}
		}
		return $data;
	}

	/**
	 * Runs the validators.
	 *
	 * @return Errors the validation errors if any
	 */
	public function validate()
	{
		$reflection = Reflections::instance()->get(get_class($this->model));

		foreach ($this->validators as $validate)
			$this->$validate($reflection->getStaticPropertyValue($validate));

		return $this->record;
	}

	/**
	 * Validates a field is not null and not blank.
	 *
	 * <code>
	 * class Person extends ActiveRecord\Model {
	 *   static $validates_presence_of = array(
	 *     array('first_name'),
	 *     array('last_name')
	 *   );
	 * }
	 * </code>
	 *
	 * Available options:
	 *
	 * <ul>
	 * <li><b>message:</b> custom error message</li>
	 * </ul>
	 *
	 * @param array $attrs Validation definition
	 */
	public function validates_presence_of($attrs)
	{
		$configuration = array_merge(self::$DEFAULT_VALIDATION_OPTIONS, array('on' => 'save'));

		foreach ($attrs as $attr)
		{
			$options = array_merge($configuration, $attr);
			$this->record->add_on_blank($options[0], $options['message']);
		}
	}

	/**
	 * Validates that a value is included the specified array.
	 *
	 * <code>
	 * class Car extends ActiveRecord\Model {
	 *   static $validates_inclusion_of = array(
	 *     array('fuel_type', 'in' => array('hyrdogen', 'petroleum', 'electric')),
	 *   );
	 * }
	 * </code>
	 *
	 * Available options:
	 *
	 * <ul>
	 * <li><b>in/within:</b> attribute should/shouldn't be a value within an array</li>
	 * <li><b>message:</b> custome error message</li>
	 * </ul>
	 *
	 * @param array $attrs Validation definition
	 */
	public function validates_inclusion_of($attrs)
	{
		$this->validates_inclusion_or_exclusion_of('inclusion', $attrs);
	}

	/**
	 * This is the opposite of {@link validates_include_of}.
	 *
	 * @param array $attrs Validation definition
	 * @see validates_inclusion_of
	 */
	public function validates_exclusion_of($attrs)
	{
		$this->validates_inclusion_or_exclusion_of('exclusion', $attrs);
	}

	/**
	 * Validates that a value is in or out of a specified list of values.
	 *
	 * @see validates_inclusion_of
	 * @see validates_exclusion_of
	 * @param string $type Either inclusion or exclusion
	 * @param $attrs Validation definition
	 */
	public function validates_inclusion_or_exclusion_of($type, $attrs)
	{
		$configuration = array_merge(self::$DEFAULT_VALIDATION_OPTIONS, array('on' => 'save'));

		foreach ($attrs as $attr)
		{
			$options = array_merge($configuration, $attr);
			$attribute = $options[0];
			$var = $this->model->$attribute;

			if (isset($options['in']))
				$enum = $options['in'];
			elseif (isset($options['within']))
				$enum = $options['within'];

			if (!is_array($enum))
				array($enum);

			if ($this->is_null_with_option($var, $options) || $this->is_blank_with_option($var, $options))
				continue;

			if (('inclusion' == $type && !in_array($var, $enum)) || ('exclusion' == $type && in_array($var, $enum)))
				$this->record->add($attribute, $type, array('default' => $options['message'], 'value' => $var));
		}
	}

	/**
	 * Validates that a value is numeric.
	 *
	 * <code>
	 * class Person extends ActiveRecord\Model {
	 *   static $validates_numericality_of = array(
	 *     array('salary', 'greater_than' => 19.99, 'less_than' => 99.99)
	 *   );
	 * }
	 * </code>
	 *
	 * Available options:
	 *
	 * <ul>
	 * <li><b>integer_only:</b> value must be an integer (e.g. not a float)</li>
	 * <li><b>even:</b> must be even</li>
	 * <li><b>odd:</b> must be odd"</li>
	 * <li><b>greater_than:</b> must be greater than specified number</li>
	 * <li><b>greater_than_or_equal_to:</b> must be greater than or equal to specified number</li>
	 * <li><b>equal_to:</b> ...</li>
	 * <li><b>less_than:</b> ...</li>
	 * <li><b>less_than_or_equal_to:</b> ...</li>
	 * </ul>
	 *
	 * @param array $attrs Validation definition
	 */
	public function validates_numericality_of($attrs)
	{
		$configuration = array_merge(self::$DEFAULT_VALIDATION_OPTIONS, array('only_integer' => false));

		// Notice that for fixnum and float columns empty strings are converted to nil.
		// Validates whether the value of the specified attribute is numeric by trying to convert it to a float with Kernel.Float
		// (if only_integer is false) or applying it to the regular expression /\A[+\-]?\d+\Z/ (if only_integer is set to true).
		foreach ($attrs as $attr)
		{
			$options = array_merge($configuration, $attr);
			$attribute = $options[0];
			$var = $this->model->$attribute;

			$numericalityOptions = array_intersect_key(self::$ALL_NUMERICALITY_CHECKS, $options);

			if ($this->is_null_with_option($var, $options))
				continue;

			if (true === $options['only_integer'] && !is_integer($var))
			{
				if (preg_match('/\A[+-]?\d+\Z/', (string)($var)))
					break;

				// if (isset($options['message']))
				// 					$message = $options['message'];
				// 				else
				// 					$message = Errors::$DEFAULT_ERROR_MESSAGES['not_a_number'];

				$this->record->add($attribute, 'not_a_number', array('default' => $options['message'], 'value' => $var));
				continue;
			}
			else
			{
				if (!is_numeric($var))
				{
					$this->record->add($attribute, 'not_a_number', array('default' => $options['message'], 'value' => $var));
					continue;
				}

				$var = (float)$var;
			}

			foreach ($numericalityOptions as $option => $check)
       		{
				$option_value = $options[$option];

				if ('odd' != $option && 'even' != $option)
				{
					$option_value = (float)$options[$option];

					if (!is_numeric($option_value))
						throw new  ValidationsArgumentError("$option must be a number");

					// if (isset($options['message']))
					// 	$message = $options['message'];
					// else
					// 	$message = Errors::$DEFAULT_ERROR_MESSAGES[$option];

					// $message = str_replace('%d', $option_value, $message);

					if ('greater_than' == $option && !($var > $option_value))
						$this->record->add($attribute, $option, array('default' => $options['message'], 'value' => $var, 'count' => $option_value));

					elseif ('greater_than_or_equal_to' == $option && !($var >= $option_value))
						$this->record->add($attribute, $option, array('default' => $options['message'], 'value' => $var, 'count' => $option_value));

					elseif ('equal_to' == $option && !($var == $option_value))
						$this->record->add($attribute, $option, array('default' => $options['message'], 'value' => $var, 'count' => $option_value));

					elseif ('less_than' == $option && !($var < $option_value))
						$this->record->add($attribute, $option, array('default' => $options['message'], 'value' => $var, 'count' => $option_value));

					elseif ('less_than_or_equal_to' == $option && !($var <= $option_value))
						$this->record->add($attribute, $option, array('default' => $options['message'], 'value' => $var, 'count' => $option_value));
				}
				else
				{
					// if (isset($options['message']))
					// 	$message = $options['message'];
					// else
					// 	$message = Errors::$DEFAULT_ERROR_MESSAGES[$option];

					if (('odd' == $option && !( Utils::is_odd($var))) || ('even' == $option && (Utils::is_odd($var))))
						$this->record->add($attribute, $option, array('default' => $options['message'], 'value' => $var, 'count' => $option_value));
				}
			}
		}
	}

	/**
	 * Alias of {@link validates_length_of}
	 *
	 * @param array $attrs Validation definition
	 */
	public function validates_size_of($attrs)
	{
		$this->validates_length_of($attrs);
	}

	/**
	 * Validates that a value is matches a regex.
	 *
	 * <code>
	 * class Person extends ActiveRecord\Model {
	 *   static $validates_format_of = array(
	 *     array('email', 'with' => '/^.*?@.*$/')
	 *   );
	 * }
	 * </code>
	 *
	 * Available options:
	 *
	 * <ul>
	 * <li><b>with:</b> a regular expression</li>
	 * <li><b>message:</b> custom error message</li>
	 * </ul>
	 *
	 * @param array $attrs Validation definition
	 */
	public function validates_format_of($attrs)
	{
		$configuration = array_merge(self::$DEFAULT_VALIDATION_OPTIONS, array('on' => 'save', 'with' => null));

		foreach ($attrs as $attr)
		{
			$options = array_merge($configuration, $attr);
			$attribute = $options[0];
			$var = $this->model->$attribute;

			if (is_null($options['with']) || !is_string($options['with']) || !is_string($options['with']))
				throw new ValidationsArgumentError('A regular expression must be supplied as the [with] option of the configuration array.');
			else
				$expression = $options['with'];

			if ($this->is_null_with_option($var, $options) || $this->is_blank_with_option($var, $options))
				continue;

			if (!@preg_match($expression, $var))
				$this->record->add($attribute, 'invalid', array('default' => $options['message'], 'value' => $var));
		}
	}

	/**
	 * Validates the length of a value.
	 *
	 * <code>
	 * class Person extends ActiveRecord\Model {
	 *   static $validates_length_of = array(
	 *     array('name', 'within' => array(1,50))
	 *   );
	 * }
	 * </code>
	 *
	 * Available options:
	 *
	 * <ul>
	 * <li><b>is:</b> attribute should be exactly n characters long</li>
	 * <li><b>in/within:</b> attribute should be within an range array(min,max)</li>
	 * <li><b>maximum/minimum:</b> attribute should not be above/below respectively</li>
	 * </ul>
	 *
	 * @param array $attrs Validation definition
	 */
	public function validates_length_of($attrs)
	{
		$configuration = array_merge(self::$DEFAULT_VALIDATION_OPTIONS);

		foreach ($attrs as $attr)
		{
			$options = array_merge($configuration, $attr);
			$range_options = array_intersect(array_keys(self::$ALL_RANGE_OPTIONS), array_keys($attr));
			sort($range_options);

			switch (sizeof($range_options))
			{
				case 0:
					throw new  ValidationsArgumentError('Range unspecified.  Specify the [within], [maximum], or [is] option.');

				case 1:
					break;

				default:
					throw new  ValidationsArgumentError('Too many range options specified.  Choose only one.');
			}

			$attribute = $options[0];
			$var = $this->model->$attribute;
			$range_option = $range_options[0];
			if (isset($options['message'])) {
				$custom_message = $options['message'];
			} else {
				// todo find correct custom message
				$custom_message = null;
			}

			if ($this->is_null_with_option($var, $options) || $this->is_blank_with_option($var, $options))
				continue;

			if ('within' == $range_option || 'in' == $range_option)
			{
				$range = $options[$range_options[0]];

				if (!(Utils::is_a('range', $range)))
					throw new  ValidationsArgumentError("$range_option must be an array composing a range of numbers with key [0] being less than key [1]");

				if (is_float($range[0]) || is_float($range[1]))
					throw new  ValidationsArgumentError("Range values cannot use floats for length.");

				if ((int)$range[0] <= 0 || (int)$range[1] <= 0)
					throw new  ValidationsArgumentError("Range values cannot use signed integers.");

				// $too_short = isset($options['message']) ? $options['message'] : $options['too_short'];
				// $too_long =  isset($options['message']) ? $options['message'] : $options['too_long'];

				// $too_short = str_replace('%d', $range[0], $too_short);
				// $too_long = str_replace('%d', $range[1], $too_long);

				if (strlen($this->model->$attribute) < (int)$range[0])
					$this->record->add($attribute, 'too_short', array('default' => $custom_message, 'count' => $range[0]));
				elseif (strlen($this->model->$attribute) > (int)$range[1])
					$this->record->add($attribute, 'too_long', array('default' => $custom_message, 'count' => $range[1]));
			}

			elseif ('is' == $range_option || 'minimum' == $range_option || 'maximum' == $range_option)
			{
				$option = $options[$range_option];

				if ((int)$option <= 0)
					throw new  ValidationsArgumentError("$range_option value cannot use a signed integer.");

				if (is_float($option))
					throw new  ValidationsArgumentError("$range_option value cannot use a float for length.");

				if (!is_null($this->model->$attribute))
				{
					// $messageOptions = array('is' => 'wrong_length', 'minimum' => 'too_short', 'maximum' => 'too_long');

					// if (isset($options[$messageOptions[$range_option]]))
					// 	$message = $options[$messageOptions[$range_option]];
					// else
					// 	$message = $options['message'];

					// $message = str_replace('%d', $option, $message);
					$attribute_value = $this->model->$attribute;
					$len = strlen($attribute_value);
					$value = (int)$attr[$range_option];

					if ('maximum' == $range_option && $len > $value)
						$this->record->add($attribute, 'too_long', array('default' => $custom_message, 'count' => $value));

					if ('minimum' == $range_option && $len < $value)
						$this->record->add($attribute, 'too_short', array('default' => $custom_message, 'count' => $value));

					if ('is' == $range_option && $len !== $value)
						$this->record->add($attribute, 'is', array('default' => $custom_message, 'count' => $value));
				}
			}
		}
	}

	/**
	 * Validates the uniqueness of a value.
	 *
	 * <code>
	 * class Person extends ActiveRecord\Model {
	 *   static $validates_uniqueness_of = array(
	 *     array('name'),
	 *     array(array('blah','bleh'), 'message' => 'blech')
	 *   );
	 * }
	 * </code>
	 *
	 * @param array $attrs Validation definition
	 */
	public function validates_uniqueness_of($attrs)
	{
		$configuration = array_merge(self::$DEFAULT_VALIDATION_OPTIONS);

		foreach ($attrs as $attr)
		{
			$options = array_merge($configuration, $attr);
			$pk = $this->model->get_primary_key();
			$pk_value = $this->model->$pk[0];

			if (is_array($options[0]))
			{
				$add_record = join("_and_", $options[0]);
				$fields = $options[0];
			}
			else
			{
				$add_record = $options[0];
				$fields = array($options[0]);
			}

			$sql = "";
			$conditions = array("");

			if ($pk_value === null)
				$sql = "{$pk[0]} is not null";
			else
			{
				$sql = "{$pk[0]}!=?";
				array_push($conditions,$pk_value);
			}

			foreach ($fields as $field)
			{
				$sql .= " and {$field}=?";
				array_push($conditions,$this->model->$field);
			}

			$conditions[0] = $sql;

			if ($this->model->exists(array('conditions' => $conditions)))
				$this->record->add($add_record, 'taken', array('default' => $custom_message, 'value' => $this->model->$attr));
		}
	}

	private function is_null_with_option($var, &$options)
	{
		return (is_null($var) && (isset($options['allow_null']) && $options['allow_null']));
	}

	private function is_blank_with_option($var, &$options)
	{
		return (Utils::is_blank($var) && (isset($options['allow_blank']) && $options['allow_blank']));
	}
}

/**
 * Class that holds {@link Validations} errors.
 *
 * @package ActiveRecord
 */
class Error
{
	// private $model;
	// private $errors;
	//
	// public static $DEFAULT_ERROR_MESSAGES = array(
	//    		'inclusion'		=> "is not included in the list",
	//      	'exclusion'		=> "is reserved",
	//       	'invalid'		=> "is invalid",
	//       	'confirmation'	=> "doesn't match confirmation",
	//       	'accepted'		=> "must be accepted",
	//       	'empty'			=> "can't be empty",
	//       	'blank'			=> "can't be blank",
	//       	'too_long'		=> "is too long (maximum is %d characters)",
	//       	'too_short'		=> "is too short (minimum is %d characters)",
	//       	'wrong_length'	=> "is the wrong length (should be %d characters)",
	//       	'taken'			=> "has already been taken",
	//       	'not_a_number'	=> "is not a number",
	//       	'greater_than'	=> "must be greater than %d",
	//       	'equal_to'		=> "must be equal to %d",
	//       	'less_than'		=> "must be less than %d",
	//       	'odd'			=> "must be odd",
	//       	'even'			=> "must be even",
	// 	'unique'		=> "must be unique",
	//       	'less_than_or_equal_to' => "must be less than or equal to %d",
	//       	'greater_than_or_equal_to' => "must be greater than or equal to %d"
	//    	);
	//
	//    	/**
	//  * Constructs an {@link Errors} object.
	//  *
	//  * @param Model $model The model the error is for
	//  * @return Errors
	//    	 */
	// public function __construct(Model $model)
	// {
	// 	$this->model = $model;
	// }
	//
	// /**
	//  * Add an error message.
	//  *
	//  * @param string $attribute Name of an attribute on the model
	//  * @param string $msg The error message
	//  */
	// public function add($attribute, $msg)
	// {
	// 	if (is_null($msg))
	// 		$msg = self :: $DEFAULT_ERROR_MESSAGES['invalid'];
	//
	// 	if (!isset($this->errors[$attribute]))
	// 		$this->errors[$attribute] = array($msg);
	// 	else
	// 		$this->errors[$attribute][] = $msg;
	// }
	//
	// /**
	//  * Adds an error message only if the attribute value is {@link http://www.php.net/empty empty}.
	//  *
	//  * @param string $attribute Name of an attribute on the model
	//  * @param string $msg The error message
	//  */
	// public function add_on_empty($attribute, $msg)
	// {
	// 	if (empty($msg))
	// 		$msg = self::$DEFAULT_ERROR_MESSAGES['empty'];
	//
	// 	if (empty($this->model->$attribute))
	// 		$this->add($attribute, $msg);
	// }
	//
	// /**
	//  * Retrieve error message for an attribute.
	//  *
	//  * @param string $attribute Name of an attribute on the model
	//  * @return string
	//  */
	// public function __get($attribute)
	// {
	// 	if (!isset($this->errors[$attribute]))
	// 		return null;
	//
	// 	return $this->errors[$attribute];
	// }
	//
	// /**
	//  * Adds the error message only if the attribute value was null or an empty string.
	//  *
	//  * @param string $attribute Name of an attribute on the model
	//  * @param string $msg The error message
	//  */
	// public function add_on_blank($attribute, $msg)
	// {
	// 	if (!$msg)
	// 		$msg = self::$DEFAULT_ERROR_MESSAGES['blank'];
	//
	// 	if (!$this->model->$attribute)
	// 		$this->add($attribute, $msg);
	// }
	//
	// /**
	//  * Returns true if the specified attribute had any error messages.
	//  *
	//  * @param string $attribute Name of an attribute on the model
	//  * @return boolean
	//  */
	// public function is_invalid($attribute)
	// {
	// 	return isset($this->errors[$attribute]);
	// }
	//
	// /**
	//  * Returns the error message for the specified attribute or null if none.
	//  *
	//  * @param string $attribute Name of an attribute on the model
	//  * @return string
	//  */
	// public function on($attribute)
	// {
	// 	if (!isset($this->errors[$attribute]))
	// 		return null;
	//
	// 	$errors = $this->errors[$attribute];
	//
	// 	if (null === $errors)
	// 		return null;
	// 	else
	// 		return count($errors) == 1 ? $errors[0] : $errors;
	// }
	//
	// /**
	//  * Returns all the error messages as an array.
	//  *
	//  * <code>
	//  * $model->errors->full_messages();
	//  *
	//  * # array(
	//  * #  "Name can't be blank",
	//  * #  "State is the wrong length (should be 2 chars)"
	//  * # )
	//  * </code>
	//  *
	//  * @param array $options Options for messages
	//  * @return array
	//  */
	// public function full_messages()
	// {
	// 	// $full_messages = array();
	// 	//
	// 	// if ($this->errors)
	// 	// {
	// 	// 	foreach ($this->errors as $attribute => $messages)
	// 	// 	{
	// 	// 		foreach ($messages as $msg)
	// 	// 		{
	// 	// 			if (is_null($msg))
	// 	// 				continue;
	// 	//
	// 	// 			$full_messages[] = Utils::human_attribute($attribute) . ' ' . $msg;
	// 	// 		}
	// 	// 	}
	// 	// }
	// 	// return $full_messages;
	// }
	//
	// /**
	//  * Returns true if there are no error messages.
	//  * @return boolean
	//  */
	// public function is_empty()
	// {
	// 	return empty($this->errors);
	// }
	//
	// /**
	//  * Clears out all error messages.
	//  */
	// public function clear()
	// {
	// 	$this->errors = array();
	// }
	//
	// /**
	//  * Returns the number of error messages there are.
	//  * @return int
	//  */
	// public function size()
	// {
	// 	if ($this->is_empty())
	// 		return 0;
	//
	// 	$count = 0;
	//
	// 	foreach ($this->errors as $attribute => $error)
	// 		$count += count($error);
	//
	// 	return $count;
	// }
	//
	// /**
	//  * Returns an iterator to the error messages.
	//  *
	//  * This will allow you to iterate over the {@link Errors} object using foreach.
	//  *
	//  * <code>
	//  * foreach ($model->errors as $msg)
	//  *   echo "$msg\n";
	//  * </code>
	//  *
	//  * @return ArrayIterator
	//  */
	// public function getIterator()
	// {
	// 	return new ArrayIterator($this->full_messages());
	// }

	/**
	 * The model that is related to the error.
	 *
	 * @var string
	 */
	private $base;
	/**
	 * The attribute within the model that contains an error.
	 *
	 * @var string
	 */
	private $attribute;
	/**
	 * The kind of error there is with the model.
	 * Example: too short, too long, etc.
	 *
	 * @var string
	 */
	public $type;
	/**
	 * Same as type.
	 *
	 * @var string
	 */
	private $message;
	/**
	 * Options given at the time of error creation.
	 *
	 * @var string
	 */
	private $options;

	public function __construct(Model $base, $attribute, $type = null, $options = array())
	{
		$this->base = $base;
		$this->attribute = $attribute;
		if ($type !== null) {
			$this->type = $type;
		} else {
			$this->type = 'invalid';
		}
		$this->options = $options;
		if (isset($options['message'])) {
			$this->message = $options['message'];
			unset($options['message']);
		} else {
			$this->message = $type;
		}
	}

	public function message()
	{
		// if (is_string($this->type)) {
			// return $this->type;
		// } else {
			return $this->generate_message($this->default_options());
		// }
	}

	public function to_s()
	{
		return $this->message();
	}

	public function full_message()
	{
		// if ($this->attribute === 'base') {
			// return $this->message;
		// } else {
			return $this->generate_full_message($this->default_options());
		// }
	}

	public function value()
	{
		if (array_key_exists($attribute, $this->base)) {
			return $this->base[$attribute];
		} else {
			return null;
		}
	}

	private function generate_message($options = array())
	{
		$class_name = classify(get_class($this->base));

		$keys = array(
			"models.$class_name.attributes.{$this->attribute}.{$this->message}",
			"models.$class_name.{$this->message}",
			"messages.{$this->message}");

		if (isset($options['default'])) {
			$keys[] = $options['default'];
		}

		$options['default'] = $keys;
		return I18n::translate(array_shift($keys), $options);
	}

	private function generate_full_message($options = array())
	{
		$class_name = classify(get_class($this->base));

		// uses symbols, so I'm not sure
		$keys = array(
			"full_messages.{$this->message}",
			"full_messages.format",
			"{{attribute}} {{message}}");

		$options['default'] = $keys;
		$options['message'] = $this->message;
		return I18n::translate(array_shift($keys), $options);
	}

	private function default_options()
	{
		$options['scope'] = array('activerecord', 'errors');
		$options['model'] = classify(get_class($this->base));
		$options['attribute'] = Utils::human_attribute($this->attribute);
		$options['value'] = null;

		$options = array_merge($options, $this->options);

		return $options;
	}
};

class Errors
{
	private $errors;
	private $base;

	// public function default_error_messages()
	// {
	//
	// }

	public function __construct(Model $base)
	{
		$this->base = $base;
		$this->clear();
	}

	public function add_to_base($message)
	{
		$this->add($this->base, $message);
	}

	public function add($attribute, $message = null, $options = array())
	{
		// if ($message === null)
		// 	$msg = self::$DEFAULT_ERROR_MESSAGES['invalid'];
		if (isset($options['default'])) {
			$options['message'] = $options['default'];
			unset($options['default']);
		}

		if (is_a($message, 'Error')) {
			list($error, $message) = array($message, null);
		}

		$error = new Error($this->base, $attribute, $message, $options);

		// if (!isset($this->errors[$attribute]))
			// $this->errors[$attribute] = array($error);
		// else
		$this->errors[$attribute][] = $error;
	}

	public function add_on_empty($attributes, $custom_message = null)
	{
		$attributes = array($attributes);
		$attributes = array_flatten($attributes);

		foreach ($attributes as $attribute) {
			if (empty($this->model->$attribute)) {
				$this->add($attribute, 'empty', array('default' => $custom_message));
			}
		}

		// if ($custom_message === null)
			// $msg = self::$DEFAULT_ERROR_MESSAGES['empty'];
	}

	public function add_on_blank($attributes, $custom_message = null)
	{
		$attributes = array($attributes);
		$attributes = array_flatten($attributes);

		foreach ($attributes as $attribute) {
			if (!$this->base->$attribute) {
				$this->add($attribute, 'blank', array('default' => $custom_message));
			}
		}

		// if ($custom_message === null)
			// $msg = self::$DEFAULT_ERROR_MESSAGES['blank'];
	}

	public function is_invalid($attribute)
	{
		return isset($this->errors[$attribute]);
	}

	public function on($attribute)
	{

		if (!array_key_exists($attribute, $this->errors))
			return null;

		$errors = $this->errors[$attribute];

		if (null === $errors) {
			return null;
		} else {
			// print_r($errors);
			foreach ($errors as &$error) {
				$error = $error->to_s();
			}

			return count($errors) === 1 ? $errors[0] : $errors;
		}
	}

	public function on_base()
	{
		return $this->on($this->base);
	}

	public function _each(Closure $closure)
	{
		foreach ($this->errors as $attribute => $errors) {
			foreach ($errors as $error) {
				$closure($attribute, $error->message());
			}
		}
	}

	public function each_error(Closure $closure)
	{
		foreach ($this->errors as $attribute => $errors) {
			foreach ($errors as $error) {
				$closure($attribute, $error);
			}
		}
	}

	public function each_full(Closure $closure)
	{
		foreach ($this->full_messages() as $message) {
			$closure($message);
		}
	}

	public function full_messages($options = array())
	{
		// $full_messages = array();
		//
		foreach ($this->errors as $errors)
		{
			foreach ($errors as $error)
			{
				// if (is_null($msg))
				// 	continue;
				//
				// $full_messages[] = Utils::human_attribute($attribute) . ' ' . $msg;
				$full_messages[] = $error->full_message();
			}
		}
		return $full_messages;
	}

	public function is_empty()
	{
		return empty($this->errors);
	}

	public function clear()
	{
		$this->errors = array();
	}

	public function size()
	{
		$size = 0;
		foreach ($this->errors as $error) {
			$size += count($error);
		}
		return $size;
	}
}

?>