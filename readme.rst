Description
===========

This extension grabs the eZ publish debug output and places it inside a
toolbar at the right side. The toolbar can be toggled to show/hide the
debug output.

This means that the page no longer gets affected by long debug output.

Installation
------------

Install using composer::

	composer require aplia/debug-toolbar


Enable the extension by adding this to site.ini::

  [ExtensionSettings]
  ActiveExtensions[]=debug-toolbar

Then modify pagelayout.tpl by find the debug marker::

  <!--DEBUG_REPORT-->

and replacing it with::

  {if eq( ezini( 'DebugSettings', 'DebugOutput', 'site.ini' ), 'enabled' )}
  {include uri='design:debug/debug-toolbar.tpl'}
  {/if}

