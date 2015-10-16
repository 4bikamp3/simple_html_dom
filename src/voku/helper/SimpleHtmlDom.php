<?php

namespace voku\helper;

/**
 * simple html dom parser
 *
 * Paperg - in the find routine: allow us to specify that we want case insensitive testing of the value of the
 * selector.
 * Paperg - change $size from protected to public so we can easily access it
 * Paperg - added ForceTagsClosed in the constructor which tells us whether we trust the html or not.  Default is to
 * NOT trust it.
 *
 * @package voku\helper
 */
class SimpleHtmlDom
{
  /**
   * @var string
   */
  const token_blank = " \t\r\n";

  /**
   * @var string
   */
  const token_equal = ' =/>';

  /**
   * @var string
   */
  const token_slash = " />\r\n\t";

  /**
   * @var string
   */
  const token_attr = ' >';

  /**
   * @var SimpleHtmlDomNode|null
   */
  public $root = null;

  /**
   * @var SimpleHtmlDom[]
   */
  public $nodes = array();

  /**
   * @var null|string
   */
  public $callback = null;

  /**
   * Used to keep track of how large the text was when we started.
   *
   * @var int
   */
  public $original_size;

  /**
   * @var int
   */
  public $size;

  /**
   * @var string
   */
  public $default_span_text = '';

  /**
   * @var int
   */
  protected $pos;

  /**
   * @var string
   */
  protected $doc;

  /**
   * @var string
   */
  protected $char;

  /**
   * @var int
   */
  protected $cursor;

  /**
   * @var SimpleHtmlDom
   */
  protected $parent;

  /**
   * @var array
   */
  protected $noise = array();

  /**
   * @var bool
   */
  protected $ignore_noise = false;

  /**
   * @var string
   */
  protected $default_br_text = '';

  /**
   * use isset instead of in_array, performance boost about 30%...
   *
   * @var array
   */
  protected $self_closing_tags = array(
      'img'    => 1,
      'br'     => 1,
      'wbr'    => 1,
      'input'  => 1,
      'meta'   => 1,
      'link'   => 1,
      'hr'     => 1,
      'base'   => 1,
      'embed'  => 1,
      'spacer' => 1,
  );

  protected $block_tags = array(
      'root'  => 1,
      'body'  => 1,
      'form'  => 1,
      'div'   => 1,
      'span'  => 1,
      'table' => 1,
  );

  /**
   * Known sourceforge issue #2977341
   *
   * B tags that are not closed cause us to return everything to the end of the document.
   *
   * @var array
   */
  protected $optional_closing_tags = array(
      'tr'     => array(
          'tr' => 1,
          'td' => 1,
          'th' => 1,
      ),
      'th'     => array(
          'th' => 1,
      ),
      'td'     => array(
          'td' => 1,
      ),
      'li'     => array(
          'li' => 1,
      ),
      'dt'     => array(
          'dt' => 1,
          'dd' => 1,
      ),
      'dd'     => array(
          'dd' => 1,
          'dt' => 1,
      ),
      'dl'     => array(
          'dd' => 1,
          'dt' => 1,
      ),
      'p'      => array(
          'p' => 1,
      ),
      'nobr'   => array(
          'nobr' => 1,
      ),
      'b'      => array(
          'b' => 1,
      ),
      'option' => array(
          'option' => 1,
      ),
  );

  /**
   * __construct
   *
   * @param null   $str
   * @param bool   $forceTagsClosed
   */
  public function __construct($str = null, $forceTagsClosed = true)
  {
    if ($str) {
      $this->load($str);
    }

    // Forcing tags to be closed implies that we don't trust the html, but it can lead to parsing errors if we SHOULD trust the html.
    if (!$forceTagsClosed) {
      $this->optional_closing_array = array();
    }
  }

  /**
   * load html from string
   *
   * @param           $str
   * @param bool|true $ignoreNoise
   *
   * @return $this
   */
  public function load($str, $ignoreNoise = false)
  {
    // prepare
    $this->prepare($str, $ignoreNoise);
    // strip out cdata
    $this->remove_noise("'<!\[CDATA\[(.*?)\]\]>'is", true);
    // strip out comments
    $this->remove_noise("'<!--(.*?)-->'is");
    // Per sourceforge http://sourceforge.net/tracker/?func=detail&aid=2949097&group_id=218559&atid=1044037
    // Script tags removal now preceeds style tag removal.
    // strip out <script> tags
    $this->remove_noise("'<\s*script[^>]*[^/]>(.*?)<\s*/\s*script\s*>'is");
    $this->remove_noise("'<\s*script\s*>(.*?)<\s*/\s*script\s*>'is");
    // strip out <style> tags
    $this->remove_noise("'<\s*style[^>]*[^/]>(.*?)<\s*/\s*style\s*>'is");
    $this->remove_noise("'<\s*style\s*>(.*?)<\s*/\s*style\s*>'is");
    // strip out preformatted tags
    $this->remove_noise("'<\s*(?:code)[^>]*>(.*?)<\s*/\s*(?:code)\s*>'is");
    // strip out server side scripts
    $this->remove_noise("'(<\?)(.*?)(\?>)'s", true);
    // strip smarty scripts
    $this->remove_noise("'(\{\w)(.*?)(\})'s", true);

    // parsing
    while ($this->parse()) {
      ;
    }
    // end
    $this->root->_[HDOM_INFO_END] = $this->cursor;

    // make load function chainable
    return $this;

  }

  /**
   * prepare HTML data and init everything
   *
   * @param            $str
   * @param bool|false $ignoreNoise
   */
  protected function prepare($str, $ignoreNoise = false)
  {
    $this->clear();
    $str = (string)$str;

    // Set the length of content before we do anything to it.
    $this->size = strlen($str);
    // Save the original size of the html that we got in.  It might be useful to someone.
    $this->original_size = $this->size;

    // Before we save the string as the doc...  strip out the \r \n's
    $str = str_replace(array("\r", "\n"), ' ', $str);

    // Set the length of content since we have changed it.
    $this->size = strlen($str);

    $this->doc = $str;
    $this->pos = 0;
    $this->cursor = 1;
    $this->noise = array();
    $this->ignore_noise = $ignoreNoise;
    $this->nodes = array();
    $this->root = new SimpleHtmlDomNode($this);
    $this->root->tag = 'root';
    $this->root->_[HDOM_INFO_BEGIN] = -1;
    $this->root->nodetype = HDOM_TYPE_ROOT;
    $this->parent = $this->root;
    if ($this->size > 0) {
      $this->char = $this->doc[0];
    }
  }

  /**
   * clean up memory due to php5 circular references memory leak...
   */
  public function clear()
  {
    foreach ($this->nodes as $n) {
      $n->clear();
      $n = null;
    }

    // This add next line is documented in the sourceforge repository. 2977248 as a fix for ongoing memory leaks that occur even with the use of clear.
    if (isset($this->children)) {
      /** @noinspection PhpWrongForeachArgumentTypeInspection */
      foreach ($this->children as $n) {
        if (is_object($n)) {
          /** @noinspection PhpUndefinedMethodInspection */
          $n->clear();
        }
        $n = null;
      }
    }

    if (isset($this->parent)) {
      $this->parent->clear();
      unset($this->parent);
    }

    if (isset($this->root)) {
      $this->root->clear();
      unset($this->root);
    }

    unset($this->doc, $this->docArray, $this->noise, $this->parent, $this->root);
  }

  /**
   * remove noise from html content
   * save the noise in the $this->noise array.
   *
   * @param      $pattern
   * @param bool $remove_tag
   */
  protected function remove_noise($pattern, $remove_tag = false)
  {
    $count = preg_match_all($pattern, $this->doc, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

    for ($i = $count - 1; $i > -1; --$i) {
      $key = '___noise___' . sprintf('% 5d', count($this->noise) + 1000);
      $idx = ($remove_tag) ? 0 : 1;
      $this->noise[$key] = $matches[$i][$idx][0];

      if ($this->ignore_noise) {
        $this->doc = substr_replace($this->doc, '', $matches[$i][$idx][1], strlen($matches[$i][$idx][0]));
      } else {
        $this->doc = substr_replace($this->doc, $key, $matches[$i][$idx][1], strlen($matches[$i][$idx][0]));
      }
    }

    // reset the length of content
    $this->size = strlen($this->doc);
    if ($this->size > 0) {
      $this->char = $this->doc[0];
    }
  }

  /**
   * parse html content
   *
   * @return bool
   */
  protected function parse()
  {
    if (($s = $this->copy_until_char('<')) === '') {
      return $this->read_tag();
    }

    // text
    $node = new SimpleHtmlDomNode($this);
    ++$this->cursor;
    $node->_[HDOM_INFO_TEXT] = $s;
    $this->link_nodes($node, false);

    return true;
  }

  /**
   * copy until - char
   *
   * @param $char
   *
   * @return string
   */
  protected function copy_until_char($char)
  {
    if ($this->char === null) {
      return '';
    }

    if (($pos = strpos($this->doc, $char, $this->pos)) === false) {
      $ret = substr($this->doc, $this->pos, $this->size - $this->pos);
      $this->char = null;
      $this->pos = $this->size;

      return $ret;
    }

    if ($pos === $this->pos) {
      return '';
    }

    $pos_old = $this->pos;
    $this->char = $this->doc[$pos];
    $this->pos = $pos;

    return substr($this->doc, $pos_old, $pos - $pos_old);
  }

  /**
   * read tag info
   *
   * @return bool
   */
  protected function read_tag()
  {
    if ($this->char !== '<') {
      $this->root->_[HDOM_INFO_END] = $this->cursor;

      return false;
    }
    $begin_tag_pos = $this->pos;
    $this->char = (++$this->pos < $this->size) ? $this->doc[$this->pos] : null; // next

    // end tag
    if ($this->char === '/') {
      $this->char = (++$this->pos < $this->size) ? $this->doc[$this->pos] : null; // next

      // This represents the change in the simple_html_dom trunk from revision 180 to 181.
      // $this->skip(self::token_blank_t);
      $this->skip(self::token_blank);
      $tag = $this->copy_until_char('>');

      // skip attributes in end tag
      $pos = strpos($tag, ' ');
      if ($pos !== false) {
        $tag = substr($tag, 0, $pos);
      }

      $parent_tag = strtolower($this->parent->tag);
      $tag = strtolower($tag);

      if ($parent_tag !== $tag) {
        if (isset($this->optional_closing_tags[$parent_tag]) && isset($this->block_tags[$tag])) {
          $this->parent->_[HDOM_INFO_END] = 0;
          $org_parent = $this->parent;

          while (($this->parent->parent) && strtolower($this->parent->tag) !== $tag) {
            $this->parent = $this->parent->parent;
          }

          if (strtolower($this->parent->tag) !== $tag) {
            $this->parent = $org_parent; // restore original parent
            if ($this->parent->parent) {
              $this->parent = $this->parent->parent;
            }
            $this->parent->_[HDOM_INFO_END] = $this->cursor;

            return $this->as_text_node($tag);
          }
        } elseif (($this->parent->parent) && isset($this->block_tags[$tag])) {
          $this->parent->_[HDOM_INFO_END] = 0;
          $org_parent = $this->parent;

          while (($this->parent->parent) && strtolower($this->parent->tag) !== $tag) {
            $this->parent = $this->parent->parent;
          }

          if (strtolower($this->parent->tag) !== $tag) {
            $this->parent = $org_parent; // restore original parent
            $this->parent->_[HDOM_INFO_END] = $this->cursor;

            return $this->as_text_node($tag);
          }
        } elseif (($this->parent->parent) && strtolower($this->parent->parent->tag) === $tag) {
          $this->parent->_[HDOM_INFO_END] = 0;
          $this->parent = $this->parent->parent;
        } else {
          return $this->as_text_node($tag);
        }
      }

      $this->parent->_[HDOM_INFO_END] = $this->cursor;
      if ($this->parent->parent) {
        $this->parent = $this->parent->parent;
      }

      $this->char = (++$this->pos < $this->size) ? $this->doc[$this->pos] : null; // next
      return true;
    }

    $node = new SimpleHtmlDomNode($this);
    $node->_[HDOM_INFO_BEGIN] = $this->cursor;
    ++$this->cursor;
    $tag = $this->copy_until(self::token_slash);
    $node->tag_start = $begin_tag_pos;

    // doctype, cdata & comments...
    if (isset($tag[0]) && $tag[0] === '!') {
      $node->_[HDOM_INFO_TEXT] = '<' . $tag . $this->copy_until_char('>');

      if (isset($tag[2]) && $tag[1] === '-' && $tag[2] === '-') {
        $node->nodetype = HDOM_TYPE_COMMENT;
        $node->tag = 'comment';
      } else {
        $node->nodetype = HDOM_TYPE_UNKNOWN;
        $node->tag = 'unknown';
      }
      if ($this->char === '>') {
        $node->_[HDOM_INFO_TEXT] .= '>';
      }
      $this->link_nodes($node, true);
      $this->char = (++$this->pos < $this->size) ? $this->doc[$this->pos] : null; // next
      return true;
    }

    // text
    $pos = strpos($tag, '<');
    if ($pos !== false) {
      $tag = '<' . substr($tag, 0, -1);
      $node->_[HDOM_INFO_TEXT] = $tag;
      $this->link_nodes($node, false);
      $this->char = $this->doc[--$this->pos]; // prev
      return true;
    }

    if (!preg_match("/^[\w-:]+$/", $tag)) {
      $node->_[HDOM_INFO_TEXT] = '<' . $tag . $this->copy_until('<>');
      if ($this->char === '<') {
        $this->link_nodes($node, false);

        return true;
      }

      if ($this->char === '>') {
        $node->_[HDOM_INFO_TEXT] .= '>';
      }
      $this->link_nodes($node, false);
      $this->char = (++$this->pos < $this->size) ? $this->doc[$this->pos] : null; // next
      return true;
    }

    // begin tag
    $node->nodetype = HDOM_TYPE_ELEMENT;
    $tag = strtolower($tag);
    $node->tag = $tag;

    // handle optional closing tags
    if (isset($this->optional_closing_tags[$tag])) {
      while (isset($this->optional_closing_tags[$tag][strtolower($this->parent->tag)])) {
        $this->parent->_[HDOM_INFO_END] = 0;
        $this->parent = $this->parent->parent;
      }
      $node->parent = $this->parent;
    }

    $guard = 0; // prevent infinity loop
    $space = array(
        $this->copy_skip(self::token_blank),
        '',
        '',
    );

    // attributes
    do {
      if ($this->char !== null && $space[0] === '') {
        break;
      }
      $name = $this->copy_until(self::token_equal);
      if ($guard === $this->pos) {
        $this->char = (++$this->pos < $this->size) ? $this->doc[$this->pos] : null; // next
        continue;
      }
      $guard = $this->pos;

      // handle endless '<'
      if ($this->pos >= $this->size - 1 && $this->char !== '>') {
        $node->nodetype = HDOM_TYPE_TEXT;
        $node->_[HDOM_INFO_END] = 0;
        $node->_[HDOM_INFO_TEXT] = '<' . $tag . $space[0] . $name;
        $node->tag = 'text';
        $this->link_nodes($node, false);

        return true;
      }

      // handle mismatch '<'
      if ($this->doc[$this->pos - 1] == '<') {
        $node->nodetype = HDOM_TYPE_TEXT;
        $node->tag = 'text';
        $node->attr = array();
        $node->_[HDOM_INFO_END] = 0;
        $node->_[HDOM_INFO_TEXT] = substr($this->doc, $begin_tag_pos, $this->pos - $begin_tag_pos - 1);
        $this->pos -= 2;
        $this->char = (++$this->pos < $this->size) ? $this->doc[$this->pos] : null; // next
        $this->link_nodes($node, false);

        return true;
      }

      if ($name !== '/' && $name !== '') {
        $space[1] = $this->copy_skip(self::token_blank);
        $name = $this->restore_noise($name);
        $name = strtolower($name);
        if ($this->char === '=') {
          $this->char = (++$this->pos < $this->size) ? $this->doc[$this->pos] : null; // next
          $this->parse_attr($node, $name, $space);
        } else {
          //no value attr: nowrap, checked selected...
          $node->_[HDOM_INFO_QUOTE][] = HDOM_QUOTE_NO;
          $node->attr[$name] = true;
          if ($this->char != '>') {
            $this->char = $this->doc[--$this->pos];
          } // prev
        }
        $node->_[HDOM_INFO_SPACE][] = $space;
        $space = array(
            $this->copy_skip(self::token_blank),
            '',
            '',
        );
      } else {
        break;
      }
    } while ($this->char !== '>' && $this->char !== '/');

    $this->link_nodes($node, true);
    $node->_[HDOM_INFO_ENDSPACE] = $space[0];

    // check self closing
    if ($this->copy_until_char_escape('>') === '/') {
      $node->_[HDOM_INFO_ENDSPACE] .= '/';
      $node->_[HDOM_INFO_END] = 0;
    } else {
      // reset parent
      if (!isset($this->self_closing_tags[strtolower($node->tag)])) {
        $this->parent = $node;
      }
    }
    $this->char = (++$this->pos < $this->size) ? $this->doc[$this->pos] : null; // next

    // If it's a BR tag, we need to set it's text to the default text.
    // This way when we see it in plaintext, we can generate formatting that the user wants.
    // since a br tag never has sub nodes, this works well.
    if ($node->tag == 'br') {
      $node->_[HDOM_INFO_INNER] = $this->default_br_text;
    }

    return true;
  }

  /**
   * skip
   *
   * @param $chars
   */
  protected function skip($chars)
  {
    $this->pos += strspn($this->doc, $chars, $this->pos);
    $this->char = ($this->pos < $this->size) ? $this->doc[$this->pos] : null; // next
  }

  /**
   * as a text node
   *
   * @param $tag
   *
   * @return bool
   */
  protected function as_text_node($tag)
  {
    $node = new SimpleHtmlDomNode($this);
    ++$this->cursor;
    $node->_[HDOM_INFO_TEXT] = '</' . $tag . '>';
    $this->link_nodes($node, false);
    $this->char = (++$this->pos < $this->size) ? $this->doc[$this->pos] : null; // next

    return true;
  }

  /**
   * link node's parent
   *
   * @param $node
   * @param $is_child
   */
  protected function link_nodes(&$node, $is_child)
  {
    $node->parent = $this->parent;
    $this->parent->nodes[] = $node;

    if ($is_child) {
      $this->parent->children[] = $node;
    }
  }

  /**
   * copy until
   *
   * @param $chars
   *
   * @return string
   */
  protected function copy_until($chars)
  {
    $pos = $this->pos;
    $len = strcspn($this->doc, $chars, $pos);
    $this->pos += $len;
    $this->char = ($this->pos < $this->size) ? $this->doc[$this->pos] : null; // next

    return substr($this->doc, $pos, $len);
  }

  /**
   * copy skip
   *
   * @param $chars
   *
   * @return string
   */
  protected function copy_skip($chars)
  {
    $pos = $this->pos;
    $len = strspn($this->doc, $chars, $pos);
    $this->pos += $len;
    $this->char = ($this->pos < $this->size) ? $this->doc[$this->pos] : null; // next

    if ($len === 0) {
      return '';
    } else {
      return substr($this->doc, $pos, $len);
    }
  }

  /**
   * restore noise to html content
   *
   * @param $text
   *
   * @return string
   */
  public function restore_noise($text)
  {
    while (($pos = strpos($text, '___noise___')) !== false) {
      // Sometimes there is a broken piece of markup, and we don't GET the pos+11 etc... token which indicates a problem outside of us...
      if (strlen($text) > $pos + 15) {
        $key = '___noise___' . $text[$pos + 11] . $text[$pos + 12] . $text[$pos + 13] . $text[$pos + 14] . $text[$pos + 15];

        if (isset($this->noise[$key])) {
          $text = substr($text, 0, $pos) . $this->noise[$key] . substr($text, $pos + 16);
        } else {
          // do this to prevent an infinite loop.
          $text = substr($text, 0, $pos) . 'UNDEFINED NOISE FOR KEY: ' . $key . substr($text, $pos + 16);
        }
      } else {
        // There is no valid key being given back to us... We must get rid of the ___noise___ or we will have a problem.
        $text = substr($text, 0, $pos) . 'NO NUMERIC NOISE KEY' . substr($text, $pos + 11);
      }
    }

    return $text;
  }

  /**
   * parse attributes
   *
   * @param $node
   * @param $name
   * @param $space
   */
  protected function parse_attr($node, $name, &$space)
  {
    // Per sourceforge: http://sourceforge.net/tracker/?func=detail&aid=3061408&group_id=218559&atid=1044037
    // If the attribute is already defined inside a tag, only pay attention to the first one as opposed to the last one.
    if (isset($node->attr[$name])) {
      return;
    }

    $space[2] = $this->copy_skip(self::token_blank);
    switch ($this->char) {
      case '"':
        $node->_[HDOM_INFO_QUOTE][] = HDOM_QUOTE_DOUBLE;
        $this->char = (++$this->pos < $this->size) ? $this->doc[$this->pos] : null; // next
        $node->attr[$name] = $this->restore_noise($this->copy_until_char_escape('"'));
        $this->char = (++$this->pos < $this->size) ? $this->doc[$this->pos] : null; // next
        break;
      case '\'':
        $node->_[HDOM_INFO_QUOTE][] = HDOM_QUOTE_SINGLE;
        $this->char = (++$this->pos < $this->size) ? $this->doc[$this->pos] : null; // next
        $node->attr[$name] = $this->restore_noise($this->copy_until_char_escape('\''));
        $this->char = (++$this->pos < $this->size) ? $this->doc[$this->pos] : null; // next
        break;
      default:
        $node->_[HDOM_INFO_QUOTE][] = HDOM_QUOTE_NO;
        $node->attr[$name] = $this->restore_noise($this->copy_until(self::token_attr));
    }

    // PaperG: Attributes should not have \r or \n in them, that counts as html whitespace.
    $node->attr[$name] = str_replace(array("\r", "\n"), '', $node->attr[$name]);

    // PaperG: If this is a "class" selector, lets get rid of the preceding and trailing space since some people leave it in the multi class case.
    if ($name == 'class') {
      $node->attr[$name] = trim($node->attr[$name]);
    }
  }

  /**
   * copy until - char-escape
   *
   * @param $char
   *
   * @return string
   */
  protected function copy_until_char_escape($char)
  {
    if ($this->char === null) {
      return '';
    }

    $start = $this->pos;
    while (1) {
      if (($pos = strpos($this->doc, $char, $start)) === false) {
        $ret = substr($this->doc, $this->pos, $this->size - $this->pos);
        $this->char = null;
        $this->pos = $this->size;

        return $ret;
      }

      if ($pos === $this->pos) {
        return '';
      }

      if ($this->doc[$pos - 1] === '\\') {
        $start = $pos + 1;
        continue;
      }

      $pos_old = $this->pos;
      $this->char = $this->doc[$pos];
      $this->pos = $pos;

      return substr($this->doc, $pos_old, $pos - $pos_old);
    }

    return '';
  }

  /**
   * __destruct
   */
  public function __destruct()
  {
    $this->clear();
  }

  /**
   * set callback function
   *
   * @param $function_name
   */
  public function set_callback($function_name)
  {
    $this->callback = $function_name;
  }

  /**
   * remove callback function
   */
  public function remove_callback()
  {
    $this->callback = null;
  }

  /**
   * save dom as string or file (if $filepath is used)
   *
   * @param string $filepath
   *
   * @return mixed
   */
  public function save($filepath = '')
  {
    $ret = $this->root->innertext();
    if ($filepath !== '') {
      file_put_contents($filepath, $ret, LOCK_EX);
    }

    return $ret;
  }

  /**
   * @param bool $show_attr
   */
  public function dump($show_attr = true)
  {
    $this->root->dump($show_attr);
  }

  /**
   * Sometimes we NEED one of the noise elements.
   *
   * @param $text
   *
   * @return mixed
   */
  public function search_noise($text)
  {
    foreach ($this->noise as $noiseElement) {
      if (strpos($noiseElement, $text) !== false) {
        return $noiseElement;
      }
    }

    return '';
  }

  /**
   * magic toString
   *
   * @return mixed
   */
  public function __toString()
  {
    return $this->root->innertext();
  }

  /**
   * magic get
   *
   * @param $name
   *
   * @return string
   */
  public function __get($name)
  {
    switch ($name) {
      case 'outertext':
        return $this->root->innertext();
      case 'innertext':
        return $this->root->innertext();
      case 'plaintext':
        return $this->root->text();
    }

    return '';
  }

  /**
   * child nodes
   *
   * @param int $idx
   *
   * @return mixed
   */
  public function childNodes($idx = -1)
  {
    return $this->root->childNodes($idx);
  }

  /**
   * first child
   *
   * @return mixed
   */
  public function firstChild()
  {
    return $this->root->first_child();
  }

  /**
   * last child
   *
   * @return null
   */
  public function lastChild()
  {
    return $this->root->last_child();
  }

  /**
   * create element
   *
   * @param      $name
   * @param null $value
   *
   * @return mixed
   */
  public function createElement($name, $value = null)
  {
    /** @noinspection PhpUsageOfSilenceOperatorInspection */
    /** @noinspection PhpUndefinedFunctionInspection */
    return @str_get_html("<$name>$value</$name>")->first_child();
  }

  /**
   * create text node
   *
   * @param $value
   *
   * @return mixed
   */
  public function createTextNode($value)
  {
    /** @noinspection PhpUsageOfSilenceOperatorInspection */
    /** @noinspection PhpUndefinedFunctionInspection */
    return @end(str_get_html($value)->nodes);
  }

  /**
   * get element by id
   *
   * @param string $id
   *
   * @return SimpleHtmlDomNode
   */
  public function getElementById($id)
  {
    return $this->find("#$id", 0);
  }

  /**
   * find dom node by css selector
   *
   * Paperg - allow us to specify that we want case insensitive testing of the value of the selector.
   *
   * @param      $selector
   * @param null $idx
   *
   * @return array|null|\voku\helper\SimpleHtmlDomNode[]|\voku\helper\SimpleHtmlDomNode
   */
  public function find($selector, $idx = null)
  {
    $find = $this->root->find($selector, $idx);

    if ($find === null) {
      return new SimpleHtmlDomNodeBlank();
    } else {
      return $find;
    }
  }

  /**
   * get elements by id
   *
   * @param string $id
   * @param null   $idx
   *
   * @return SimpleHtmlDomNode
   */
  public function getElementsById($id, $idx = null)
  {
    return $this->find("#$id", $idx);
  }

  /**
   * get element by tag name
   *
   * @param string $name
   *
   * @return SimpleHtmlDomNode
   */
  public function getElementByTagName($name)
  {
    return $this->find($name, 0);
  }

  /**
   * get elements by tag name
   *
   * @param string $name
   * @param int    $idx
   *
   * @return SimpleHtmlDomNode
   */
  public function getElementsByTagName($name, $idx = -1)
  {
    return $this->find($name, $idx);
  }
}
