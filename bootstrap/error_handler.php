<?php

// Usage:
// To install the required packages run the following commands:
// composer require firephp/firephp-core
// composer require raven/raven
// composer install
//
// Create (or update) config.php and set one of the following.
//
// Set $GLOBALS['ezpDebugMode'] to one of:
// - prod - Disables eZ publish debug handling and installs Raven handlers
// - dev - Enables regular eZ publish debug handling
// - dev-strict - Stops on first error or warning, shows error with stack trace
// For CLI usage set $GLOBALS['ezpDebugModeCli']
//
// To disable xdebug output (if using 'dev' mode) set global var 'ezpDebugUseXdebug'.
// e.g. $GLOBALS['ezpDebugUseXdebug'] = false;
//
// To configure Raven DSN set global var 'RAVEN.dsn'
// e.g. $GLOBALS['RAVEN']['dsn'] = '<url>'
//
// To decide what gets logged by Raven set the global var 'RAVEN.severity'
// e.g. //$GLOBALS['RAVEN']['severity'] = 'warning';
// Choose from:
// - error
// - warning
// - notice
//
// Then bootstrap by doing
// require_once 'extension/debug-toolbar/bootstrap/error_handler.php';


$GLOBALS['ezpBootstrapRoot'] = realpath(__DIR__ . '/../../../');
$GLOBALS['ezpKernelBootstrap'] = function ($options = array()) {
    if (!file_exists($GLOBALS['ezpBootstrapRoot'] . "/lib/ezutils/classes/ezdebug.php")) {
        header('HTTP/1.1 500 Internal Server Error');
        echo "The debug file 'lib/ezutils/classes/ezdebug.php' does not exist (or not found in load path), cannot install override.";
        exit(1);
    }

    $options = array_merge(array(
        'fatal_handler' => false,
    ), $options);

    // Load the eZDebug class and monkey-patch it to give it a different name
    $debug = preg_replace('/^\s*<[?]php\s?(.+)[?]>\s*$/ms', '$1', file_get_contents("lib/ezutils/classes/ezdebug.php") );
    $debug = preg_replace("/class\s+eZDebug/m", "class MonkeyPatch_eZDebug", $debug);
    eval($debug);
    unset($debug);

    // Load the eZExecution class and inject a different function for registering a shutdown/exception handler
    $ezexec = preg_replace('/^\s*<[?]php\s?(.+)[?]>\s*$/ms', '$1', file_get_contents("lib/ezutils/classes/ezexecution.php") );
    $ezexec = preg_replace("/function +registerShutdownHandler/ms", "function origRegisterShutdownHandler", $ezexec);
    $register_sdh = <<<'EOD'
        static public function registerShutdownHandler( $documentRoot = false )
        {
            if ( !self::$shutdownHandle )
            {
                if ( isset($GLOBALS['ezpExecution']['installHandler']) ? $GLOBALS['ezpExecution']['installHandler'] : true ) {
                    register_shutdown_function( array('eZExecution', 'uncleanShutdownHandler') );
                    set_exception_handler( array('eZExecution', 'defaultExceptionHandler') );
                }

                /*
                    see:
                    - http://www.php.net/manual/en/function.session-set-save-handler.php
                    - http://bugs.php.net/bug.php?id=33635
                    - http://bugs.php.net/bug.php?id=33772
                */
                @register_shutdown_function( array('eZSession', 'stop') );

                self::$shutdownHandle = true;
            }

            // Needed by the error handler, since the current directory is lost when
            // the callback function eZExecution::uncleanShutdownHandler is called.
            if ( $documentRoot )
            {
                self::$eZDocumentRoot = $documentRoot;
            }
            else if ( self::$eZDocumentRoot === null )
            {
                self::$eZDocumentRoot = getcwd();
            }
        }
EOD;
    $ezexec = preg_replace("/class\s+eZExecution\s+[{]\s+/ms", "\\0" . $register_sdh, $ezexec);
    eval($ezexec);
    unset($ezexec);
    unset($register_sdh);

    class ApliaGitHelper
    {
        public function getGitRef($path, $refspec = 'HEAD')
        {
            $output = array();
            exec("cd $path && git rev-parse --abbrev-ref $refspec", $output, $result);
            if ($result != 0)
                return false;
            $ref = $output[0];
            if ($ref == 'HEAD') {
                $output = array();
                exec("cd $path && git rev-parse $refspec", $output, $result);
                if ($result != 0)
                    return false;
                $ref = $output[0];
            }
            return $ref;
        }

        public function getGitTag($path)
        {
            $filename = $path . '/HEAD';
            if ( is_file($filename) ) {
                $tag = file_get_contents($filename);
                if ( preg_match('/^ref:\s*(.+)/', $tag, $matches) ) {
                    $filename = $path . '/' . $matches[1];
                    if ( is_file($filename) ) {
                        $tag = file_get_contents($filename);
                        return $tag;
                    } else {
                        return null;
                    }
                }
                return $tag;
            }
            return null;
        }

        public function getGitPath($path)
        {
            if ( is_file($path) ) {
                $ref = file_get_contents($path);
                if ( preg_match('/^gitdir:\s*(.+)/', $ref, $matches) ) {
                    $filename = dirname($path) . '/' . $matches[1];
                    return $filename;
                }
                return null;
            } else if ( is_dir($path) ) {
                return $path;
            }
            return null;
        }
    }

    class ezpFatalError extends ErrorException
    {
        public $type;
        public $textTrace;
        public $extraTrace;

        public function getCustomTrace()
        {
            $trace = parent::getTrace();
            if ($this->extraTrace)
                $trace = array_merge($this->extraTrace, $trace);
            return $trace;
        }
    }

    class ezpErrorHandler
    {
        public $warningTypes;
        public $errorTypes;
        public $templateContextLines = 10;

        public function __construct($warningTypes = null, $errorTypes = null)
        {
            if (!$warningTypes)
                $warningTypes = E_WARNING | E_CORE_WARNING | E_COMPILE_WARNING | E_USER_WARNING | E_DEPRECATED;
            if (!$errorTypes)
                $errorTypes = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_STRICT | E_RECOVERABLE_ERROR;
            $this->warningTypes = $warningTypes;
            $this->errorTypes = $errorTypes;
        }

        function handleException( $exc )
        {
            if ( PHP_SAPI != 'cli' ) {
                if ($this->writeTrace($exc) === false)
                    return false;
            } else {
                echo "Exception ", get_class($exc), ": ", $exc->getMessage(), "\n";
            }

            if (class_exists('eZExecution')) {
                eZExecution::setCleanExit();
            }
            if ( PHP_SAPI != 'cli' ) {
                header('HTTP/1.1 500 Internal Server Error');
                header( 'Content-Type: text/html' );
            }
            exit(1);
        }

        public function writeHeader()
        {
            echo "<!DOCTYPE html><html><head>";

            $this->writeTraceStylesheet();
            $this->writeScripts();
            echo "</head><body>";
        }

        public function writeFooter()
        {
            echo "</body></html>";
        }

        public function writeScripts()
        {
            // Jquery makes everything easier
            echo '<script src="//code.jquery.com/jquery-1.11.3.min.js"></script>';
            echo '<script src="https://cdnjs.cloudflare.com/ajax/libs/clipboard.js/1.5.5/clipboard.min.js"></script>';
        }

        public function writeTraceStylesheet()
        {
            // Use Font Awesome for icons
            echo '<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/font-awesome/4.5.0/css/font-awesome.min.css">';

            // Internal styles
            echo "<style>
body
{
    margin: 0px;
}

.exception-message
{
    padding: 30px 20px;
}

.exception-message h3:nth-child(1)
{
    margin-top: 0;
}


.exception-message table.request-info
{
    margin-top: 10px;
}

.exception-message table.request-info th
{
    text-align: right;
}

.exception-message .message
{
    font-family: monospace;
    white-space: pre-wrap;
}

.exception-message.error
{
    background-color: #FDFFC9;
}

.exception-message.warning
{
    background-color: #E0DEDB;
}

.stack-trace
{
    margin: 10px 20px;
}

.stack-trace > .title
{
    margin: 0;
    margin-bottom: 10px;
}

.stack-trace .code-line
{
    font-family: monospace;
    white-space: pre-wrap;
    line-height: 22px;
    font-size: 12px;
    word-wrap: break-word;
    padding-left: 20px;
    color: rgba(0, 0, 0, 0.75);
    background-color: #f8f8f8;
}

.stack-trace .code-line.active
{
    background-color: #dee3e9;
}

.globals
{
    margin: 10px 20px;
}

.hidden, .hidden-inline
{
    display: none;
}

.expanded .expandable {
    height: auto;
}

.collapsed .expandable {
    overflow: hidden;
    height: 0;
    display: none;
}

.frame .context
{
    padding-bottom: 10px;
    margin: 0 0 0 20px;
}

.frame .context,
.collapsed .expand-trigger,
.expanded .expand-trigger
{
    cursor: pointer;
}

.frame.frame-hidden
{
    background-color: #ABABAB;
    margin: 5px 0;
}

.collapsed h3.expand-trigger:after
{
    content: \" ...\";
}

.collapsed h3.expand-trigger:hover,
.expanded h3.expand-trigger:hover
{
    background-color: #dee3e9;
}


table.vars td, table.vars th
{
    border: 0;
    vertical-align: top;
    padding: 5px;
}

div.args, div.vars
{
    padding-left: 20px;
}

table.vars .varname
{
    background-color: #f8f8f8;
    font-style: italic;
}

.git-branch
{
    margin-left: 10px;
}

.btn
{
    position: relative;
    display: inline-block;
    padding: 2px 4px;
    font-size: 13px;
    font-weight: bold;
    line-height: 20px;
    white-space: nowrap;
    vertical-align: middle;
    cursor: pointer;
    background-image: none;
    border: 1px solid rgba(0, 0, 0, 0);
    border-radius: 4px;
    -webkit-user-select: none;
    -moz-user-select: none;
    -ms-user-select: none;
    user-select: none;
    -webkit-appearance: none;
}

.bnt:hover, .bnt:active
{
    text-decoration: none;
}

.btn-default
{
    color: #333;
    background-color: #fff;
    border-color: #ccc;
}

.btn-primary
{
    color: #fff;
    background-color: #337ab7;
    border-color: #2e6da4;
}

.btn-default:hover
{
    color: #333;
    background-color: #e6e6e6;
    border-color: #adadad;
}

.btn-primary:hover
{
    color: #fff;
    background-color: #286090;
    border-color: #204d74;
}

.path-line
{
    margin-bottom: 3px;
}

.path-project
{
    background-color: #ddd;
}

</style>
            ";
        }

        /* Taken from Raven_Stacketrace::read_source_file since it was private */
        public static function read_source_file($filename, $lineno, $context_lines = 5)
        {
            $frame = array(
                'prefix' => array(),
                'line' => '',
                'suffix' => array(),
                'filename' => $filename,
                'lineno' => $lineno,
            );

            if ($filename === null || $lineno === null) {
                return $frame;
            }

            // Code which is eval'ed have a modified filename.. Extract the
            // correct filename + linenumber from the string.
            $matches = array();
            $matched = preg_match("/^(.*?)\((\d+)\) : eval\(\)'d code$/",
                $filename, $matches);
            if ($matched) {
                $frame['filename'] = $filename = $matches[1];
                $frame['lineno'] = $lineno = $matches[2];
            }

            // In the case of an anonymous function, the filename is sent as:
            // "</path/to/filename>(<lineno>) : runtime-created function"
            // Extract the correct filename + linenumber from the string.
            $matches = array();
            $matched = preg_match("/^(.*?)\((\d+)\) : runtime-created function$/",
                $filename, $matches);
            if ($matched) {
                $frame['filename'] = $filename = $matches[1];
                $frame['lineno'] = $lineno = $matches[2];
            }

            try {
                $file = new SplFileObject($filename);
                $target = max(0, ($lineno - ($context_lines + 1)));
                $file->seek($target);
                $cur_lineno = $target+1;
                while (!$file->eof()) {
                    $line = rtrim($file->current(), "\r\n");
                    if ($cur_lineno == $lineno) {
                        $frame['line'] = $line;
                    } elseif ($cur_lineno < $lineno) {
                        $frame['prefix'][] = $line;
                    } elseif ($cur_lineno > $lineno) {
                        $frame['suffix'][] = $line;
                    }
                    $cur_lineno++;
                    if ($cur_lineno > $lineno + $context_lines) {
                        break;
                    }
                    $file->next();
                }
            } catch (RuntimeException $exc) {
                return $frame;
            }

            return $frame;
        }

        public function getIgnoreErrorHandlers()
        {
            $handlers = array(
                'corrupt_exif' => array( $this, 'ignoreCorruptExif' ),
                'image_alias_not_found' => array( $this, 'ignoreImageAlias' ),
                'extension' => array( $this, 'ignoreExtension' ),
            );
            if ( isset($GLOBALS['ezpDebugIgnoreHandlers']) )
                $handlers = array_merge( $handlers, $GLOBALS['ezpDebugIgnoreHandlers'] );
            return $handlers;
        }

        public function checkIgnoreError( $errno, $errstr, $errfile, $errline )
        {
            $handlers = $this->getIgnoreErrorHandlers();
            foreach ( $handlers as $handler ) {
                if (!$handler)
                    continue;

                $status = call_user_func( $handler, $this, $errno, $errstr, $errfile, $errline );
                if ($status)
                    return $status;
            }
        }

        public function ignoreCorruptExif( $errorHandler, $errno, $errstr, $errfile, $errline )
        {
            // Corrupt exif headers are not really an error/warning, turn into a notice
            if (preg_match("/^exif_read_data.*(corrupt|file not supported)/mis", $errstr))
               return array( 'is_ignored' => true, 'errno' => E_NOTICE );
        }

        public function ignoreImageAlias( $errorHandler, $errno, $errstr, $errfile, $errline )
        {
            if (isset($GLOBALS['ezpDebug']['ignore']['ImageAlias']) ? $GLOBALS['ezpDebug']['ignore']['ImageAlias'] : false ) {
                if ( preg_match("/^The reference alias.*does not exist.*eZImageManager::createImageAlias/mis", $errstr) ||
                     preg_match("/^Original alias does not exist.*cannot create other aliases/mis", $errstr)
                     )
                   return array( 'is_ignored' => true, 'errno' => E_NOTICE );
           }
        }

        public function ignoreExtension( $errorHandler, $errno, $errstr, $errfile, $errline )
        {
            if (isset($GLOBALS['ezpDebug']['ignore']['extension']) ? $GLOBALS['ezpDebug']['ignore']['extension'] : false ) {
                if ( preg_match("/Extension '[^']+' was reported to have modules but has not yet been activated/mis", $errstr) ) {
                    return array( 'is_ignored' => true, 'errno' => E_NOTICE );
                }
            }
        }

        public function getExtraSourceHandlers()
        {
            $handlers = array(
                'ezptemplate' => array( $this, 'findTemplateSource' ),
            );
            if ( isset($GLOBALS['ezpDebugSourceHandlers']) )
                $handlers = array_merge( $handlers, $GLOBALS['ezpDebugSourceHandlers'] );
            return $handlers;
        }

        public function findTemplateSource( $errorHandler, $exc, $message, $stackTrace )
        {
            $tplFile = null;
            $tplLine = null;
            $tplCol = null;
            $tplFunc = null;

            if (preg_match("#eZTemplate(?::(?P<func>.+))?.*@ *(?P<path>[^:]+)(?::(?P<lineno>[0-9]+)(?:\[(?P<colno>[0-9])\])?)?#mis", $message, $matches)) {
                $tplFunc = isset($matches['func']) ? $matches['func'] : null;
                $tplFile = $matches['path'];
                $tplLine = isset($matches['lineno']) ? (int)$matches['lineno'] : null;
                $tplCol = isset($matches['colno']) ? (int)$matches['colno'] : null;
            } else if (preg_match("#parser error.*@ *(?P<path>[^:]+)(?::(?P<lineno>[0-9]+)(?:\[(?P<colno>[0-9])\])?)?.*eZTemplate(?::(?P<func>.+))?#mis", $message, $matches)) {
                $tplFunc = isset($matches['func']) ? $matches['func'] : null;
                $tplFile = $matches['path'];
                $tplLine = isset($matches['lineno']) ? (int)$matches['lineno'] : null;
                $tplCol = isset($matches['colno']) ? (int)$matches['colno'] : null;
            }


            if ($tplFile) {
                $context = self::read_source_file($tplFile, $tplLine ? $tplLine : 1, $this->templateContextLines);
                $absPath = realpath($tplFile);
                $frame = array(
                    'abs_path' => $absPath,
                    'filename' => $tplFile,
                    'lineno' => $tplLine,
                    'module' => $tplFile,
                    'function' => $tplFunc,
                    'pre_context' => $context['prefix'],
                    'context_line' => $context['line'],
                    'post_context' => $context['suffix'],
                    'is_expanded' => true,
                );
                array_unshift($stackTrace, $frame);
                return $stackTrace;
            }
        }

        public function findExtraSources($exc, $message, $stackTrace)
        {
            $handlers = $this->getExtraSourceHandlers();
            foreach ( $handlers as $handler ) {
                if (!$handler)
                    continue;

                $newStackTrace = call_user_func($handler, $this, $exc, $message, $stackTrace);
                if ($newStackTrace !== null)
                    $stackTrace = $newStackTrace;
            }
            return $stackTrace;
        }

        public function writeTrace($exc)
        {
            $trace = method_exists($exc, 'getCustomTrace') ? $exc->getCustomTrace() : $exc->getTrace();

            if (!class_exists('Raven_Stacktrace')) {
                @include_once((isset($_SERVER['DOCUMENT_ROOT']) ? ($_SERVER['DOCUMENT_ROOT'] . '/') : '') . 'vendor/raven/raven/lib/Raven/Stacktrace.php');
                //spl_autoload_call('Raven_Stacktrace');
            }

            if (class_exists('Raven_Stacktrace')) {
                $message = '';
                if (get_class($exc) != 'ezpFatalError')
                    $message = get_class($exc) . "\n";
                $message .= $exc->getMessage();

                $info = Raven_Stacktrace::get_stack_info(
                    $trace, true, true, null, 1024
                );

                $info = array_reverse($info);

                // Skip current file and other sources that trigger the errors
                // TODO: Move into a function with configurable callbacks
                foreach ($info as $idx => $frame)
                {
                    if (!isset($frame['abs_path']))
                        continue;

                    if (preg_match(";^" . __FILE__ . "([(]|$);", $frame['abs_path'] )) {
                        $info[$idx]['is_hidden'] = true;
                        continue;
                    }
                    if (isset($frame['function'])) {
                         if (preg_match(";lib/eztemplate/classes/eztemplate.php$;", $frame['abs_path']) &&
                             in_array($frame['function'], array('error', 'warning'))) {
                            $info[$idx]['is_hidden'] = true;
                            continue;
                         }
                    }
                    break;
                }

                // Expand the first visible frame
                foreach ($info as $idx => $frame) {
                    if (isset($frame['is_hidden']) && $frame['is_hidden'])
                        continue;

                    if (!isset($frame['is_expanded'])) {
                        $frame['is_expanded'] = true;
                        $info[$idx] = $frame;
                        break;
                    }
                }

                $info = $this->findExtraSources($exc, $message, $info);

                echo $this->writeHeader();

                if ($exc instanceof ErrorException)
                    $excClass = ($this->warningTypes & $exc->getSeverity()) ? 'warning' : 'error';
                else
                    $excClass = 'error';
                $errorName = get_class($exc);
                if ($errorName == 'ezpFatalError')
                    $errorName = $exc->type;

                $ezpversion = null;
                if (class_exists('eZPublishSDK') || file_exists($GLOBALS['ezpBootstrapRoot'] . '/lib/version.php')) {
                    @include_once('lib/version.php');
                    if (class_exists('eZPublishSDK')) {
                        $ezpversion = eZPublishSDK::version();
                    }
                }
                $gwbase_version = null;
                if (class_exists('gwBaseInfo') ||
                    file_exists($GLOBALS['ezpBootstrapRoot'] . '/extension/gwbase/ezinfo.php') ||
                    file_exists($GLOBALS['ezpBootstrapRoot'] . '/extension/gwbase3/ezinfo.php')) {
                    if (file_exists($GLOBALS['ezpBootstrapRoot'] . '/extension/gwbase3/ezinfo.php')) {
                        @include_once('extension/gwbase3/ezinfo.php');
                    } elseif (file_exists($GLOBALS['ezpBootstrapRoot'] . '/extension/gwbase/ezinfo.php')) {
                        @include_once('extension/gwbase/ezinfo.php');
                    }
                    if (class_exists('gwBaseInfo')) {
                        $gwBaseInfo = gwBaseInfo::info();
                        $gwbase_version = $gwBaseInfo['Version'];
                    }
                }
                $git = new ApliaGitHelper();
                $repoPath = $_SERVER['DOCUMENT_ROOT'] . '/.git';
                $gitTag = $git->getGitTag($repoPath);
                $gitRef = $git->getGitRef($repoPath);

                echo "<div class=\"exception-message $excClass\">";
                echo "<h3>$errorName at ", $_SERVER['REQUEST_URI'], "</h3>";
                echo "<div class=\"message\">", $message, "</div>\n";
                echo "<table class=\"request-info\">";
                echo "<tr><th>Request method<th><td>", $_SERVER['REQUEST_METHOD'], "</td></tr>";
                echo "<tr><th>Request url<th><td>", $_SERVER['REQUEST_URI'], "</td></tr>";
                echo "<tr><th>PHP version<th><td>", phpversion(), "</td></tr>";
                if (isset($_SERVER['SERVER_SOFTWARE']))
                    echo "<tr><th>Server<th><td>", $_SERVER['SERVER_SOFTWARE'], "</td></tr>";
                if (isset($_SERVER['SCRIPT_NAME']))
                    echo "<tr><th>Script<th><td>", $_SERVER['SCRIPT_NAME'], "</td></tr>";
                if ($ezpversion) {
                    echo "<tr><th>eZ publish version<th><td>", $ezpversion, "</td></tr>";
                }
                if ($gwbase_version) {
                    echo "<tr><th>gwBase version<th><td>", $gwbase_version, "</td></tr>";
                }
                if ($gitTag) {
                    $gitRefUrl = null;
                    if (isset($GLOBALS['ezpDebug']['git']['commit_view']) && $gitRef)
                        $gitRefUrl = preg_replace("/\\<git-ref\\>/", $gitRef, $GLOBALS['ezpDebug']['git']['ref_view']);
                    $gitCommitUrl = null;
                    if (isset($GLOBALS['ezpDebug']['git']['commit_view']))
                        $gitCommitUrl = preg_replace("/\\<git-tag\\>/", $gitTag, $GLOBALS['ezpDebug']['git']['commit_view']);
                    echo "<tr><th>git commit<th><td class=\"git\">";
                    if ($gitCommitUrl)
                        echo "<a target=\"blank\" href=\"", htmlspecialchars($gitCommitUrl), "\">";
                    echo $gitTag;
                    if ($gitCommitUrl) {
                        echo ' <i class="fa fa-external-link-square"></i>';
                        echo "</a>";
                    }
                    if ($gitRef) {
                        echo " <span class=\"git-branch\">";
                        if ($gitRefUrl)
                            echo "<a target=\"blank\" href=\"", htmlspecialchars($gitRefUrl), "\">";
                        echo $gitRef;
                        if ($gitRefUrl) {
                            echo ' <i class="fa fa-external-link-square"></i>';
                            echo "</a>";
                        }
                            echo "</span>";
                    }
                    echo "</td></tr>";
                }
                echo "<tr><th>Server time<th><td>", date("c"), "</td></tr>";
                echo "</table>";
                echo "</div>";
                echo '<script>
function expandFrameContext(e)
{
    e.preventDefault();
    var el = e.target;

    function findTopFrame(frame) {
        if (frame.classList) {
            for (var i = 0; i < frame.classList.length; ++i) {
                if (frame.classList[i] == "collapsed" || frame.classList[i] == "expanded")
                    return frame;
            }
        }
        if (frame.parentElement)
            return findTopFrame(frame.parentElement);
    }
    var frame = findTopFrame(el);
    if (!frame)
        return;
    var classes = [];
    for (var i = 0; i < frame.classList.length; ++i)
    {
        if (frame.classList[i] == "collapsed")
            classes.push("expanded");
        else if (frame.classList[i] == "expanded")
            classes.push("collapsed");
        else
            classes.push(frame.classList[i]);
    }
    frame.setAttribute("class", classes.join(" ") );
}

function toggleHiddenFrames(e)
{
    e.preventDefault();

    var $el = $(".stack-trace"),
        $hidden = $el.find(".frame.frame-hidden"),
        $toggle = $el.find(".hidden-frame-toggle"),
        shouldHide = $hidden.filter(".hidden").length == 0;
    $toggle.find(".hidden-frame-toggle-show").toggleClass("hidden-inline", !shouldHide);
    $toggle.find(".hidden-frame-toggle-hide").toggleClass("hidden-inline", shouldHide);
    $hidden.toggleClass( "hidden", shouldHide );

}

function elementToClip(el)
{
    var copyEvent = new ClipboardEvent("copy");
    copyEvent.clipboardData.items.add($(el).text(), "text/plain");
    document.dispatchEvent(copyEvent);
    // if (window.clipboard) {
    //     window.clipboard.setData( "text/plain", $(el).text() );
    // }
}

$(function () {
    $trace = $(".stack-trace");
    $(".expand-trigger").on("click", expandFrameContext);
    $trace.find(".hidden-frame-toggle").on("click", toggleHiddenFrames);
});

</script>
';
                echo "<div class=\"stack-trace\">";
                echo "<h3 class=\"title\">Stacktrace</h3>\n";
                $hiddenFrames = 0;
                foreach ( $info as $frame ) {
                    if (isset($frame['is_hidden']) && $frame['is_hidden'])
                        $hiddenFrames += 1;
                }
                if ($hiddenFrames) {
                    echo "<div>";
                    echo "<span>($hiddenFrames hidden frames) ",
                         "<a class=\"hidden-frame-toggle\" href=\"#\">",
                         "<span class=\"hidden-frame-toggle-show\">show</span>",
                         "<span class=\"hidden-frame-toggle-hide hidden-inline\">hide</span>",
                         "</a></span>";
                    echo "</div>";
                }

                $exportVariable = function ($val) {
                    if (in_array(gettype($val), array('object', 'array'))) {
                        $val = json_encode($val, JSON_PRETTY_PRINT);
                        // ob_start();
                        // var_dump($val);
                        // $val = ob_get_contents();
                        // ob_end_clean();
                    } else {
                        $val = htmlspecialchars(var_export($val, true));
                    }
                    if (strlen($val) > 800)
                        $val = substr($val, 0, 800);
                    return $val;
                };

                $docRoot = realpath($_SERVER['DOCUMENT_ROOT']);

                if ($docRoot[strlen($docRoot - 1)] != '/')
                    $docRoot .= '/';

                $relativePath = function ($path) use ($docRoot) {
                    $path = realpath($path);
                    if (substr($path, 0, strlen($docRoot)) == $docRoot)
                        return substr($path, strlen($docRoot));
                    return $path;
                };

                $splitPath = function ($path) use ($docRoot) {
                    $path = realpath($path);
                    if (substr($path, 0, strlen($docRoot)) == $docRoot)
                        return array( $docRoot, substr($path, strlen($docRoot)) );
                    return array( null, $path );
                };

                $exportType = function ($val) {
                    $type = gettype($val);
                    if ($type == 'object')
                        $type = get_class($val);
                    return $type;
                };


                foreach ($info as $frame) {
                    $frame['id'] = md5('' . mt_rand());
                    $frameClass = (isset($frame['is_expanded']) ? $frame['is_expanded'] : false) ? "expanded" : "collapsed";
                    $isHidden = isset($frame['is_hidden']) ? $frame['is_hidden'] : false;
                    echo "<div id=\"frame-", $frame['id'], "\" class=\"frame $frameClass ", $isHidden ? "frame-hidden hidden" : "", "\">";
                    $pathSplit = call_user_func( $splitPath, $frame['abs_path'] );
                    echo "<div class=\"path-line\"><code>";
                    if ($pathSplit[0]) {
                        echo "<span class=\"path path-root\">", htmlspecialchars( $pathSplit[0] ), "</span>";
                    }
                    echo "<span class=\"path path-project\">", htmlspecialchars( $pathSplit[1] ), "</span>:<span>", $frame['lineno'], "</span></code>",
                         " <span>in</span> <span class=\"func-name\">", $frame['function'], "</span>", $isHidden ? " (hidden)" : "",
                         " <button class=\"btn btn-primary clipboard\" title=\"Copy path to clipboard\" data-clipboard-text=\"", htmlspecialchars( $pathSplit[1] ), ":", $frame['lineno'], "\"><i class=\"fa fa-clipboard\"></i></button>",
                         " <button class=\"btn btn-default clipboard\" title=\"Copy full path to clipboard\" data-clipboard-text=\"", htmlspecialchars( $pathSplit[0] ), ":", $frame['lineno'], "\"><i class=\"fa fa-clipboard\"></i></button>",
                         "</div>\n";
                    echo '<ol class="context" start="', ($frame['lineno'] - count($frame['pre_context'])) , '">';
                    foreach ($frame['pre_context'] as $context) {
                        echo "<li class=\"code-line expandable expand-trigger\">", htmlspecialchars($context), "</li>\n";
                    }
                    echo "<li class=\"code-line active expand-trigger\">", htmlspecialchars($frame['context_line']), "</li>\n";
                    foreach ($frame['post_context'] as $context) {
                        echo "<li class=\"code-line expandable expand-trigger\">", htmlspecialchars($context), "</li>\n";
                    }
                    echo "</ol>";

                    if (isset($frame['args'])) {
                        echo "<div class=\"expandable args\"><h3>Arguments</h3><table class=\"vars\">";
                        foreach ($frame['args'] as $name => $arg) {
                            echo "<tr><td class=\"varname\">$name</td><td>", call_user_func($exportType, $arg), "</td><td>", call_user_func($exportVariable, $arg), "</td></tr>\n";
                        }
                        echo "</table></div>";
                    }

                    if (isset($frame['vars'])) {
                        echo "<div class=\"expandable vars\"><h3>Variables</h3><table class=\"vars\">";
                        foreach ($frame['vars'] as $name => $var) {
                            echo "<tr><td class=\"varname\">$name</td><td>", call_user_func($exportType, $var), "</td><td>", call_user_func($exportVariable, $var), "</td></tr>\n";
                        }
                        echo "</table></div>";
                    }

                    echo "</div>";
                }

                if (isset($exc->textTrace))
                    echo "<div class=\"text-trace collapsed\"><h3 class=\"expand-trigger\">Xdebug stacktrace</h3><div class=\"expandable expand-trigger\">", $exc->textTrace, "</div></div>";
                echo "</div>";

                $vars = $_SERVER;
                ksort($vars);
                echo "<div class=\"globals\">";
                echo "<div class=\"server-info collapsed\">";
                echo "<h3 class=\"expand-trigger\">Server (", count($vars), ")</h3>";
                echo "<table class=\"vars expandable\">";
                foreach ($vars as $name => $var) {
                    echo "<tr><td class=\"varname\">", htmlspecialchars($name), "</td><td>", call_user_func($exportVariable, $var), "</td></tr>";
                }
                echo "</table>";
                echo "</div>";

                if (isset($_SERVER['REQUEST_METHOD'])) {
                    if ($_SERVER['REQUEST_METHOD'] == 'GET') {
                        $requestVars = $_GET;
                    } else {
                        $requestVars = $_POST;
                    }

                    ksort($requestVars);
                    echo "<div class=\"request expanded\">";
                    echo "<h3 class=\"expand-trigger\">Request [", $_SERVER['REQUEST_METHOD'], "] (", count($requestVars), ")</h3>";
                    echo "<table class=\"vars expandable\">";
                    echo "<tr><td colspan=\"2\">", $_SERVER['REQUEST_URI'], "</td></tr>";
                    foreach ($requestVars as $name => $var) {
                        echo "<tr><td class=\"varname\">", htmlspecialchars($name), "</td><td>", call_user_func($exportVariable, $var), "</td></tr>";
                    }
                    echo "</table>";
                    echo "</div>";
                }

                if (isset($_COOKIE)) {
                    $vars = $_COOKIE;
                    ksort($vars);
                    echo "<div class=\"request collapsed\">";
                    echo "<h3 class=\"expand-trigger\">Cookies (", count($vars), ")</h3>";
                    echo "<table class=\"vars expandable\">";
                    foreach ($vars as $name => $var) {
                        echo "<tr><td class=\"varname\">", htmlspecialchars($name), "</td><td>", call_user_func($exportVariable, $var), "</td></tr>";
                    }
                    echo "</table>";
                    echo "</div>";
                }

                if (isset($_SESSION)) {
                    $vars = $_SESSION;
                    ksort($vars);
                    echo "<div class=\"request collapsed\">";
                    echo "<h3 class=\"expand-trigger\">Session (", count($vars), ")</h3>";
                    echo "<table class=\"vars expandable\">";
                    foreach ($vars as $name => $var) {
                        echo "<tr><td class=\"varname\">", htmlspecialchars($name), "</td><td>", call_user_func($exportVariable, $var), "</td></tr>";
                    }
                    echo "</table>";
                    echo "</div>";
                }

                if (isset($_ENV)) {
                    $vars = $_ENV;
                    ksort($vars);
                    echo "<div class=\"request collapsed\">";
                    echo "<h3 class=\"expand-trigger\">Env (", count($vars), ")</h3>";
                    echo "<table class=\"vars expandable\">";
                    foreach ($vars as $name => $var) {
                        echo "<tr><td class=\"varname\">", htmlspecialchars($name), "</td><td>", call_user_func($exportVariable, $var), "</td></tr>";
                    }
                    echo "</table>";
                    echo "</div>";
                }

                if (class_exists('eZExtension')) {
                    $ezpExtensions = @eZExtension::activeExtensions();
                    sort($ezpExtensions);
                    echo "<div class=\"request collapsed\">";
                    echo "<h3 class=\"expand-trigger\">eZ extensions (", count($ezpExtensions), ")</h3>";
                    echo "<table class=\"vars expandable\">";
                    foreach ($ezpExtensions as $name) {
                        echo "<tr><td class=\"varname\">", htmlspecialchars($name), "</td></tr>";
                    }
                    echo "</table>";
                    echo "</div>";
                }

                echo "</div>";

                echo "<script>new Clipboard('.clipboard');</script>";

                echo $this->writeFooter();
            } else {
                return false;
            }
        }
    }

    // Redefine eZDebug with the patched functions
    // - This fixes the bug with calling restore_error_handler() when $type == HANDLE_TO_PHP,
    //   restore_error_handler() must only be called if it was previously set by eZDebug.
    // - It also installs supporting for overriding the type in the global variable 'ezpDebugType'.
    class eZDebug extends MonkeyPatch_eZDebug
    {
        public $strict = null;
        public $warningTypes;
        public $errorTypes;
        public $ignoreNext = false;
        // Define constant for exception handling, does not exist in older eZ publish installations
        // Same value as eZDebug::HANDLE_EXCEPTION, but uses different name to avoid conflicts
        const EZDEBUG_HANDLE_EXCEPTION = 3;

        function write( $string, $verbosityLevel = self::LEVEL_NOTICE, $label = "", $backgroundClass = "", $alwaysLog = false )
        {
            if ($this->strict === null )
            {
                $this->strict = isset($GLOBALS['ezpDebugStrict']) ? $GLOBALS['ezpDebugStrict'] : false;
                $this->warningTypes = E_WARNING | E_CORE_WARNING | E_COMPILE_WARNING | E_USER_WARNING | E_DEPRECATED;
                $this->errorTypes = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_STRICT | E_RECOVERABLE_ERROR;
            }

            $types = 0;
            if ( !$this->ignoreNext && (
                    ($this->strict == 'warning' && ($verbosityLevel == self::LEVEL_WARNING || $verbosityLevel == self::LEVEL_ERROR)) ||
                    ($this->strict == 'error' && $verbosityLevel == self::LEVEL_ERROR)
                ) )
            {
                $this->ignoreNext = false;
                $code = $verbosityLevel == self::LEVEL_WARNING ? E_USER_WARNING : E_USER_ERROR;
                $file = '';
                $line = 1;
                if ($label)
                    $string = $string . "\n" . $label . "";

                // Figure out filename and line from caller
                $trace = debug_backtrace();
                if ($trace && count($trace) >= 2) {
                    $file = $trace[1]['file'];
                    $line = $trace[1]['line'];
                }

                $errorHandler = new ezpErrorHandler($this->warningTypes, $this->errorTypes);

                $ignoreError = false;
                $ignoreStatus = $errorHandler->checkIgnoreError($verbosityLevel == self::LEVEL_WARNING ? E_USER_WARNING : E_USER_ERROR, $string, $file, $line);
                if ( $ignoreStatus ? $ignoreStatus['is_ignored'] : false ) {
                    if ( isset($ignoreStatus['errno']) )
                        $errno = $ignoreStatus['errno'];
                    $ignoreError = true;
                }

                if (!$ignoreError) {
                    $exc = new ezpFatalError($string, 0, $code, $file, $line);
                    $exc->type = $verbosityLevel == self::LEVEL_WARNING ? 'warning' : 'error';

                    if ($errorHandler->handleException($exc) !== false)
                        return;
                } else {
                    $this->ignoreNext = false;
                    $verbosityLevel = self::LEVEL_NOTICE;
                }
            }

            parent::write($string, $verbosityLevel, $label, $backgroundClass, $alwaysLog);
        }

        function errorHandler( $errno, $errstr, $errfile, $errline )
        {
            if ( error_reporting() == 0 ) // @ error-control operator is used
                return true;
            // echo "errorHandler( $errno, $errstr, $errfile, $errline )\n";

            if ($this->strict === null )
            {
                $this->strict = isset($GLOBALS['ezpDebugStrict']) ? $GLOBALS['ezpDebugStrict'] : false;
                $this->warningTypes = E_WARNING | E_CORE_WARNING | E_COMPILE_WARNING | E_USER_WARNING | E_DEPRECATED;
                $this->errorTypes = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_STRICT | E_RECOVERABLE_ERROR;
            }

            $types = 0;
            if ($this->strict == 'warning')
                $types |= $this->warningTypes | $this->errorTypes;
            else if ($this->strict == 'error')
                $types |= $this->errorTypes;

            if ($types & $errno)
            {
                $errorHandler = new ezpErrorHandler($this->warningTypes, $this->errorTypes);
                $ignoreError = false;
                $ignoreStatus = $errorHandler->checkIgnoreError($errno, $errstr, $errfile, $errline);
                if ( $ignoreStatus ? $ignoreStatus['is_ignored'] : false ) {
                    if ( isset($ignoreStatus['errno']) )
                        $errno = $ignoreStatus['errno'];
                    $ignoreError = true;
                }

                if (!$ignoreError) {
                    $exc = new ezpFatalError($errstr, 0, $errno, $errfile, $errline);
                    $exc->type = $errno & $this->errorTypes ? 'error' : 'warning';

                    if ($errorHandler->handleException($exc) !== false)
                        return;
                } else {
                    $this->ignoreNext = false;
                    if ($this->HandleType == self::HANDLE_FROM_PHP) {
                        // If eZDebug is handling errors we need to flag it so it does not end up as a strict error
                        // and stops execution, instead we want it be logged in eZDebug as normal.
                        $this->ignoreNext = true;
                    }
                }
            }

            parent::errorHandler($errno, $errstr, $errfile, $errline);
        }

        public static function setHandleType( $type )
        {
            $instance = eZDebug::instance();

            if (isset($GLOBALS['ezpDebugType']) && $GLOBALS['ezpDebugType'] !== null)
                $type = $GLOBALS['ezpDebugType'];

            if ( $type != self::HANDLE_TO_PHP and
                 $type != self::HANDLE_FROM_PHP and
                 $type != self::EZDEBUG_HANDLE_EXCEPTION )
                $type = self::HANDLE_NONE;
            if ( extension_loaded( 'xdebug' ) &&
                 (isset($GLOBALS['ezpDebugUseXdebug']) ? $GLOBALS['ezpDebugUseXdebug'] : true) &&
                 $type == self::HANDLE_FROM_PHP )
                $type = self::HANDLE_NONE;
            if ( $type == $instance->HandleType )
                return $instance->HandleType;

            if ( $instance->HandleType == self::HANDLE_FROM_PHP or $instance->HandleType == self::EZDEBUG_HANDLE_EXCEPTION )
                restore_error_handler();
            switch ( $type )
            {
                case self::HANDLE_FROM_PHP:
                {
                    set_error_handler( array( $instance, 'recursionProtectErrorHandler' ) );
                } break;

                case self::EZDEBUG_HANDLE_EXCEPTION:
                {
                    set_error_handler( array( $instance, 'exceptionErrorHandler' ) );
                } break;
            }
            $oldHandleType = $instance->HandleType;
            $instance->HandleType = $type;
            return $oldHandleType;
        }
    }

    // Redefine eZExecution with the patched functions
    // - Support for not installing a shutdown handler and exception handler if some other
    //   code should handle it instead
    // class eZExecution extends MonkeyPatch_eZExecution
    // {
    // }

    // TODO: Fix exception handler, eZ publish takes it over
    $exceptionHandler = function ($exc) {
        $errorHandler = new ezpErrorHandler();
        return $errorHandler->handleException($exc);
    };

    $shutdownHandler = function () {
        if (!defined('EZP_ERROR_HANDLED')) {
            define('EZP_ERROR_HANDLED', true);
            if (null === $lastError = error_get_last()) {
                return;
            }

            // Store the xdebug stack trace if it was printed, needed as we don't have a stack trace in this handler
            // TODO: Parse xdebug markup and turn into regular trace array
            $textTrace = ob_get_contents();
            @ob_end_clean();

            $exc = new ezpFatalError(
                @$lastError['message'], @$lastError['type'], @$lastError['type'],
                @$lastError['file'], @$lastError['line']
            );

            $frame_where_exception_thrown = array(
                'file' => $exc->getFile(),
                'line' => $exc->getLine(),
            );

            // if (!$trace || !isset($trace[0]['file']) || $trace[0]['file'] != $frame_where_exception_thrown['file'])
                // array_unshift($trace, $frame_where_exception_thrown);
            $exc->extraTrace = array( $frame_where_exception_thrown );
            $exc->textTrace = $textTrace;
            $exc->type = 'fatal error';

            $errorHandler = new ezpErrorHandler();
            return $errorHandler->handleException($exc);
        }
    };

    if ($options['fatal_handler']) {
        // Turn of xdebug features if enabled as we want to have control over the output
        // if (function_exists('xdebug_disable'))
        //     xdebug_disable();

        // Capture all output such as xdebug, we don't want to it to disturb the HTML output.
        // For CLI however we need to output immediatelyg
        if ( PHP_SAPI != 'cli' ) {
            ob_start();
        }

        register_shutdown_function( $shutdownHandler );
        //require_once('lib/ezutils/classes/ezexecution.php');
        //eZExecution::addFatalErrorHandler( $shutdownHandler );
        set_exception_handler($exceptionHandler);
    }
};

$GLOBALS['RAVEN']['bootstrap'] = function () {
    require_once('vendor/raven/raven/lib/Raven/Autoloader.php');
    Raven_Autoloader::register();

    if (!isset($GLOBALS['RAVEN']))
        $GLOBALS['RAVEN'] = array();

    if (!isset($GLOBALS['RAVEN']['dsn']))
        return;

    if (!isset($GLOBALS['RAVEN']['tags']))
        $GLOBALS['RAVEN']['tags'] = array();

    $GLOBALS['RAVEN']['tags']['php_version'] = phpversion();
    $GLOBALS['RAVEN']['client'] = new Raven_Client($GLOBALS['RAVEN']['dsn'], array(
        'tags' => $GLOBALS['RAVEN']['tags']
    ));

    // Error types we want to report
    if ( !isset($GLOBAL['RAVEN']['error_types']) ) {
        $severity = isset($GLOBALS['RAVEN']['severity']) ? $GLOBALS['RAVEN']['severity'] : 'error';
        $GLOBAL['RAVEN']['error_types'] = array(
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_COMPILE_ERROR,
            E_USER_ERROR,
            E_STRICT,
            E_RECOVERABLE_ERROR
        );
        if ($severity == 'warning' || $severity == 'notice') {
            $GLOBAL['RAVEN']['error_types'] = array_merge(
                $GLOBAL['RAVEN']['error_types'],
                array(
                    E_WARNING,
                    E_CORE_WARNING,
                    E_COMPILE_WARNING,
                    E_USER_WARNING,
                    E_DEPRECATED,
                    E_USER_DEPRECATED,
                )
            );
        }
        if ($severity == 'notice') {
            $GLOBAL['RAVEN']['error_types'] = array_merge(
                $GLOBAL['RAVEN']['error_types'],
                array(
                    E_NOTICE,
                    E_USER_NOTICE,
                )
            );
        }
    }

    // Extend the Raven error handler to dynamically update tags when they first are needed
    class Aplia_ErrorHandler extends Raven_ErrorHandler
    {
        public function handleException($e, $isError = false, $vars = null)
        {
            $this->updateTags();
            parent::handleException($e, $isError, $vars);
        }

        public function handleError($code, $message, $file = '', $line = 0, $context=array())
        {
            $this->updateTags();
            parent::handleError($code, $message, $file, $line, $context);
        }

        public function handleFatalError()
        {
            $this->updateTags();
            parent::handleFatalError();
        }

        public function updateTags()
        {
            if ($this->isTagsUpdated)
                return;

            $tags = array();
            if (class_exists('eZPublishSDK') || file_exists($GLOBALS['ezpBootstrapRoot'] . '/lib/version.php')) {
                require_once('lib/version.php');
                $tags['ezp_version'] = eZPublishSDK::version();
            }
            if (class_exists('gwBaseInfo') ||
                file_exists($GLOBALS['ezpBootstrapRoot'] . '/extension/gwbase/ezinfo.php') ||
                file_exists($GLOBALS['ezpBootstrapRoot'] . '/extension/gwbase3/ezinfo.php')) {
                if (file_exists($GLOBALS['ezpBootstrapRoot'] . '/extension/gwbase/ezinfo.php')) {
                    require_once('extension/gwbase/ezinfo.php');
                } elseif (file_exists($GLOBALS['ezpBootstrapRoot'] . '/extension/gwbase3/ezinfo.php')) {
                    require_once('extension/gwbase3/ezinfo.php');
                }
                $gwBaseInfo = gwBaseInfo::info();
                $tags['gwbase_version'] = $gwBaseInfo['Version'];
            }
            $git = new ApliaGitHelper();
            $gitTag = $git->getGitTag('.git');
            if ($gitTag)
                $tags['git_tag'] = $gitTag;
            // $gitTag = $this->getGitTag($this->getGitPath('extension/gwbase3/.git'));
            // if ($gitTag)
            //     $tags['gwbase_git_tag'] = $gitTag;
            $this->client->tags_context($tags);

            $this->isTagsUpdated = true;
        }

        private $isTagsUpdated = false;
    }

    // Create error handler
    $GLOBALS['RAVEN']['error_handler'] = new Aplia_ErrorHandler( $GLOBALS['RAVEN']['client'] );

    // Register error handler callbacks, make sure we only report the types we want
    $GLOBALS['RAVEN']['error_handler']->registerErrorHandler(false, array_sum( $GLOBAL['RAVEN']['error_types'] ) );
    $GLOBALS['RAVEN']['error_handler']->registerExceptionHandler(false);
    $GLOBALS['RAVEN']['error_handler']->registerShutdownFunction();
};

$GLOBALS['FirePHP']['bootstrap'] = function () {
    if ( file_exists($GLOBALS['ezpBootstrapRoot'] . '/vendor/firephp/firephp-core/lib/FirePHPCore/FirePHP.class.php') ) {
        require_once($GLOBALS['ezpBootstrapRoot'] . '/vendor/firephp/firephp-core/lib/FirePHPCore/FirePHP.class.php');

        // Error types we want to report
        $logTypes = array();
        if ( isset($GLOBAL['FirePHP']['error_types']) ) {
            $logTypes = $GLOBAL['FirePHP']['error_types'];
        } else {
            $severity = isset($GLOBALS['FirePHP']['severity']) ? $GLOBALS['FirePHP']['severity'] : 'warning';
            $logTypes = array(
                E_ERROR,
                E_PARSE,
                E_CORE_ERROR,
                E_COMPILE_ERROR,
                E_USER_ERROR,
                E_STRICT,
                E_RECOVERABLE_ERROR
            );
            if ($severity == 'warning' || $severity == 'notice') {
                $logTypes = array_merge(
                    $logTypes,
                    array(
                        E_WARNING,
                        E_CORE_WARNING,
                        E_COMPILE_WARNING,
                        E_USER_WARNING,
                        E_DEPRECATED,
                        E_USER_DEPRECATED,
                    )
                );
            }
            if ($severity == 'notice') {
                $logTypes = array_merge(
                    $logTypes,
                    array(
                        E_NOTICE,
                        E_USER_NOTICE,
                    )
                );
            }
        }

        class Aplia_AjaxLogger extends FirePHP
        {
            public $logTypes;
            public function __construct( $logTypes = false )
            {
                $this->logTypes = $logTypes;
            }

            // We do not use the error handler from FirePHP since we want some more control of what gets reported
            public function errorHandler($errno, $errstr, $errfile, $errline, $errcontext)
            {
                // Don't throw exception if error reporting is switched off
                if (error_reporting() == 0) {
                    return;
                }

                // Only throw exceptions for errors we are asking for
                if (error_reporting() & $errno) {
                    if ($this->logTypes === false || $errno & $this->logTypes) {
                        $exception = new ErrorException($errstr, 0, $errno, $errfile, $errline);
                        if ($this->throwErrorExceptions) {
                            throw $exception;
                        } else {
                            $this->fb($exception);
                        }
                    }
                }
            }

            public function shutdownHandler()
            {
                if (null === $lastError = error_get_last()) {
                    return;
                }

                if ($lastError['type'] & $this->logTypes) {
                    if (!$this->throwErrorExceptions) {
                        $exception = new ErrorException(
                            @$lastError['message'], @$lastError['type'], @$lastError['type'],
                            @$lastError['file'], @$lastError['line']
                        );
                        $this->fb($exception, 'Fatal Error', self::ERROR);
                    }
                }
            }

            public function registerShutdownHandler()
            {
                register_shutdown_function( array($this, 'shutdownHandler') );
            }
        }

        $GLOBALS['FirePHP']['instance'] = $firePHP = new Aplia_AjaxLogger( array_sum( $logTypes ) );
        $firePHP->registerErrorHandler();
        $firePHP->registerExceptionHandler();
        $firePHP->registerAssertionHandler();
        $firePHP->registerShutdownHandler();
    }
};

if (!isset($GLOBALS['ezpDebugMode']))
    $GLOBALS['ezpDebugMode'] = 'default';
if (!isset($GLOBALS['ezpDebugModeCli']))
    $GLOBALS['ezpDebugModeCli'] = 'default';

if ( PHP_SAPI == 'cli' ) {
    $GLOBALS['ezpDebugModeCurrent'] = $GLOBALS['ezpDebugModeCli'];
} else {
    $GLOBALS['ezpDebugModeCurrent'] = $GLOBALS['ezpDebugMode'];
}

if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest') {
    // TODO: Install FirePHP
    if ($GLOBALS['ezpDebugModeCurrent'] == 'dev' || $GLOBALS['ezpDebugModeCurrent'] == 'dev-strict') {
        call_user_func($GLOBALS['ezpKernelBootstrap']);

        // Override default debug type no matter what the code tries to set it to
        // We want to disable the error handler and send all internal errors to PHP,
        // allowing the current handler to take care of them, for instance to use Raven
        $GLOBALS['ezpDebugType'] = eZDebug::HANDLE_TO_PHP;

        // Disable eZExecution shutdown and exception handler, Raven will handle this
        $GLOBALS['ezpExecution'] = array( 'installHandler' => false );

        call_user_func($GLOBALS['FirePHP']['bootstrap']);
    }
} else {
    if ($GLOBALS['ezpDebugModeCurrent'] == 'prod') {
        call_user_func($GLOBALS['ezpKernelBootstrap']);

        // Override default debug type no matter what the code tries to set it to
        // We want to disable the error handler and send all internal errors to PHP,
        // allowing the current handler to take care of them, for instance to use Raven
        $GLOBALS['ezpDebugType'] = eZDebug::HANDLE_TO_PHP;

        // Disable eZExecution shutdown and exception handler, Raven will handle this
        $GLOBALS['ezpExecution'] = array( 'installHandler' => false );

        call_user_func($GLOBALS['RAVEN']['bootstrap']);
    } else if ($GLOBALS['ezpDebugModeCurrent'] == 'dev') {
        // Override eZDebug
        if (!isset($GLOBALS['ezpDebugFatalErrorHandler']))
            $GLOBALS['ezpDebugFatalErrorHandler'] = true;

        // Disable eZExecution shutdown and exception handler, custom handlers will be installed
        $GLOBALS['ezpExecution'] = array( 'installHandler' => false );

        call_user_func($GLOBALS['ezpKernelBootstrap'], array('fatal_handler' => true));
    } else if ($GLOBALS['ezpDebugModeCurrent'] == 'dev-strict') {
        // Override eZDebug
        if (!isset($GLOBALS['ezpDebugFatalErrorHandler']))
            $GLOBALS['ezpDebugFatalErrorHandler'] = true;
        if (!isset($GLOBALS['ezpDebugStrict']))
            $GLOBALS['ezpDebugStrict'] = 'warning';

        // Disable eZExecution shutdown and exception handler, custom handlers will be installed
        $GLOBALS['ezpExecution'] = array( 'installHandler' => false );
        call_user_func($GLOBALS['ezpKernelBootstrap'], array('fatal_handler' => true));
    }
}

?>
