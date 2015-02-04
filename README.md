[![SensioLabsInsight](https://insight.sensiolabs.com/projects/be3e4851-272f-4499-9fc4-4b2704a43301/mini.png)](https://insight.sensiolabs.com/projects/be3e4851-272f-4499-9fc4-4b2704a43301)
[![Total Downloads](https://poser.pugx.org/voku/simple_html_dom/downloads.svg)](https://packagist.org/packages/voku/simple_html_dom)


simple_html_dom
===============

Adaptation for Composer and PSR-0 of: [PHP Simple HTML DOM Parser project](http://simplehtmldom.sourceforge.net/) usable as a [Composer](http://getcomposer.org/) package.

Check the [official documentation at SourceForge](http://simplehtmldom.sourceforge.net/manual.htm).

- A HTML DOM parser written in PHP5+ let you manipulate HTML in a very easy way!
- Require PHP 5+.
- Supports invalid HTML.
- Find tags on an HTML page with selectors just like jQuery.
- Extract contents from HTML in a single line.


## Installation

First, you need to add this repository at the root of your `composer.json`:

```json
"require": {
    "simple_html_dom/simple_html_dom": "1.*"
}
```

Do a `composer validate`, just to be sure that your file is still valid.

And voilà, you’re ready to `composer update`.

## Usage

```php
use voku\helper\HtmlDomParser;

...
$dom = HtmlDomParser::str_get_html( $str );
// or 
$dom = HtmlDomParser::file_get_html( $file_name );

$elems = $dom->find($elem_name);
...

```
