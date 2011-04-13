<?php

class HamlParser
{
 /**
  * The stitic variable which holds an instance of HamlParser
  *
  * @var object HamlParser
  */
  private static $_instance = null;

 /**
  * Array of each line of the passed HAML source
  *
  * @var array
  */
  private $_source = array();
  
 /**
  * The class variable which will hold the DOMDocument Object
  * 
  * @var object DOMDocument
  */
  private $_doc = null;
  
 /**
  * The document type which is used for parcing the current document
  * The variable is static so that when we have partials and components
  * they can inherit the document type of the layout.haml
  * 
  * The document type defines if HTML or XHTML will be used for the html tags.
  *
  * @var string
  */
  private static $_doctype = null;
  
 /**
  * If the document being parsed does not explicitly specify document 
  * type we will remove the <!DOCTYPE ...?> declaration as this file is 
  * partial or component.
  *
  * @var boolean
  */
  private $_keep_doctype = false;
 
 /**
  * The array of DOMElement objects.
  * Each array key represents a document indent level and 
  * the value is the DOMElement object which is the current
  * parent of the indent level.
  *
  * @var array
  */
  private $_parents = array();
  
 /**
  * Holds the current line being read by HamlParser::_parseLine()
  *
  * @var integer
  */
  private $_current_line = 0;

 /**
  * Returns the HamlParser object instance
  *
  * @param bool $initialize Whether to call HamlParser::_initialize() before returning the object
  * @return object HamlParser
  */  
  public static function getInstance($initialize = false)
  {
    if (!(self::$_instance instanceof self)) {
      self::$_instance = new self();
    }

    if ($initialize) {
      self::$_instance->_initialize();
    }
    
    return self::$_instance;
  }

 /**
  * Parses the passed in Haml source and returns the result as (X)HTML.
  * A convenience static method that can be used directly.
  *
  * @param string $haml_source The Haml source to parse
  * @return string The resulting Haml source
  */
  public static function toHTML($haml_source)
  {
    $h = self::getInstance();
    return $h->parse($haml_source, 'HTML');
  }

 /**
  * Parses the passed in Haml source and returns the result as raw PHP.
  * A convenience static method that can be used directly.
  * 
  * @param string $haml_source The Haml source to parse
  * @return string The raw PHP mixed with (X)HTML
  */
  public static function toPHP($haml_source)
  {
    $h = self::getInstance();
    return $h->parse($haml_source, 'PHP');
  }

 /**
  * The generic method to parse Haml source to (X)HTML/PHP
  *
  * @param string $haml_source
  * @param string $output_type Can be HTML or PHP 
  * @return string
  */
  public function parse($haml_source, $output_type = 'HTML')
  {
    if (!trim($haml_source, self::TOKEN_INDENT)) {
      return null;
    }

    $this->_initialize();
    $this->_setSource($haml_source);
    foreach ($this->_source as $line) {
      $this->_parseLine($line);
    }

    if ($this->_isHTML()) {
      $php_source = trim($this->_doc->saveHTML(), self::TOKEN_LINE);
    } else {
      $php_source = trim($this->_doc->saveXML($this->_doc, LIBXML_NOEMPTYTAG), self::TOKEN_LINE);
    }
    $php_source = $this->_cleanup($php_source);
    
    switch (strtoupper($output_type))
    {
      case 'PHP':
        return $php_source;
        break;
      case 'HTML':
      default:
        // create a temporary file
        $file = tempnam('/tmp', 'haml');

        // dump the php source in the file
        file_put_contents($file, $php_source);

        // we need to include the file so that we can get the output
        ob_start();
        include_once($file);
        $html_source = ob_get_clean();

        // delete the temporary file, we are done with it
        @unlink($file);

        return $html_source;
        break;
    }

    return null;
  }

 /**
  * Sets the Document type to use for the output document
  *
  * @param string $doctype
  */
  public function setDoctype($doctype)
  {
    self::$_doctype = $doctype;
  }
  
  private function _initialize()
  {
    if (is_null(self::$_doctype) && defined('HAML_DOCTYPE')) {
      self::$_doctype = HAML_DOCTYPE;
    }
    
    $this->_keep_doctype = false;
    $this->_current_line = 0;
    $this->_source = array();
    $this->_doc = $this->_createDocument(self::$_doctype);
    $this->_parents = array();
  }

  private function _parseLine($line)
  {
    $this->_current_line += 1;
    $indent = strspn($line, self::TOKEN_INDENT);
    $line = trim($line, self::TOKEN_INDENT);

    if (strchr($line, "\t")) {
      $this->_syntax_error("Tabs cannot be used as identation");
    } else if ($indent % self::INDENT != 0) {
      $this->_syntax_error("Invalid identation");
    }

    // Remove silent comments from the line if any
    if (false !== ($pos = strpos($line, '-#'))) {
      $line = substr($line, 0, $pos);
    }

    switch (substr($line, 0, 1))
    {
      case '':
        // Do nothing, the line is empty
        break;
      case substr(self::TOKEN_DOCTYPE, 0, 1):
        $matches = array();
        if (preg_match('/^'.self::TOKEN_DOCTYPE.'(.*)/', $line, $matches))
        {
          if ($this->_doc->getElementsByTagName('*')->length != 0) {
            $this->_syntax_error("Document type can be declared only at the beginning of the document");
          }
          
          $this->_doc = $this->_createDocument($matches[1]);
          $this->_addPHPInstruction("if (!defined('HAML_DOCTYPE')) define('HAML_DOCTYPE', '".self::$_doctype."');", 0);
          $this->_keep_doctype = true;
        } else {
          $this->_addText('plain', $line, $indent);
        }
        break;
      case self::TOKEN_SINGLE:
        $this->_addText('comment', substr($line, 1), $indent);
        break;
      case self::TOKEN_INSTRUCTION_PHP:
        $this->_addPHPInstruction(trim(substr($line, 1)), $indent);
        break;
      case self::TOKEN_PARSE_PHP:
        $this->_addPHPEcho(trim(substr($line, 1)), $indent);
        break;
      case self::TOKEN_ID:
      case self::TOKEN_CLASS:
        $line = '%div'.$line;
      case self::TOKEN_TAG:
        $raw = array();
        $regex = "/[%]([-:\w]+)([-\w\.\#]*)(\{.*\})?(\[.*\])?([=\/\~]?)?(.*)?/";
        if (preg_match($regex, $line, $matches))
        {
          $raw['tag'] = $tag = $matches[1];
          $raw['attributes'] = $matches[2];
          $raw['options'] = array_filter(explode(self::TOKEN_OPTIONS_SEPARATOR, trim(str_replace(array('{','}'), array('',''), $matches[3]))));
          $raw['helper'] = $matches[4];
          $raw['operator'] = $matches[5];
          $raw['content'] = $content = $matches[6];
        }

        // Parse attributes
        $attributes = array();
        foreach ($raw['options'] as $option)
        {
          @list($key, $value) = explode(self::TOKEN_OPTION_VALUE, trim($option));

          $regex = "/^\s*(".preg_quote(self::TOKEN_OPTION)."(\w*)|(('|\")([^\\\#]*?)\4))\s*$/";
          preg_match($regex, $key, $matches);
          if (!@$matches[2] && !@$matches[5]) {
            continue;
          }

          if (!ctype_digit(trim($value, " \"'")) && $this->_eval_syntax("return ".trim($value).";") !== false) 
          {
            $value  = "[?php \$_haml_attr = '".htmlspecialchars(trim($value), ENT_QUOTES)."'; ";
            $value .= "echo eval('return '.stripcslashes(htmlspecialchars_decode(html_entity_decode(\$_haml_attr), ENT_QUOTES)).';');?]";
          } else {
            $value = trim($value, " \"'");
          }
          
          $key = ltrim(trim($key), self::TOKEN_OPTION);
          $attributes[] = array('key' => $key, 'value' => $value);
        }

        while ($raw['attributes'])
        {
          $control_char = substr($raw['attributes'], 0, 1);
          $raw['attributes'] = substr($raw['attributes'], 1);
          $pos = strcspn($raw['attributes'], self::TOKEN_ID.self::TOKEN_CLASS);
          switch ($control_char)
          {
            case self::TOKEN_ID:
              $key = 'id';
              break;
            case self::TOKEN_CLASS:
              $key = 'class';
              break;
          }
          $attributes[] = array('key' => $key, 'value' => substr($raw['attributes'], 0, $pos));
          $raw['attributes'] = substr($raw['attributes'], $pos);
        }

        if ($raw['operator'] == '=')
        {
          if (!empty($content)) 
          {
            $content = $this->_createPHPEcho($content);
          } else {
            $this->_syntax_error('If you use the "=" operator you need to have content after it.');
          }
        }

        $this->_addTag($tag, $attributes, $content, $indent);
        break;
      case self::TOKEN_ESCAPE:
        $line = substr($line, 1);
      default:
        $this->_addText('plain', $line, $indent);
        break;
    }
  }

  private function _setSource($haml_source)
  {
    $this->_source = explode(self::TOKEN_LINE, preg_replace('/\s\\'.self::TOKEN_BREAK.'\s/si', '', $haml_source));
  }

  private function _getParent($indent)
  {
    $key = intval($indent) - intval(self::INDENT);
    $parent = null;
    while (is_null($parent))
    {
      if ($key < 0) {
        $parent = $this->_doc;
      } else if (isset($this->_parents[$key])) {
        $parent = $this->_parents[$key];
      }
      if (is_object($parent)) 
      {
        $type = (is_object($parent->attributes))?$parent->attributes->getNamedItem('type'):null;
        if (!is_null($type) && $type->value == 'instruction') {
          $parent = null;
        }
      }
      $key -= intval(self::INDENT);
    }

    return $parent;
  }

  private function _setParent($node, $indent)
  {
    $indent = intval($indent);
    $this->_parents[$indent] = $node;
    foreach (array_keys($this->_parents) as $k) {
      if ($k > $indent) unset($this->_parents[$k]);
    }
  }

  private function _addPHPInstruction($content, $indent)
  {
    $content = trim($content);
    if (empty($content)) return false;
    
    $node = $this->_createPHPInstruction($content);
    $parent = $this->_getParent($indent);
    if ($parent instanceof DOMCharacterData)
    {
      if ($parent->length > 0) {
        $parent->appendData(' ');
      }
      $parent->appendData($content);
    } else {
      $node = $parent->appendChild($node);
    }
    $this->_setParent($parent, $indent);
    
    return true;
  }
  
  private function _createPHPInstruction($content)
  {
    // let's determine if we need terminator character and which one
    $terminator = '';
    $tokens = token_get_all('<? '.$content);
    $last = $tokens[count($tokens)-1];
    if ($last != ';' && count($tokens) >= 3) 
    {
      if (in_array($tokens[2][1], self::$_php_loop_tags)) {
        $terminator = ':';
      } else {
        $terminator = ';';
      }
    }
    
    $content = trim($content).$terminator;
    $attributes = array(array('key' =>'type', 'value' => 'instruction'));
    $node = $this->_createTag('php', $attributes, $content);
    
    return $node;
  }

  private function _addPHPEcho($content, $indent = 0)
  {
    $content = trim($content);
    if (empty($content)) return false;
    
    $node = $this->_createPHPEcho($content);
    $parent = $this->_getParent($indent);
    if ($parent instanceof DOMCharacterData)
    {
      if ($parent->length > 0) {
        $parent->appendData(' ');
      }
      $parent->appendData($content);
    } else {
      $node = $parent->appendChild($node);
    }
    $this->_setParent($node, $indent);
    
    return true;
  }
  
  private function _createPHPEcho($content)
  {
    $content = '<?php echo '.trim($content).';?>';
    
    return $this->_createTextNode($content);
  }
  
  private function _addTag($tag, $attributes = array(), $content = null, $indent = 0)
  {
    $node = $this->_createTag($tag, $attributes, $content);
    
    $parent = $this->_getParent($indent);
    if (!($parent instanceof DOMCharacterData))
    {
      $node = $parent->appendChild($node);
      $this->_setParent($node, $indent);
    }
  }
  
  private function _createTag($tag, $attributes = array(), $content = null)
  {
    // handle special cases for %javascript and %style
    if ($tag == 'javascript')
    {
      $tag = 'script';
      $attributes[] = array('key' => 'type', 'value' => 'text/javascript');
      $attributes[] = array('key' => 'language', 'value' => 'javascript');
    } else if ($tag == 'style') {
      $attributes[] = array('key' => 'type', 'value' => 'text/css');
    }

    $node = $this->_doc->createElement($tag);

    if ($content instanceof DOMCharacterData) 
    {
      $content = $content->textContent;
    }
    
    $content = ltrim($content, self::TOKEN_INDENT);
    if (!empty($content))
    {
      $text = $this->_createTextNode($content);
      $text = $node->appendChild($text);
    }
    
    if (is_array($attributes) && !empty($attributes))
    {
      $keys = array();
      foreach ($attributes as $attribute) {
        if (strtolower($attribute['key']) == 'id') {
          $keys[strtolower($attribute['key'])] = $attribute['value'];
        } else {
          $keys[strtolower($attribute['key'])][] = $attribute['value'];
        }
      }

      ksort($keys);
      foreach ($keys as $key => $values)
      {
        if (!is_array($values)) $values = array(0 => $values);
        $node->setAttribute($key, implode(' ', $values));
      }
    }
    
    return $node;
  }

  private function _addText($type = 'plain', $content, $indent)
  {
    $content = $orig_content = ltrim($content, self::TOKEN_INDENT);
    $parent = $this->_getParent($indent);
    switch ($type)
    {
      case 'comment':
        $node = $this->_doc->createComment($content);
        break;
      case 'plain':
      default:
        $content = ltrim($content, self::TOKEN_LEVEL.self::TOKEN_INDENT);
        $node = $this->_createTextNode($content);
        break;
    }

    if ($parent instanceof DOMCharacterData)
    {
      if ($parent->substringData(0, 10) == '<?php echo') {
        $parent->insertData($parent->length - 3, $orig_content);
      }
      else
      {
        if ($parent->length > 0) {
          $parent->appendData(' ');
        }
        $parent->appendData($content);
      }
    } else {
      $node = $parent->appendChild($node);
      $this->_setParent($node, $indent);
    }
  }

  private function _createDocument($doctype = null)
  { 
    $doc = null;
    
    if (empty($doctype)) {
      $doctype = self::$_doctype;
    } else {
      self::$_doctype = trim($doctype);
    }
    
    $p = explode(' ', self::$_doctype);
    $p = array_pad($p, 3, '');
    if (!$options = @self::$_doctypes[$p[0]][$p[1]][$p[2]]) 
    {
      self::$_doctype = 'HTML 4.01 Strict';
      $options = self::$_doctypes['HTML']['4.01']['Strict'];
    }
    $DOM = new DOMImplementation();
    $doc = $DOM->createDocument(null, null, $DOM->createDocumentType("html", $options[0], $options[1]));

    $doc->encoding = 'utf-8';
    $doc->formatOutput = true;

    return $doc;
  }
  
  private function _createTextNode($content) 
  {
    if ($content != htmlspecialchars($content, ENT_NOQUOTES)) {
      return $this->_doc->createCDATASection($content);
    } else {
      return $this->_doc->createTextNode($content);
    }
  }

  private function _syntax_error($message)
  {
    $message = sprintf(trim($message)." (line %d)", $this->_current_line);
    throw new HamlSyntaxException($message);
  }

  private function _eval_syntax($code)
  {
    $b = 0;

    foreach (token_get_all($code) as $token)
    {
      if ('{' == $token) {
        ++$b;
      } else if ('}' == $token) {
        --$b;
      }
    }

    // Unbalanced braces would break the eval below
    if ($b != 0) return false;
    else
    {
      // Catch potential parse error messages
      $error_reporting = error_reporting(0);

      ob_start();
      // Put $code in a dead code sandbox to prevent its execution
      $code = eval('if(false){' . $code . '}');
      ob_end_clean();

      // Set the error reporting to its previous value
      error_reporting($error_reporting);

      return false !== $code;
    }
  }

  private function _cleanup($source)
  {
    if ($this->_isXHTML()) 
    {
      if (false !== ($pos = strpos($source, "?>\n"))) {
        $source = substr($source, $pos + 3);
      }
      if (false !== ($start = strpos($source, '    <meta http-equiv="Content-Type"'))) {
        $end = strpos($source, "/>\n", $start);
        $source = substr_replace($source, '', $start, $end - $start + 3);
      }
    }
    
    $patterns = array(
      '/(\]\]\>)?\s*\<\/php\>\s*\<php type\=\"(instruction|echo)\"\>\s*(\<\!\[CDATA\[)?/i',
      '/([ ]+)?\<php type\=\"(instruction|echo)\"\>\s*(\<\!\[CDATA\[)?/i', '/\s*(\]\]\>)?\s*\<\/php\>/i',
      '/\<(.*)\>\s*\<\!\[CDATA\[(.*)\]\]\>\s*\<\/(.*)\>/i',
      '/\<\!\[CDATA\[/i', '/\]\]\>/i'
    );
    $replacements = array(
      '',
      '<?php ', '?>',
      '<$1>$2</$3>',
      '', ''
    );
    $source = preg_replace($patterns, $replacements, $source);

    $source = ltrim($source, self::TOKEN_LINE);
    $source = str_replace(array('%5B', '%20', '%24', '%5D'), array('[', ' ', '$', ']'), $source);
    $source = str_replace(array('[?php', '?]'), array('<?php', '?>'), $source);

    foreach (self::$_closed_tags as $tag)
    {
      $source = str_replace('></'.$tag.'>', ' />', $source);
    }
    
    if (!$this->_keep_doctype && substr($source, 0, 9) == '<!DOCTYPE') 
    {
      $pos = strpos($source, '">'."\n");
      $source = substr($source, $pos + 3);
    }

    return trim($source);
  }

  private function _isHTML()
  {
    return (substr(self::$_doctype, 0, 4) == 'HTML');
  }
  
  private function _isXHTML()
  {
    return (substr(self::$_doctype, 0, 5) == 'XHTML');
  }
  
  /**
   * End of line character
   */
  const TOKEN_LINE = "\n";

  /**
   * Indention token
   */
  const TOKEN_INDENT = ' ';

  /**
   * Create tag (%strong, %div)
   */
  const TOKEN_TAG = '%';

  /**
   * Set element ID (#foo, %strong#bar)
   */
  const TOKEN_ID = '#';

  /**
   * Set element class (.foo, %strong.lorem.ipsum)
   */
  const TOKEN_CLASS = '.';

  /**
   * Start the options (attributes) list
   */
  const TOKEN_OPTIONS_LEFT = '{';

  /**
   * End the options list
   */
  const TOKEN_OPTIONS_RIGHT = '}';

  /**
   * Options separator
   */
  const TOKEN_OPTIONS_SEPARATOR = ',';

  /**
   * Start option name
   */
  const TOKEN_OPTION = ':';

  /**
   * Start option value
   */
  const TOKEN_OPTION_VALUE = '=>';

  /**
   * Begin PHP instruction (without displaying)
   */
  const TOKEN_INSTRUCTION_PHP = '-';

  /**
   * Parse PHP (and display)
   */
  const TOKEN_PARSE_PHP = '=';

  /**
   * Set DOCTYPE or XML header (!!! 1.1, !!!, !!! XML)
   */
  const TOKEN_DOCTYPE = '!!!';

  /**
   * Comment code (block and inline)
   */
  const TOKEN_COMMENT = '/';

  /**
   * Escape character
   */
  const TOKEN_ESCAPE = '\\';

  /**
   * Translate content (%strong$ Translate)
   */
  const TOKEN_TRANSLATE = '$';

  /**
   * Mark level (%strong?3, !! foo?3)
   */
  const TOKEN_LEVEL = '?';

  /**
   * Create single, closed tag (%meta{ :foo => 'bar'}/)
   */
  const TOKEN_SINGLE = '/';

  /**
   * Break line
   */
  const TOKEN_BREAK = '|';

  /**
   * Begin automatic id and classes naming (%tr[$model])
   */
  const TOKEN_AUTO_LEFT = '[';

  /**
   * End automatic id and classes naming
   */
  const TOKEN_AUTO_RIGHT = ']';

  /**
   * Insert text block (:textile)
   */
  const TOKEN_TEXT_BLOCKS = ':';

  /**
   * Number of TOKEN_INDENT to indent
   */
  const INDENT = 2;

  /**
   * Doctype definitions
   *
   * @var array
   */
  private static $_doctypes = array (
    'HTML' => array(
      '4.01' => array(
        'Strict' => array("-//W3C//DTD HTML 4.01//EN", "http://www.w3.org/TR/html4/strict.dtd"),
        'Transitional' => array("-//W3C//DTD HTML 4.01 Transitional//EN", "http://www.w3.org/TR/html4/loose.dtd"),
        'Frameset' => array("-//W3C//DTD HTML 4.01 Frameset//EN", "http://www.w3.org/TR/html4/frameset.dtd")
      )
    ),
    'XHTML' => array(
      '1.0' => array(
        'Strict' => array("-//W3C//DTD XHTML 1.0 Strict//EN", "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd"),
        'Transitional' => array("-//W3C//DTD XHTML 1.0 Transitional//EN", "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd"),
        'Frameset' => array("-//W3C//DTD XHTML 1.0 Frameset//EN", "http://www.w3.org/TR/xhtml1/DTD/xhtml1-frameset.dtd"),
      ),
      '1.1' => array("-//W3C//DTD XHTML 1.1//EN", "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd")
    )
  );

  /**
   * List of closed tags
   *
   * @var array
   */
  private static $_closed_tags = array('br', 'hr', 'link', 'meta', 'img', 'input');
  
  /**
   * List of the tags that need to be escaped with CDATA
   *
   * @var array
   */
  private static $_cdata_tags = array('script', 'style');

  /**
   * List of inline tags
   *
   * @var array
   */
  private static $_php_loop_tags = array('if', 'elseif', 'else', 'for', 'foreach', 'while');
}

class HamlSyntaxException   extends Exception {}
