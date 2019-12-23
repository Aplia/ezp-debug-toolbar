# Debug toolbar for eZ publish legacy

This extension grabs the eZ publish debug output and places it inside a
toolbar at the right side. The toolbar can be toggled to show/hide the
debug output.

This means that the page no longer gets affected by long debug output.

[![Latest Stable Version](https://img.shields.io/packagist/v/aplia/debug-toolbar.svg?style=flat-square)](https://packagist.org/packages/aplia/debug-toolbar)
[![Minimum PHP Version](https://img.shields.io/badge/php-%3E%3D%205.3-8892BF.svg?style=flat-square)](https://php.net/)


## Installation

Install using composer

```console
composer require aplia/debug-toolbar
```

Then enable the extension by adding this to site.ini:

```ini
[ExtensionSettings]
ActiveExtensions[]=debug-toolbar
```

Then modify pagelayout.tpl by find the debug marker:

```
<!--DEBUG_REPORT-->
```

and replacing it with:

```eztemplate
{if eq( ezini( 'DebugSettings', 'DebugOutput', 'site.ini' ), 'enabled' )}
{include uri='design:debug/debug-toolbar.tpl'}
{/if}
```

## License

The debug toolbar is open-sourced software licensed under the MIT license.
