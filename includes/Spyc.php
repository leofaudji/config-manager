<?php
/**
 * Spyc -- A Simple PHP YAML Class
 * @version 0.6.3
 * @author Vlad Andersen <vlad.andersen@gmail.com>
 * @author Chris Wanstrath <chris@ozmm.org>
 * @link https://github.com/mustangostang/spyc/
 * @copyright Copyright 2005-2006 Chris Wanstrath, 2006-2011 Vlad Andersen
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

if (!function_exists('spyc_load')) {
  /**
   * Parses YAML to array.
   * @param string $string YAML string.
   * @return array
   */
  function spyc_load ($string) {
    return Spyc::YAMLLoadString($string);
  }
}

if (!function_exists('spyc_load_file')) {
  /**
   * Parses YAML to array.
   * @param string $file Path to YAML file.
   * @return array
   */
  function spyc_load_file ($file) {
    return Spyc::YAMLLoad($file);
  }
}

if (!function_exists('spyc_dump')) {
  /**
   * Dumps array to YAML.
   * @param array $data Array.
   * @return string
   */
  function spyc_dump ($data) {
    return Spyc::YAMLDump($data, false, false, true);
  }
}

/**
 * The Simple PHP YAML Class.
 *
 * This class can be used to read a YAML file and convert its contents
 * into a PHP array.  It currently supports a very limited subsection of
 * the YAML spec.
 *
 * A simple YAML file might look like this:
 * <code>
 * ---
 * group: staff
 * members:
 *   - Chris
 *   - Vlad
 *   - Time
 * ...
 * </code>
 *
 * Usage:
 * <code>
 *   $Spyc  = new Spyc;
 *   $array = $Spyc->load($file);
 * </code>
 *
 * or:
 * <code>
 *   $array = Spyc::YAMLLoad($file);
 * </code>
 *
 * or:
 * <code>
 *   $array = spyc_load_file($file);
 * </code>
 *
 * @package Spyc
 */
class Spyc {

  // SETTINGS

  /**
   * Setting this to true will force YAMLDump to use [] syntax for arrays.
   * @var bool
   */
  public $setting_dump_force_arrays = false;

  /**
   * Setting this to true will forse YAMLLoad to use native quoted string parsing
   * @var bool
   */
  public $setting_use_native_yaml_parser = false;

  /**
   * Setting this to true will forse YAMLLoad to use SYCK parser
   * @var bool
   */
  public $setting_use_syck_is_possible = false;


  /**
   * Setting this to true will forse YAMLLoad to use eval() to evaluate some values.
   * This is probably NOT a good idea.
   * @var bool
   */
  public $setting_use_eval = false;

  /**
   * Path to syck extension.
   * @var string
   */
  public $syck_path = 'syck.so';

  /**#@+
   * @access private
   * @var mixed
   */
  private $_dumpIndent;
  private $_dumpWordWrap;
  private $_containsGroupAnchor = false;
  private $_containsGroupAlias = false;
  private $path;
  private $result;
  private $LiteralPlaceHolder = '___YAML_Literal_Block___';
  private $SavedGroups = array();
  private $indent;
  /**
   * Path modifier that should be applied after adding current element.
   * @var array
   */
  private $path_mod;
  /**
   * @var bool
   */
  private $skip_group_alias_handling;
  /**#@+
   * @access public
   * @var mixed
   */
  public $_nodeId;

  /**
   * Load a YAML string to a PHP array.
   *
   * The load method, when supplied with a YAML stream (string or file),
   * will do its best to convert YAML in a file into a PHP array.  Pretty
   * simple.
   *  Usage:
   *  <code>
   *   $Spyc  = new Spyc;
   *   $array = $Spyc->load($file);
   *  </code>
   *  or:
   *  <code>
   *   $array = Spyc::YAMLLoad($file);
   *  </code>
   * @access public
   * @return array
   * @param string $input Path of YAML file or string containing YAML
   */
  public function load($input) {
    return self::YAMLLoad($input);
  }

  /**
   * Load a YAML string to a PHP array.
   *
   * The load method, when supplied with a YAML stream (string or file),
   * will do its best to convert YAML in a file into a PHP array.  Pretty
   * simple.
   *  Usage:
   *  <code>
   *   $Spyc  = new Spyc;
   *   $array = $Spyc->load($file);
   *  </code>
   *  or:
   *  <code>
   *   $array = Spyc::YAMLLoad($file);
   *  </code>
   * @access public
   * @return array
   * @param string $input Path of YAML file or string containing YAML
   */
  public static function YAMLLoad($input) {
    $Spyc = new Spyc;
    return $Spyc->YAMLLoadString($input);
  }

  /**
   * Load a YAML string to a PHP array.
   *
   * The load method, when supplied with a YAML stream (string or file),
   * will do its best to convert YAML in a file into a PHP array.  Pretty
   * simple.
   *  Usage:
   *  <code>
   *   $Spyc  = new Spyc;
   *   $array = $Spyc->load($file);
   *  </code>
   *  or:
   *  <code>
   *   $array = Spyc::YAMLLoad($file);
   *  </code>
   * @access public
   * @return array
   * @param string $input Path of YAML file or string containing YAML
   */
  public function YAMLLoadString($input) {
    $this->_nodeId = null;
    $this->path = array();
    $this->result = array();

    if (is_file($input)) {
      $this->path_mod = 'file';
      $input = file_get_contents($input);
    } else {
      $this->path_mod = 'string';
    }

    $this->indent = 0;
    $this->skip_group_alias_handling = false;

    if ($this->setting_use_syck_is_possible && function_exists('syck_load')) {
      $this->setting_use_native_yaml_parser = true;
      if (ini_get('safe_mode')) {
        $this->setting_use_eval = false;
      }
      dl($this->syck_path);
      $array = syck_load($input);
      return is_array($array) ? $array : array();
    }

    $lines = explode("\n", $input);
    foreach ($lines as $line) {
      $this->indent = 0;
      $line = rtrim($line, "\r\n");
      if (preg_match('/^\s*$/', $line)) {
        continue;
      }
      if (preg_match('/^#/', $line)) {
        continue;
      }
      $this->readLine($line);
    }
    return $this->result;
  }

  /**
   * Dumps a PHP array to a YAML string.
   *
   * The dump method, when supplied with an array, will do its best
   * to convert the array into friendly YAML.  Pretty simple.  Feel free to
   * save the returned string as nothing.yaml and pass it around.
   *
   * Oh, and it supports passing more than one array in a single call.
   *
   * @access public
   * @return string A YAML string
   * @param array $array PHP array
   * @param int $indent Pass a number of spaces to indent subsequent lines
   * @param int $wordwrap Pass a number of characters to wrap a line to
   * @param bool $no_opening_dashes Do not start YAML file with ---
   */
  public static function YAMLDump($array, $indent = false, $wordwrap = false) {
    $spyc = new Spyc;
    return $spyc->dump($array, $indent, $wordwrap);
  }


  /**
   * Dumps a PHP array to a YAML string.
   *
   * The dump method, when supplied with an array, will do its best
   * to convert the array into friendly YAML.  Pretty simple.  Feel free to
   * save the returned string as nothing.yaml and pass it around.
   *
   * Oh, and it supports passing more than one array in a single call.
   *
   * @access public
   * @return string A YAML string
   * @param array $array PHP array
   * @param int $indent Pass a number of spaces to indent subsequent lines
   * @param int $wordwrap Pass a number of characters to wrap a line to
   * @param bool $no_opening_dashes Do not start YAML file with ---
   */
  public function dump($array, $indent = false, $wordwrap = false) {
    // Dumps to some very clean YAML.  We'll have to add some more features
    // and options soon.  And better support for folding.

    // New features and options.
    if ($indent === false or !is_numeric($indent)) {
      $this->_dumpIndent = 2;
    } else {
      $this->_dumpIndent = $indent;
    }

    if ($wordwrap === false or !is_numeric($wordwrap)) {
      $this->_dumpWordWrap = 40;
    } else {
      $this->_dumpWordWrap = $wordwrap;
    }

    // New YAML document
    $string = "---\n";

    // Start at the base of the array and move through it.
    $string .= $this->yamlizeArray($array, 0);
    return str_replace($this->LiteralPlaceHolder, '', $string);
  }

  /**
   * Attempts to convert a key value array element to YAML
   * @access private
   * @return string
   * @param $key The name of the key
   * @param $value The value of the item
   * @param $indent The indent of the current node
   */
  private function yamlize($key, $value, $indent, $last_key = null) {
    if (is_array($value)) {
      if (empty ($value))
        return $this->dumpNode($key, array(), $indent, $last_key);

      $is_sequence_item = (is_int($key) && $key - 1 === $last_key && !$this->setting_dump_force_arrays);

      // Special case for a sequence of maps (like the 'servers' list)
      if ($is_sequence_item) {
        $string = '';
        $array_content = $this->yamlizeArray($value, $indent + $this->_dumpIndent);
        $lines = explode("\n", trim($array_content));
        $first_line = array_shift($lines);
        if ($first_line !== null) {
            // Attach the first line of the map to the dash
            $string .= str_repeat(' ', $indent) . '- ' . ltrim($first_line) . "\n";
        }
        // Indent the rest of the lines of the map
        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $string .= str_repeat(' ', $indent + $this->_dumpIndent) . $line . "\n";
            }
        }
        return $string;
      }

      // It's a normal map or a sequence of scalars.
      $string = $this->dumpNode($key, null, $indent, $last_key);
      $indent += $this->_dumpIndent;
      $string .= $this->yamlizeArray($value, $indent);

    } elseif (!is_array($value)) {
      // It doesn't have children.  YAY.
      $string = $this->dumpNode($key, $value, $indent, $last_key);
    }
    return $string;
  }

  /**
   * Attempts to convert an array to YAML
   * @access private
   * @return string
   * @param $array The array you want to convert
   * @param $indent The indent of the current level
   */
  private function yamlizeArray($array, $indent) {
    if (empty ($array)) return '';
    $string = '';
    // A kludge to make sure lists are formatted correctly.
    $is_sequence = (array_keys($array) === range(0, count($array) - 1));
    $last_key = $is_sequence ? -1 : null;
    foreach ($array as $key => $value) {
      $string .= $this->yamlize($key, $value, $indent, $last_key);
      $last_key = $key;
    }
    return $string;
  }

  /**
   * Returns YAML formatted string for a an array element.
   * @access private
   * @return string
   * @param $key The name of the key
   * @param $value The value of the item
   * @param $indent The indent of the current node
   */
  private function dumpNode($key, $value, $indent, $last_key = null) {
    // do some folding here, for blocks
    if (is_string($value) && strpos($value, "\n") !== false) {
      // Use literal block for multi-line strings
      $value_indent = $indent + $this->_dumpIndent;
      $value = "|-\n" . str_repeat(' ', $value_indent) . $this->LiteralPlaceHolder . str_replace("\n", "\n" . str_repeat(' ', $value_indent), $value);
    } elseif (($key === 'version' && !is_array($value) && $value !== null) || // Force 'version' to be a string
        (is_string($value) && ((strpos($value, ":") !== false || strpos($value, "- ") !== false || strpos($value, " ") !== false || // Quote strings with colons, starting dash, or spaces
        strpos($value, "*") !== false || strpos($value, "#") !== false || strpos($value, "<") !== false || strpos($value, ">") !== false || strpos($value, "!") !== false ||
        strpos($value, "[") !== false || strpos($value, "]") !== false || strpos($value, "{") !== false || strpos($value, "}") !== false) || substr($value, -1, 1) == ':')))
    {
      // For single-line strings with special characters, or for the 'version' key, quote the value
      // to ensure it's treated as a string by docker-compose.
      $value = '"' . str_replace('"', '\"', (string)$value) . '"';
    }

    $spaces = str_repeat(' ', $indent);

    // array?
    if (is_array($value)) $value = null;

    if ($value === null) {
      return $spaces.$key.":\n";
    }

    if (is_bool($value)) {
      $value = ($value) ? "true" : "false";
    }

    if ($value === '') $value = "''";

    if (is_int($key) && $key - 1 === $last_key && !$this->setting_dump_force_arrays) {
      // It's a sequence
      $string = $spaces.'- '.$value."\n";
    } else {
      // It's mapped
      if (strpos($key, ' ') !== false || strpos($key, ':') !== false) {
        $key = '"'.str_replace('"', '\"', $key).'"';
      }
      $string = $spaces.$key.': '.$value."\n";
    }
    return $string;
  }

  private function readLine($line) {
    // This is where the magic happens.  We need to determine the type of line we're dealing with.
    $line = ltrim($line);
    $this->indent = strlen($line) - strlen(ltrim($line));

    if (preg_match('/^(\s*)\- (.*)/', $line, $matches)) {
      // It's a list item.
      $this->addToList($matches[2]);
    } elseif (preg_match('/^(\s*)([^:]+): (.*)/', $line, $matches)) {
      // It's a key/value pair.
      $this->addToArray($matches[2], $matches[3]);
    } elseif (preg_match('/^(\s*)([^:]+):/', $line, $matches)) {
      // It's a key with no value.
      $this->addToArray($matches[2], '');
    }
  }

  private function addToArray($key, $value) {
    if ($this->indent == 0) {
      $this->result[$key] = $value;
      $this->path = array($key);
    } else {
      // The path needs to be changed.
      $this->path = array_slice($this->path, 0, $this->indent / 2);
      $this->path[] = $key;
      $this->setArrayValue($this->result, $this->path, $value);
    }
  }

  private function addToList($value) {
    if ($this->indent == 0) {
      $this->result[] = $value;
    } else {
      $this->path = array_slice($this->path, 0, $this->indent / 2);
      $this->setArrayValue($this->result, $this->path, array($value), true);
    }
  }

  private function setArrayValue(&$array, $path, $value, $isList = false) {
    $temp = &$array;
    foreach ($path as $key) {
      if (!isset($temp[$key])) {
        $temp[$key] = array();
      }
      $temp = &$temp[$key];
    }
    if ($isList) {
      $temp[] = $value[0];
    } else {
      $temp = $value;
    }
  }

  /**
   * Used in custom yaml parser.
   * @access private
   * @return bool
   */
  private function isComment($line) {
    if (!$line) return false;
    if ($line[0] == '#') return true;
    if (trim($line, " \r\n\t") == '---') return true;
    return false;
  }

  /**
   * Used in custom yaml parser.
   * @access private
   * @return bool
   */
  private function isArrayElement($line) {
    if (!$line) return false;
    if ($line[0] != '-') return false;
    if (strlen($line) > 3)
      if (substr($line,0,3) == '---') return false;

    return true;
  }

  /**
   * Used in custom yaml parser.
   * @access private
   * @return bool
   */
  private function isHashElement($line) {
    return strpos($line, ':');
  }

  /**
   * Used in custom yaml parser.
   * @access private
   * @return bool
   */
  private function isLiteral($line) {
    if ($this->isArrayElement($line)) return false;
    if ($this->isHashElement($line)) return false;
    if ($line == '|' || $line == '>') return true;
    return false;
  }

  /**
   * Used in custom yaml parser.
   * @access private
   * @return bool
   */
  private function startsLiteralBlock($line) {
    $last_character = substr(trim($line), -1);
    if ($last_character != '|' && $last_character != '>') return false;
    if ($last_character == '|') return '|';
    if ($last_character == '>') return '>';
    return false;
  }

  /**
   * Used in custom yaml parser.
   * @access private
   * @return string
   */
  private function addLiteralLine($literal_block, $line, $literal_type) {
    $line = self::unindent($line);
    if ($literal_type == '|') {
      return $literal_block.$line;
    }
    if (strlen($line) == 0)
      return $literal_block;
    $line = trim($line, "\r\n")." ";
    return $literal_block.$line;
  }

  function unindent($line) {
    if ($this->indent == 0) return $line;
    $new_line = substr($line, $this->indent);
    return $new_line;
  }

  /**
   * Parses a string for a YAML file.
   * @access public
   * @return array
   * @param string $string String to parse
   */
  public function parse($string) {
    return $this->YAMLLoadString($string);
  }

}
?>